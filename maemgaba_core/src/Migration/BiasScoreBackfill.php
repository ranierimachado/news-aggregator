<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Migration;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\maemgaba_core\BiasScore;

/**
 * Backfills the numeric bias score fields from the legacy list fields.
 *
 * Maps left → −1, center → 0, right → +1 (BiasScore::FROM_LEGACY): never an
 * extreme, since no existing card was ever assigned one. Pure SQL
 * INSERT … SELECT per table, so it copes with tens of thousands of cards
 * without loading entities. Idempotent: rows that already have a score
 * (same entity/revision, language, delta) are left alone.
 *
 * Runs from maemgaba_core_deploy_bias_score_backfill(), i.e. after
 * config:import has created the score fields.
 */
class BiasScoreBackfill {

  /**
   * Tuples of [entity type, bundle, legacy field, score field] to backfill.
   */
  public const PAIRS = [
    ['node', 'card', 'field_article_bias', 'field_bias_score'],
    ['node', 'feed_source', 'field_article_bias', 'field_default_bias_score'],
    ['taxonomy_term', 'sources', 'field_default_bias', 'field_default_bias_score'],
  ];

  public function __construct(
    protected Connection $database,
    protected CacheBackendInterface $entityCache,
  ) {}

  /**
   * Copies legacy values into the score tables.
   *
   * @return array<string, int>
   *   Rows inserted, keyed "entity_type.bundle.table".
   */
  public function run(): array {
    $case = 'CASE a.%s';
    $whens = '';
    foreach (BiasScore::FROM_LEGACY as $legacy => $score) {
      $whens .= " WHEN '{$legacy}' THEN {$score}";
    }
    $allowed = "'" . implode("','", array_keys(BiasScore::FROM_LEGACY)) . "'";

    $counts = [];
    foreach (self::PAIRS as [$entityType, $bundle, $legacyField, $scoreField]) {
      $revisionPrefix = $entityType === 'taxonomy_term' ? 'taxonomy_term_revision' : 'node_revision';
      $tables = [
        "{$entityType}__{$legacyField}" => ["{$entityType}__{$scoreField}", ['entity_id']],
        "{$revisionPrefix}__{$legacyField}" => ["{$revisionPrefix}__{$scoreField}", ['entity_id', 'revision_id']],
      ];
      foreach ($tables as $from => [$to, $keys]) {
        if (!$this->database->schema()->tableExists($from) || !$this->database->schema()->tableExists($to)) {
          continue;
        }
        $match = implode(' AND ', array_map(fn ($k) => "s.{$k} = a.{$k}", array_merge($keys, [
          'langcode',
          'delta',
          'deleted',
        ])));
        $sql = "INSERT INTO {{$to}} (bundle, deleted, entity_id, revision_id, langcode, delta, {$scoreField}_value)
          SELECT a.bundle, a.deleted, a.entity_id, a.revision_id, a.langcode, a.delta, "
          . sprintf($case, "{$legacyField}_value") . $whens . " END
          FROM {{$from}} a
          WHERE a.bundle = :bundle AND a.{$legacyField}_value IN ({$allowed})
            AND NOT EXISTS (SELECT 1 FROM {{$to}} s WHERE {$match})";
        $statement = $this->database->prepareStatement($sql, [], TRUE);
        $statement->execute([':bundle' => $bundle]);
        $counts["{$entityType}.{$bundle}.{$to}"] = $statement->rowCount();
      }
    }

    // Entities cached before the fill don't carry the new values.
    $this->entityCache->deleteAll();
    return $counts;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Database\Connection;
use Drupal\maemgaba_core\BiasScore;
use Drupal\maemgaba_core\CardRole;

/**
 * Per-outlet bias tallies, aggregated in SQL.
 *
 * Shared by the sources page and the MCP source_bias_report tool. Never
 * hydrates card entities: with thousands of cards that exhausts PHP's memory
 * limit (the /fontes 500 of 2026-09-22).
 */
class SourceBiasStats {

  public function __construct(
    protected Connection $database,
    protected SpectrumService $spectrum,
  ) {}

  /**
   * Published-card counts per source term, bucketed by the site spectrum.
   *
   * Counts the outlet's own news reporting only: reprints
   * (field_syndicated_from) and opinion (field_section = opinion) are left
   * out when the site has those fields.
   *
   * @return array<int, array{tid: int, name: string, default_score: int|null, dist: array<string, int>, total: int, last: int}>
   *   Keyed by source term id, only outlets with at least one scored card.
   */
  public function tally(): array {
    $query = $this->database->select('node_field_data', 'nfd');
    $query->innerJoin('node__field_source', 'nfs', 'nfs.entity_id = nfd.nid AND nfs.deleted = 0');
    $query->innerJoin('node__field_bias_score', 'nbs', 'nbs.entity_id = nfd.nid AND nbs.deleted = 0');
    $query->condition('nfd.type', 'card');
    $query->condition('nfd.status', 1);
    $schema = $this->database->schema();
    if ($schema->tableExists('node__field_syndicated_from')) {
      $query->leftJoin('node__field_syndicated_from', 'nsf', 'nsf.entity_id = nfd.nid AND nsf.deleted = 0');
      $query->isNull('nsf.field_syndicated_from_target_id');
    }
    if ($schema->tableExists('node__field_section')) {
      $query->leftJoin('node__field_section', 'nsec', "nsec.entity_id = nfd.nid AND nsec.deleted = 0 AND nsec.bundle = 'card'");
      $or = $query->orConditionGroup()
        ->isNull('nsec.field_section_value')
        ->condition('nsec.field_section_value', CardRole::SECTION_OPINION, '<>');
      $query->condition($or);
    }
    $query->fields('nfs', ['field_source_target_id']);
    $query->fields('nbs', ['field_bias_score_value']);
    $query->addExpression('COUNT(*)', 'cnt');
    $query->addExpression('MAX(nfd.created)', 'last_created');
    $query->groupBy('nfs.field_source_target_id');
    $query->groupBy('nbs.field_bias_score_value');
    $rows = $query->execute()->fetchAll();

    $tids = array_values(array_unique(array_map(fn ($row) => (int) $row->field_source_target_id, $rows)));
    if (!$tids) {
      return [];
    }

    $terms = [];
    $termQuery = $this->database->select('taxonomy_term_field_data', 'ttd');
    $termQuery->leftJoin('taxonomy_term__field_default_bias_score', 'tds', 'tds.entity_id = ttd.tid AND tds.deleted = 0');
    $termQuery->condition('ttd.tid', $tids, 'IN');
    $termQuery->fields('ttd', ['tid', 'name']);
    $termQuery->fields('tds', ['field_default_bias_score_value']);
    foreach ($termQuery->execute() as $row) {
      $terms[(int) $row->tid] = [
        'name' => (string) $row->name,
        'default_score' => BiasScore::normalize($row->field_default_bias_score_value),
      ];
    }

    $sources = [];
    foreach ($rows as $row) {
      $tid = (int) $row->field_source_target_id;
      if (!isset($terms[$tid])) {
        continue;
      }
      $key = $this->spectrum->keyFor(BiasScore::normalize($row->field_bias_score_value));
      if ($key === NULL) {
        continue;
      }
      $sources[$tid] ??= [
        'tid' => $tid,
        'name' => $terms[$tid]['name'],
        'default_score' => $terms[$tid]['default_score'],
        'dist' => $this->spectrum->emptyDistribution(),
        'total' => 0,
        'last' => 0,
      ];
      $sources[$tid]['dist'][$key] += (int) $row->cnt;
      $sources[$tid]['total'] += (int) $row->cnt;
      $sources[$tid]['last'] = max($sources[$tid]['last'], (int) $row->last_created);
    }
    return $sources;
  }

}

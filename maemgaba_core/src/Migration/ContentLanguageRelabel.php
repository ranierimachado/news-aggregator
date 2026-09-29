<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Migration;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Relabels monolingual content from one langcode to another, in SQL.
 *
 * A site whose content was created while the default language was English
 * has every node, term and path alias stored as "en", whatever language the
 * text is in. When such a site moves its interface to its real language
 * (say pt-br), the stored langcode must follow, or locale marks every
 * article lang="en" and new content ends up in a different language than
 * old content.
 *
 * Only for monolingual content: it refuses to run if any entity already has
 * a row in the target language (that would mean real translations). Works
 * table by table from each entity type's SQL table mapping (base, data,
 * revision and every dedicated field table), plus node_access, then clears
 * entity caches and rebuilds Search API trackers (their item ids embed the
 * language).
 */
class ContentLanguageRelabel {

  /**
   * Entity types relabelled.
   */
  public const ENTITY_TYPES = ['node', 'taxonomy_term', 'path_alias'];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Every table (with a langcode column) holding data for the entity types.
   *
   * @return string[]
   *   The table names.
   */
  public function tables(): array {
    $tables = [];
    foreach (self::ENTITY_TYPES as $entityTypeId) {
      if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      if (!$storage instanceof SqlEntityStorageInterface) {
        continue;
      }
      foreach ($storage->getTableMapping()->getTableNames() as $table) {
        if ($this->database->schema()->fieldExists($table, 'langcode')) {
          $tables[] = $table;
        }
      }
    }
    if ($this->database->schema()->tableExists('node_access')) {
      $tables[] = 'node_access';
    }
    return array_values(array_unique($tables));
  }

  /**
   * Relabels $from to $to.
   *
   * @return array<string, int>
   *   Rows updated per table.
   *
   * @throws \RuntimeException
   *   When content already exists in $to (translations): nothing is changed.
   */
  public function run(string $from, string $to): array {
    if ($from === $to) {
      return [];
    }
    $tables = $this->tables();
    foreach ($tables as $table) {
      $existing = (int) $this->database->select($table)->condition('langcode', $to)->countQuery()->execute()->fetchField();
      if ($existing > 0 && $table !== 'node_access') {
        throw new \RuntimeException("Table {$table} already has {$existing} row(s) in '{$to}': content is not monolingual, refusing to relabel.");
      }
    }

    $counts = [];
    $transaction = $this->database->startTransaction();
    try {
      foreach ($tables as $table) {
        $counts[$table] = (int) $this->database->update($table)
          ->fields(['langcode' => $to])
          ->condition('langcode', $from)
          ->execute();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);

    foreach (self::ENTITY_TYPES as $entityTypeId) {
      if ($this->entityTypeManager->hasDefinition($entityTypeId)) {
        $this->entityTypeManager->getStorage($entityTypeId)->resetCache();
      }
    }
    \Drupal::service('cache.entity')->deleteAll();
    \Drupal::service('path_alias.manager')->cacheClear();
    Cache::invalidateTags(['node_list', 'taxonomy_term_list', 'rendered']);

    if ($this->moduleHandler->moduleExists('search_api')) {
      foreach ($this->entityTypeManager->getStorage('search_api_index')->loadMultiple() as $index) {
        /** @var \Drupal\search_api\IndexInterface $index */
        if ($index->status()) {
          $index->rebuildTracker();
        }
      }
    }
    return array_filter($counts);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Vdb;

use Drupal\ai_vdb_provider_mariadb\Plugin\VdbProvider\MariaDBProvider;

/**
 * The MariaDB vector provider, with complete deletes before a re-index.
 *
 * Swapped in for the `mariadb` VDB plugin by
 * maemgaba_core_ai_vdb_provider_info_alter(). ai_vdb_provider_mariadb 1.0.1
 * looks up the rows to delete before re-indexing with querySearch(), which
 * defaults to LIMIT 10. A batch that re-indexes more than 10 already-indexed
 * items therefore deletes only 10 old rows and leaves the rest as
 * duplicates. In production this showed on the first vector-clustering cron
 * run (2026-09-27): the post-request batch re-indexed about 25 events and
 * left 14 of them with two rows. The same bug explains the "stale duplicate
 * rows after a re-index" seen on another site in July 2026.
 *
 * This override is identical to the parent except for the limit. It is
 * harmless once the provider fixes the bug upstream.
 */
class MariaDbVectorProvider extends MariaDBProvider {

  /**
   * Row cap for the id lookup: far above any batch's chunk count.
   */
  public const ID_LOOKUP_LIMIT = 1000000;

  /**
   * {@inheritdoc}
   */
  public function getVdbIds(
    string $collection_name,
    array $drupalIds,
    ?string $database = NULL,
    ?string $index_id = NULL,
  ): array {
    if (empty($drupalIds)) {
      return [];
    }
    $connection = $this->getConnection($database);
    $filters = 'WHERE drupal_entity_id IN ' . $this->getClient()->prepareStringArrayForSql(
      items: $drupalIds,
      connection: $connection,
    );
    if ($index_id !== NULL) {
      $filters .= ' AND index_id = ' . $this->getClient()->escapeStringForSql(
        string_to_escape: $index_id,
        connection: $connection,
      );
    }
    $data = $this->querySearch(
      collection_name: $collection_name,
      output_fields: ['id'],
      filters: $filters,
      limit: self::ID_LOOKUP_LIMIT,
      database: $database,
    );
    return array_map(static fn (array $row) => $row['id'], $data ?: []);
  }

}

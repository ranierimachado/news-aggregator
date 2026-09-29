<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Ask;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Proves the read-only target can see the ask_* views and nothing else.
 *
 * The target is $databases['readonly']['default'] in settings.local.php. The
 * deciding check lists every table the account can see in
 * information_schema.TABLES (outside information_schema itself, which every
 * account sees, and a few session tables every account may read): it must
 * be exactly the four views. MariaDB only lists
 * objects the account holds a privilege on, so a leaked base-table grant, a
 * database-wide grant or a PUBLIC grant shows up here. The account must also
 * differ from the site's own database user. The result is cached for ten
 * minutes; any failure turns the feature off with the "unavailable" state.
 */
class ReadonlyProbe implements ReadonlyProbeInterface {

  /**
   * Connection key of the read-only target.
   */
  public const KEY = 'readonly';

  /**
   * Cache id and lifetime of the probe result.
   */
  protected const CID = 'maemgaba_core:ask_probe';
  protected const TTL = 600;

  /**
   * Server tables MariaDB lets every account read, about its own session.
   *
   * They hold server status and variables, not site data, and the validator
   * denies performance_schema anyway. Anything else visible fails the probe.
   */
  public const ALWAYS_VISIBLE = [
    'performance_schema.global_status',
    'performance_schema.global_variables',
    'performance_schema.session_status',
    'performance_schema.session_variables',
    'performance_schema.session_account_connect_attrs',
  ];

  public function __construct(
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function connection(): ?Connection {
    if (!Database::getConnectionInfo(self::KEY)) {
      return NULL;
    }
    return Database::getConnection('default', self::KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function check(bool $fresh = FALSE): array {
    if (!$fresh && ($cached = $this->cache->get(self::CID))) {
      return $cached->data;
    }
    $result = $this->run();
    // Cache successes for the full window and failures briefly, so fixing
    // settings.local.php takes effect within a minute.
    $this->cache->set(self::CID, $result, $this->time->getRequestTime() + ($result['ok'] ? self::TTL : 60));
    return $result;
  }

  /**
   * Runs the checks.
   */
  protected function run(): array {
    $info = Database::getConnectionInfo(self::KEY);
    if (!$info) {
      return $this->fail('no_target', 'No $databases[\'readonly\'] target in settings.');
    }
    try {
      $connection = $this->connection();
      $prefix = $connection->getPrefix();
      $visible = $connection->query("SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA <> 'information_schema'")->fetchAll(FetchAs::List);
      $database = (string) $connection->query('SELECT DATABASE()')->fetchField();
    }
    catch (\Throwable $e) {
      return $this->fail('connect_failed', get_class($e));
    }

    $expected = array_map(fn (string $view) => $prefix . $view, AskSchema::VIEWS);
    $extra = [];
    $seen = [];
    foreach ($visible as [$schema, $table]) {
      if ($schema === $database && in_array($table, $expected, TRUE)) {
        $seen[] = $table;
        continue;
      }
      if (in_array($schema . '.' . $table, self::ALWAYS_VISIBLE, TRUE)) {
        continue;
      }
      $extra[] = $schema . '.' . $table;
    }
    if ($extra) {
      return $this->fail('extra_tables', count($extra) . ' object(s) outside the allow-list are visible, e.g. ' . implode(', ', array_slice($extra, 0, 3)));
    }
    $missing = array_diff($expected, $seen);
    if ($missing) {
      return $this->fail('missing_views', 'Not visible: ' . implode(', ', $missing));
    }
    $default = Database::getConnectionInfo('default');
    if (($info['default']['username'] ?? '') === ($default['default']['username'] ?? NULL)) {
      return $this->fail('same_user', 'The readonly target uses the site\'s own database user.');
    }
    return ['ok' => TRUE, 'reason' => '', 'detail' => ''];
  }

  /**
   * A failed check.
   */
  protected function fail(string $reason, string $detail): array {
    return ['ok' => FALSE, 'reason' => $reason, 'detail' => $detail];
  }

}

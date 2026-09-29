<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Ask;

/**
 * Builds SQL that turns a Unix timestamp into the site's local wall time.
 *
 * The shared production MariaDB has no time zone tables, so
 * CONVERT_TZ(..., 'America/New_York') returns NULL there, and loading them
 * would write to the server's own `mysql` schema. Instead, PHP's time zone
 * database is compiled into a CASE over the zone's UTC-offset transitions,
 * and the local time is computed as epoch + (timestamp + offset) seconds.
 * The result is a DATETIME that does not depend on the session time zone.
 */
final class LocalTimeSql {

  /**
   * The CASE expression giving the zone's UTC offset (seconds) at $column.
   *
   * @param string $timezone
   *   An IANA zone id, e.g. America/New_York.
   * @param string $column
   *   SQL expression holding a Unix timestamp.
   * @param int $from
   *   First timestamp the table must cover.
   * @param int $to
   *   Last timestamp the table must cover.
   */
  public static function offsetExpression(string $timezone, string $column, int $from, int $to): string {
    $transitions = self::transitions($timezone, $from, $to);
    if (count($transitions) === 1) {
      return (string) $transitions[0]['offset'];
    }
    $sql = 'CASE';
    // Each transition starts a new offset; the first entry is the offset in
    // force at $from.
    for ($i = 1, $n = count($transitions); $i < $n; $i++) {
      $sql .= sprintf(' WHEN %s < %d THEN %d', $column, $transitions[$i]['ts'], $transitions[$i - 1]['offset']);
    }
    return $sql . sprintf(' ELSE %d END', $transitions[count($transitions) - 1]['offset']);
  }

  /**
   * SQL for the local DATETIME of a Unix timestamp column.
   */
  public static function localDatetime(string $timezone, string $column, int $from, int $to): string {
    return sprintf("CAST('1970-01-01 00:00:00' AS DATETIME) + INTERVAL (%s + %s) SECOND", $column, self::offsetExpression($timezone, $column, $from, $to));
  }

  /**
   * Offset transitions between $from and $to, first entry at $from.
   *
   * @return array<int, array{ts: int, offset: int}>
   *   Ordered by ts.
   */
  public static function transitions(string $timezone, int $from, int $to): array {
    $zone = new \DateTimeZone($timezone);
    $out = [];
    foreach ($zone->getTransitions($from, $to) ?: [] as $t) {
      $offset = (int) $t['offset'];
      // getTransitions() repeats the state at $from as its first entry, and
      // may list transitions that don't change the offset (abbreviation
      // changes only). Keep offset changes only.
      if ($out && end($out)['offset'] === $offset) {
        continue;
      }
      $out[] = ['ts' => (int) $t['ts'], 'offset' => $offset];
    }
    if (!$out) {
      $out[] = ['ts' => $from, 'offset' => $zone->getOffset(new \DateTimeImmutable('@' . $from))];
    }
    return $out;
  }

  /**
   * The zone's current UTC offset as '+HH:MM', for SET time_zone.
   */
  public static function currentOffset(string $timezone, ?int $now = NULL): string {
    $date = new \DateTimeImmutable('@' . ($now ?? time()));
    return $date->setTimezone(new \DateTimeZone($timezone))->format('P');
  }

}

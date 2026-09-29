<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Unit;

use Drupal\maemgaba_core\Ask\LocalTimeSql;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The compiled UTC-offset table matches PHP's time zone database.
 */
#[Group('maemgaba_core')]
class LocalTimeSqlTest extends UnitTestCase {

  /**
   * Evaluates the generated CASE for one timestamp, in PHP.
   */
  protected function evaluate(string $case, int $ts): int {
    if (preg_match('/^-?\d+$/', $case)) {
      return (int) $case;
    }
    preg_match_all('/WHEN c < (-?\d+) THEN (-?\d+)/', $case, $m, PREG_SET_ORDER);
    foreach ($m as [, $limit, $offset]) {
      if ($ts < (int) $limit) {
        return (int) $offset;
      }
    }
    preg_match('/ELSE (-?\d+) END$/', $case, $else);
    return (int) $else[1];
  }

  /**
   * New York offsets around both 2026 DST switches and across the range.
   */
  public function testNewYorkTransitions(): void {
    $from = 1704067200;
    $to = 1988150400;
    $case = LocalTimeSql::offsetExpression('America/New_York', 'c', $from, $to);
    $zone = new \DateTimeZone('America/New_York');
    $probes = [
      // 2026-03-08 06:59:59 UTC (EST) and 07:00:00 UTC (EDT).
      1772953199, 1772953200,
      // 2026-11-01 05:59:59 UTC (EDT) and 06:00:00 UTC (EST).
      1793512799, 1793512800,
    ];
    for ($ts = $from; $ts < $to; $ts += 86400 * 11 + 3607) {
      $probes[] = $ts;
    }
    foreach ($probes as $ts) {
      $expected = $zone->getOffset(new \DateTimeImmutable('@' . $ts));
      $this->assertSame($expected, $this->evaluate($case, $ts), "Offset at $ts");
    }
    $this->assertSame(-18000, $this->evaluate($case, 1772953199));
    $this->assertSame(-14400, $this->evaluate($case, 1772953200));
  }

  /**
   * A zone without DST compiles to a constant.
   */
  public function testZoneWithoutTransitions(): void {
    $this->assertSame('0', LocalTimeSql::offsetExpression('UTC', 'c', 1704067200, 1988150400));
    $this->assertSame('-10800', LocalTimeSql::offsetExpression('America/Sao_Paulo', 'c', 1704067200, 1988150400));
  }

  /**
   * The local datetime expression wraps the offset.
   */
  public function testLocalDatetime(): void {
    $sql = LocalTimeSql::localDatetime('UTC', 'n.created', 0, 10);
    $this->assertSame("CAST('1970-01-01 00:00:00' AS DATETIME) + INTERVAL (n.created + 0) SECOND", $sql);
  }

  /**
   * The session offset string follows DST.
   */
  public function testCurrentOffset(): void {
    $this->assertSame('-04:00', LocalTimeSql::currentOffset('America/New_York', 1790000000));
    $this->assertSame('-05:00', LocalTimeSql::currentOffset('America/New_York', 1795000000));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Unit;

use Drupal\maemgaba_core\Calibration\CalibrationScorer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Calibration metrics on a hand-computed five-bucket example.
 */
#[Group('maemgaba_core')]
class CalibrationScorerTest extends UnitTestCase {

  /**
   * Bucket keys, index 0 = left.
   */
  protected const KEYS = ['left', 'lean_left', 'center', 'lean_right', 'right'];

  /**
   * A row with defaults.
   */
  protected function row(string $outlet, ?int $expected, ?int $a, ?int $b, ?int $hand = NULL, bool $partial = FALSE, ?float $conf = 0.8): array {
    return [
      'outlet' => $outlet,
      'expected' => $expected,
      'hand' => $hand,
      'partial' => $partial,
      'a' => $a,
      'b' => $b,
      'conf_a' => $a === NULL ? NULL : $conf,
      'conf_b' => $b === NULL ? NULL : $conf,
    ];
  }

  /**
   * Agreement, skew, instability, split, confidence and failures.
   */
  public function testScore(): void {
    $rows = [
      // Right outlet read right, then lean right: exact, unstable.
      $this->row('Fox', 4, 4, 3, 4),
      // Right outlet read center: off by two, left skew.
      $this->row('Fox', 4, 2, 2, 3),
      // Lean-left outlet read center (the expected left-of-median skew is
      // the opposite; this is a rightward miss): off by one.
      $this->row('NYT', 1, 2, 2, NULL, TRUE, 0.4),
      // Center outlet, exact.
      $this->row('The Hill', 2, 2, 2),
      // Failed call.
      $this->row('NPR', 1, NULL, NULL),
    ];
    $m = CalibrationScorer::score($rows, self::KEYS);

    $this->assertSame(5, $m['items']);
    $this->assertSame(4, $m['scored']);
    $this->assertSame(1, $m['failed']);

    $this->assertSame(4, $m['prior']['n']);
    $this->assertSame(0.5, $m['prior']['exact']);
    $this->assertSame(0.75, $m['prior']['within_one']);
    // Deltas: 0, −2, +1, 0 → −0.25.
    $this->assertSame(-0.25, $m['prior']['mean_delta']);
    $this->assertSame(2, $m['prior']['per_bucket']['right']['n']);
    $this->assertSame(0.5, $m['prior']['per_bucket']['right']['exact']);
    $this->assertSame(3.0, $m['prior']['per_bucket']['right']['mean_predicted']);
    $this->assertSame(0, $m['prior']['per_bucket']['left']['n']);

    // Hand labels on two rows: 4→4 exact, 3→2 off by one.
    $this->assertSame(2, $m['hand']['n']);
    $this->assertSame(0.5, $m['hand']['exact']);
    $this->assertSame(1.0, $m['hand']['within_one']);
    // Prior vs hand: 4 vs 4, 4 vs 3.
    $this->assertSame(0.5, $m['prior_vs_hand']['exact']);

    $this->assertSame(4, $m['instability']['n']);
    $this->assertSame(0.25, $m['instability']['changed']);
    $this->assertSame(0.25, $m['instability']['mean_abs_delta']);

    $this->assertSame(0.7, $m['confidence']['all']);
    $this->assertSame(0.4, $m['confidence']['partial']);
    $this->assertSame(1, $m['split']['partial']['n']);
    $this->assertSame(3, $m['split']['full']['n']);

    $this->assertSame(1, $m['confusion']['right']['right']);
    $this->assertSame(1, $m['confusion']['right']['center']);
    $this->assertSame(1, $m['confusion']['lean_left']['center']);

    $this->assertSame(-1.0, $m['outlets']['Fox']['mean_delta']);
    $this->assertSame(4, $m['outlets']['Fox']['prior']);
  }

}

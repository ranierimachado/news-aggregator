<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\maemgaba_core\BiasScore;
use Drupal\maemgaba_core\Service\SpectrumService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Score → bucket mapping for 3-bucket and 5-bucket configs.
 *
 * Kernel (not unit) so the config is saved through strict schema checking:
 * a spectrum config that doesn't match maemgaba_core.schema.yml fails here.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class SpectrumServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'ai', 'maemgaba_core'];

  /**
   * A five-bucket US-style scale (P7).
   */
  protected const FIVE_BUCKETS = [
    [
      'key' => 'left',
      'label' => 'Left',
      'short_label' => 'L',
      'min_score' => -2,
      'max_score' => -2,
      'color' => '#1d4ed8',
    ],
    [
      'key' => 'lean_left',
      'label' => 'Lean Left',
      'short_label' => 'LL',
      'min_score' => -1,
      'max_score' => -1,
      'color' => '#7c3aed',
    ],
    [
      'key' => 'center',
      'label' => 'Center',
      'short_label' => 'C',
      'min_score' => 0,
      'max_score' => 0,
      'color' => '#6b7280',
    ],
    [
      'key' => 'lean_right',
      'label' => 'Lean Right',
      'short_label' => 'LR',
      'min_score' => 1,
      'max_score' => 1,
      'color' => '#ea580c',
    ],
    [
      'key' => 'right',
      'label' => 'Right',
      'short_label' => 'R',
      'min_score' => 2,
      'max_score' => 2,
      'color' => '#dc2626',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $dir = $this->container->get('extension.list.module')->getPath('maemgaba_core') . '/config/install';
    $this->config('maemgaba_core.spectrum')->setData(Yaml::parseFile("$dir/maemgaba_core.spectrum.yml"))->save();
    $this->config('maemgaba_core.settings')->setData(Yaml::parseFile("$dir/maemgaba_core.settings.yml"))->save();
  }

  /**
   * A fresh service (buckets are memoized per instance).
   */
  protected function spectrum(): SpectrumService {
    return new SpectrumService($this->container->get('config.factory'));
  }

  /**
   * The shipped 3-bucket default: −2/−1 left, 0 center, +1/+2 right.
   */
  public function testThreeBuckets(): void {
    $spectrum = $this->spectrum();
    $this->assertSame([], $spectrum->validate());
    $this->assertSame(['left', 'center', 'right'], $spectrum->keys());

    $expected = [-2 => 'left', -1 => 'left', 0 => 'center', 1 => 'right', 2 => 'right'];
    foreach ($expected as $score => $key) {
      $this->assertSame($key, $spectrum->keyFor($score), "score $score");
      // The 3-bucket display agrees with the legacy list mapping.
      $this->assertSame(BiasScore::toLegacy($score), $spectrum->keyFor($score));
    }
    $this->assertNull($spectrum->keyFor(NULL));
    $this->assertNull($spectrum->keyFor(3));

    $dist = $spectrum->distribution([-2, -1, 0, 0, 1, NULL, 7]);
    $this->assertSame(['left' => 2, 'center' => 2, 'right' => 1], $dist);
    $this->assertSame(['left' => 40, 'center' => 40, 'right' => 20], $spectrum->percentages($dist));
    $this->assertSame(['left' => 0, 'center' => 0, 'right' => 0], $spectrum->percentages($spectrum->emptyDistribution()));
  }

  /**
   * For N = 3, balanceScore() equals the formula the theme hardcoded before.
   */
  public function testBalanceMatchesLegacyFormula(): void {
    $spectrum = $this->spectrum();
    for ($l = 0; $l <= 6; $l++) {
      for ($c = 0; $c <= 6; $c++) {
        for ($r = 0; $r <= 6; $r++) {
          $total = $l + $c + $r;
          $dist = ['left' => $l, 'center' => $c, 'right' => $r];
          if ($total === 0) {
            $this->assertNull($spectrum->balanceScore($dist));
            continue;
          }
          $pl = round($l / $total * 100);
          $pc = round($c / $total * 100);
          $pr = 100 - $pl - $pc;
          $ideal = 100 / 3;
          $legacy = (int) round(100 - (abs($pl - $ideal) + abs($pc - $ideal) + abs($pr - $ideal)) / 2);
          $this->assertSame($legacy, $spectrum->balanceScore($dist), "dist $l/$c/$r");
        }
      }
    }
    $this->assertSame('BALANCED', $spectrum->balanceLabel(70));
    $this->assertSame('MODERATE', $spectrum->balanceLabel(69));
    $this->assertSame('N/A', $spectrum->balanceLabel(NULL));
  }

  /**
   * Divergence: declared line wins, else dominant bucket; configured levels.
   */
  public function testDivergence(): void {
    $spectrum = $this->spectrum();
    $this->assertNull($spectrum->divergence(['left' => 0, 'center' => 0, 'right' => 0], 0));

    // Declared center, 3 of 10 off-line → 30% high.
    $d = $spectrum->divergence(['left' => 1, 'center' => 7, 'right' => 2], 0);
    $this->assertSame(['primary' => 'center', 'pct' => 30, 'level' => 'high', 'level_label' => 'HIGH'], $d);

    // Declared +2 falls in "right".
    $this->assertSame('right', $spectrum->divergence(['left' => 0, 'center' => 1, 'right' => 5], 2)['primary']);

    // No declared score: dominant bucket, ties go to the first in order.
    $d = $spectrum->divergence(['left' => 4, 'center' => 4, 'right' => 2], NULL);
    $this->assertSame('left', $d['primary']);
    $this->assertSame(60, $d['pct']);

    $this->assertSame('moderate', $spectrum->divergence(['left' => 0, 'center' => 17, 'right' => 3], 0)['level']);
    $this->assertSame('low', $spectrum->divergence(['left' => 1, 'center' => 9, 'right' => 0], 0)['level']);
  }

  /**
   * A 5-bucket config maps every score to its own bucket and renders N-wide.
   */
  public function testFiveBuckets(): void {
    $this->config('maemgaba_core.spectrum')->set('buckets', self::FIVE_BUCKETS)->save();
    $spectrum = $this->spectrum();
    $this->assertSame([], $spectrum->validate());
    $this->assertSame(['left', 'lean_left', 'center', 'lean_right', 'right'], $spectrum->keys());

    $expected = [-2 => 'left', -1 => 'lean_left', 0 => 'center', 1 => 'lean_right', 2 => 'right'];
    foreach ($expected as $score => $key) {
      $this->assertSame($key, $spectrum->keyFor($score), "score $score");
    }
    $this->assertSame('Lean Right', $spectrum->bucketFor(1)['label']);

    $even = $spectrum->distribution([-2, -1, 0, 1, 2]);
    $this->assertSame(array_fill_keys($spectrum->keys(), 1), $even);
    $this->assertSame(100, $spectrum->balanceScore($even));
    $this->assertSame(100, array_sum($spectrum->percentages($even)));

    // All coverage in one of five buckets: deviation (80 + 4×20) / 2 = 80.
    $this->assertSame(20, $spectrum->balanceScore([
      'left' => 0,
      'lean_left' => 0,
      'center' => 3,
      'lean_right' => 0,
      'right' => 0,
    ]));
  }

  /**
   * The validate() method reports gaps, overlaps and bad ranges.
   */
  public function testValidateCatchesBadConfig(): void {
    $gap = self::FIVE_BUCKETS;
    unset($gap[2]);
    $this->config('maemgaba_core.spectrum')->set('buckets', array_values($gap))->save();
    $this->assertContains('Score 0 is not covered by any bucket.', $this->spectrum()->validate());

    $overlap = self::FIVE_BUCKETS;
    $overlap[1]['max_score'] = 0;
    $this->config('maemgaba_core.spectrum')->set('buckets', $overlap)->save();
    $this->assertContains('Bucket center overlaps or is out of order.', $this->spectrum()->validate());
  }

}

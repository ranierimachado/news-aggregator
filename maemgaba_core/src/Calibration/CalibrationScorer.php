<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Calibration;

/**
 * Scores a calibration run: agreement, skew, instability, confidence.
 *
 * Pure: works on bucket indexes (0 = leftmost display bucket), so it serves
 * a five-bucket site (index = score + 2) and a three-bucket one alike.
 * "Delta" is predicted − reference in buckets: negative = the model reads
 * the article further LEFT than the reference.
 *
 * Each row: ['outlet' => string, 'expected' => ?int (outlet prior bucket),
 * 'hand' => ?int (hand-label bucket), 'partial' => bool, 'a' => ?int (run A
 * bucket), 'b' => ?int (run B bucket), 'conf_a' => ?float, 'conf_b' =>
 * ?float]. A NULL run means the call failed.
 */
final class CalibrationScorer {

  /**
   * Computes every metric the calibration report needs.
   *
   * @param array $rows
   *   Rows as described in the class docblock.
   * @param string[] $bucketKeys
   *   Bucket keys in index order (left to right).
   */
  public static function score(array $rows, array $bucketKeys): array {
    $scored = array_values(array_filter($rows, fn ($r) => $r['a'] !== NULL));

    $metrics = [
      'items' => count($rows),
      'scored' => count($scored),
      'failed' => count($rows) - count($scored),
      'prior' => self::agreement($scored, 'expected', $bucketKeys),
      'hand' => self::agreement($scored, 'hand', $bucketKeys),
      'prior_vs_hand' => self::priorVsHand($rows, $bucketKeys),
      'instability' => self::instability($scored, $bucketKeys),
      'confidence' => [
        'all' => self::mean(array_column($scored, 'conf_a')),
        'full' => self::mean(array_column(array_filter($scored, fn ($r) => !$r['partial']), 'conf_a')),
        'partial' => self::mean(array_column(array_filter($scored, fn ($r) => $r['partial']), 'conf_a')),
      ],
      'split' => [
        'full' => self::agreement(array_values(array_filter($scored, fn ($r) => !$r['partial'])), 'expected', $bucketKeys, FALSE),
        'partial' => self::agreement(array_values(array_filter($scored, fn ($r) => $r['partial'])), 'expected', $bucketKeys, FALSE),
      ],
      'outlets' => self::outlets($scored),
      'confusion' => self::confusion($scored, 'expected', $bucketKeys),
    ];
    return $metrics;
  }

  /**
   * Agreement of run A with a reference column ('expected' or 'hand').
   *
   * @return array
   *   ['n', 'exact', 'within_one', 'mean_delta', 'per_bucket' => [key =>
   *   ['n', 'exact', 'within_one', 'mean_delta', 'mean_predicted']]].
   */
  public static function agreement(array $rows, string $reference, array $bucketKeys, bool $perBucket = TRUE): array {
    $rows = array_values(array_filter($rows, fn ($r) => $r[$reference] !== NULL && $r['a'] !== NULL));
    $out = self::pairStats(array_map(fn ($r) => [$r[$reference], $r['a']], $rows));
    if ($perBucket) {
      $out['per_bucket'] = [];
      foreach ($bucketKeys as $index => $key) {
        $in = array_values(array_filter($rows, fn ($r) => $r[$reference] === $index));
        $stats = self::pairStats(array_map(fn ($r) => [$r[$reference], $r['a']], $in));
        $stats['mean_predicted'] = self::mean(array_column($in, 'a'));
        $out['per_bucket'][$key] = $stats;
      }
    }
    return $out;
  }

  /**
   * How often the outlet prior matches the hand label (quality of the proxy).
   */
  public static function priorVsHand(array $rows, array $bucketKeys): array {
    $pairs = [];
    foreach ($rows as $r) {
      if ($r['hand'] !== NULL && $r['expected'] !== NULL) {
        $pairs[] = [$r['hand'], $r['expected']];
      }
    }
    return self::pairStats($pairs);
  }

  /**
   * Run-to-run instability between run A and run B.
   *
   * @return array
   *   ['n', 'changed' (share of items whose bucket differs), 'mean_abs_delta',
   *   'per_bucket' => [key => ['n', 'changed']]] — per bucket of run A.
   */
  public static function instability(array $rows, array $bucketKeys): array {
    $rows = array_values(array_filter($rows, fn ($r) => $r['a'] !== NULL && $r['b'] !== NULL));
    $n = count($rows);
    $changed = count(array_filter($rows, fn ($r) => $r['a'] !== $r['b']));
    $out = [
      'n' => $n,
      'changed' => $n ? round($changed / $n, 3) : NULL,
      'mean_abs_delta' => self::mean(array_map(fn ($r) => abs($r['a'] - $r['b']), $rows)),
      'per_bucket' => [],
    ];
    foreach ($bucketKeys as $index => $key) {
      $in = array_values(array_filter($rows, fn ($r) => $r['a'] === $index));
      $c = count(array_filter($in, fn ($r) => $r['a'] !== $r['b']));
      $out['per_bucket'][$key] = ['n' => count($in), 'changed' => $in ? round($c / count($in), 3) : NULL];
    }
    return $out;
  }

  /**
   * Per-outlet mean predicted bucket vs its prior.
   *
   * @return array
   *   outlet => ['n', 'prior', 'mean_predicted', 'mean_delta'].
   */
  public static function outlets(array $rows): array {
    $by = [];
    foreach ($rows as $r) {
      $by[$r['outlet']][] = $r;
    }
    ksort($by);
    $out = [];
    foreach ($by as $outlet => $in) {
      $withPrior = array_values(array_filter($in, fn ($r) => $r['expected'] !== NULL));
      $out[$outlet] = [
        'n' => count($in),
        'prior' => $withPrior ? $withPrior[0]['expected'] : NULL,
        'mean_predicted' => self::mean(array_column($in, 'a')),
        'mean_delta' => self::mean(array_map(fn ($r) => $r['a'] - $r['expected'], $withPrior)),
      ];
    }
    return $out;
  }

  /**
   * Confusion matrix: reference bucket key => predicted bucket key => count.
   */
  public static function confusion(array $rows, string $reference, array $bucketKeys): array {
    $matrix = [];
    foreach ($bucketKeys as $ref) {
      $matrix[$ref] = array_fill_keys($bucketKeys, 0);
    }
    foreach ($rows as $r) {
      if ($r[$reference] !== NULL && $r['a'] !== NULL && isset($bucketKeys[$r[$reference]], $bucketKeys[$r['a']])) {
        $matrix[$bucketKeys[$r[$reference]]][$bucketKeys[$r['a']]]++;
      }
    }
    return $matrix;
  }

  /**
   * Exact / within-one / mean signed delta over [reference, predicted] pairs.
   */
  protected static function pairStats(array $pairs): array {
    $n = count($pairs);
    if (!$n) {
      return ['n' => 0, 'exact' => NULL, 'within_one' => NULL, 'mean_delta' => NULL];
    }
    $exact = count(array_filter($pairs, fn ($p) => $p[0] === $p[1]));
    $within = count(array_filter($pairs, fn ($p) => abs($p[0] - $p[1]) <= 1));
    return [
      'n' => $n,
      'exact' => round($exact / $n, 3),
      'within_one' => round($within / $n, 3),
      'mean_delta' => self::mean(array_map(fn ($p) => $p[1] - $p[0], $pairs)),
    ];
  }

  /**
   * Mean of the non-NULL values, rounded to 3 places, or NULL if none.
   */
  protected static function mean(array $values): ?float {
    $values = array_filter($values, fn ($v) => $v !== NULL);
    return $values ? round(array_sum($values) / count($values), 3) : NULL;
  }

}

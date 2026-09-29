<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\maemgaba_core\BiasScore;

/**
 * Maps numeric bias scores (−2..+2) onto a site's display buckets.
 *
 * The single place that knows how a site *shows* the spectrum. Buckets come
 * from maemgaba_core.spectrum (ordered, each {key, label, short_label,
 * min_score, max_score, color}): a pack ships 3, another 5. Balance and
 * editorial-divergence labels/thresholds come from maemgaba_core.settings.
 * Everything that used to hardcode left/center/right (consensus prompt lines,
 * the sources page, the MCP tools, the event/teaser templates) goes through
 * here, so any number of buckets renders.
 */
class SpectrumService {

  use StringTranslationTrait;

  /**
   * Buckets as loaded from config, memoized per request.
   *
   * @var array<int, array>|null
   */
  protected ?array $buckets = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The ordered display buckets.
   *
   * @return array<int, array{key: string, label: string, short_label: string, min_score: int, max_score: int, color: string}>
   *   One row per bucket, in display order.
   */
  public function buckets(): array {
    if ($this->buckets === NULL) {
      $this->buckets = [];
      foreach ((array) $this->configFactory->get('maemgaba_core.spectrum')->get('buckets') as $bucket) {
        $this->buckets[] = [
          'key' => (string) $bucket['key'],
          'label' => (string) ($bucket['label'] ?? $bucket['key']),
          'short_label' => (string) (($bucket['short_label'] ?? '') ?: ($bucket['label'] ?? $bucket['key'])),
          'min_score' => (int) $bucket['min_score'],
          'max_score' => (int) $bucket['max_score'],
          'color' => (string) ($bucket['color'] ?? ''),
        ];
      }
    }
    return $this->buckets;
  }

  /**
   * Bucket keys in display order.
   *
   * @return string[]
   *   The bucket keys.
   */
  public function keys(): array {
    return array_column($this->buckets(), 'key');
  }

  /**
   * The bucket a score falls in, or NULL (no score / not covered by config).
   */
  public function bucketFor(?int $score): ?array {
    if ($score === NULL) {
      return NULL;
    }
    foreach ($this->buckets() as $bucket) {
      if ($score >= $bucket['min_score'] && $score <= $bucket['max_score']) {
        return $bucket;
      }
    }
    return NULL;
  }

  /**
   * Shortcut: the bucket key for a score, or NULL.
   */
  public function keyFor(?int $score): ?string {
    return $this->bucketFor($score)['key'] ?? NULL;
  }

  /**
   * A bucket by key, or NULL.
   */
  public function bucket(string $key): ?array {
    foreach ($this->buckets() as $bucket) {
      if ($bucket['key'] === $key) {
        return $bucket;
      }
    }
    return NULL;
  }

  /**
   * Zero counts for every bucket, in display order.
   *
   * @return array<string, int>
   *   Zeros keyed by bucket key.
   */
  public function emptyDistribution(): array {
    return array_fill_keys($this->keys(), 0);
  }

  /**
   * Counts scores per bucket; scores outside every bucket are ignored.
   *
   * @param iterable<int|null> $scores
   *   The bias scores to count.
   *
   * @return array<string, int>
   *   Counts keyed by bucket key.
   */
  public function distribution(iterable $scores): array {
    $dist = $this->emptyDistribution();
    foreach ($scores as $score) {
      $key = $this->keyFor($score);
      if ($key !== NULL) {
        $dist[$key]++;
      }
    }
    return $dist;
  }

  /**
   * Whole-number percentages per bucket.
   *
   * @param array<string, int> $dist
   *   Counts keyed by bucket.
   * @param bool $absorbRemainder
   *   TRUE: the last bucket takes 100 − sum(others), so bars always fill
   *   exactly (event teasers). FALSE: each bucket rounded independently
   *   (sources table).
   *
   * @return array<string, int>
   *   Percentages keyed by bucket key.
   */
  public function percentages(array $dist, bool $absorbRemainder = TRUE): array {
    $total = array_sum($dist);
    $perc = array_fill_keys(array_keys($dist), 0);
    if ($total === 0) {
      return $perc;
    }
    $keys = array_keys($dist);
    $last = end($keys);
    $sum = 0;
    foreach ($dist as $key => $count) {
      if ($absorbRemainder && $key === $last) {
        $perc[$key] = 100 - $sum;
        break;
      }
      $perc[$key] = (int) round($count / $total * 100);
      $sum += $perc[$key];
    }
    return $perc;
  }

  /**
   * Balance score (0–100): 100 = coverage spread evenly over every bucket.
   *
   * Deviation from an even split (100/N per bucket), halved so that all
   * coverage in one bucket of three scores ~33. For N = 3 this is exactly
   * the formula the theme used before buckets were configurable.
   *
   * @return int|null
   *   NULL when there is no coverage.
   */
  public function balanceScore(array $dist): ?int {
    if (array_sum($dist) === 0 || !$dist) {
      return NULL;
    }
    $perc = $this->percentages($dist);
    $ideal = 100 / count($perc);
    $dev = 0.0;
    foreach ($perc as $value) {
      $dev += abs($value - $ideal);
    }
    return (int) round(100 - $dev / 2);
  }

  /**
   * The configured label for a balance score (maemgaba_core.settings).
   */
  public function balanceLabel(?int $score): string {
    if ($score === NULL) {
      return (string) $this->t('N/A');
    }
    $levels = (array) $this->configFactory->get('maemgaba_core.settings')->get('balance_levels');
    usort($levels, fn ($a, $b) => (int) $b['min'] <=> (int) $a['min']);
    foreach ($levels as $level) {
      if ($score >= (int) $level['min']) {
        return (string) $level['label'];
      }
    }
    return '';
  }

  /**
   * Editorial divergence of an outlet: share of its coverage off its line.
   *
   * @param array<string, int> $dist
   *   Card counts per bucket for the outlet.
   * @param int|null $declaredScore
   *   The outlet's declared default score, if any. Its bucket is the
   *   editorial line; without one, the dominant bucket is (ties: the first
   *   in display order).
   *
   * @return array{primary: string, pct: int, level: string, level_label: string}|null
   *   NULL when the outlet has no coverage.
   */
  public function divergence(array $dist, ?int $declaredScore): ?array {
    $total = array_sum($dist);
    if ($total === 0) {
      return NULL;
    }
    $primary = $this->keyFor($declaredScore);
    if ($primary === NULL || !isset($dist[$primary])) {
      $max = max($dist);
      $primary = (string) array_search($max, $dist, TRUE);
    }
    $pct = (int) round(($total - $dist[$primary]) / $total * 100);

    $levels = (array) $this->configFactory->get('maemgaba_core.settings')->get('divergence_levels');
    usort($levels, fn ($a, $b) => (int) $b['min'] <=> (int) $a['min']);
    $level = ['key' => '', 'label' => ''];
    foreach ($levels as $candidate) {
      if ($pct >= (int) $candidate['min']) {
        $level = $candidate;
        break;
      }
    }
    return [
      'primary' => $primary,
      'pct' => $pct,
      'level' => (string) $level['key'],
      'level_label' => (string) $level['label'],
    ];
  }

  /**
   * Checks that the buckets cover −2..+2 exactly once, in order.
   *
   * @return string[]
   *   Problems found; empty when the config is valid.
   */
  public function validate(): array {
    $errors = [];
    $buckets = $this->buckets();
    if (!$buckets) {
      return ['maemgaba_core.spectrum has no buckets.'];
    }
    $seen = [];
    $previousMax = NULL;
    foreach ($buckets as $bucket) {
      if ($bucket['min_score'] > $bucket['max_score']) {
        $errors[] = "Bucket {$bucket['key']}: min_score > max_score.";
      }
      if ($previousMax !== NULL && $bucket['min_score'] <= $previousMax) {
        $errors[] = "Bucket {$bucket['key']} overlaps or is out of order.";
      }
      $previousMax = $bucket['max_score'];
      if (isset($seen[$bucket['key']])) {
        $errors[] = "Duplicate bucket key {$bucket['key']}.";
      }
      $seen[$bucket['key']] = TRUE;
    }
    for ($score = BiasScore::MIN; $score <= BiasScore::MAX; $score++) {
      if ($this->bucketFor($score) === NULL) {
        $errors[] = "Score {$score} is not covered by any bucket.";
      }
    }
    return $errors;
  }

}

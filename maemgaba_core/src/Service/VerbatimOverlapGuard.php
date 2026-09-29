<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\maemgaba_core\TextStats;

/**
 * Rejects AI output that copies a run of words from a source article.
 *
 * Copyright policy: synthesis must analyse coverage,
 * not reproduce it. Any run of N consecutive words (default 8) that appears
 * in both the model's output and a source text counts as copying. Words are
 * compared case-insensitively with punctuation ignored (TextStats::words()),
 * so re-punctuating a sentence doesn't get it through.
 *
 * Configured by maemgaba_core.settings:overlap_guard (enabled, ngram). Off
 * when the key is absent, which keeps sites that never set it unchanged.
 */
class VerbatimOverlapGuard {

  /**
   * Run length used when overlap_guard.ngram is unset or invalid.
   */
  public const DEFAULT_NGRAM = 8;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether the site has the guard switched on.
   */
  public function enabled(): bool {
    return (bool) $this->configFactory->get('maemgaba_core.settings')->get('overlap_guard.enabled');
  }

  /**
   * The configured run length (words).
   */
  public function ngram(): int {
    $n = (int) $this->configFactory->get('maemgaba_core.settings')->get('overlap_guard.ngram');
    return $n >= 2 ? $n : self::DEFAULT_NGRAM;
  }

  /**
   * First copied run found in any of the candidate texts, or NULL.
   *
   * @param string[] $candidates
   *   Model output strings to check.
   * @param string[] $sources
   *   Source texts (HTML allowed).
   * @param int|null $n
   *   Run length; NULL = the configured one.
   *
   * @return string|null
   *   The offending run (normalised words joined by spaces), or NULL.
   */
  public function check(array $candidates, array $sources, ?int $n = NULL): ?string {
    return self::findRun($candidates, $sources, $n ?? $this->ngram());
  }

  /**
   * Pure implementation of check(), for unit tests and callers without DI.
   *
   * @param string[] $candidates
   *   Model output strings to check.
   * @param string[] $sources
   *   Source texts (HTML allowed).
   * @param int $n
   *   Run length in words (>= 2).
   *
   * @return string|null
   *   The first offending run, or NULL when nothing is copied.
   */
  public static function findRun(array $candidates, array $sources, int $n): ?string {
    $n = max(2, $n);
    $shingles = [];
    foreach ($sources as $source) {
      $words = TextStats::words((string) $source);
      for ($i = 0, $last = count($words) - $n; $i <= $last; $i++) {
        $shingles[implode(' ', array_slice($words, $i, $n))] = TRUE;
      }
    }
    if (!$shingles) {
      return NULL;
    }
    foreach ($candidates as $candidate) {
      $words = TextStats::words((string) $candidate);
      for ($i = 0, $last = count($words) - $n; $i <= $last; $i++) {
        $run = implode(' ', array_slice($words, $i, $n));
        if (isset($shingles[$run])) {
          return $run;
        }
      }
    }
    return NULL;
  }

}

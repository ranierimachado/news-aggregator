<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Syndication;

use Drupal\maemgaba_core\TextStats;

/**
 * MinHash fingerprints of word shingles, for near-duplicate detection.
 *
 * A text becomes the set of its K-word shingles (words as TextStats::words()
 * sees them: lower-cased, punctuation ignored). The signature keeps, for each
 * of HASHES hash functions, the smallest hash over that set; the share of
 * positions two signatures agree on estimates the Jaccard similarity of the
 * two shingle sets (standard error sqrt(J(1−J)/HASHES), ≈ 0.035 at
 * J = 0.8). Knowing each set's size also gives the
 * containment of the smaller text in the larger one, which catches the
 * common trimmed reprint (the wire story with its last paragraphs cut).
 *
 * Pure functions: no services, so unit tests and the detector share them.
 */
final class MinHash {

  /**
   * Words per shingle.
   */
  public const K = 5;

  /**
   * Hash functions per signature.
   */
  public const HASHES = 128;

  /**
   * Mersenne prime 2^31 − 1, the hash modulus.
   *
   * (a·x + b) mod P stays inside a signed 64-bit integer for a, b < P and
   * 32-bit x.
   */
  protected const P = 2147483647;

  /**
   * Per-function coefficients [a, b], derived once from fixed seeds.
   *
   * @var array<int, array{0: int, 1: int}>|null
   */
  protected static ?array $coefficients = NULL;

  /**
   * Fingerprints a text.
   *
   * @return array{signature: int[], shingles: int, words: int}
   *   The signature (HASHES ints, empty when the text has fewer than K
   *   words), the number of distinct shingles and the word count.
   */
  public static function fingerprint(string $text): array {
    $words = TextStats::words($text);
    $count = count($words);
    if ($count < self::K) {
      return ['signature' => [], 'shingles' => 0, 'words' => $count];
    }
    $bases = [];
    for ($i = 0, $last = $count - self::K; $i <= $last; $i++) {
      $shingle = implode(' ', array_slice($words, $i, self::K));
      $bases[(int) hexdec(hash('xxh32', $shingle))] = TRUE;
    }
    $signature = array_fill(0, self::HASHES, self::P);
    foreach (self::coefficients() as $h => [$a, $b]) {
      $min = self::P;
      foreach ($bases as $x => $unused) {
        $v = ($a * $x + $b) % self::P;
        if ($v < $min) {
          $min = $v;
        }
      }
      $signature[$h] = $min;
    }
    return ['signature' => $signature, 'shingles' => count($bases), 'words' => $count];
  }

  /**
   * Estimated Jaccard similarity of two signatures (0..1).
   */
  public static function jaccard(array $a, array $b): float {
    $n = min(count($a), count($b));
    if ($n === 0) {
      return 0.0;
    }
    $same = 0;
    for ($i = 0; $i < $n; $i++) {
      if ($a[$i] === $b[$i]) {
        $same++;
      }
    }
    return $same / $n;
  }

  /**
   * Estimated share of the smaller shingle set found in the larger (0..1).
   *
   * |A∩B| = J·(|A|+|B|)/(1+J), divided by min(|A|, |B|).
   */
  public static function containment(float $jaccard, int $sizeA, int $sizeB): float {
    $smaller = min($sizeA, $sizeB);
    if ($smaller <= 0) {
      return 0.0;
    }
    $intersection = $jaccard * ($sizeA + $sizeB) / (1 + $jaccard);
    return min(1.0, $intersection / $smaller);
  }

  /**
   * Packs a signature for storage (4 bytes per hash).
   */
  public static function pack(array $signature): string {
    return $signature ? pack('N*', ...$signature) : '';
  }

  /**
   * Unpacks a stored signature.
   *
   * @return int[]
   *   The signature, zero-indexed.
   */
  public static function unpack(string $packed): array {
    if ($packed === '') {
      return [];
    }
    return array_values(unpack('N*', $packed) ?: []);
  }

  /**
   * The [a, b] pair of every hash function.
   *
   * @return array<int, array{0: int, 1: int}>
   *   HASHES pairs with 1 <= a < P and 0 <= b < P.
   */
  protected static function coefficients(): array {
    if (self::$coefficients === NULL) {
      self::$coefficients = [];
      for ($i = 0; $i < self::HASHES; $i++) {
        $a = (int) hexdec(hash('xxh32', "maemgaba-minhash-a-$i")) % (self::P - 1) + 1;
        $b = (int) hexdec(hash('xxh32', "maemgaba-minhash-b-$i")) % self::P;
        self::$coefficients[$i] = [$a, $b];
      }
    }
    return self::$coefficients;
  }

}

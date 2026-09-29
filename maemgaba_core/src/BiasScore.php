<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core;

/**
 * The shared numeric bias scale (−2..+2) and its legacy 3-value mapping.
 *
 * The field_bias_score (card) and field_default_bias_score (feed_source,
 * sources term) fields are canonical. How a score is *displayed* is per-site
 * config (see SpectrumService); this class only knows the scale itself and
 * the legacy left/center/right list fields, which are kept one release as
 * values derived from the score (maemgaba_core_entity_presave()).
 *
 * Pure functions, no services: used by the pipeline, the presave hook, the
 * backfill deploy hook and tests alike.
 */
final class BiasScore {

  public const MIN = -2;

  public const MAX = 2;

  /**
   * Evidence quotes kept per classification.
   *
   * The schema can't cap arrays: Anthropic structured output rejects
   * maxItems.
   */
  public const MAX_EVIDENCE = 3;

  /**
   * Max characters kept per evidence quote.
   */
  public const MAX_EVIDENCE_LENGTH = 500;

  /**
   * Legacy list value => score used when backfilling from the old field.
   *
   * Deliberately never ±2: no existing card was ever assigned an extreme.
   */
  public const FROM_LEGACY = [
    'left' => -1,
    'center' => 0,
    'right' => 1,
  ];

  /**
   * Returns an integer score in range, or NULL for anything else.
   */
  public static function normalize(mixed $value): ?int {
    if (is_int($value)) {
      $score = $value;
    }
    elseif (is_float($value) && floor($value) === $value) {
      $score = (int) $value;
    }
    elseif (is_string($value) && preg_match('/^[+-]?\d+$/', trim($value))) {
      $score = (int) trim($value);
    }
    else {
      return NULL;
    }
    return ($score >= self::MIN && $score <= self::MAX) ? $score : NULL;
  }

  /**
   * Reads a model-reported score: integers are clamped into range.
   *
   * The JSON schema can't bound integers portably (Anthropic structured
   * output rejects minimum/maximum, Gemini only allows string enums), so an
   * over-eager "3" becomes +2 here rather than an unscored card.
   */
  public static function fromModel(mixed $value): ?int {
    if (is_string($value) && preg_match('/^[+-]?\d+$/', trim($value))) {
      $value = (int) trim($value);
    }
    if (is_float($value) && floor($value) === $value) {
      $value = (int) $value;
    }
    if (!is_int($value)) {
      return NULL;
    }
    return max(self::MIN, min(self::MAX, $value));
  }

  /**
   * The legacy list value a score maps to (left = −2/−1, center = 0, right = +1/+2).
   */
  public static function toLegacy(int $score): string {
    return $score < 0 ? 'left' : ($score > 0 ? 'right' : 'center');
  }

  /**
   * The score a legacy list value backfills to, or NULL if unknown.
   */
  public static function fromLegacy(?string $value): ?int {
    return self::FROM_LEGACY[(string) $value] ?? NULL;
  }

  /**
   * Clamps a model-reported confidence to 0..1, or NULL if not numeric.
   */
  public static function normalizeConfidence(mixed $value): ?float {
    if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
      return NULL;
    }
    return max(0.0, min(1.0, (float) $value));
  }

  /**
   * Keeps up to MAX_EVIDENCE non-empty, trimmed, length-capped quotes.
   *
   * @return string[]
   *   The normalised quotes.
   */
  public static function normalizeEvidence(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $quotes = [];
    foreach ($value as $quote) {
      if (!is_string($quote)) {
        continue;
      }
      $quote = trim($quote);
      if ($quote === '') {
        continue;
      }
      $quotes[] = mb_substr($quote, 0, self::MAX_EVIDENCE_LENGTH);
      if (count($quotes) >= self::MAX_EVIDENCE) {
        break;
      }
    }
    return $quotes;
  }

}

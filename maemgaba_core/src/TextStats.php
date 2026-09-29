<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core;

/**
 * Word-level text helpers shared by ingest, analysis and synthesis.
 *
 * Pure functions, no services, so the partial-text threshold, the evidence
 * and summary caps and the verbatim-overlap guard all count words the same
 * way.
 */
final class TextStats {

  /**
   * Plain text from HTML: tags stripped, entities decoded, spaces folded.
   */
  public static function plain(string $html): string {
    // Block-level tags become spaces so "</p><p>" doesn't glue two words.
    $text = preg_replace('/<(?:br|\/p|\/div|\/li|\/h\d)[^>]*>/i', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
  }

  /**
   * Lower-cased word tokens (letters and digits only).
   *
   * Apostrophes and hyphens split words ("don't" → "don", "t"), on every
   * side of a comparison alike, so matching stays consistent.
   *
   * @return string[]
   *   The tokens, in order.
   */
  public static function words(string $text): array {
    $text = mb_strtolower(self::plain($text));
    $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return $words ?: [];
  }

  /**
   * Number of words in a text (HTML allowed).
   */
  public static function wordCount(string $text): int {
    return count(self::words($text));
  }

  /**
   * Caps a text at $max words, preferring to end on a full sentence.
   *
   * Whitespace-delimited words are kept verbatim (punctuation included).
   * If a sentence ends inside the cap, the text is cut there; otherwise it
   * is cut at the cap and an ellipsis added.
   */
  public static function capWords(string $text, int $max): string {
    $text = trim($text);
    if ($max <= 0 || $text === '') {
      return $text;
    }
    $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) <= $max) {
      return $text;
    }
    $kept = array_slice($parts, 0, $max);
    for ($i = count($kept) - 1; $i > 0; $i--) {
      if (preg_match('/[.!?]["\')\]]?$/u', $kept[$i])) {
        return implode(' ', array_slice($kept, 0, $i + 1));
      }
    }
    return rtrim(implode(' ', $kept), ' ,;:') . '…';
  }

}

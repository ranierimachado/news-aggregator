<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Translatable "how long ago" labels (replaces hand-built PT strings).
 *
 * Two shapes used by the templates:
 * - long(): "3 hours", "1 day", "2 wk", "1 month" (teasers, next to a
 *   count), one unit, rounded down.
 * - compact(): "5 min ago", "3 h ago", "2 d ago" (card and outlet
 *   timestamps). Callers uppercase it where the design wants caps.
 * English source strings; each site's .po supplies its language.
 */
class RelativeTime {

  use StringTranslationTrait;

  public function __construct(
    protected TimeInterface $time,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Long form, e.g. "3 hours".
   */
  public function long(int $timestamp): string {
    $diff = max(0, $this->time->getRequestTime() - $timestamp);
    if ($diff < 3600) {
      return (string) $this->formatPlural(max(1, intdiv($diff, 60)), '1 min', '@count min', [], ['context' => 'maemgaba_time']);
    }
    if ($diff < 86400) {
      return (string) $this->formatPlural(intdiv($diff, 3600), '1 hour', '@count hours', [], ['context' => 'maemgaba_time']);
    }
    if ($diff < 604800) {
      return (string) $this->formatPlural(intdiv($diff, 86400), '1 day', '@count days', [], ['context' => 'maemgaba_time']);
    }
    if ($diff < 2592000) {
      return (string) $this->formatPlural(intdiv($diff, 604800), '1 wk', '@count wk', [], ['context' => 'maemgaba_time']);
    }
    return (string) $this->formatPlural(max(1, intdiv($diff, 2592000)), '1 month', '@count months', [], ['context' => 'maemgaba_time']);
  }

  /**
   * Compact form, e.g. "5 min ago" / "3 h ago" / "2 d ago".
   */
  public function compact(int $timestamp): string {
    $diff = max(0, $this->time->getRequestTime() - $timestamp);
    return $this->compactSeconds($diff);
  }

  /**
   * Compact form for an elapsed number of seconds.
   */
  public function compactSeconds(int $seconds): string {
    if ($seconds < 3600) {
      return (string) $this->t('@count min ago', ['@count' => max(1, intdiv($seconds, 60))], ['context' => 'maemgaba_time']);
    }
    if ($seconds < 86400) {
      return (string) $this->t('@count h ago', ['@count' => intdiv($seconds, 3600)], ['context' => 'maemgaba_time']);
    }
    return (string) $this->t('@count d ago', ['@count' => intdiv($seconds, 86400)], ['context' => 'maemgaba_time']);
  }

}

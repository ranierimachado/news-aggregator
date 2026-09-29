<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Removes outlet identity from text before blind classification.
 *
 * A prompt entity with `blind: true` must never tell the model which outlet
 * wrote the article, or the model scores the outlet's reputation instead of
 * the article's framing. The pipeline never passes the outlet name or prior
 * as a prompt token, but article bodies routinely name their own outlet
 * ("Fox News Digital has learned", "Copyright 2026 The New York Times",
 * "Sign up for the Mother Jones newsletter"). This service replaces the
 * article's OWN outlet identity with a neutral placeholder.
 *
 * Other outlets named in the text are left alone on purpose: "as the New
 * York Times reported" inside another outlet's article is source selection,
 * one of the framing signals the rubric asks the model to read.
 *
 * Terms for an outlet: its name (with and without a leading "The"), the
 * domains of its feed_source URLs, and its aliases from
 * maemgaba_core.settings:blind_aliases (nicknames and sub-brands the
 * registry doesn't carry, e.g. "NYT", "Fox News Digital").
 */
class OutletRedactor {

  /**
   * What replaces a redacted outlet name.
   */
  public const PLACEHOLDER = '[outlet]';

  /**
   * Feed hosts that belong to a feed service, not to the outlet.
   */
  protected const GENERIC_HOSTS = ['feedburner.com', 'feedblitz.com', 'rss.app'];

  /**
   * Terms per outlet name (lower-cased key), computed once per request.
   *
   * @var array<string, string[]>
   */
  protected array $termsByOutlet = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Redacts $outlet's identity from $text.
   */
  public function redact(string $text, string $outlet): string {
    $outlet = trim($outlet);
    if ($outlet === '') {
      return $text;
    }
    return self::redactTerms($text, $this->termsFor($outlet));
  }

  /**
   * Pure implementation: replaces each term, longest first, case-insensitive.
   *
   * Terms shorter than three characters are ignored (too many false hits).
   * Matches must stand alone: "Fox" does not match inside "Foxconn".
   *
   * @param string $text
   *   The text to redact.
   * @param string[] $terms
   *   Outlet names, nicknames and domains.
   */
  public static function redactTerms(string $text, array $terms): string {
    $terms = array_unique(array_filter(array_map('trim', $terms), fn ($t) => mb_strlen($t) >= 3));
    usort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach ($terms as $term) {
      $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu';
      $text = preg_replace($pattern, self::PLACEHOLDER, $text) ?? $text;
    }
    return $text;
  }

  /**
   * An outlet name plus the variant without a leading "The".
   *
   * @return string[]
   *   Variants to redact.
   */
  public static function variants(string $name): array {
    $name = trim($name);
    $variants = [$name];
    if (preg_match('/^the\s+(.+)$/iu', $name, $m)) {
      $variants[] = $m[1];
    }
    return $variants;
  }

  /**
   * The outlet's domain for a feed URL: rss.ledger.example → ledger.example.
   *
   * Returns '' for feed-service hosts (feedburner etc.). Keeps the last two
   * labels, which is right for every .com/.org/.news outlet in the
   * registries; a two-part public suffix (.co.uk) would need a PSL.
   */
  public static function domainFromUrl(string $url): string {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
      return '';
    }
    $labels = explode('.', $host);
    $domain = implode('.', array_slice($labels, -2));
    return in_array($domain, self::GENERIC_HOSTS, TRUE) ? '' : $domain;
  }

  /**
   * Name variants, feed domains and configured aliases for one outlet.
   *
   * @return string[]
   *   Terms to redact.
   */
  public function termsFor(string $outlet): array {
    $key = mb_strtolower($outlet);
    if (isset($this->termsByOutlet[$key])) {
      return $this->termsByOutlet[$key];
    }
    $terms = self::variants($outlet);

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $ids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'feed_source')
      ->execute();
    foreach ($nodeStorage->loadMultiple($ids) as $feed) {
      $name = $feed->hasField('field_media_outlet') ? (string) $feed->get('field_media_outlet')->value : '';
      $name = $name !== '' ? $name : (string) $feed->label();
      if (mb_strtolower(trim($name)) !== $key) {
        continue;
      }
      foreach (['field_feed_url', 'field_website'] as $field) {
        if ($feed->hasField($field) && !$feed->get($field)->isEmpty()) {
          $item = $feed->get($field)->first();
          $domain = self::domainFromUrl((string) ($item->uri ?? $item->value ?? ''));
          if ($domain !== '') {
            $terms[] = $domain;
          }
        }
      }
    }

    $aliases = $this->configFactory->get('maemgaba_core.settings')->get('blind_aliases');
    foreach (is_array($aliases) ? $aliases : [] as $entry) {
      if (mb_strtolower(trim((string) ($entry['outlet'] ?? ''))) === $key) {
        $terms = array_merge($terms, array_map('strval', (array) ($entry['terms'] ?? [])));
      }
    }

    return $this->termsByOutlet[$key] = array_values(array_unique($terms));
  }

}

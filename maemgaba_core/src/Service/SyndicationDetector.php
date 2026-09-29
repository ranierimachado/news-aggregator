<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\maemgaba_core\Syndication\MinHash;
use Drupal\maemgaba_core\TextStats;

/**
 * Finds reprints (AP/Reuters copy, shared stories) among recent cards.
 *
 * Wire syndication is huge in some markets (the US above all), and eight
 * outlets running the same AP story are one perspective, not eight. At
 * ingest, before any AI call, the new article's text is fingerprinted
 * (MinHash over 5-word shingles) and compared with every card fingerprinted
 * in the last lookback_hours. A match is a card whose estimated Jaccard
 * similarity reaches `threshold`, or whose containment (share of the shorter
 * text found in the longer) reaches `containment_threshold`; when both
 * articles carry the same wire byline (`wires` patterns near the top or
 * bottom of the text) both thresholds drop to `byline_threshold`. The
 * reprint then points at the earliest matching card (field_syndicated_from,
 * following chains to their root) and reuses its classification.
 *
 * Fingerprints live in maemgaba_card_signature (nothing readable: hashes,
 * counts and the detected wire name). Texts shorter than min_words (e.g.
 * headline + abstract only) are never fingerprinted or matched.
 *
 * Configured by maemgaba_core.settings:syndication; off when unset, which
 * keeps sites that never set it unchanged.
 */
class SyndicationDetector {

  /**
   * The fingerprint table.
   */
  public const TABLE = 'maemgaba_card_signature';

  /**
   * Defaults for unset syndication.* keys.
   */
  protected const DEFAULTS = [
    'threshold' => 0.8,
    'containment_threshold' => 0.85,
    'byline_threshold' => 0.5,
    'lookback_hours' => 48,
    'min_words' => 80,
  ];

  /**
   * How far into the text (from each end) a wire byline is looked for.
   */
  protected const BYLINE_WINDOW_WORDS = 60;

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * Whether the site has syndication detection switched on.
   */
  public function enabled(): bool {
    return (bool) $this->configFactory->get('maemgaba_core.settings')->get('syndication.enabled');
  }

  /**
   * A syndication.* setting, falling back to DEFAULTS.
   */
  public function setting(string $key): float|int {
    $value = $this->configFactory->get('maemgaba_core.settings')->get('syndication.' . $key);
    $default = self::DEFAULTS[$key];
    if ($value === NULL || $value === '') {
      return $default;
    }
    return is_int($default) ? (int) $value : (float) $value;
  }

  /**
   * Fingerprints an article: MinHash signature plus detected wire byline.
   *
   * @param string $text
   *   Article text (HTML allowed); the headline should not be included, so
   *   two outlets re-titling the same copy still match.
   *
   * @return array{signature: int[], shingles: int, words: int, wire: string|null, eligible: bool}
   *   eligible = long enough to compare (words >= min_words).
   */
  public function fingerprint(string $text): array {
    $fp = MinHash::fingerprint($text);
    $fp['wire'] = $this->wireOf($text);
    $fp['eligible'] = $fp['signature'] && $fp['words'] >= (int) $this->setting('min_words');
    return $fp;
  }

  /**
   * The wire service whose byline the text carries, or NULL.
   *
   * Looks for each configured pattern (case-insensitive, literal) in the
   * first and last BYLINE_WINDOW_WORDS words: datelines ("WASHINGTON (AP)
   * —") and credit lines ("The Associated Press contributed") sit there,
   * while a mid-article "Reuters reported" does not count.
   */
  public function wireOf(string $text): ?string {
    $parts = preg_split('/\s+/u', TextStats::plain($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) {
      return NULL;
    }
    $n = self::BYLINE_WINDOW_WORDS;
    $edges = implode(' ', array_slice($parts, 0, $n)) . "\n" . implode(' ', array_slice($parts, -$n));
    foreach ((array) $this->configFactory->get('maemgaba_core.settings')->get('syndication.wires') as $wire) {
      foreach ((array) ($wire['patterns'] ?? []) as $pattern) {
        $pattern = trim((string) $pattern);
        if ($pattern !== '' && mb_stripos($edges, $pattern) !== FALSE) {
          return (string) $wire['name'];
        }
      }
    }
    return NULL;
  }

  /**
   * The card a new article reprints, or NULL.
   *
   * @param array $fp
   *   A fingerprint() result.
   *
   * @return array{card: int, event: int|null, jaccard: float, containment: float, wire: string|null}|null
   *   The earliest matching card (root of any syndication chain), its
   *   current parent event, and the best match's scores.
   */
  public function findOriginal(array $fp): ?array {
    if (empty($fp['eligible'])) {
      return NULL;
    }
    $since = $this->time->getCurrentTime() - (int) $this->setting('lookback_hours') * 3600;
    $rows = $this->database->select(self::TABLE, 's')
      ->fields('s', ['card_nid', 'shingles', 'wire', 'signature'])
      ->condition('s.created', $since, '>=')
      ->condition('s.shingles', 0, '>')
      ->execute();

    $matches = [];
    foreach ($rows as $row) {
      $jaccard = MinHash::jaccard($fp['signature'], MinHash::unpack((string) $row->signature));
      $containment = MinHash::containment($jaccard, (int) $fp['shingles'], (int) $row->shingles);
      $sameWire = $fp['wire'] !== NULL && $fp['wire'] === ((string) $row->wire ?: NULL);
      $threshold = $sameWire ? (float) $this->setting('byline_threshold') : (float) $this->setting('threshold');
      $containmentThreshold = $sameWire ? (float) $this->setting('byline_threshold') : (float) $this->setting('containment_threshold');
      if ($jaccard >= $threshold || $containment >= $containmentThreshold) {
        $matches[(int) $row->card_nid] = ['jaccard' => $jaccard, 'containment' => $containment];
      }
    }
    if (!$matches) {
      return NULL;
    }

    // Resolve each match to the root of its chain, then take the earliest
    // published root that still belongs to an event.
    $storage = $this->entityTypeManager->getStorage('node');
    $best = NULL;
    foreach ($storage->loadMultiple(array_keys($matches)) as $nid => $card) {
      $root = $card;
      for ($hops = 0; $hops < 5 && $root->hasField('field_syndicated_from') && ($parent = $root->get('field_syndicated_from')->entity); $hops++) {
        $root = $parent;
      }
      if ($root->bundle() !== 'card' || !$root->isPublished()) {
        continue;
      }
      $key = [(int) $root->getCreatedTime(), (int) $root->id()];
      if ($best === NULL || $key < $best['key']) {
        $best = ['root' => $root, 'key' => $key] + $matches[$nid];
      }
    }
    if ($best === NULL) {
      return NULL;
    }
    $event = $best['root']->get('field_parent_event')->target_id;
    return [
      'card' => (int) $best['root']->id(),
      'event' => $event ? (int) $event : NULL,
      'jaccard' => round($best['jaccard'], 3),
      'containment' => round($best['containment'], 3),
      'wire' => $fp['wire'],
    ];
  }

  /**
   * Stores a card's fingerprint so later articles can match it.
   *
   * Reprints are stored too: a third copy can match either one, and chains
   * resolve to the root anyway. Ineligible (short) texts store only the
   * wire, so the "via AP" tag still works for them.
   */
  public function remember(int $cardId, int $eventId, array $fp): void {
    $this->database->merge(self::TABLE)
      ->key('card_nid', $cardId)
      ->fields([
        'event_nid' => $eventId,
        'created' => $this->time->getCurrentTime(),
        'words' => (int) $fp['words'],
        'shingles' => !empty($fp['eligible']) ? (int) $fp['shingles'] : 0,
        'wire' => mb_substr((string) ($fp['wire'] ?? ''), 0, 32),
        'signature' => !empty($fp['eligible']) ? MinHash::pack($fp['signature']) : '',
      ])
      ->execute();
  }

  /**
   * Detected wire names for a set of cards.
   *
   * @param int[] $cardIds
   *   Card node ids.
   *
   * @return array<int, string>
   *   Wire name keyed by card nid, only for cards with a detected wire.
   */
  public function wires(array $cardIds): array {
    if (!$cardIds || !$this->database->schema()->tableExists(self::TABLE)) {
      return [];
    }
    return array_map('strval', $this->database->select(self::TABLE, 's')
      ->fields('s', ['card_nid', 'wire'])
      ->condition('s.card_nid', $cardIds, 'IN')
      ->condition('s.wire', '', '<>')
      ->execute()
      ->fetchAllKeyed());
  }

  /**
   * Deletes a card's fingerprint.
   */
  public function forget(int $cardId): void {
    $this->database->delete(self::TABLE)->condition('card_nid', $cardId)->execute();
  }

}

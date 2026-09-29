<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\maemgaba_core\BiasScore;
use Drupal\node\NodeInterface;

/**
 * Outbound click-through log: which outlets and buckets readers go on to read.
 *
 * Acquisition policy: per-source click-through is evidence that the site
 * sends readers to the articles rather than substituting for them, and a
 * methodology-page metric. Every outbound link goes through /out/{card}
 * (OutboundController), which records one row {card, event, outlet, bucket,
 * timestamp} in maemgaba_click_log. Nothing identifies the reader: no IP,
 * no cookie, no user agent is stored.
 *
 * Click-through rate needs a denominator: event pages report a view through
 * a small beacon (POST /out/view/{event}), counted per event and day in
 * maemgaba_event_view. A card's impressions = views of its event's page;
 * CTR = clicks / impressions, per outlet and per bucket.
 *
 * Links only go through /out when maemgaba_core.settings:click_log.enabled
 * is on (off when unset: direct links).
 */
class ClickLog {

  /**
   * One row per outbound click.
   */
  public const TABLE = 'maemgaba_click_log';

  /**
   * Event page views per event and day.
   */
  public const VIEW_TABLE = 'maemgaba_event_view';

  /**
   * Cache tag of anything that shows the aggregate (invalidated on cron).
   */
  public const CACHE_TAG = 'maemgaba_click_stats';

  /**
   * User agents that are never logged (crawlers, link previewers).
   */
  protected const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|curl|wget|python-requests|httpclient|headless/i';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected SpectrumService $spectrum,
  ) {}

  /**
   * Whether outbound links should go through /out/{card}.
   */
  public function enabled(): bool {
    return (bool) $this->configFactory->get('maemgaba_core.settings')->get('click_log.enabled');
  }

  /**
   * Days covered by the methodology-page aggregate (default 30).
   */
  public function reportDays(): int {
    $days = (int) $this->configFactory->get('maemgaba_core.settings')->get('click_log.report_days');
    return $days > 0 ? $days : 30;
  }

  /**
   * Whether a request's user agent looks automated (not logged).
   */
  public static function isBot(string $userAgent): bool {
    return $userAgent === '' || (bool) preg_match(self::BOT_PATTERN, $userAgent);
  }

  /**
   * Records one outbound click on a card.
   */
  public function logClick(NodeInterface $card): void {
    $outlet = '';
    if ($card->hasField('field_source') && ($term = $card->get('field_source')->entity)) {
      $outlet = mb_substr((string) $term->label(), 0, 128);
    }
    $score = $card->hasField('field_bias_score') ? BiasScore::normalize($card->get('field_bias_score')->value) : NULL;
    $event = $card->hasField('field_parent_event') ? (int) $card->get('field_parent_event')->target_id : 0;
    $this->database->insert(self::TABLE)
      ->fields([
        'card_nid' => (int) $card->id(),
        'event_nid' => $event,
        'outlet' => $outlet,
        'bucket' => (string) ($this->spectrum->keyFor($score) ?? ''),
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Counts one view of an event page (today's row for that event).
   */
  public function logView(int $eventId): void {
    $day = (int) gmdate('Ymd', $this->time->getRequestTime());
    $this->database->merge(self::VIEW_TABLE)
      ->keys(['event_nid' => $eventId, 'day' => $day])
      ->insertFields(['event_nid' => $eventId, 'day' => $day, 'views' => 1])
      ->expression('views', '[views] + 1')
      ->execute();
  }

  /**
   * Click-through report since a timestamp.
   *
   * @return array{since: int, clicks: int, views: int, impressions: int, ctr: float|null, outlets: array<string, array>, buckets: array<string, array>}
   *   outlets/buckets rows are {clicks, impressions, ctr}; ctr is a
   *   percentage (NULL without impressions). Buckets come in spectrum order,
   *   plus '' for clicks on unscored cards if any.
   */
  public function report(int $since): array {
    $sinceDay = (int) gmdate('Ymd', $since);

    // Clicks per outlet and per bucket.
    $outlets = [];
    $buckets = array_fill_keys($this->spectrum->keys(), ['clicks' => 0, 'impressions' => 0, 'ctr' => NULL]);
    $clicks = 0;
    $query = $this->database->select(self::TABLE, 'c');
    $query->fields('c', ['outlet', 'bucket']);
    $query->addExpression('COUNT(*)', 'n');
    $query->condition('c.created', $since, '>=');
    $query->groupBy('c.outlet');
    $query->groupBy('c.bucket');
    foreach ($query->execute() as $row) {
      $n = (int) $row->n;
      $clicks += $n;
      $outlets[(string) $row->outlet]['clicks'] = ($outlets[(string) $row->outlet]['clicks'] ?? 0) + $n;
      $buckets[(string) $row->bucket]['clicks'] = ($buckets[(string) $row->bucket]['clicks'] ?? 0) + $n;
    }

    // Views per event, then one impression per listed card per view.
    $views = $this->database->select(self::VIEW_TABLE, 'v');
    $views->fields('v', ['event_nid']);
    $views->addExpression('SUM(v.views)', 'views');
    $views->condition('v.day', $sinceDay, '>=');
    $views->groupBy('v.event_nid');
    $viewsByEvent = array_map('intval', $views->execute()->fetchAllKeyed());

    $impressions = 0;
    if ($viewsByEvent) {
      $cards = $this->database->select('node_field_data', 'n');
      $cards->innerJoin('node__field_parent_event', 'pe', 'pe.entity_id = n.nid AND pe.deleted = 0');
      $cards->leftJoin('node__field_source', 'fs', 'fs.entity_id = n.nid AND fs.deleted = 0');
      $cards->leftJoin('taxonomy_term_field_data', 't', 't.tid = fs.field_source_target_id');
      $cards->leftJoin('node__field_bias_score', 'bs', 'bs.entity_id = n.nid AND bs.deleted = 0');
      $cards->condition('n.type', 'card');
      $cards->condition('n.status', 1);
      $cards->condition('pe.field_parent_event_target_id', array_keys($viewsByEvent), 'IN');
      $cards->addField('pe', 'field_parent_event_target_id', 'event_nid');
      $cards->addField('t', 'name', 'outlet');
      $cards->addField('bs', 'field_bias_score_value', 'score');
      foreach ($cards->execute() as $row) {
        $seen = $viewsByEvent[(int) $row->event_nid] ?? 0;
        $impressions += $seen;
        $outlet = mb_substr((string) $row->outlet, 0, 128);
        $outlets[$outlet]['impressions'] = ($outlets[$outlet]['impressions'] ?? 0) + $seen;
        $key = (string) ($this->spectrum->keyFor(BiasScore::normalize($row->score)) ?? '');
        $buckets[$key]['impressions'] = ($buckets[$key]['impressions'] ?? 0) + $seen;
      }
    }

    $finish = function (array $rows): array {
      foreach ($rows as $key => $row) {
        $rows[$key] += ['clicks' => 0, 'impressions' => 0];
        $rows[$key]['ctr'] = self::ctr($rows[$key]['clicks'], $rows[$key]['impressions']);
      }
      return $rows;
    };
    $outlets = $finish($outlets);
    ksort($outlets, SORT_NATURAL | SORT_FLAG_CASE);
    $buckets = $finish($buckets);
    if (isset($buckets['']) && $buckets['']['clicks'] === 0 && $buckets['']['impressions'] === 0) {
      unset($buckets['']);
    }

    return [
      'since' => $since,
      'clicks' => $clicks,
      'views' => array_sum($viewsByEvent),
      'impressions' => $impressions,
      'ctr' => self::ctr($clicks, $impressions),
      'outlets' => $outlets,
      'buckets' => $buckets,
    ];
  }

  /**
   * The methodology-page aggregate over the last report_days days.
   *
   * @return array{days: int, clicks: int, views: int, impressions: int, ctr: float|null}
   *   Totals only; per-outlet detail stays in the Drush report.
   */
  public function summary(): array {
    $days = $this->reportDays();
    $report = $this->report($this->time->getRequestTime() - $days * 86400);
    return [
      'days' => $days,
      'clicks' => $report['clicks'],
      'views' => $report['views'],
      'impressions' => $report['impressions'],
      'ctr' => $report['ctr'],
    ];
  }

  /**
   * Clicks / impressions as a percentage with one decimal, NULL if no base.
   */
  public static function ctr(int $clicks, int $impressions): ?float {
    return $impressions > 0 ? round($clicks / $impressions * 100, 1) : NULL;
  }

}

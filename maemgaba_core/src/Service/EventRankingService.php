<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Ranks events by time-decayed coverage — the same order the home page uses.
 *
 * Single source of truth for "what counts as a top event": published,
 * relevant events from the last WINDOW_DAYS, ordered by a decayed score
 * (Hacker News-style): published card count divided by the event's age in
 * days raised to GRAVITY. A fresh event with a handful of cards can outrank
 * an old event that accumulated many — old events sink no matter how much
 * coverage they gathered. HomeController uses this to pick the hero + grid;
 * the event page uses it to decide whether an event is eligible for
 * on-demand consensus synthesis.
 */
class EventRankingService {

  /**
   * Only events created within this many days are eligible.
   */
  protected const WINDOW_DAYS = 33;

  /**
   * Exponent applied to event age (in days) in the ranking score.
   *
   * The score is card_count / (age_days + AGE_OFFSET_DAYS) ^ GRAVITY. Higher
   * gravity sinks old events faster; 1.0 would make score roughly
   * "cards per day".
   */
  protected const GRAVITY = 1.5;

  /**
   * Days added to age before applying gravity.
   *
   * Keeps brand-new events (age ≈ 0) from dividing by a near-zero base and
   * dominating the ranking with a single card.
   */
  protected const AGE_OFFSET_DAYS = 2;

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * The top N event ids by decayed coverage score, in order.
   *
   * @param int $limit
   *   How many event ids to return.
   *
   * @return int[]
   *   Ordered event node ids (most-covered first).
   */
  public function topEventIds(int $limit): array {
    return array_map('intval', $this->orderedEventIds(0, $limit, NULL));
  }

  /**
   * Whether $nid is currently among the top $limit events by coverage.
   */
  public function isInTop(int $nid, int $limit): bool {
    return in_array($nid, $this->topEventIds($limit), TRUE);
  }

  /**
   * Returns one page of event ids plus whether more remain.
   *
   * @param int $offset
   *   Result offset.
   * @param int $limit
   *   Result limit.
   * @param int|null $exclude
   *   An event id to exclude, or NULL.
   *
   * @return array
   *   [ (int[]) node ids, (bool) has_more ].
   */
  public function page(int $offset, int $limit, ?int $exclude): array {
    $ids = $this->orderedEventIds($offset, $limit, $exclude);
    $total = $this->eventCount($exclude);
    $has_more = ($offset + count($ids)) < $total;
    return [array_map('intval', $ids), $has_more];
  }

  /**
   * Counts events eligible for ranking.
   *
   * @param int|null $exclude
   *   An event id to exclude, or NULL.
   */
  public function eventCount(?int $exclude): int {
    return (int) $this->baseEventQuery($exclude)->countQuery()->execute()->fetchField();
  }

  /**
   * Base query: published, relevant events created within the window.
   *
   * @param int|null $exclude
   *   An event id to exclude, or NULL.
   */
  protected function baseEventQuery(?int $exclude) {
    $cutoff = $this->time->getRequestTime() - (self::WINDOW_DAYS * 86400);
    $query = $this->database->select('node_field_data', 'e');
    $query->condition('e.type', 'event');
    $query->condition('e.status', 1);
    $query->condition('e.created', $cutoff, '>=');
    $query->join('node__field_social_relevance', 'r', 'r.entity_id = e.nid');
    $query->condition('r.field_social_relevance_value', 'relevant');
    if ($exclude !== NULL) {
      $query->condition('e.nid', $exclude, '<>');
    }
    return $query;
  }

  /**
   * Ordered event ids by decayed coverage score desc, date tiebreaker.
   *
   * @param int $offset
   *   Result offset.
   * @param int $limit
   *   Result limit.
   * @param int|null $exclude
   *   An event id to exclude, or NULL.
   *
   * @return array
   *   Ordered event node ids.
   */
  protected function orderedEventIds(int $offset, int $limit, ?int $exclude): array {
    $query = $this->baseEventQuery($exclude);
    $query->leftJoin('node__field_parent_event', 'pe', 'pe.field_parent_event_target_id = e.nid');
    $query->leftJoin('node_field_data', 'c', "c.nid = pe.entity_id AND c.type = 'card' AND c.status = 1");
    $query->addField('e', 'nid', 'nid');
    $query->addExpression(
      'COUNT(c.nid) / POW(((:now - e.created) / 86400.0) + :age_offset, :gravity)',
      'score',
      [
        ':now' => $this->time->getRequestTime(),
        ':age_offset' => self::AGE_OFFSET_DAYS,
        ':gravity' => self::GRAVITY,
      ]
    );
    $query->groupBy('e.nid');
    $query->groupBy('e.created');
    $query->orderBy('score', 'DESC');
    $query->orderBy('e.created', 'DESC');
    $query->range($offset, $limit);
    return $query->execute()->fetchCol();
  }

}

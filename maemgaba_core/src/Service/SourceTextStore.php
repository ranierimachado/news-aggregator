<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Private copy of each card's source text, for the verbatim-overlap guard.
 *
 * The inbound_queue item (and its article body) is deleted once a card is
 * made, but consensus synthesis runs later and must be checked against
 * every source's text (VerbatimOverlapGuard). This table keeps the plain
 * text per card. It is never rendered and never leaves the database.
 *
 * Only written when maemgaba_core.settings:overlap_guard.enabled is on, and
 * purged on cron after overlap_guard.retention_days.
 */
class SourceTextStore {

  /**
   * The table name.
   */
  public const TABLE = 'maemgaba_source_text';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Stores (or replaces) a card's source text.
   */
  public function save(int $cardId, int $eventId, string $text): void {
    $this->database->merge(self::TABLE)
      ->key('card_nid', $cardId)
      ->fields([
        'event_nid' => $eventId,
        'body' => $text,
        'created' => $this->time->getCurrentTime(),
      ])
      ->execute();
  }

  /**
   * Source texts for every card of an event.
   *
   * @return string[]
   *   Texts keyed by card nid.
   */
  public function forEvent(int $eventId): array {
    return $this->database->select(self::TABLE, 's')
      ->fields('s', ['card_nid', 'body'])
      ->condition('s.event_nid', $eventId)
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * Deletes a card's text.
   */
  public function delete(int $cardId): void {
    $this->database->delete(self::TABLE)->condition('card_nid', $cardId)->execute();
  }

  /**
   * Deletes texts older than $days days. Returns the number of rows removed.
   */
  public function purgeOlderThan(int $days): int {
    if ($days <= 0) {
      return 0;
    }
    return (int) $this->database->delete(self::TABLE)
      ->condition('created', $this->time->getCurrentTime() - $days * 86400, '<')
      ->execute();
  }

}

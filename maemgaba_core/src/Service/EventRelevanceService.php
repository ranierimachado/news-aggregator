<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\maemgaba_core\CardRole;
use Drupal\node\NodeInterface;

/**
 * Rolls an event's social relevance and topic up from its cards.
 *
 * Relevance is never AI-classified at the event level: it is derived from the
 * event's clustered cards so the event can never disagree with its own
 * articles, and the "maybe becomes relevant with >=2 outlets" corroboration
 * rule (which only the event knows) is encoded in one place.
 */
class EventRelevanceService {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Recomputes and saves relevance + topic for a single event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   */
  public function recalculate(NodeInterface $event): void {
    if ($event->bundle() !== 'event') {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $cardIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'card')
      ->condition('field_parent_event', $event->id())
      ->condition('status', 1)
      ->execute();

    // Card count is kept accurate independent of the relevance/topic
    // early-returns below — it's what /events sorts "Destaque" by (see
    // views.view.events.yml's field_card_count sort), so it must reflect
    // the real published-card count even when a rollup drops to zero.
    $changed = FALSE;
    $card_count = count($cardIds);
    if ($event->hasField('field_card_count') && (int) $event->get('field_card_count')->value !== $card_count) {
      $event->set('field_card_count', $card_count);
      $changed = TRUE;
    }

    // No published cards: leave relevance/topic untouched (fail open —
    // displayable), but still persist the card-count change above.
    if (!$cardIds) {
      if ($changed) {
        $event->save();
      }
      return;
    }

    $relevances = [];
    $topics = [];
    $outlets = [];
    foreach ($storage->loadMultiple($cardIds) as $card) {
      if ($r = $card->get('field_social_relevance')->value) {
        $relevances[] = $r;
      }
      if ($t = $card->get('field_topic')->value) {
        $topics[$t] = ($topics[$t] ?? 0) + 1;
      }
      // A reprint or an opinion piece doesn't corroborate the story.
      if (CardRole::isPerspective($card) && ($sid = $card->get('field_source')->target_id)) {
        $outlets[$sid] = TRUE;
      }
    }

    // No rated cards: fail open, leave relevance unset, but still persist
    // the card-count change above.
    if (!$relevances) {
      if ($changed) {
        $event->save();
      }
      return;
    }

    $has_relevant = in_array('relevant', $relevances, TRUE);
    $has_maybe = in_array('maybe', $relevances, TRUE);
    $distinct_outlets = count($outlets);

    if ($has_relevant || ($has_maybe && $distinct_outlets >= 2)) {
      $relevance = 'relevant';
    }
    elseif ($has_maybe) {
      $relevance = 'maybe';
    }
    else {
      $relevance = 'irrelevant';
    }

    // Dominant topic (most frequent among the cards).
    $topic = NULL;
    if ($topics) {
      arsort($topics);
      $topic = array_key_first($topics);
    }

    if ($event->get('field_social_relevance')->value !== $relevance) {
      $event->set('field_social_relevance', $relevance);
      $changed = TRUE;
    }
    if ($topic !== NULL && $event->get('field_topic')->value !== $topic) {
      $event->set('field_topic', $topic);
      $changed = TRUE;
    }
    if ($changed) {
      $event->save();
    }
  }

  /**
   * Recomputes relevance + topic for an event by id.
   *
   * @param int|string $event_id
   *   The event node id.
   */
  public function recalculateById(int|string $event_id): void {
    $event = $this->entityTypeManager->getStorage('node')->load($event_id);
    if ($event instanceof NodeInterface) {
      $this->recalculate($event);
    }
  }

  /**
   * Recomputes relevance + topic for every event.
   *
   * @return int
   *   Number of events processed.
   */
  public function recalculateAll(): int {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->execute();
    foreach ($ids as $id) {
      $this->recalculateById($id);
    }
    return count($ids);
  }

}

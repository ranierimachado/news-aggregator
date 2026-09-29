<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\maemgaba_core\AiAnalyzerService;
use Drupal\maemgaba_core\BiasScore;
use Drupal\maemgaba_core\TextStats;
use Drupal\node\NodeInterface;

/**
 * Consumes the inbound queue and backfills relevance on existing cards.
 *
 * Holds the news-processing business logic so it can be driven from Drush, an
 * admin form, cron, or a queue worker. Methods return plain-array summaries
 * (counts + human-readable messages) rather than printing, so any caller can
 * present the outcome.
 */
class QueueProcessor {

  /**
   * Allowed social-relevance values (guards the select-list field).
   */
  public const RELEVANCE_VALUES = ['irrelevant', 'maybe', 'relevant'];

  /**
   * Default review batch size.
   */
  public const DEFAULT_REVIEW_BATCH = 25;

  /**
   * Default queue batch size (rate-limits Gemini calls).
   */
  public const DEFAULT_BATCH = 5;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiAnalyzerService $aiAnalyzer,
    protected EventRelevanceService $eventRelevance,
    protected ConsensusService $consensus,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected EventCandidateFinder $eventCandidates,
    protected ConfigFactoryInterface $configFactory,
    protected PipelineIdentity $pipelineIdentity,
    protected LockBackendInterface $lock,
    protected TopicService $topics,
    protected SourceTextStore $sourceTexts,
    protected VerbatimOverlapGuard $overlapGuard,
    protected SyndicationDetector $syndication,
  ) {}

  /**
   * Processes one batch of the inbound queue: classify, cluster, card, clean.
   *
   * @param int $limit
   *   Maximum queue items to process.
   * @param bool $newestFirst
   *   If TRUE, consumes the queue newest-to-oldest (by nid) instead of the
   *   default oldest-to-newest.
   * @param callable|null $onItem
   *   Optional progress hook, invoked once per queue item AFTER it is
   *   handled (whichever branch — duplicate, AI failure, or created), with
   *   a single array argument: ['title' => string, 'outcome' =>
   *   'duplicate'|'ai_failure'|'no_event'|'created'|'syndicated',
   *   'duration_ms' => int].
   *   This service never prints — callers (Drush command, batch op) use the
   *   hook to drive their own progress bar / ETA display.
   *
   * @return array
   *   ['processed' => int, 'events_created' => int, 'cards_created' => int,
   *    'skipped' => int, 'duplicates' => int, 'syndicated' => int,
   *    'messages' => string[]]. Syndicated reprints count in processed and
   *    cards_created too.
   */
  public function processBatch(int $limit = self::DEFAULT_BATCH, bool $newestFirst = FALSE, ?callable $onItem = NULL): array {
    $summary = [
      'processed' => 0,
      'events_created' => 0,
      'cards_created' => 0,
      'skipped' => 0,
      'duplicates' => 0,
      'syndicated' => 0,
      'messages' => [],
    ];
    $storage = $this->entityTypeManager->getStorage('node');

    $queueIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'inbound_queue')
      ->sort('nid', $newestFirst ? 'DESC' : 'ASC')
      ->range(0, $limit)
      ->execute();

    if (empty($queueIds)) {
      $summary['messages'][] = 'The inbound queue is empty.';
      return $summary;
    }

    foreach ($storage->loadMultiple($queueIds) as $queueNode) {
      $itemStart = microtime(TRUE);
      $title = $queueNode->getTitle();
      $articleText = $queueNode->get('field_raw_html_body')->value;
      $sourceUrl = $queueNode->get('field_source_url')->uri;
      $sourceName = $queueNode->get('field_source_name')->value;
      $section = $queueNode->hasField('field_section') ? $queueNode->get('field_section')->value : NULL;

      $report = function (string $outcome) use ($onItem, $itemStart, $title): void {
        if ($onItem !== NULL) {
          $onItem([
            'title' => $title,
            'outcome' => $outcome,
            'duration_ms' => (int) round((microtime(TRUE) - $itemStart) * 1000),
          ]);
        }
      };

      // A card for this URL may already exist: the ingestion-time URL dedup
      // can't see it if the item was queued before the card existed, and a
      // crash between card creation and queue deletion (observed 2026-08-19:
      // stale field definitions right after a DB import killed the batch
      // mid-item) leaves the item queued so the retry would double-create.
      // Checked before the AI call so a re-queued duplicate costs nothing.
      $existingCard = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'card')
        ->condition('field_original_url.uri', $sourceUrl)
        ->range(0, 1)
        ->execute();
      if ($existingCard) {
        $queueNode->delete();
        $summary['duplicates']++;
        $summary['messages'][] = "Card already exists for this URL (item removed from queue): {$title}";
        $report('duplicate');
        continue;
      }

      // A reprint of a recent card (wire copy, shared story) reuses that
      // card's classification and event: no AI call, one perspective.
      $fingerprint = NULL;
      if ($this->syndication->enabled()) {
        $fingerprint = $this->syndication->fingerprint((string) $articleText);
        $original = $this->syndication->findOriginal($fingerprint);
        if ($original !== NULL && $original['event'] !== NULL && ($source = $storage->load($original['card']))) {
          $card = $this->createSyndicatedCard($source, $title, $sourceUrl, (string) $sourceName, $section);
          $this->syndication->remember((int) $card->id(), $original['event'], $fingerprint);
          if ($this->overlapGuard->enabled()) {
            $this->sourceTexts->save((int) $card->id(), $original['event'], $title . "\n" . TextStats::plain((string) $articleText));
          }
          $this->eventRelevance->recalculateById($original['event']);
          $queueNode->delete();
          $summary['processed']++;
          $summary['cards_created']++;
          $summary['syndicated']++;
          $summary['messages'][] = sprintf('[syndicated%s | J=%.2f C=%.2f] reprint of card %d, event %d: %s',
            $original['wire'] ? ' via ' . $original['wire'] : '', $original['jaccard'], $original['containment'], $original['card'], $original['event'], $title);
          $report('syndicated');
          continue;
        }
      }

      $context = [
        'type' => 'inbound_queue',
        'id' => $queueNode->id(),
        'label' => $title,
      ];
      $candidates = $this->clusteringRetrieval() === 'vector'
        ? $this->eventCandidates->findCandidates($articleText, 8, $context)
        : $this->recencyCandidates();

      $analysis = $this->aiAnalyzer->classifyAndCluster($articleText, $candidates, NULL, $context, [
        'headline' => $title,
        'outlet' => (string) $sourceName,
      ]);
      if (empty($analysis)) {
        $summary['skipped']++;
        $summary['messages'][] = "AI failure (skipped): {$title}";
        $report('ai_failure');
        continue;
      }

      $eventId = $analysis['matched_event_id'] ?? NULL;
      if (!$eventId && !empty($analysis['event_title'])) {
        $eventNode = $storage->create([
          'type' => 'event',
          'uid' => $this->pipelineIdentity->uid(),
          'title' => $analysis['event_title'],
          'field_neutral_summary' => [
            'value' => $this->newEventSummary((string) ($analysis['neutral_summary'] ?? ''), $title, (string) $articleText),
            'format' => 'basic_html',
          ],
          'status' => 1,
        ]);
        $eventNode->save();
        $eventId = $eventNode->id();
        $summary['events_created']++;

        // Search API's "index directly" option defers to a post-request
        // listener, not the current request — confirmed by testing two
        // same-story articles in one batch: the second could not find the
        // event the first had just created, even though it was a clean
        // 0.19-distance match once the request ended. In vector mode a
        // sibling article later in this same batch needs this event
        // searchable *now*, so index it synchronously instead of waiting.
        if ($this->clusteringRetrieval() === 'vector') {
          $this->indexEventImmediately($eventNode);
        }
      }

      if (!$eventId) {
        $summary['skipped']++;
        $summary['messages'][] = "No event resolved (skipped): {$title}";
        $report('no_event');
        continue;
      }

      $relevance = $this->whitelist($analysis['social_relevance'] ?? '', self::RELEVANCE_VALUES, 'maybe');
      $topic = $this->topics->whitelist((string) ($analysis['topic'] ?? ''));

      $cardValues = [
        'type' => 'card',
        'uid' => $this->pipelineIdentity->uid(),
        'title' => $title,
        'field_parent_event' => $eventId,
        // field_article_bias (legacy list) is derived from the score on
        // presave — see maemgaba_core_entity_presave().
        'field_bias_score' => $analysis['bias_score'] ?? NULL,
        'field_bias_confidence' => $analysis['bias_confidence'] ?? NULL,
        'field_bias_evidence' => $analysis['bias_evidence'] ?? [],
        'field_source' => $this->resolveSourceTerm($sourceName),
        'field_original_url' => $sourceUrl,
        'field_micro_summary' => [
          // Model text in a basic_html field (rendered on card pages).
          'value' => trim(strip_tags((string) ($analysis['micro_summary'] ?? ''))),
          'format' => 'basic_html',
        ],
        'field_social_relevance' => $relevance,
        'field_topic' => $topic,
        'field_relevance_reason' => $analysis['relevance_reason'] ?? '',
        'field_framing_line' => $analysis['framing_line'] ?? '',
        'field_partial_text' => !empty($analysis['partial_text']),
        'field_section' => $section ?: NULL,
        'status' => 1,
      ];
      $card = $storage->create($cardValues);
      $card->save();

      if ($this->overlapGuard->enabled()) {
        $this->sourceTexts->save((int) $card->id(), (int) $eventId, $title . "\n" . TextStats::plain((string) $articleText));
      }
      if ($fingerprint !== NULL) {
        $this->syndication->remember((int) $card->id(), (int) $eventId, $fingerprint);
      }

      // Refresh the event's derived relevance/topic from its cards.
      $this->eventRelevance->recalculateById($eventId);

      // If this event *already* has consensus stored, a new card means fresh
      // coverage that the existing synthesis doesn't reflect yet, so refresh
      // it now. First synthesis depends on consensus_mode: in lazy_top5
      // (default) it's triggered when a visitor opens an eligible event page
      // (see ConsensusController); in eager it happens here, as soon as the
      // event has cards from MIN_SOURCES distinct outlets — recalculate()
      // enforces that gate itself, so the first card is a cheap no-op.
      $event = $storage->load($eventId);
      if ($event && $this->consensus->hasConsensus($event)) {
        $this->consensus->recalculateById($eventId, TRUE);
      }
      elseif ($event instanceof NodeInterface && $this->consensusMode() === ConsensusService::MODE_EAGER) {
        $this->synthesizeEagerly($event);
      }

      // Remove the staging item now that it is a card.
      $queueNode->delete();

      $summary['processed']++;
      $summary['cards_created']++;
      $summary['messages'][] = "[{$relevance} | {$topic}] event {$eventId}: {$title}";
      $report('created');
    }

    return $summary;
  }

  /**
   * Creates a reprint card: the original's classification, its own identity.
   *
   * Title, URL, outlet and section are the reprint's own; score,
   * confidence, evidence, framing line, topic and relevance are copied from
   * the card it reprints (the text is the same, so the reading is too), and
   * it joins that card's event. field_syndicated_from marks it so it is
   * listed under the original instead of counted as a perspective.
   */
  protected function createSyndicatedCard(EntityInterface $source, string $title, string $url, string $outlet, ?string $section): EntityInterface {
    $values = [
      'type' => 'card',
      'uid' => $this->pipelineIdentity->uid(),
      'title' => $title,
      'field_parent_event' => $source->get('field_parent_event')->target_id,
      'field_syndicated_from' => $source->id(),
      'field_source' => $this->resolveSourceTerm($outlet),
      'field_original_url' => $url,
      'field_section' => $section ?: NULL,
      'status' => 1,
    ];
    $copied = [
      'field_bias_score', 'field_bias_confidence', 'field_bias_evidence',
      'field_framing_line', 'field_partial_text', 'field_micro_summary',
      'field_social_relevance', 'field_topic', 'field_relevance_reason',
    ];
    foreach ($copied as $field) {
      if ($source->hasField($field) && !$source->get($field)->isEmpty()) {
        $values[$field] = $source->get($field)->getValue();
      }
    }
    $card = $this->entityTypeManager->getStorage('node')->create($values);
    $card->save();
    return $card;
  }

  /**
   * The neutral summary a new event gets from its first article.
   *
   * Capped at neutral_summary_max_words and, with the overlap guard on,
   * dropped (left empty until consensus synthesis writes one) if it copies
   * a run of words from the article — it is shown to readers.
   */
  protected function newEventSummary(string $summary, string $headline, string $articleText): string {
    // Model text, stored in a basic_html field: tags never belong in it.
    $summary = trim(strip_tags($summary));
    $max = (int) $this->configFactory->get('maemgaba_core.settings')->get('neutral_summary_max_words');
    if ($max > 0) {
      $summary = TextStats::capWords($summary, $max);
    }
    if ($summary !== '' && $this->overlapGuard->enabled()) {
      $run = $this->overlapGuard->check([$summary], [$headline, $articleText]);
      if ($run !== NULL) {
        $this->loggerFactory->get('maemgaba_ai')->notice('New-event summary dropped: copies "@run" from the article.', ['@run' => $run]);
        return '';
      }
    }
    return $summary;
  }

  /**
   * Backfills relevance/topic/reason on cards that do not have them yet.
   *
   * Classifies from title + micro-summary (the raw article text is gone once
   * the queue item is deleted). Idempotent — safe to run until drained.
   *
   * @param int $limit
   *   Maximum cards to classify in this call.
   *
   * @return array
   *   ['classified' => int, 'failed' => int, 'remaining' => int,
   *    'messages' => string[]].
   */
  public function backfillRelevance(int $limit = 25): array {
    $summary = ['classified' => 0, 'failed' => 0, 'remaining' => 0, 'messages' => []];
    $storage = $this->entityTypeManager->getStorage('node');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'card')
      ->notExists('field_social_relevance')
      ->range(0, $limit)
      ->execute();

    if (empty($ids)) {
      $summary['messages'][] = 'No cards awaiting relevance.';
      return $summary;
    }

    foreach ($storage->loadMultiple($ids) as $card) {
      $reason_summary = $card->hasField('field_micro_summary') ? (string) $card->get('field_micro_summary')->value : '';
      $analysis = $this->aiAnalyzer->classifyRelevance($card->getTitle(), $reason_summary, NULL, [
        'type' => 'card',
        'id' => $card->id(),
        'label' => $card->getTitle(),
      ]);

      if (empty($analysis)) {
        $summary['failed']++;
        $summary['messages'][] = "AI failed for card {$card->id()}.";
        continue;
      }

      $relevance = $this->whitelist($analysis['social_relevance'] ?? '', self::RELEVANCE_VALUES, 'maybe');
      $topic = $this->topics->whitelist((string) ($analysis['topic'] ?? ''));

      $card->set('field_social_relevance', $relevance);
      $card->set('field_topic', $topic);
      $card->set('field_relevance_reason', $analysis['relevance_reason'] ?? '');
      $card->save();

      $summary['classified']++;
      $summary['messages'][] = "[{$relevance} | {$topic}] " . mb_substr($card->getTitle(), 0, 60);
    }

    $summary['remaining'] = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'card')
      ->notExists('field_social_relevance')
      ->count()
      ->execute();

    return $summary;
  }

  /**
   * Second-layer review: re-checks bias/topic on already-published cards.
   *
   * Reads only node data (title + micro-summary) since the raw article text
   * is gone once the queue item that created the card is deleted — see
   * AiAnalyzerService::reviewCard(). Routed via operation_models.review_cards
   * (typically to a stronger model than the primary classification pass).
   * Idempotent — cards are marked reviewed via field_reviewed_at, so
   * re-running only picks up cards that have never been reviewed.
   *
   * Scoped by maemgaba_core.settings:review_since (see reviewSinceTimestamp())
   * when set: cards created before that cutoff are permanently out of scope,
   * not just deferred — set once to draw a line under an old backlog you
   * don't intend to spend review budget on, without it drifting as "today"
   * on later runs.
   *
   * @param int $limit
   *   Maximum cards to review in this call.
   *
   * @return array
   *   ['reviewed' => int, 'corrected' => int, 'confirmed' => int,
   *    'failed' => int, 'remaining' => int, 'messages' => string[]].
   */
  public function reviewCards(int $limit = self::DEFAULT_REVIEW_BATCH): array {
    $summary = ['reviewed' => 0, 'corrected' => 0, 'confirmed' => 0, 'failed' => 0, 'remaining' => 0, 'messages' => []];
    $storage = $this->entityTypeManager->getStorage('node');
    $since = $this->reviewSinceTimestamp();

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'card')
      ->notExists('field_reviewed_at')
      ->range(0, $limit);
    if ($since !== NULL) {
      $query->condition('created', $since, '>=');
    }
    $ids = $query->execute();

    if (empty($ids)) {
      $summary['messages'][] = 'No cards awaiting review.';
      return $summary;
    }

    foreach ($storage->loadMultiple($ids) as $card) {
      $currentScore = BiasScore::normalize($card->get('field_bias_score')->value);
      $currentTopic = (string) $card->get('field_topic')->value;
      $cardSummary = $card->hasField('field_micro_summary') ? (string) $card->get('field_micro_summary')->value : '';

      $review = $this->aiAnalyzer->reviewCard($card->getTitle(), $cardSummary, $currentScore, $currentTopic, NULL, [
        'type' => 'card',
        'id' => $card->id(),
        'label' => $card->getTitle(),
      ]);

      if (empty($review)) {
        $summary['failed']++;
        $summary['messages'][] = "AI failed for card {$card->id()} (review skipped; it stays pending for a retry).";
        continue;
      }

      $newScore = $review['bias_score'] ?? NULL;
      $newScore = $newScore ?? $currentScore;
      $newTopic = $this->topics->whitelist((string) ($review['topic'] ?? ''), $currentTopic);
      $changed = $newScore !== $currentScore || $newTopic !== $currentTopic;

      $card->set('field_bias_score', $newScore);
      if (($review['bias_score'] ?? NULL) !== NULL) {
        $card->set('field_bias_confidence', $review['bias_confidence'] ?? NULL);
        $card->set('field_bias_evidence', $review['bias_evidence'] ?? []);
      }
      $card->set('field_topic', $newTopic);
      $card->set('field_reviewed_at', time());
      $card->save();

      $summary['reviewed']++;
      if ($changed) {
        $summary['corrected']++;
        $summary['messages'][] = "[corrected " . $this->scoreLabel($currentScore) . "/{$currentTopic} → " . $this->scoreLabel($newScore) . "/{$newTopic}] " . mb_substr($card->getTitle(), 0, 60);
      }
      else {
        $summary['confirmed']++;
        $summary['messages'][] = "[confirmed " . $this->scoreLabel($newScore) . "/{$newTopic}] " . mb_substr($card->getTitle(), 0, 60);
      }

      if ($changed && $card->hasField('field_parent_event') && !$card->get('field_parent_event')->isEmpty()) {
        $this->eventRelevance->recalculateById((int) $card->get('field_parent_event')->target_id);
      }
    }

    $remainingQuery = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'card')
      ->notExists('field_reviewed_at');
    if ($since !== NULL) {
      $remainingQuery->condition('created', $since, '>=');
    }
    $summary['remaining'] = (int) $remainingQuery->count()->execute();

    return $summary;
  }

  /**
   * The review cutoff, if maemgaba_core.settings:review_since is set.
   *
   * @return int|null
   *   Unix timestamp, or NULL for no cutoff (review the full backlog).
   */
  protected function reviewSinceTimestamp(): ?int {
    $value = (int) ($this->configFactory->get('maemgaba_core.settings')->get('review_since') ?? 0);
    return $value > 0 ? $value : NULL;
  }

  /**
   * Events created in the last 24h, for clustering context (recency source).
   *
   * Default candidate source, and the fallback if clustering_retrieval is
   * anything other than 'vector'. Known limitation this is why Step 3
   * exists: a slow-burning story older than 24h never matches here,
   * producing a duplicate event instead.
   *
   * @return array
   *   Map of event id => ['title' => string, 'similarity' => NULL], same
   *   shape as EventCandidateFinder::findCandidates() so
   *   AiAnalyzerService::classifyAndCluster() doesn't need to know which
   *   source it got.
   */
  protected function recencyCandidates(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('created', time() - (24 * 60 * 60), '>=')
      ->execute();

    $events = [];
    foreach ($storage->loadMultiple($ids) as $event) {
      $events[$event->id()] = ['title' => $event->getTitle(), 'similarity' => NULL];
    }
    return $events;
  }

  /**
   * The active candidate-event retrieval strategy: 'recency' or 'vector'.
   */
  protected function clusteringRetrieval(): string {
    return $this->configFactory->get('maemgaba_core.settings')->get('clustering_retrieval') ?? 'recency';
  }

  /**
   * The active consensus trigger mode (ConsensusService::MODE_*).
   */
  protected function consensusMode(): string {
    return $this->configFactory->get('maemgaba_core.settings')->get('consensus_mode') ?? ConsensusService::MODE_LAZY_TOP5;
  }

  /**
   * First consensus synthesis at ingest time (consensus_mode: eager).
   *
   * Takes the same per-event lock as ConsensusController so a visitor-
   * triggered synthesis and this one never run concurrently. If the lock is
   * held, someone else is already synthesising this event — skip.
   */
  protected function synthesizeEagerly(NodeInterface $event): void {
    $lockName = ConsensusService::lockName((int) $event->id());
    if (!$this->lock->acquire($lockName, ConsensusService::LOCK_TIMEOUT)) {
      return;
    }
    try {
      $this->consensus->recalculate($event);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Indexes one event synchronously.
   *
   * Rather than waiting for the deferred post-request indexing Search API
   * normally does.
   *
   * Best-effort: a failure here just means this event stays invisible to
   * vector retrieval until the next cron/index run (same as the pre-Step-3
   * behavior), so it's logged and swallowed rather than failing the batch.
   */
  protected function indexEventImmediately(EntityInterface $eventNode): void {
    try {
      $indexId = (string) ($this->configFactory->get('maemgaba_core.settings')->get('events_index') ?: EventSearch::DEFAULT_INDEX_ID);
      $index = $this->entityTypeManager->getStorage('search_api_index')->load($indexId);
      if (!$index) {
        return;
      }
      $item_id = 'entity:node/' . $eventNode->id() . ':' . $eventNode->language()->getId();
      $index->indexSpecificItems([$item_id => EntityAdapter::createFromEntity($eventNode)]);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('maemgaba_core')->warning(
        'Could not immediately index event @id for vector clustering: @msg',
        ['@id' => $eventNode->id(), '@msg' => $e->getMessage()]
      );
    }
  }

  /**
   * Resolves (creating if needed) the 'sources' term id for an outlet name.
   */
  protected function resolveSourceTerm(string $name): ?int {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties(['name' => $name, 'vid' => 'sources']);
    if ($terms) {
      return (int) reset($terms)->id();
    }
    $term = $storage->create(['name' => $name, 'vid' => 'sources']);
    $term->save();
    return (int) $term->id();
  }

  /**
   * Formats a score for log lines: "+1", "-2", "0", "?" when unscored.
   */
  protected function scoreLabel(?int $score): string {
    return $score === NULL ? '?' : ($score > 0 ? '+' . $score : (string) $score);
  }

  /**
   * Returns $value if it is in $allowed, otherwise $fallback.
   */
  protected function whitelist(string $value, array $allowed, string $fallback): string {
    return in_array($value, $allowed, TRUE) ? $value : $fallback;
  }

}

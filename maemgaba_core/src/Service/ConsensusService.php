<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\maemgaba_core\AiAnalyzerService;
use Drupal\maemgaba_core\BiasScore;
use Drupal\maemgaba_core\CardRole;
use Drupal\maemgaba_core\TextStats;
use Drupal\node\NodeInterface;

/**
 * Synthesises an event's consensus and disputed points from its cards.
 *
 * A per-event AI pass (distinct from the per-card relevance/bias classifier):
 * it reads how every source framed the same fact and stores what they agree
 * on (field_common_points) separately from what they contest
 * (field_disputed_points). The event detail template renders both.
 *
 * The synthesis needs at least two distinct outlets to be meaningful, and is
 * idempotent by default — recalculate() only calls the model when the event
 * has no consensus yet. Pass $force to refresh an event whose coverage has
 * since grown (see QueueProcessor, which does this on ingest for events that
 * already have consensus). When first synthesis happens is set by
 * maemgaba_core.settings:consensus_mode — 'lazy_top5' (default): on demand by
 * ConsensusController when a visitor opens an eligible event page; 'eager':
 * by QueueProcessor at ingest, once the event reaches MIN_SOURCES outlets.
 *
 * With maemgaba_core.settings:overlap_guard.enabled, output that copies a run
 * of words from any source (VerbatimOverlapGuard, texts from
 * SourceTextStore) is rejected whole and nothing is saved. With
 * synthesis_neutral_summary, the synthesis also replaces the event's neutral
 * summary (written from one article at event creation) with an event-level
 * one drawn from all sources, capped at neutral_summary_max_words.
 *
 * Only perspectives (CardRole) are synthesised and counted towards
 * MIN_SOURCES: reprints repeat another card and opinion is not coverage.
 * Their headlines and texts still feed the overlap guard.
 */
class ConsensusService {

  /**
   * Minimum distinct outlets before a consensus synthesis is worthwhile.
   */
  protected const MIN_SOURCES = 2;

  /**
   * Consensus mode: first synthesis on visitor request, home top-5 only.
   */
  public const MODE_LAZY_TOP5 = 'lazy_top5';

  /**
   * Consensus mode: first synthesis at ingest, once MIN_SOURCES is reached.
   */
  public const MODE_EAGER = 'eager';

  /**
   * Seconds a per-event synthesis lock is held before it expires.
   */
  public const LOCK_TIMEOUT = 30;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiAnalyzerService $aiAnalyzer,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected SpectrumService $spectrum,
    protected ConfigFactoryInterface $configFactory,
    protected VerbatimOverlapGuard $overlapGuard,
    protected SourceTextStore $sourceTexts,
  ) {}

  /**
   * Synthesises and saves consensus/disputed points for a single event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param bool $force
   *   Recompute even if the event already has consensus stored.
   *
   * @return bool
   *   TRUE if the event was (re)synthesised and saved, FALSE if it was skipped
   *   (wrong bundle, already done, too little coverage, or an AI failure).
   */
  public function recalculate(NodeInterface $event, bool $force = FALSE): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }

    // Idempotent fast path: leave an already-synthesised event untouched.
    if (!$force && $this->hasConsensus($event)) {
      return FALSE;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $cardIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'card')
      ->condition('field_parent_event', $event->id())
      ->condition('status', 1)
      ->sort('created', 'ASC')
      ->execute();

    if (!$cardIds) {
      return FALSE;
    }

    $fragments = (array) $this->configFactory->get('maemgaba_core.locale')->get('prompt_fragments');
    $lines = [];
    $outlets = [];
    $titles = [];
    foreach ($storage->loadMultiple($cardIds) as $card) {
      $titles[] = $card->getTitle();
      if (!CardRole::isPerspective($card)) {
        continue;
      }
      // The site's display bucket label (e.g. "Esquerda"), so the model sees
      // the same spectrum vocabulary the readers do.
      $bucket = $this->spectrum->bucketFor(BiasScore::normalize($card->get('field_bias_score')->value));
      $biasLabel = $bucket['label'] ?? (string) ($fragments['unscored_bias'] ?? '?');

      $source = (string) ($fragments['unknown_source'] ?? '?');
      if ($term = $card->get('field_source')->entity) {
        $source = $term->label();
        $outlets[$term->id()] = TRUE;
      }

      $summary = trim(strip_tags((string) $card->get('field_micro_summary')->value));
      if ($summary === '') {
        $summary = $card->getTitle();
      }

      $lines[] = "{$biasLabel} · {$source} · {$summary}";
    }

    // Need more than one voice for "consensus vs dispute" to mean anything.
    if (count($outlets) < self::MIN_SOURCES) {
      return FALSE;
    }

    $neutralSummary = trim(strip_tags((string) $event->get('field_neutral_summary')->value));
    $result = $this->aiAnalyzer->synthesizeConsensus(
      $event->getTitle(),
      $neutralSummary,
      implode("\n", $lines),
      ['type' => 'event', 'id' => $event->id(), 'label' => $event->getTitle()],
    );

    $common = $this->cleanPoints($result['common_points'] ?? []);
    $disputed = $this->cleanPoints($result['disputed_points'] ?? []);
    $newSummary = '';
    if ($this->configFactory->get('maemgaba_core.settings')->get('synthesis_neutral_summary')) {
      $newSummary = trim(strip_tags((string) ($result['neutral_summary'] ?? '')));
      $max = (int) $this->configFactory->get('maemgaba_core.settings')->get('neutral_summary_max_words');
      $newSummary = $max > 0 ? TextStats::capWords($newSummary, $max) : $newSummary;
    }

    // The model gave us nothing usable: don't overwrite existing data with [].
    if (!$common && !$disputed) {
      return FALSE;
    }

    if ($this->overlapGuard->enabled()) {
      $run = $this->overlapGuard->check(
        array_merge($common, $disputed, [$newSummary]),
        array_merge(array_values($this->sourceTexts->forEvent((int) $event->id())), $titles),
      );
      if ($run !== NULL) {
        $this->loggerFactory->get('maemgaba_ai')->warning(
          'Consensus for event @id rejected by the verbatim-overlap guard: "@run" appears in a source.',
          ['@id' => $event->id(), '@run' => $run],
        );
        return FALSE;
      }
    }

    // Same defensive guard as hasConsensus() above: never let a transient
    // field-definition inconsistency crash the caller (e.g. the whole
    // maemgaba:process batch) — skip this event's synthesis instead.
    if (!$event->hasField('field_common_points') || !$event->hasField('field_disputed_points')) {
      $this->loggerFactory->get('maemgaba_ai')->warning(
        'Consensus fields unavailable on event @id — skipping save.',
        ['@id' => $event->id()],
      );
      return FALSE;
    }

    $event->set('field_common_points', $common);
    $event->set('field_disputed_points', $disputed);
    if ($newSummary !== '') {
      $event->set('field_neutral_summary', ['value' => $newSummary, 'format' => 'basic_html']);
    }
    $event->save();

    $this->loggerFactory->get('maemgaba_ai')->info(
      'Consensus synthesized for event @id (@c common, @d disputed).',
      ['@id' => $event->id(), '@c' => count($common), '@d' => count($disputed)],
    );

    return TRUE;
  }

  /**
   * Synthesises consensus for an event by id.
   *
   * @param int|string $event_id
   *   The event node id.
   * @param bool $force
   *   Recompute even if the event already has consensus stored.
   *
   * @return bool
   *   TRUE if the event was (re)synthesised and saved.
   */
  public function recalculateById(int|string $event_id, bool $force = FALSE): bool {
    $event = $this->entityTypeManager->getStorage('node')->load($event_id);
    return $event instanceof NodeInterface ? $this->recalculate($event, $force) : FALSE;
  }

  /**
   * Name of the per-event lock guarding a first synthesis.
   *
   * Shared by ConsensusController (lazy mode) and QueueProcessor (eager
   * mode) so the two triggers never synthesise the same event concurrently.
   */
  public static function lockName(int $event_id): string {
    return 'consensus_synth_' . $event_id;
  }

  /**
   * Whether the event already has at least one stored consensus point.
   *
   * Guards with hasField() rather than assuming the fields are always
   * present: a transient field-definition inconsistency here (observed
   * 2026-08-20 mid a 120-item `maemgaba:process` run, correlated with
   * Search API's synchronous 'index_directly' indexing) must never crash
   * the whole batch — fail soft ("no consensus yet") like everywhere else
   * AI/field access can go wrong in this pipeline.
   */
  public function hasConsensus(NodeInterface $event): bool {
    if (!$event->hasField('field_common_points') || !$event->hasField('field_disputed_points')) {
      return FALSE;
    }
    return !$event->get('field_common_points')->isEmpty()
      || !$event->get('field_disputed_points')->isEmpty();
  }

  /**
   * Normalises a model point list into clean, non-empty, plain strings.
   *
   * @param mixed $points
   *   The raw value from the decoded model response.
   *
   * @return array
   *   Trimmed, tag-stripped, de-duplicated non-empty strings.
   */
  protected function cleanPoints(mixed $points): array {
    if (!is_array($points)) {
      return [];
    }
    $clean = [];
    foreach ($points as $point) {
      if (!is_string($point)) {
        continue;
      }
      $point = trim(strip_tags($point));
      if ($point !== '') {
        $clean[$point] = $point;
      }
    }
    return array_values($clean);
  }

}

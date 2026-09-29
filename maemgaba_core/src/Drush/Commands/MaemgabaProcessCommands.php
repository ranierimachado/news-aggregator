<?php

namespace Drupal\maemgaba_core\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\maemgaba_core\Service\ConsensusService;
use Drupal\maemgaba_core\Service\EventRelevanceService;
use Drupal\maemgaba_core\Service\QueueProcessor;
use Drupal\maemgaba_core\Service\StatsService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for News Engine AI processing.
 *
 * The business logic lives in QueueProcessor and EventRelevanceService; these
 * commands only invoke the services and present the result on the CLI.
 */
class MaemgabaProcessCommands extends DrushCommands {

  /**
   * How many of the most recent successful calls feed the ETA estimate.
   */
  protected const ETA_SAMPLE_SIZE = 30;

  public function __construct(
    protected QueueProcessor $queueProcessor,
    protected EventRelevanceService $eventRelevance,
    protected ConsensusService $consensus,
    protected StatsService $stats,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Dependency Injection factory compatible with Drush 13.
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('maemgaba_core.queue_processor'),
      $container->get('maemgaba_core.event_relevance'),
      $container->get('maemgaba_core.consensus'),
      $container->get('maemgaba_core.stats'),
      $container->get('config.factory'),
    );
  }

  /**
   * Consumes the inbound_queue; Gemini generates the ideological clustering.
   *
   * Shows a live progress bar with an ETA. The ETA has two sources: a
   * pre-run baseline from the median latency of each routed operation's
   * recent successful calls (maemgaba_ai_call_log), refined as this run's
   * own items complete — see estimateSecondsPerItem() and the median() used
   * once real per-item timings are available. Median, not mean, because
   * this pipeline's call latency has documented outliers (Ollama VRAM/
   * model-swap spikes) that would badly skew a plain average — see
   * StatsService::recentLatencyMedianMs().
   */
  #[CLI\Command(name: 'maemgaba:process', aliases: ['mg-process'])]
  #[CLI\Option(name: 'limit', description: 'How many queue items to process in this run.')]
  #[CLI\Option(name: 'newest-first', description: 'Consume the queue newest-to-oldest instead of the default oldest-to-newest.')]
  public function processQueue(array $options = ['limit' => QueueProcessor::DEFAULT_BATCH, 'newest-first' => FALSE]) {
    $limit = (int) $options['limit'];
    $queueDepth = $this->stats->queueDepth();

    if ($queueDepth === 0) {
      $this->output()->writeln('The inbound queue is empty.');
      return;
    }

    $total = min($limit, $queueDepth);
    $io = $this->io();

    $baselineSeconds = $this->estimateSecondsPerItem();
    $io->writeln(sprintf(
      '⚡ Starting processing of %d item(s)%s...',
      $total,
      $baselineSeconds !== NULL
        ? sprintf(' (initial estimate ~%s, %.1fs/item from the median of recent calls)', $this->formatDuration($baselineSeconds * $total), $baselineSeconds)
        : '',
    ));

    // The bar's own line must stay a fixed, bounded width: Symfony's
    // ProgressBar auto-shrinks %bar% whenever a rendered line overflows the
    // terminal, and that shrink is cumulative (it permanently reduces the
    // stored bar width on every overflow, compounding call after call —
    // see Helper\ProgressBar::buildLine()). Article titles routinely
    // overflow a terminal, so they must never be part of the bar's own
    // format; they're written as a separate line via clear()/writeln()
    // instead, which is the pattern Symfony documents for interleaving
    // arbitrary-width messages with a progress bar. %elapsed% is also
    // replaced with our own compact formatDuration() for the same
    // bounded-width reason (its built-in "X hrs, Y mins, Z secs" format
    // grows unbounded on multi-hour overnight runs).
    $bar = $io->createProgressBar($total);
    $bar->setBarWidth(20);
    $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  elapsed %elapsedc:-8s%  ETA %eta:-8s%');
    $bar->setMessage('0s', 'elapsedc');
    $bar->setMessage($baselineSeconds !== NULL ? $this->formatDuration($baselineSeconds * $total) : '—', 'eta');
    $bar->start();

    $durations = [];
    $startTime = microtime(TRUE);
    $output = $this->output();
    $onItem = function (array $event) use ($bar, $output, &$durations, $total, $startTime): void {
      $durations[] = $event['duration_ms'] / 1000;

      $remaining = $total - count($durations);
      $median = $this->median($durations);
      $eta = ($remaining > 0 && $median !== NULL) ? $this->formatDuration($median * $remaining) : '0s';
      $bar->setMessage($eta, 'eta');
      $bar->setMessage($this->formatDuration(microtime(TRUE) - $startTime), 'elapsedc');

      $icon = match ($event['outcome']) {
        'created' => '✅',
        'duplicate' => '⏭️',
        'syndicated' => '🔁',
        'ai_failure' => '⚠️',
        default => 'ℹ️',
      };

      $bar->clear();
      $output->writeln('  ' . $icon . ' ' . mb_substr($event['title'], 0, 100));
      $bar->advance();
    };

    $summary = $this->queueProcessor->processBatch($limit, (bool) $options['newest-first'], $onItem);
    $bar->finish();
    $io->newLine(2);

    foreach ($summary['messages'] as $line) {
      $this->output()->writeln('  ' . $line);
    }
    $this->output()->writeln(
      "🚀 Done: {$summary['processed']} processed, " .
      "{$summary['events_created']} new event(s), {$summary['skipped']} skipped, " .
      "{$summary['duplicates']} duplicate(s) removed, " .
      ($summary['syndicated'] ?? 0) . " reprint(s) linked without an AI call."
    );
  }

  /**
   * Pre-run per-item ETA baseline, from recent measured latency.
   *
   * Sums the median latency of 'classify_cluster' with 'classify_bias' when
   * the composite refinement pass is routed (AiAnalyzerService::
   * refineBiasTopic() fires a second call in that case) — see
   * maemgaba_core.settings:operation_models.
   *
   * @return float|null
   *   Estimated seconds per queue item, or NULL if there is no recent call
   *   history to estimate from yet (e.g. a brand new environment).
   */
  protected function estimateSecondsPerItem(): ?float {
    $primaryMs = $this->stats->recentLatencyMedianMs('classify_cluster', self::ETA_SAMPLE_SIZE);
    if ($primaryMs === NULL) {
      return NULL;
    }

    $route = $this->configFactory->get('maemgaba_core.settings')->get('operation_models.classify_bias');
    $refinementMs = 0.0;
    if (!empty($route['provider']) && !empty($route['model'])) {
      $refinementMs = $this->stats->recentLatencyMedianMs('classify_bias', self::ETA_SAMPLE_SIZE) ?? 0.0;
    }

    return ($primaryMs + $refinementMs) / 1000;
  }

  /**
   * Median of a list of floats.
   */
  protected function median(array $values): ?float {
    if (!$values) {
      return NULL;
    }
    sort($values);
    $count = count($values);
    $mid = intdiv($count, 2);
    return $count % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
  }

  /**
   * Formats a duration in seconds as a compact human-readable string.
   */
  protected function formatDuration(float $seconds): string {
    $seconds = max(0, (int) round($seconds));
    if ($seconds < 60) {
      return "{$seconds}s";
    }
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
      return sprintf('%dm%02ds', $minutes, $seconds % 60);
    }
    $hours = intdiv($minutes, 60);
    return sprintf('%dh%02dm', $hours, $minutes % 60);
  }

  /**
   * Backfills social relevance / topic / justification on existing cards.
   */
  #[CLI\Command(name: 'maemgaba:backfill-relevance', aliases: ['mg-backfill'])]
  #[CLI\Option(name: 'limit', description: 'How many cards to process in this run.')]
  public function backfillRelevance(array $options = ['limit' => 25]) {
    $summary = $this->queueProcessor->backfillRelevance((int) $options['limit']);
    foreach ($summary['messages'] as $line) {
      $this->output()->writeln('  ' . $line);
    }
    $this->output()->writeln(
      "✅ {$summary['classified']} classified, {$summary['failed']} failed, " .
      "{$summary['remaining']} left."
    );
  }

  /**
   * Re-reviews bias/topic on already-published cards (second-layer audit).
   */
  #[CLI\Command(name: 'maemgaba:review-cards', aliases: ['mg-review'])]
  #[CLI\Option(name: 'limit', description: 'How many cards to review in this run.')]
  public function reviewCards(array $options = ['limit' => QueueProcessor::DEFAULT_REVIEW_BATCH]) {
    $this->output()->writeln('🔎 Reviewing processed cards...');

    $summary = $this->queueProcessor->reviewCards((int) $options['limit']);
    foreach ($summary['messages'] as $line) {
      $this->output()->writeln('  ' . $line);
    }
    $this->output()->writeln(
      "✅ {$summary['reviewed']} reviewed: {$summary['corrected']} corrected, " .
      "{$summary['confirmed']} confirmed, {$summary['failed']} failed, " .
      "{$summary['remaining']} left."
    );
  }

  /**
   * Recalculates relevance and topic of all events from their cards.
   */
  #[CLI\Command(name: 'maemgaba:rollup-events', aliases: ['mg-rollup'])]
  public function rollupEvents() {
    $this->output()->writeln('🔁 Recalculating event relevance...');
    $count = $this->eventRelevance->recalculateAll();
    $this->output()->writeln("✅ Rollup finished for {$count} event(s).");
  }

  /**
   * Synthesizes common ground / points of dispute for the given events.
   *
   * Manual override: consensus is now synthesized on demand when a visitor
   * opens the page of one of the 5 featured events on the home page (see
   * ConsensusController). This command exists to force/debug specific events
   * and always re-synthesizes the given ids.
   */
  #[CLI\Command(name: 'maemgaba:consensus', aliases: ['mg-consensus'])]
  #[CLI\Argument(name: 'nids', description: 'Event node ids to synthesize, separated by spaces.')]
  public function consensus(array $nids) {
    if (!$nids) {
      throw new \InvalidArgumentException('Give at least one event node id, e.g. drush maemgaba:consensus 402 519');
    }

    $this->output()->writeln('🧭 Synthesizing event consensus...');
    foreach ($nids as $nid) {
      $ok = $this->consensus->recalculateById((int) $nid, TRUE);
      $this->output()->writeln($ok
        ? "✅ Event {$nid} synthesized."
        : "⏭️  Event {$nid} skipped (fewer than 2 outlets, or the AI returned no points).");
    }
  }

}

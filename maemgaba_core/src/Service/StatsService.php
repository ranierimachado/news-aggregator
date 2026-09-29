<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Read-only reporting queries for the News Engine.
 */
class StatsService {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Number of items waiting in the inbound queue.
   */
  public function queueDepth(): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'inbound_queue')
      ->count()
      ->execute();
  }

  /**
   * Total node count for a bundle.
   */
  public function contentCount(string $bundle): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->count()
      ->execute();
  }

  /**
   * Number of published, actively-testing feed sources (what harvest reads).
   */
  public function activeFeedCount(): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'feed_source')
      ->condition('status', 1)
      ->condition('field_active_testing', 1)
      ->count()
      ->execute();
  }

  /**
   * Number of cards that have not been classified for relevance yet.
   */
  public function unratedCardCount(): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'card')
      ->notExists('field_social_relevance')
      ->count()
      ->execute();
  }

  /**
   * Number of cards that have not gone through the second-layer review yet.
   *
   * Scoped by maemgaba_core.settings:review_since when set — see
   * QueueProcessor::reviewSinceTimestamp() — so this matches what
   * `maemgaba:review-cards` will actually pick up, not a stale pre-cutoff
   * backlog nobody intends to review.
   */
  public function unreviewedCardCount(): int {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'card')
      ->notExists('field_reviewed_at');
    $since = (int) ($this->configFactory->get('maemgaba_core.settings')->get('review_since') ?? 0);
    if ($since > 0) {
      $query->condition('created', $since, '>=');
    }
    return (int) $query->count()->execute();
  }

  /**
   * The active review_since cutoff, or NULL if none is set.
   */
  public function reviewSince(): ?int {
    $value = (int) ($this->configFactory->get('maemgaba_core.settings')->get('review_since') ?? 0);
    return $value > 0 ? $value : NULL;
  }

  /**
   * Median latency of an operation's recent successful AI calls.
   *
   * Source for pre-run ETA estimates (e.g. `maemgaba:process`'s progress
   * bar): median rather than mean, since AI call latency for this pipeline
   * has documented outliers (Ollama VRAM/model-swap spikes of 300s+ on an
   * otherwise ~8-20s/call operation — see 99-metrics-log.md, 2026-08-20)
   * that would badly skew a plain average.
   *
   * @param string $operation
   *   The maemgaba_prompt operation key (e.g. 'classify_cluster').
   * @param int $sample
   *   How many of the most recent successful calls to sample.
   *
   * @return float|null
   *   Median latency in milliseconds, or NULL if there is no recent data.
   */
  public function recentLatencyMedianMs(string $operation, int $sample = 30): ?float {
    $values = $this->database->select('maemgaba_ai_call_log', 'l')
      ->fields('l', ['latency_ms'])
      ->condition('operation', $operation)
      ->condition('success', 1)
      ->orderBy('created', 'DESC')
      ->range(0, $sample)
      ->execute()
      ->fetchCol();

    if (!$values) {
      return NULL;
    }
    $values = array_map('intval', $values);
    sort($values);
    $count = count($values);
    $mid = intdiv($count, 2);
    return $count % 2
      ? (float) $values[$mid]
      : ($values[$mid - 1] + $values[$mid]) / 2;
  }

  /**
   * Relevance breakdown for a bundle.
   *
   * @param string $bundle
   *   Either 'card' or 'event'.
   *
   * @return array
   *   ['relevant' => int, 'maybe' => int, 'irrelevant' => int].
   */
  public function relevanceBreakdown(string $bundle): array {
    $out = ['relevant' => 0, 'maybe' => 0, 'irrelevant' => 0];
    $query = $this->database->select('node__field_social_relevance', 'r');
    $query->join('node_field_data', 'n', 'n.nid = r.entity_id');
    $query->addField('r', 'field_social_relevance_value', 'v');
    $query->addExpression('COUNT(*)', 'c');
    $query->condition('n.type', $bundle);
    $query->groupBy('r.field_social_relevance_value');
    foreach ($query->execute() as $row) {
      if (isset($out[$row->v])) {
        $out[$row->v] = (int) $row->c;
      }
    }
    return $out;
  }

  /**
   * Every event with the number of cards clustered under it.
   *
   * @return array
   *   List of ['nid' => int, 'title' => string, 'card_count' => int],
   *   ordered by card count descending.
   */
  public function eventCardCounts(): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid', 'title']);
    // LEFT JOIN so events with zero cards still appear.
    $query->leftJoin('node__field_parent_event', 'f', 'f.field_parent_event_target_id = n.nid');
    $query->addExpression('COUNT(f.entity_id)', 'card_count');
    $query->condition('n.type', 'event');
    $query->groupBy('n.nid');
    $query->groupBy('n.title');
    $query->orderBy('card_count', 'DESC');

    $rows = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $rows[] = [
        'nid' => (int) $row->nid,
        'title' => $row->title,
        'card_count' => (int) $row->card_count,
      ];
    }
    return $rows;
  }

}

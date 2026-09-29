<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Finds candidate events for clustering via vector similarity.
 *
 * Replaces QueueProcessor's recency-window ("events created in the last
 * 24h") candidate selection with a true retrieval step: embed the incoming
 * article, query the events Search API index
 * (maemgaba_core.settings:events_index; MariaDB VDB backend
 * — the primary engine; Milvus is a comparison/learning instance only, not
 * used in production business logic), and return the top-k events above a
 * similarity floor. This is what lets a slow-burning story older than 24h
 * still match its existing event instead of spawning a duplicate.
 *
 * Fails open like the rest of the pipeline: any embedding/query failure is
 * logged and returns an empty candidate list rather than throwing, so a
 * transient AI outage degrades to "no match found" (a new event gets
 * created) instead of crashing the whole queue batch.
 */
class EventCandidateFinder {

  /**
   * Default cosine-distance ceiling for a candidate to be considered.
   *
   * Used when maemgaba_core.settings:clustering_max_distance is unset.
   * Lower distance = more similar. Picked from Phase 2 Step 1 testing at
   * 3072-d: same-story content landed around 0.20-0.30 distance, unrelated
   * content around 0.50+. Re-checked at 768-d in P14 with an A/B run on a
   * production corpus.
   */
  public const DEFAULT_MAX_DISTANCE = 0.35;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiProviderPluginManager $aiProvider,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected AiCallLogger $callLogger,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The configured cosine-distance ceiling.
   */
  public function maxDistance(): float {
    $value = $this->configFactory->get('maemgaba_core.settings')->get('clustering_max_distance');
    return is_numeric($value) ? (float) $value : self::DEFAULT_MAX_DISTANCE;
  }

  /**
   * Finds the top-k candidate events most similar to an article's text.
   *
   * @param string $articleText
   *   The cleaned article body to embed and match against.
   * @param int $k
   *   Maximum number of candidates to return.
   * @param array $context
   *   Optional business-context attribution for the call log — same shape
   *   as AiAnalyzerService::classifyAndCluster()'s $context: 'type', 'id',
   *   'label'.
   *
   * @return array
   *   Map of event node id => ['title' => string, 'similarity' => float],
   *   ordered most-similar first. Empty if nothing clears the similarity
   *   floor, the index is unavailable, or the embedding call failed.
   */
  public function findCandidates(string $articleText, int $k = 8, array $context = []): array {
    $logger = $this->loggerFactory->get('maemgaba_ai');

    $indexId = (string) ($this->configFactory->get('maemgaba_core.settings')->get('events_index') ?: EventSearch::DEFAULT_INDEX_ID);
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($indexId);
    if (!$index) {
      $logger->error('EventCandidateFinder: index @id not found.', ['@id' => $indexId]);
      return [];
    }

    $call_group = bin2hex(random_bytes(12));
    $attempt_start = microtime(TRUE);
    $default = $this->aiProvider->getDefaultProviderForOperationType('embeddings');

    try {
      $query = $index->query();
      // Verbatim text, not parsed terms: see EventSearch::useDirectParseMode().
      EventSearch::useDirectParseMode($query);
      $query->keys($articleText);
      $query->range(0, $k);
      // Lower distance = more similar for our cosine metric — see the
      // usort()-drops-keys gotcha recorded in the phase doc, worked around
      // below by building ids from $item->getId(), not array keys.
      $query->sort('search_api_relevance', 'ASC');
      $result_set = $query->execute();

      $latency_ms = (int) round((microtime(TRUE) - $attempt_start) * 1000);
      $this->callLogger->log([
        'operation' => 'embed_candidates',
        'provider' => $default['provider_id'] ?? '',
        'model' => $default['model_id'] ?? '',
        'call_group' => $call_group,
        'attempt' => 1,
        'latency_ms' => $latency_ms,
        'success' => TRUE,
      ] + $this->contextFields($context));
    }
    catch (\Throwable $e) {
      $latency_ms = (int) round((microtime(TRUE) - $attempt_start) * 1000);
      $this->callLogger->log([
        'operation' => 'embed_candidates',
        'provider' => $default['provider_id'] ?? '',
        'model' => $default['model_id'] ?? '',
        'call_group' => $call_group,
        'attempt' => 1,
        'latency_ms' => $latency_ms,
        'error_message' => $e->getMessage(),
      ] + $this->contextFields($context));
      $logger->error('EventCandidateFinder: candidate query failed: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }

    $result_items = $result_set->getResultItems();
    if (!$result_items) {
      return [];
    }

    $item_ids = array_map(static fn ($item) => $item->getId(), $result_items);
    $entities = $index->loadItemsMultiple($item_ids);

    $ceiling = $this->maxDistance();
    $candidates = [];
    foreach ($result_items as $item) {
      $distance = (float) $item->getScore();
      if ($distance > $ceiling) {
        // Sorted ascending, so nothing after this is closer either.
        break;
      }
      $adapter = $entities[$item->getId()] ?? NULL;
      $entity = $adapter?->getValue();
      if (!$entity) {
        continue;
      }
      $candidates[(int) $entity->id()] = [
        'title' => $entity->label(),
        'similarity' => round(1 - $distance, 4),
      ];
    }

    return $candidates;
  }

  /**
   * Maps a caller's context array onto the call-log column names.
   *
   * Mirrors AiAnalyzerService::contextFields() — duplicated rather than
   * shared because the two classes have no common base and this is three
   * lines; not worth an abstraction for that.
   */
  protected function contextFields(array $context): array {
    return [
      'context_type' => $context['type'] ?? '',
      'context_id' => $context['id'] ?? NULL,
      'context_label' => $context['label'] ?? '',
    ];
  }

}

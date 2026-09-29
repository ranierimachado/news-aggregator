<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;

/**
 * Semantic search over events: one place for the query the page runs.
 *
 * SearchController (the /search page) and maemgaba:search-eval both call
 * this, so the evaluation measures exactly what a visitor gets: the same
 * index, the same sort, the same distance ceiling.
 *
 * The index is the ai_search backend over MariaDB native vectors. Its score
 * is a cosine DISTANCE (VEC_DISTANCE_COSINE): lower is more similar, so
 * results are sorted ascending and cut at the first one above the ceiling.
 */
class EventSearch {

  /**
   * Events index id used when maemgaba_core.settings:events_index is empty.
   */
  public const DEFAULT_INDEX_ID = 'events';

  /**
   * Default distance ceiling when maemgaba_core.settings has none.
   *
   * Hand-tuned on 2026-07-30 against a 3072-d production corpus.
   */
  public const DEFAULT_MAX_DISTANCE = 0.32;

  /**
   * Raw results fetched from the index before the ceiling is applied.
   */
  public const FETCH_LIMIT = 100;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
  ) {}

  /**
   * The id of the Search API index of events (MariaDB vector backend).
   *
   * A site names its own index; maemgaba_core.settings:events_index points
   * the engine at it. Empty means the default id.
   */
  public function indexId(): string {
    return (string) ($this->configFactory->get('maemgaba_core.settings')->get('events_index') ?: self::DEFAULT_INDEX_ID);
  }

  /**
   * The search index, or NULL when it is missing or disabled.
   */
  public function index(): ?IndexInterface {
    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($this->indexId());
    return ($index && $index->status()) ? $index : NULL;
  }

  /**
   * The configured distance ceiling for a result to be shown.
   */
  public function maxDistance(): float {
    $value = $this->configFactory->get('maemgaba_core.settings')->get('search_max_distance');
    return is_numeric($value) ? (float) $value : self::DEFAULT_MAX_DISTANCE;
  }

  /**
   * Runs a semantic query.
   *
   * @param string $query
   *   The visitor's text. Embedded once by the backend.
   * @param int $limit
   *   How many raw results to fetch before the ceiling.
   * @param float|null $maxDistance
   *   Ceiling override; NULL = maxDistance(). Pass INF to get the raw ranking
   *   (the eval does, to report where the expected event actually ranked).
   *
   * @return array<int, array{item_id: string, nid: int, distance: float}>
   *   Matches, closest first.
   *
   * @throws \RuntimeException
   *   When the index is unavailable.
   * @throws \Drupal\search_api\SearchApiException
   *   When the backend fails (embedding call, SQL).
   */
  public function search(string $query, int $limit = self::FETCH_LIMIT, ?float $maxDistance = NULL): array {
    $index = $this->index();
    if ($index === NULL) {
      throw new \RuntimeException(sprintf('Search index %s is missing or disabled.', $this->indexId()));
    }
    $ceiling = $maxDistance ?? $this->maxDistance();

    $search_query = $index->query();
    self::useDirectParseMode($search_query);
    $search_query->keys($query);
    $search_query->range(0, $limit);
    // Ascending: the score is a distance. Build ids from getId(), not array
    // keys (the backend's usort() drops keys; see the phase-2 notes).
    $search_query->sort('search_api_relevance', 'ASC');
    $result_set = $search_query->execute();

    $matches = [];
    $seen = [];
    foreach ($result_set->getResultItems() as $item) {
      $distance = (float) $item->getScore();
      if ($distance > $ceiling) {
        break;
      }
      $nid = self::nidFromItemId($item->getId());
      // One row per chunk: keep each event's closest chunk only.
      if ($nid === NULL || isset($seen[$nid])) {
        continue;
      }
      $seen[$nid] = TRUE;
      $matches[] = [
        'item_id' => $item->getId(),
        'nid' => $nid,
        'distance' => $distance,
      ];
    }
    return $matches;
  }

  /**
   * Published events whose title or neutral summary contain every word.
   *
   * The plain keyword baseline: what a LIKE search that requires all query
   * words (three letters or more) would return. Used for the search log's
   * keyword_would_be_zero flag and by the eval. It is not shown to anyone.
   *
   * @return int[]
   *   Matching event node ids (unordered; LIKE has no ranking).
   */
  public function keywordMatches(string $query, int $limit = 1000): array {
    $words = self::keywordTerms($query);
    if (!$words) {
      return [];
    }

    $select = $this->database->select('node_field_data', 'e');
    $select->fields('e', ['nid']);
    $select->condition('e.type', 'event');
    $select->condition('e.status', 1);
    $select->leftJoin('node__field_neutral_summary', 's', 's.entity_id = e.nid');
    foreach ($words as $word) {
      $like = '%' . $this->database->escapeLike($word) . '%';
      $select->condition($select->orConditionGroup()
        ->condition('e.title', $like, 'LIKE')
        ->condition('s.field_neutral_summary_value', $like, 'LIKE'));
    }
    $select->distinct();
    $select->range(0, $limit);
    return array_map('intval', $select->execute()->fetchCol());
  }

  /**
   * The words the keyword baseline requires: lowercased, 3+ characters.
   *
   * @return string[]
   *   Unique terms, punctuation trimmed.
   */
  public static function keywordTerms(string $query): array {
    $words = preg_split('/\s+/u', mb_strtolower($query)) ?: [];
    $words = array_map(static fn (string $w): string => trim($w, " \t\n\r\0\x0B.,;:!?\"'()[]{}"), $words);
    return array_values(array_unique(array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 3)));
  }

  /**
   * Hands the text to the backend verbatim.
   *
   * Search API's default "terms" parse mode splits keys into terms, turns
   * quoted runs into nested phrase arrays and a leading "-" into a negation.
   * The ai_search backend then implode()s one level and embeds the literal
   * word "Array" for every nested group ("Array to string conversion"),
   * which article text full of quotes triggers constantly. An embedding
   * wants the original text, so both /search and vector clustering use the
   * "direct" parse mode.
   */
  public static function useDirectParseMode(QueryInterface $query): void {
    $query->setParseMode(\Drupal::service('plugin.manager.search_api.parse_mode')->createInstance('direct'));
  }

  /**
   * Node id from a Search API item id ("entity:node/123:en").
   */
  public static function nidFromItemId(string $item_id): ?int {
    return preg_match('#^entity:node/(\d+)(?::|$)#', $item_id, $m) ? (int) $m[1] : NULL;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Writes one row per /busca search to maemgaba_search_log.
 *
 * Purpose is narrow and specific: the Phase 2 resume metric is "how many
 * zero-result keyword searches would vector search have rescued" — this
 * table is what makes that number computable later, not a general search
 * analytics log.
 */
class SemanticSearchLogger {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Records one /busca search.
   *
   * @param string $query
   *   The search query text.
   * @param int $resultCount
   *   Vector-search results after the similarity floor.
   * @param float|null $topScore
   *   Similarity of the best match, NULL if no results.
   * @param bool $keywordWouldBeZero
   *   TRUE if a plain LIKE/fulltext search for the same terms would have
   *   returned nothing.
   */
  public function log(string $query, int $resultCount, ?float $topScore, bool $keywordWouldBeZero): void {
    $this->database->insert('maemgaba_search_log')->fields([
      'query' => mb_substr($query, 0, 255),
      'result_count' => $resultCount,
      'top_score' => $topScore,
      'keyword_would_be_zero' => $keywordWouldBeZero ? 1 : 0,
      'uid' => $this->currentUser->id() ?: NULL,
      'created' => $this->time->getCurrentTime(),
    ])->execute();
  }

}

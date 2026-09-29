<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ParseMode\ParseModePluginManager;

/**
 * Runs the /explore query the way the page does, for maemgaba:search-eval.
 *
 * Same index, same "terms" parse mode as the page's fulltext filter, same
 * relevance sort, and SolrHybridQuerySubscriber applies the mode. The eval
 * sets `maemgaba_search_nocache` so every query pays its real embedding call.
 */
class SolrEventSearch {

  public const DEFAULT_INDEX = 'solr_events';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected ?ParseModePluginManager $parseModes = NULL,
  ) {}

  /**
   * The configured Solr index, or NULL when missing or disabled.
   */
  public function index(): ?IndexInterface {
    $id = $this->configFactory->get('maemgaba_core.settings')->get('explore.index') ?: self::DEFAULT_INDEX;
    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($id);
    return ($index && $index->status()) ? $index : NULL;
  }

  /**
   * Searches in a mode.
   *
   * @return array<int, array{nid: int, score: float}>
   *   Results, best first.
   *
   * @throws \RuntimeException
   *   When the index is unavailable.
   */
  public function search(string $keys, string $mode, int $limit = 10): array {
    $index = $this->index();
    if ($index === NULL) {
      throw new \RuntimeException('The Solr search index is missing or disabled.');
    }
    $query = $index->query();
    if ($this->parseModes) {
      $query->setParseMode($this->parseModes->createInstance('terms'));
    }
    $query->keys($keys);
    $query->range(0, $limit);
    $query->sort('search_api_relevance', 'DESC');
    $query->setOption('maemgaba_search_mode', $mode);
    $query->setOption('maemgaba_search_nocache', TRUE);
    $results = [];
    foreach ($query->execute()->getResultItems() as $item) {
      $nid = EventSearch::nidFromItemId($item->getId());
      if ($nid !== NULL) {
        $results[] = ['nid' => $nid, 'score' => (float) $item->getScore()];
      }
    }
    return $results;
  }

}

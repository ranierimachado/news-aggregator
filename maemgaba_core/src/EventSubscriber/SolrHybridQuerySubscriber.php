<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\maemgaba_core\Search\SolrHybridQuery;
use Drupal\maemgaba_core\Service\QueryEmbedder;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Keyword, vector or hybrid ranking for Solr indexes with dense vectors.
 *
 * Search_api_solr_dense_vector (alpha9) indexes the vectors, and its only
 * query-time option is an index-wide "pure vector" ranker that replaces the
 * keyword query. Hybrid ranking and a per-request mode are what this adds;
 * the module's own ranker stays off (`enable_vector_rerank: false`) and its
 * indexing, field type and embedding settings are used as they are.
 *
 * The mode comes from the Search API query option `maemgaba_search_mode`
 * (maemgaba:search-eval sets it), else the request's `?mode=`, else
 * `maemgaba_core.settings:explore.default_mode` (hybrid). Keyword mode makes
 * no embedding call. If the embedding is refused by the flood limit or
 * fails, the query stays keyword-only and the page says so.
 *
 * Listens by event class name so the engine has no hard dependency on
 * search_api_solr (a site may not install it).
 */
class SolrHybridQuerySubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Event name: the PostConvertedQueryEvent class of search_api_solr.
   */
  public const EVENT = 'Drupal\search_api_solr\Event\PostConvertedQueryEvent';

  public function __construct(
    protected QueryEmbedder $embedder,
    protected ConfigFactoryInterface $configFactory,
    protected RequestStack $requestStack,
    protected MessengerInterface $messenger,
    protected KillSwitch $pageCacheKillSwitch,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the dense-vector module's own subscriber (priority 0).
    return [self::EVENT => ['onPostConvertQuery', -100]];
  }

  /**
   * Rewrites the Solr query for vector or hybrid mode.
   *
   * @param \Drupal\search_api_solr\Event\PostConvertedQueryEvent $event
   *   The event (untyped here: search_api_solr is optional).
   */
  public function onPostConvertQuery(object $event): void {
    /** @var \Drupal\search_api\Query\QueryInterface $query */
    $query = $event->getSearchApiQuery();
    $index = $query->getIndex();
    if (!$index->isValidProcessor('solr_densevector')) {
      return;
    }
    $dense = $index->getThirdPartySetting('search_api_solr', 'dense_vector', []);
    if (!empty($dense['enable_vector_rerank'])) {
      // The module's ranker is in charge on this index.
      return;
    }
    $processor = $index->getProcessor('solr_densevector')->getConfiguration();
    $keys = $query->getOriginalKeys();
    if (is_array($keys)) {
      $keys = implode(' ', array_filter($keys, 'is_string'));
    }
    $keys = trim((string) $keys);
    if ($keys === '' || empty($processor['ai_provider']) || empty($processor['ai_model_id'])) {
      return;
    }

    $mode = $this->mode($query);
    $query->setOption('maemgaba_search_mode_used', 'keyword');
    if ($mode === 'keyword') {
      return;
    }

    $vector = $this->embedder->embed($keys, $processor['ai_provider'], $processor['ai_model_id'], !$query->getOption('maemgaba_search_nocache'));
    if ($vector === NULL) {
      $this->pageCacheKillSwitch->trigger();
      if (PHP_SAPI !== 'cli') {
        $this->messenger->addWarning($this->embedder->wasLimited()
          ? $this->t('Too many searches from your connection: showing keyword matches only for a few minutes.')
          : $this->t('Meaning-based ranking is unavailable right now: showing keyword matches only.'));
      }
      return;
    }

    $field = $this->vectorField($index);
    if ($field === NULL) {
      return;
    }
    $settings = $this->configFactory->get('maemgaba_core.settings');
    $built = SolrHybridQuery::build(
      $mode,
      (string) $event->getSolariumQuery()->getQuery(),
      $vector,
      $field,
      (float) ($settings->get('explore.vector_weight') ?? 10.0),
      (float) ($settings->get('explore.min_similarity') ?? 0.8),
    );

    /** @var \Solarium\QueryType\Select\Query\Query $solarium */
    $solarium = $event->getSolariumQuery();
    $solarium->removeComponent('edismax');
    // The highlighter cannot highlight a vector query.
    $solarium->removeParam('hl');
    foreach ($built['params'] as $name => $value) {
      $solarium->addParam($name, $value);
    }
    $solarium->setQuery($built['q']);
    $query->setOption('maemgaba_search_mode_used', $mode);
  }

  /**
   * The requested mode: query option, then ?mode=, then the site default.
   */
  protected function mode(QueryInterface $query): string {
    $candidates = [
      $query->getOption('maemgaba_search_mode'),
      $this->requestStack->getCurrentRequest()?->query->get('mode'),
      $this->configFactory->get('maemgaba_core.settings')->get('explore.default_mode'),
    ];
    foreach ($candidates as $mode) {
      if (is_string($mode) && in_array($mode, SolrHybridQuery::MODES, TRUE)) {
        return $mode;
      }
    }
    return 'hybrid';
  }

  /**
   * The Solr field of the index's first dense-vector field.
   */
  protected function vectorField($index): ?string {
    foreach ($index->getFields() as $id => $field) {
      if ($field->getType() !== 'solr_densevector') {
        continue;
      }
      $backend = $index->getServerInstance()->getBackend();
      $map = method_exists($backend, 'getSolrFieldNames') ? $backend->getSolrFieldNames($index) : [];
      return $map[$id] ?? ('knns_' . $id);
    }
    $this->loggerFactory->get('maemgaba_core')->warning('Index @index has the dense-vector processor but no dense-vector field.', ['@index' => $index->id()]);
    return NULL;
  }

}

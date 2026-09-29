<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\maemgaba_core\Controller\SearchController;

/**
 * Embeds a visitor's query for /explore, with a cache and a flood limit.
 *
 * Every facet click on /explore re-runs the query with the same words, so
 * the vector is cached by (provider, model, text) for a week: the embedding
 * call, its cost and its flood count happen once per distinct query. Only a
 * cache miss counts against the per-IP limit (search_flood, shared defaults
 * with /search but a separate flood event). Over the limit this returns
 * NULL and the caller falls back to keyword search. CLI runs (drush, cron)
 * and search admins are never limited.
 */
class QueryEmbedder {

  /**
   * Flood event for /explore embeddings.
   */
  public const FLOOD_EVENT = 'maemgaba_core.explore';

  /**
   * Cache lifetime for query vectors (seconds).
   */
  public const CACHE_TTL = 604800;

  /**
   * Whether the last embed() call was refused by the flood limit.
   */
  protected bool $limited = FALSE;

  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected CacheBackendInterface $cache,
    protected FloodInterface $flood,
    protected AccountProxyInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns the query vector, or NULL when limited or on failure.
   *
   * @param string $text
   *   The query text.
   * @param string $provider
   *   AI provider plugin id.
   * @param string $model
   *   Embedding model id.
   * @param bool $useCache
   *   FALSE to force a fresh call (the eval measures real latency).
   */
  public function embed(string $text, string $provider, string $model, bool $useCache = TRUE): ?array {
    $this->limited = FALSE;
    $cid = 'maemgaba_core:query_vector:' . hash('sha256', $provider . "\n" . $model . "\n" . $text);
    if ($useCache && ($hit = $this->cache->get($cid)) && is_array($hit->data)) {
      return $hit->data;
    }

    if ($this->floodApplies()) {
      $settings = $this->configFactory->get('maemgaba_core.settings');
      $limit = (int) ($settings->get('search_flood.limit') ?: SearchController::FLOOD_LIMIT);
      $window = (int) ($settings->get('search_flood.window') ?: SearchController::FLOOD_WINDOW);
      if (!$this->flood->isAllowed(self::FLOOD_EVENT, $limit, $window)) {
        $this->limited = TRUE;
        return NULL;
      }
      $this->flood->register(self::FLOOD_EVENT, $window);
    }

    try {
      $vector = $this->aiProvider->createInstance($provider)
        ->embeddings(new EmbeddingsInput($text), $model, ['maemgaba_explore'])
        ->getNormalized();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('maemgaba_core')->error('Query embedding failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
    if (!$vector) {
      return NULL;
    }
    $this->cache->set($cid, $vector, time() + self::CACHE_TTL, ['maemgaba_core_query_vector']);
    return $vector;
  }

  /**
   * Whether the last embed() call was refused by the flood limit.
   */
  public function wasLimited(): bool {
    return $this->limited;
  }

  /**
   * Web requests by non-admins are limited; CLI and search admins are not.
   */
  protected function floodApplies(): bool {
    return PHP_SAPI !== 'cli' && !$this->currentUser->hasPermission('administer news engine semantic search');
  }

}

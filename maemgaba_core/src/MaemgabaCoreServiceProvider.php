<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\maemgaba_core\EventSubscriber\GuardedDenseVectorQuerySubscriber;

/**
 * Container changes for optional contrib modules.
 */
class MaemgabaCoreServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    // search_api_solr_dense_vector (alpha9) embeds the query on every Solr
    // search, before it checks whether its ranker is enabled. With the
    // ranker off (the engine's SolrHybridQuerySubscriber ranks instead),
    // every keyword search and facet click paid a wasted embedding call
    // (about 1.6 s and a Gemini request). The guarded subclass returns early
    // in that case and otherwise behaves exactly like the module's.
    if ($container->hasDefinition('search_api_solr_dense_vector.solr_query_alter')) {
      $container->getDefinition('search_api_solr_dense_vector.solr_query_alter')
        ->setClass(GuardedDenseVectorQuerySubscriber::class);
    }
  }

}

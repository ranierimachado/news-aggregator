<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\EventSubscriber;

use Drupal\search_api_solr\Event\PostConvertedQueryEvent;
use Drupal\search_api_solr_dense_vector\EventSubscriber\SearchApiSolrDenseVectorQuerySubscriber;

/**
 * The dense-vector module's query subscriber, minus a wasted embedding call.
 *
 * Swapped in by MaemgabaCoreServiceProvider only when the module is
 * installed. The parent embeds the query first and only then checks
 * `enable_vector_rerank`; this checks first.
 */
class GuardedDenseVectorQuerySubscriber extends SearchApiSolrDenseVectorQuerySubscriber {

  /**
   * {@inheritdoc}
   */
  public function postConvertQuery(PostConvertedQueryEvent $event): void {
    $index = $event->getSearchApiQuery()->getIndex();
    $settings = $index->getThirdPartySetting('search_api_solr', 'dense_vector', []);
    if (empty($settings['enable_vector_rerank'])) {
      return;
    }
    parent::postConvertQuery($event);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\EventSubscriber;

use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes a site's configured embedding size real for Matryoshka models.
 *
 * Two places declare a size: an ai_search server
 * (`embeddings_engine_configuration: {set_dimensions: true, dimensions: N}`,
 * the MariaDB vector table is created with N dimensions) and a Solr index
 * with the search_api_solr_dense_vector processor (the `knn_vector` Solr
 * field type's `vectorDimension`, Solr caps it at 1024). Neither drupal/ai
 * 1.4, gemini_provider 1.0 nor the dense-vector module passes N to the
 * provider, though: Gemini's gemini-embedding-001 always answers with 3072
 * values, which a 768-d table or Solr field rejects.
 *
 * gemini-embedding-001 is trained with Matryoshka representation learning:
 * its N-dimension output is exactly the first N values of the full vector
 * (checked 2026-09-27: the API's outputDimensionality=768 response equals
 * the 3072-d response truncated, max difference 0.0). So this subscriber
 * truncates every embedding from that provider/model to the configured size
 * and re-normalises them to unit length, as Google recommends for sizes
 * below 3072. Cosine distance does not depend on length, but a dot-product
 * engine (Solr's dot_product similarity) requires unit vectors.
 *
 * It only ever shortens a vector, only for provider/model pairs a server or
 * index explicitly sizes, and does nothing when they disagree about the size
 * for the same model. The dense-vector module's calls carry no tag, so the
 * rule is per model, not per caller: a site that sizes a model uses it at
 * that size everywhere.
 */
class EmbeddingDimensionSubscriber implements EventSubscriberInterface {

  /**
   * Target size per "provider__model", built once per request.
   *
   * @var array<string, int>|null
   */
  protected ?array $targets = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PostGenerateResponseEvent::EVENT_NAME => ['onPostGenerate', 0]];
  }

  /**
   * Truncates an ai_search embedding to its server's configured size.
   */
  public function onPostGenerate(PostGenerateResponseEvent $event): void {
    if ($event->getOperationType() !== 'embeddings') {
      return;
    }
    $output = $event->getOutput();
    if (!$output instanceof EmbeddingsOutput) {
      return;
    }
    $target = $this->targets()[$event->getProviderId() . '__' . $event->getModelId()] ?? NULL;
    $vector = $output->getNormalized();
    if ($target === NULL || count($vector) <= $target) {
      return;
    }
    $event->setOutput(new EmbeddingsOutput(self::truncate($vector, $target), $output->getRawOutput(), $output->getMetadata()));
  }

  /**
   * First $size values of $vector, scaled back to unit length.
   *
   * @param float[] $vector
   *   The full embedding.
   * @param int $size
   *   Target dimensions.
   *
   * @return float[]
   *   The shortened, L2-normalised vector.
   */
  public static function truncate(array $vector, int $size): array {
    $short = array_slice(array_values($vector), 0, $size);
    $norm = sqrt(array_sum(array_map(static fn ($x): float => (float) $x * (float) $x, $short)));
    if ($norm <= 0.0) {
      return $short;
    }
    return array_map(static fn ($x): float => (float) $x / $norm, $short);
  }

  /**
   * Configured sizes: "provider__model" => dimensions.
   *
   * @return array<string, int>
   *   Only models that every server and index using them sizes the same way.
   */
  protected function targets(): array {
    if ($this->targets !== NULL) {
      return $this->targets;
    }
    $sizes = [];
    try {
      $servers = $this->entityTypeManager->getStorage('search_api_server')->loadMultiple();
    }
    catch (\Throwable) {
      return $this->targets = [];
    }
    foreach ($servers as $server) {
      if ($server->getBackendId() !== 'search_api_ai_search' || !$server->status()) {
        continue;
      }
      $config = $server->getBackendConfig();
      $engine = (string) ($config['embeddings_engine'] ?? '');
      $engine_config = $config['embeddings_engine_configuration'] ?? [];
      if ($engine === '' || empty($engine_config['set_dimensions']) || (int) ($engine_config['dimensions'] ?? 0) <= 0) {
        continue;
      }
      $sizes[$engine][] = (int) $engine_config['dimensions'];
    }
    foreach ($this->solrDenseVectorSizes() as $engine => $size) {
      $sizes[$engine][] = $size;
    }

    $this->targets = [];
    foreach ($sizes as $engine => $list) {
      $list = array_unique($list);
      if (count($list) === 1) {
        $this->targets[$engine] = reset($list);
      }
      else {
        $this->loggerFactory->get('maemgaba_core')->warning('Embedding model @engine is configured with different dimensions on different servers or indexes (@sizes); vectors are left untruncated.', [
          '@engine' => $engine,
          '@sizes' => implode(', ', $list),
        ]);
      }
    }
    return $this->targets;
  }

  /**
   * Sizes declared by Solr indexes with the dense-vector processor.
   *
   * The size Solr enforces is the `knn_vector` field type's vectorDimension
   * (search_api_solr_dense_vector ships it as knn_vector_und_9_0_0); the
   * processor's own `vector_dimension` is the fallback.
   *
   * @return array<string, int>
   *   "provider__model" => dimensions.
   */
  protected function solrDenseVectorSizes(): array {
    $sizes = [];
    try {
      $indexes = $this->entityTypeManager->getStorage('search_api_index')->loadMultiple();
      $field_type = $this->entityTypeManager->hasDefinition('solr_field_type')
        ? $this->entityTypeManager->getStorage('solr_field_type')->load('knn_vector_und_9_0_0')
        : NULL;
    }
    catch (\Throwable) {
      return [];
    }
    $solr_size = $field_type ? (int) ($field_type->getFieldType()['vectorDimension'] ?? 0) : 0;
    foreach ($indexes as $index) {
      if (!$index->status() || !$index->isValidProcessor('solr_densevector')) {
        continue;
      }
      $config = $index->getProcessor('solr_densevector')->getConfiguration();
      if (empty($config['ai_provider']) || empty($config['ai_model_id'])) {
        continue;
      }
      $size = $solr_size ?: (int) ($config['vector_dimension'] ?? 0);
      if ($size > 0) {
        $sizes[$config['ai_provider'] . '__' . $config['ai_model_id']] = $size;
      }
    }
    return $sizes;
  }

}

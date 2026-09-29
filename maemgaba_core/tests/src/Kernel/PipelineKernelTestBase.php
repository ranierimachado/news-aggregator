<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\maemgaba_core\AiAnalyzerService;
use Drupal\maemgaba_core\BiasScore;
use Symfony\Component\Yaml\Yaml;

/**
 * Shared setup for pipeline kernel tests.
 *
 * Builds only the content model the pipeline touches (node types, fields,
 * the sources vocabulary) from the module's own config/install YAML, rather
 * than installing all of maemgaba_core's config — which pulls in views,
 * search_api and AI-provider config these tests don't need. The AI analyzer
 * is replaced with a mock so no provider is ever called.
 */
abstract class PipelineKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'link',
    'node',
    'taxonomy',
    'key',
    'ai',
    'maemgaba_core',
  ];

  /**
   * The mocked AI analyzer, installed into the container.
   */
  protected AiAnalyzerService $aiAnalyzer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('maemgaba_core', [
      'maemgaba_source_text',
      'maemgaba_card_signature',
      'maemgaba_click_log',
      'maemgaba_event_view',
    ]);
    $this->installConfig(['system', 'field', 'filter', 'node', 'taxonomy']);

    $dir = $this->container->get('extension.list.module')->getPath('maemgaba_core') . '/config/install';
    $patterns = [
      'node.type.*.yml' => 'node_type',
      'taxonomy.vocabulary.*.yml' => 'taxonomy_vocabulary',
      'field.storage.*.yml' => 'field_storage_config',
      'field.field.*.yml' => 'field_config',
    ];
    foreach ($patterns as $pattern => $entityType) {
      $storage = $this->container->get('entity_type.manager')->getStorage($entityType);
      foreach (glob("$dir/$pattern") as $file) {
        $storage->createFromStorageRecord(Yaml::parseFile($file))->save();
      }
    }

    foreach (['maemgaba_core.settings', 'maemgaba_core.spectrum', 'maemgaba_core.topics', 'maemgaba_core.locale'] as $name) {
      $this->config($name)->setData(Yaml::parseFile("$dir/$name.yml"))->save();
    }

    $this->aiAnalyzer = $this->createMock(AiAnalyzerService::class);
    $this->container->set('maemgaba_core.ai_analyzer', $this->aiAnalyzer);
  }

  /**
   * Creates and configures the pipeline account; returns its uid.
   */
  protected function provisionPipelineUser(): int {
    // User 1 exists on every real site; create it so the fallback is loadable.
    $this->container->get('entity_type.manager')->getStorage('user')
      ->create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    $this->container->get('maemgaba_core.pipeline_identity')->provision();
    return (int) $this->config('maemgaba_core.settings')->get('pipeline_uid');
  }

  /**
   * Creates an inbound_queue node directly (bypassing HTTP harvesting).
   */
  protected function queueItem(string $title, string $url, string $outlet, ?string $body = NULL, ?string $section = NULL): int {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->create([
      'type' => 'inbound_queue',
      'title' => $title,
      'field_source_url' => $url,
      'field_source_name' => $outlet,
      'field_raw_html_body' => ['value' => $body ?? "<p>$title</p>", 'format' => 'basic_html'],
      'field_section' => $section,
      'status' => 0,
    ]);
    $node->save();
    return (int) $node->id();
  }

  /**
   * A classifyAndCluster() response that matches or creates an event.
   */
  protected function analysis(?int $matchedEventId, int $biasScore = 0): array {
    return [
      'matched_event_id' => $matchedEventId,
      'event_title' => 'Test event',
      'neutral_summary' => 'Neutral summary.',
      'bias_score' => $biasScore,
      'bias' => BiasScore::toLegacy($biasScore),
      'bias_confidence' => 0.9,
      'bias_evidence' => [],
      'micro_summary' => 'Micro summary.',
      'social_relevance' => 'relevant',
      'topic' => 'politics',
      'relevance_reason' => 'Test.',
    ];
  }

}

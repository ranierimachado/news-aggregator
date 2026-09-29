<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Symfony\Component\Yaml\Yaml;

/**
 * The shipped sample locale pack loads, seeds and scores end to end.
 *
 * Guards the one pack the public package ships: its config imports against
 * the schema, its five buckets cover the scale, its seven fictional feeds
 * seed, its golden set is consistent with its own topic list, and a replay
 * through the eval runner scores it.
 *
 * @group maemgaba_core
 */
class SamplePackTest extends PipelineKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('maemgaba_core', ['maemgaba_eval_run', 'maemgaba_ai_call_log']);
  }

  /**
   * Config, feeds, golden set and eval replay of the sample pack.
   */
  public function testSamplePackLoadsAndScores(): void {
    $this->provisionPipelineUser();
    $module = $this->container->get('extension.list.module')->getPath('maemgaba_core');
    $pack = "$module/config/locale/sample";

    // 1. Pack config imports and validates against the module's schema.
    foreach (['maemgaba_core.spectrum', 'maemgaba_core.topics', 'maemgaba_core.locale', 'maemgaba_core.methodology'] as $name) {
      $this->config($name)->setData(Yaml::parseFile("$pack/config/$name.yml"))->save();
    }
    $settings = $this->config('maemgaba_core.settings');
    foreach (Yaml::parseFile("$pack/settings-excerpt.yml") as $key => $value) {
      $settings->set($key, $value);
    }
    $settings->save();

    // 2. Five buckets cover -2..+2 in order.
    $spectrum = $this->container->get('maemgaba_core.spectrum');
    $this->assertSame(['left', 'lean_left', 'center', 'lean_right', 'right'], $spectrum->keys());
    foreach ([-2, -1, 0, 1, 2] as $score) {
      $this->assertNotNull($spectrum->keyFor($score), "score $score maps to a bucket");
    }

    // 3. The registry parses, is fictional, and seeds.
    $seeder = $this->container->get('maemgaba_core.feed_seeder');
    $parsed = $seeder->parse("$pack/feeds.yml");
    $this->assertSame([], $parsed['errors']);
    $this->assertCount(7, $parsed['feeds']);
    foreach ($parsed['feeds'] as $feed) {
      $this->assertStringEndsWith('.example', (string) parse_url($feed['url'], PHP_URL_HOST), 'sample feeds live on .example hosts');
    }
    $this->assertSame(array_fill(0, 7, 'created'), array_column($seeder->seed($parsed['feeds']), 'action'));
    $this->assertSame(array_fill(0, 7, 'unchanged'), array_column($seeder->seed($parsed['feeds']), 'action'));

    // 4. The golden set is found through the module fallback and matches
    // the pack's own vocabulary.
    $path = $this->container->get('maemgaba_core.golden_set_exporter')->fixturePath();
    $this->assertStringEndsWith('config/locale/sample/golden/classify_cluster.json', $path);
    $this->assertFileExists($path);
    $items = json_decode((string) file_get_contents($path), TRUE);
    $this->assertGreaterThanOrEqual(15, count($items));
    $topics = $this->container->get('maemgaba_core.topics')->keys();
    foreach ($items as $item) {
      $this->assertSame(['id', 'title', 'body_excerpt', 'expected'], array_keys($item));
      $this->assertContains($item['expected']['bias'], ['left', 'center', 'right']);
      $this->assertContains($item['expected']['social_relevance'], ['relevant', 'maybe', 'irrelevant']);
      $this->assertContains($item['expected']['topic'], $topics, "golden item {$item['id']} uses a pack topic");
    }

    // 5. A replay through the eval runner scores the set. The analyzer is
    // the base class mock; here it answers each item's expected labels, so
    // the run must report zero misses.
    $prompt = $this->container->get('entity_type.manager')->getStorage('maemgaba_prompt')
      ->create(Yaml::parseFile("$module/config/install/maemgaba_core.maemgaba_prompt.classify_cluster.yml"));
    $prompt->save();
    $byBody = array_column($items, 'expected', 'body_excerpt');
    $this->aiAnalyzer->method('getPrompt')->willReturn($prompt);
    $this->aiAnalyzer->method('classifyAndCluster')->willReturnCallback(
      fn (string $text) => $byBody[$text] + ['matched_event_id' => NULL, 'bias_confidence' => 0.9],
    );
    $result = $this->container->get('maemgaba_core.eval_runner')->run('classify_cluster', 100, NULL);
    $this->assertSame(count($items), $result['metrics']['item_count']);
    $this->assertSame(0, $result['metrics']['miss_count']);
    $this->assertSame(1.0, (float) $result['metrics']['fields']['bias']['accuracy']);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Opinion pieces: carried from the feed, classified, never counted.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class OpinionSplitTest extends PipelineKernelTestBase {

  /**
   * Scores the mocked classifier hands out, in order.
   *
   * @var int[]
   */
  protected array $scores = [];

  /**
   * Card lines each synthesizeConsensus() call received.
   *
   * @var string[]
   */
  protected array $synthesisInputs = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->provisionPipelineUser();
    $this->aiAnalyzer->method('classifyAndCluster')->willReturnCallback(function () {
      $events = $this->container->get('entity_type.manager')->getStorage('node')
        ->getQuery()->accessCheck(FALSE)->condition('type', 'event')->execute();
      return $this->analysis($events ? (int) reset($events) : NULL, array_shift($this->scores) ?? 0);
    });
    $this->aiAnalyzer->method('synthesizeConsensus')->willReturnCallback(function (string $title, string $summary, string $lines) {
      $this->synthesisInputs[] = $lines;
      return ['common_points' => ['Outlets agree on the date.'], 'disputed_points' => []];
    });
  }

  /**
   * The section travels queue → card; opinion stays out of the counts.
   */
  public function testOpinionIsClassifiedButNotCounted(): void {
    $processor = $this->container->get('maemgaba_core.queue_processor');
    $this->scores = [-1, 2, 0];
    $this->queueItem('News A', 'https://a.example/1', 'Outlet A', NULL, 'news');
    $this->queueItem('Column B', 'https://b.example/2', 'Outlet B', NULL, 'opinion');
    $processor->processBatch(2);

    $opinion = $this->card('https://b.example/2');
    $this->assertSame('opinion', $opinion->get('field_section')->value);
    $this->assertSame('2', (string) $opinion->get('field_bias_score')->value, 'opinion is classified');
    $this->assertSame('news', $this->card('https://a.example/1')->get('field_section')->value);

    // One news outlet + one opinion piece: not enough voices for consensus.
    $consensus = $this->container->get('maemgaba_core.consensus');
    $this->assertFalse($consensus->recalculate($this->event()));
    $this->assertSame([], $this->synthesisInputs);

    $this->queueItem('News C', 'https://c.example/3', 'Outlet C');
    $processor->processBatch(1);
    $this->assertTrue($consensus->recalculate($this->event()));
    $this->assertCount(1, $this->synthesisInputs);
    $this->assertStringContainsString('Outlet A', $this->synthesisInputs[0]);
    $this->assertStringContainsString('Outlet C', $this->synthesisInputs[0]);
    $this->assertStringNotContainsString('Outlet B', $this->synthesisInputs[0], 'opinion never feeds consensus');

    $variables = ['node' => $this->event(), 'view_mode' => 'full', 'elements' => []];
    $this->container->get('maemgaba_core.event_preprocessor')->preprocessNode($variables);
    $this->assertSame(3, $variables['total_sources']);
    $this->assertSame(2, $variables['total_perspectives']);
    $buckets = array_column($variables['buckets'], 'count', 'key');
    $this->assertSame(['left' => 1, 'center' => 1, 'right' => 0], $buckets);
    $this->assertSame(1, $variables['opinion_count']);
    $this->assertSame('Outlet B', $variables['opinion_cards'][0]['source_name']);
    $this->assertSame('right', $variables['opinion_cards'][0]['bias']);

    // Per-outlet stats count news only.
    $tally = $this->container->get('maemgaba_core.source_bias_stats')->tally();
    $names = array_values(array_map(fn ($row) => $row['name'], $tally));
    sort($names);
    $this->assertSame(['Outlet A', 'Outlet C'], $names);
  }

  /**
   * Harvest stamps the feed's section; a partial_text feed skips the page.
   */
  public function testHarvestCarriesRegistryFlags(): void {
    $this->config('maemgaba_core.settings')->set('partial_text', ['min_words' => 150, 'max_confidence' => 0.5])->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->create([
      'type' => 'feed_source',
      'title' => 'Outlet B Opinion',
      'field_media_outlet' => 'Outlet B',
      'field_feed_url' => 'https://b.example/opinion.xml',
      'field_active_testing' => 1,
      'field_section' => 'opinion',
      'field_partial_text' => 1,
      'status' => 1,
    ])->save();

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], '<?xml version="1.0"?><rss version="2.0"><channel><title>B</title><item><title>Why the bill fails</title><link>https://b.example/opinion/1</link><description>A columnist argues against it.</description></item></channel></rss>'),
    ]));
    $stack->push(Middleware::history($history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $engine = $this->container->get('maemgaba_core.ingestion_engine');
    $feeds = $engine->getActiveFeeds();
    $this->assertCount(1, $feeds);
    $this->assertSame('opinion', $feeds[0]['section']);
    $this->assertTrue($feeds[0]['partial_text']);

    $this->assertSame(1, $engine->harvestSingle($feeds[0]));
    $this->assertCount(1, $history, 'feed fetched, article page not');
    $queued = $storage->loadByProperties(['type' => 'inbound_queue']);
    $item = reset($queued);
    $this->assertSame('opinion', $item->get('field_section')->value);
    $this->assertStringContainsString('A columnist argues against it.', $item->get('field_raw_html_body')->value);
  }

  /**
   * The registry: opinion feeds keep their own prior, not the outlet line.
   */
  public function testSeederOpinionAndPartialFlags(): void {
    $seeder = $this->container->get('maemgaba_core.feed_seeder');
    $parsed = $seeder->parse($this->registry([
      "  - url: https://t.example/news.xml\n    outlet: The Times\n    score: -1\n    section: news\n    partial_text: true",
      "  - url: https://t.example/opinion.xml\n    outlet: The Times\n    score: -2\n    section: opinion\n    published_prior: -2",
    ]));
    $this->assertSame([], $parsed['errors']);
    $this->assertTrue($parsed['feeds'][0]['partial_text']);
    $this->assertFalse($parsed['feeds'][1]['partial_text']);
    $seeder->seed($parsed['feeds']);

    $etm = $this->container->get('entity_type.manager');
    $terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'sources', 'name' => 'The Times']);
    $this->assertSame(-1, (int) reset($terms)->get('field_default_bias_score')->value, 'news line kept');
    $feeds = $etm->getStorage('node')->loadByProperties([
      'type' => 'feed_source',
      'field_feed_url' => 'https://t.example/news.xml',
    ]);
    $this->assertTrue((bool) reset($feeds)->get('field_partial_text')->value);
  }

  /**
   * Writes a feeds.yml with the given entries; returns its path.
   */
  protected function registry(array $entries): string {
    $file = $this->siteDirectory . '/feeds.yml';
    file_put_contents($file, "feeds:\n" . implode("\n", $entries) . "\n");
    return $file;
  }

  /**
   * The only event, freshly loaded.
   */
  protected function event() {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $events = $storage->loadByProperties(['type' => 'event']);
    $this->assertCount(1, $events);
    return reset($events);
  }

  /**
   * The card for a URL, freshly loaded.
   */
  protected function card(string $url) {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $cards = $storage->loadByProperties(['type' => 'card', 'field_original_url' => $url]);
    $this->assertCount(1, $cards, $url);
    return reset($cards);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\maemgaba_core\Service\SyndicationDetector;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reprints are linked to the original at ingest, without an AI call.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class SyndicationTest extends PipelineKernelTestBase {

  /**
   * Number of classifyAndCluster() calls made.
   */
  protected int $aiCalls = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->provisionPipelineUser();
    $this->config('maemgaba_core.settings')
      ->set('syndication', [
        'enabled' => TRUE,
        'threshold' => 0.8,
        'containment_threshold' => 0.85,
        'byline_threshold' => 0.5,
        'lookback_hours' => 48,
        'min_words' => 80,
        'wires' => [
          [
            'name' => 'AP',
            'patterns' => [
              '(AP)',
              'Associated Press writer',
              'Associated Press contributed',
              'The Associated Press. All rights reserved',
            ],
          ],
          ['name' => 'Reuters', 'patterns' => ['(Reuters)']],
        ],
      ])
      ->save();

    // Every AI answer lands on the first event, scored +1.
    $this->aiAnalyzer->method('classifyAndCluster')->willReturnCallback(function () {
      $this->aiCalls++;
      $events = $this->container->get('entity_type.manager')->getStorage('node')
        ->getQuery()->accessCheck(FALSE)->condition('type', 'event')->execute();
      return ['framing_line' => 'Frames the vote as bipartisan.'] + $this->analysis($events ? (int) reset($events) : NULL, 1);
    });
  }

  /**
   * Reads a fixture text as HTML paragraphs.
   */
  protected function fixture(string $name): string {
    $text = (string) file_get_contents(__DIR__ . '/../../fixtures/syndication/' . $name . '.txt');
    return '<p>' . implode('</p><p>', array_map('htmlspecialchars', preg_split('/\n\s*\n/', trim($text)))) . '</p>';
  }

  /**
   * Original, reprint and an independent story on the same event.
   */
  public function testReprintIsOnePerspective(): void {
    $processor = $this->container->get('maemgaba_core.queue_processor');

    $this->queueItem('Senate passes highway bill', 'https://outlet-a.example/1', 'Outlet A', $this->fixture('wire_original'));
    $processor->processBatch(1);
    $this->assertSame(1, $this->aiCalls);

    // A different outlet runs the same wire copy under its own headline.
    $this->queueItem('Highway bill clears Senate, heads to House', 'https://outlet-b.example/2', 'Outlet B', $this->fixture('wire_reprint'));
    $summary = $processor->processBatch(1);
    $this->assertSame(1, $this->aiCalls, 'no AI call for the reprint');
    $this->assertSame(1, $summary['syndicated']);

    $original = $this->card('https://outlet-a.example/1');
    $reprint = $this->card('https://outlet-b.example/2');
    $this->assertSame((int) $original->id(), (int) $reprint->get('field_syndicated_from')->target_id);
    $this->assertSame($original->get('field_parent_event')->target_id, $reprint->get('field_parent_event')->target_id);
    $this->assertSame('1', (string) $reprint->get('field_bias_score')->value, 'score copied');
    $this->assertEquals($original->get('field_bias_confidence')->value, $reprint->get('field_bias_confidence')->value, 'confidence copied');
    $this->assertSame('Frames the vote as bipartisan.', $reprint->get('field_framing_line')->value);
    $this->assertSame('Outlet B', $reprint->get('field_source')->entity->label(), 'own outlet kept');

    // An independent write-up of the same vote is classified normally.
    $this->queueItem('Infrastructure package goes to the House', 'https://outlet-c.example/3', 'Outlet C', $this->fixture('same_event_independent'));
    $processor->processBatch(1);
    $this->assertSame(2, $this->aiCalls);
    $this->assertTrue($this->card('https://outlet-c.example/3')->get('field_syndicated_from')->isEmpty());

    // Event page: three sources, two perspectives, the reprint "via AP"
    // under the original.
    $variables = $this->preprocess($this->event());
    $this->assertSame(3, $variables['total_sources']);
    $this->assertSame(2, $variables['total_perspectives']);
    $this->assertSame(1, $variables['syndicated_count']);
    $right = array_column($variables['buckets'], NULL, 'key')['right'];
    $this->assertSame(2, $right['count'], 'spectrum counts perspectives only');
    $byId = array_column($right['cards'], NULL, 'id');
    $this->assertCount(1, $byId[(int) $original->id()]['syndicated']);
    $this->assertSame('Outlet B', $byId[(int) $original->id()]['syndicated'][0]['source_name']);
    $this->assertSame('AP', $byId[(int) $original->id()]['syndicated'][0]['via']);
  }

  /**
   * A third copy matching the reprint points at the root, not the reprint.
   */
  public function testChainResolvesToRoot(): void {
    $processor = $this->container->get('maemgaba_core.queue_processor');
    $this->queueItem('A', 'https://a.example/1', 'Outlet A', $this->fixture('wire_original'));
    $this->queueItem('B', 'https://b.example/2', 'Outlet B', $this->fixture('wire_reprint'));
    $this->queueItem('C', 'https://c.example/3', 'Outlet C', $this->fixture('wire_reprint'));
    $processor->processBatch(3);
    $this->assertSame(1, $this->aiCalls);
    $root = (int) $this->card('https://a.example/1')->id();
    $this->assertSame($root, (int) $this->card('https://c.example/3')->get('field_syndicated_from')->target_id);
  }

  /**
   * Off by default; short texts and old cards never match.
   */
  public function testGuards(): void {
    $processor = $this->container->get('maemgaba_core.queue_processor');
    $detector = $this->container->get('maemgaba_core.syndication');

    // Lookback: the original's fingerprint is older than 48 h.
    $this->queueItem('A', 'https://a.example/1', 'Outlet A', $this->fixture('wire_original'));
    $processor->processBatch(1);
    $this->container->get('database')->update(SyndicationDetector::TABLE)->fields(['created' => time() - 49 * 3600])->execute();
    $this->queueItem('B', 'https://b.example/2', 'Outlet B', $this->fixture('wire_reprint'));
    $processor->processBatch(1);
    $this->assertSame(2, $this->aiCalls, 'outside the lookback window');

    // Headline + abstract: too short to compare.
    $this->assertFalse($detector->fingerprint('<p>WASHINGTON (AP) — The Senate approved a highway bill.</p>')['eligible']);

    // Disabled: no fingerprinting at all.
    $this->config('maemgaba_core.settings')->set('syndication.enabled', FALSE)->save();
    $this->queueItem('C', 'https://c.example/3', 'Outlet C', $this->fixture('wire_reprint'));
    $processor->processBatch(1);
    $this->assertSame(3, $this->aiCalls);
    $this->assertSame(2, (int) $this->container->get('database')->select(SyndicationDetector::TABLE)->countQuery()->execute()->fetchField());
  }

  /**
   * The byline heuristic reads only the edges of the text.
   */
  public function testWireByline(): void {
    $detector = $this->container->get('maemgaba_core.syndication');
    $this->assertSame('AP', $detector->wireOf($this->fixture('wire_original')));
    $this->assertSame('AP', $detector->wireOf($this->fixture('wire_reprint')));
    $this->assertNull($detector->wireOf($this->fixture('same_event_independent')));
    $filler = str_repeat('word ', 80);
    $this->assertNull($detector->wireOf($filler . 'as Reuters (Reuters) reported earlier ' . $filler), 'mid-text mention ignored');
    $this->assertSame('Reuters', $detector->wireOf('LONDON (Reuters) - ' . $filler));
    // Seen in the P9 harvest: a rewrite citing AP, and an author bio.
    $this->assertNull($detector->wireOf('Starbucks plans to shutter 250 stores, the Associated Press reported Thursday. ' . $filler));
    $this->assertNull($detector->wireOf($filler . 'cited in The Capital Ledger, The Associated Press and Harbor Times.'));
  }

  /**
   * Runs the event preprocessor on the full view.
   */
  protected function preprocess(NodeInterface $event): array {
    $variables = ['node' => $event, 'view_mode' => 'full', 'elements' => []];
    $this->container->get('maemgaba_core.event_preprocessor')->preprocessNode($variables);
    return $variables;
  }

  /**
   * The only event, freshly loaded.
   */
  protected function event(): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $events = $storage->loadByProperties(['type' => 'event']);
    $this->assertCount(1, $events);
    return reset($events);
  }

  /**
   * The card for a URL, freshly loaded.
   */
  protected function card(string $url): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $cards = $storage->loadByProperties(['type' => 'card', 'field_original_url' => $url]);
    $this->assertCount(1, $cards, $url);
    return reset($cards);
  }

}

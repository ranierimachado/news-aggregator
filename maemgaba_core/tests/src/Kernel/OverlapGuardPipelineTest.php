<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\maemgaba_core\Service\ConsensusService;
use Drupal\maemgaba_core\Service\SourceTextStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The verbatim-overlap guard wired into ingest and consensus synthesis.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class OverlapGuardPipelineTest extends PipelineKernelTestBase {

  /**
   * What the mocked synthesizeConsensus() returns next.
   */
  protected array $synthesis = [];

  /**
   * Body of the first source article.
   */
  protected const BODY_A = 'Lawmakers in the House passed the farm bill late on Thursday after a lengthy debate over food assistance cuts, sending the measure to the Senate.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->provisionPipelineUser();
    $this->config('maemgaba_core.settings')
      ->set('consensus_mode', ConsensusService::MODE_EAGER)
      ->set('overlap_guard', ['enabled' => TRUE, 'ngram' => 8, 'retention_days' => 30])
      ->set('synthesis_neutral_summary', TRUE)
      ->set('neutral_summary_max_words', 12)
      ->save();

    $this->aiAnalyzer->method('classifyAndCluster')->willReturnCallback(function () {
      $events = $this->container->get('entity_type.manager')->getStorage('node')
        ->getQuery()->accessCheck(FALSE)->condition('type', 'event')->execute();
      return [
        'framing_line' => 'Stresses the food-aid cuts.',
        'partial_text' => TRUE,
        // Copies 8+ words of BODY_A: must not reach the new event.
        'neutral_summary' => 'The House passed the farm bill late on Thursday after a lengthy debate.',
      ] + $this->analysis($events ? (int) reset($events) : NULL);
    });
    $this->aiAnalyzer->method('synthesizeConsensus')->willReturnCallback(fn () => $this->synthesis);
  }

  /**
   * Copied synthesis is rejected; a paraphrase is saved with its summary.
   */
  public function testGuard(): void {
    $processor = $this->container->get('maemgaba_core.queue_processor');

    $this->queue('Farm bill passes House', 'https://a.example/1', 'Outlet A', self::BODY_A);
    $processor->processBatch(1);
    $event = $this->event();
    $this->assertSame('', (string) $event->get('field_neutral_summary')->value, 'copied new-event summary dropped');

    $card = $this->card('https://a.example/1');
    $this->assertSame('Stresses the food-aid cuts.', $card->get('field_framing_line')->value);
    $this->assertTrue((bool) $card->get('field_partial_text')->value);
    $texts = $this->container->get('maemgaba_core.source_text_store')->forEvent((int) $event->id());
    $this->assertCount(1, $texts);
    $this->assertStringContainsString('food assistance cuts', reset($texts));

    // Second outlet triggers eager synthesis, which copies from BODY_A.
    $this->synthesis = [
      'common_points' => ['Both report the House passed the farm bill late on Thursday after debate.'],
      'disputed_points' => [],
      'neutral_summary' => 'The House approved a farm bill.',
    ];
    $this->queue('House approves farm bill', 'https://b.example/2', 'Outlet B', 'A different write-up of the same vote with no shared wording at all here.');
    $processor->processBatch(1);
    $this->assertFalse($this->consensus()->hasConsensus($this->event()), 'copied synthesis rejected');

    // A paraphrase goes through, and the summary is capped at 12 words.
    $this->synthesis = [
      'common_points' => ['Outlet A and Outlet B both report the House vote.'],
      'disputed_points' => ['Outlet A frames the food-aid cuts as the story; Outlet B does not.'],
      'neutral_summary' => 'The House approved a farm bill on Thursday. It now goes to the Senate for a vote.',
    ];
    $this->assertTrue($this->consensus()->recalculate($this->event()));
    $event = $this->event();
    $this->assertSame('Outlet A and Outlet B both report the House vote.', $event->get('field_common_points')->value);
    $this->assertSame('The House approved a farm bill on Thursday.', $event->get('field_neutral_summary')->value);
  }

  /**
   * The guard off: nothing is stored, nothing is rejected.
   */
  public function testGuardOff(): void {
    $this->config('maemgaba_core.settings')->set('overlap_guard.enabled', FALSE)->save();
    $this->queue('Farm bill passes House', 'https://a.example/1', 'Outlet A', self::BODY_A);
    $this->container->get('maemgaba_core.queue_processor')->processBatch(1);
    $this->assertStringContainsString('farm bill late on Thursday', (string) $this->event()->get('field_neutral_summary')->value);
    $count = $this->container->get('database')->select(SourceTextStore::TABLE)->countQuery()->execute()->fetchField();
    $this->assertSame(0, (int) $count);
  }

  /**
   * Source text is deleted with its card.
   */
  public function testDeletedWithCard(): void {
    $this->queue('Farm bill passes House', 'https://a.example/1', 'Outlet A', self::BODY_A);
    $this->container->get('maemgaba_core.queue_processor')->processBatch(1);
    $this->card('https://a.example/1')->delete();
    $this->assertSame([], $this->container->get('maemgaba_core.source_text_store')->forEvent((int) $this->event()->id()));
  }

  /**
   * Queues an item with a given body.
   */
  protected function queue(string $title, string $url, string $outlet, string $body): void {
    $this->container->get('entity_type.manager')->getStorage('node')->create([
      'type' => 'inbound_queue',
      'title' => $title,
      'field_source_url' => $url,
      'field_source_name' => $outlet,
      'field_raw_html_body' => ['value' => "<p>{$body}</p>", 'format' => 'basic_html'],
      'status' => 0,
    ])->save();
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
    return reset($cards);
  }

  /**
   * The consensus service.
   */
  protected function consensus(): ConsensusService {
    return $this->container->get('maemgaba_core.consensus');
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\maemgaba_core\Service\ConsensusService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests maemgaba_core.settings:consensus_mode.
 *
 * The setting controls first synthesis at ingest.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class ConsensusModeTest extends PipelineKernelTestBase {

  /**
   * Number of synthesizeConsensus() calls the mock has received.
   */
  protected int $synthCalls = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->provisionPipelineUser();

    // First item creates the event; every later one matches it.
    $this->aiAnalyzer->method('classifyAndCluster')->willReturnCallback(function () {
      $events = $this->container->get('entity_type.manager')->getStorage('node')
        ->getQuery()->accessCheck(FALSE)->condition('type', 'event')->execute();
      return $this->analysis($events ? (int) reset($events) : NULL);
    });
    $this->aiAnalyzer->method('synthesizeConsensus')->willReturnCallback(function () {
      $this->synthCalls++;
      return ['common_points' => ['Ponto comum'], 'disputed_points' => ['Ponto em disputa']];
    });
  }

  /**
   * Eager: nothing at the first outlet, synthesis at the second.
   */
  public function testEagerFiresAtSecondOutlet(): void {
    $this->config('maemgaba_core.settings')->set('consensus_mode', ConsensusService::MODE_EAGER)->save();
    $processor = $this->container->get('maemgaba_core.queue_processor');

    $this->queueItem('Article 1', 'https://a.example/1', 'Outlet A');
    $processor->processBatch(1);
    $this->assertSame(0, $this->synthCalls, 'no synthesis with a single outlet');
    $this->assertFalse($this->consensus()->hasConsensus($this->event()));

    // Same outlet again: still one distinct outlet, still nothing.
    $this->queueItem('Article 2', 'https://a.example/2', 'Outlet A');
    $processor->processBatch(1);
    $this->assertSame(0, $this->synthCalls, 'no synthesis with two cards from one outlet');

    $this->queueItem('Article 3', 'https://b.example/3', 'Outlet B');
    $processor->processBatch(1);
    $this->assertSame(1, $this->synthCalls, 'synthesis at the second distinct outlet');
    $event = $this->event();
    $this->assertTrue($this->consensus()->hasConsensus($event));
    $this->assertSame('Ponto comum', $event->get('field_common_points')->value);
  }

  /**
   * Eager: a held per-event lock (visitor-triggered synthesis) skips it.
   */
  public function testEagerRespectsLock(): void {
    // KernelTestBase registers a NullLockBackend (acquire always succeeds);
    // use real database locks. "Another request" holding the lock is a
    // separate backend instance, since a holder can re-acquire its own lock.
    $this->container->set('lock', new DatabaseLockBackend($this->container->get('database')));
    $other = new DatabaseLockBackend($this->container->get('database'));

    $this->config('maemgaba_core.settings')->set('consensus_mode', ConsensusService::MODE_EAGER)->save();
    $processor = $this->container->get('maemgaba_core.queue_processor');

    $this->queueItem('Article 1', 'https://a.example/1', 'Outlet A');
    $processor->processBatch(1);
    $eventId = (int) $this->event()->id();

    $this->assertTrue($other->acquire(ConsensusService::lockName($eventId), ConsensusService::LOCK_TIMEOUT));

    $this->queueItem('Article 2', 'https://b.example/2', 'Outlet B');
    $processor->processBatch(1);
    $this->assertSame(0, $this->synthCalls, 'skipped while the event is locked');
    $other->release(ConsensusService::lockName($eventId));

    // Once released, the next card on the event synthesises it.
    $this->queueItem('Article 3', 'https://c.example/3', 'Outlet C');
    $processor->processBatch(1);
    $this->assertSame(1, $this->synthCalls, 'synthesis once the lock is free');
  }

  /**
   * Default lazy_top5: ingest never does a first synthesis.
   */
  public function testLazyDefaultNeverSynthesizesAtIngest(): void {
    $this->assertSame(ConsensusService::MODE_LAZY_TOP5, $this->config('maemgaba_core.settings')->get('consensus_mode'));
    $processor = $this->container->get('maemgaba_core.queue_processor');

    $this->queueItem('Article 1', 'https://a.example/1', 'Outlet A');
    $this->queueItem('Article 2', 'https://b.example/2', 'Outlet B');
    $processor->processBatch(1);
    $processor->processBatch(1);

    $this->assertSame(0, $this->synthCalls);
    $this->assertFalse($this->consensus()->hasConsensus($this->event()));
  }

  /**
   * The single event created by the test run, freshly loaded.
   */
  protected function event() {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $events = $storage->loadByProperties(['type' => 'event']);
    $this->assertCount(1, $events);
    return reset($events);
  }

  /**
   * The consensus service.
   */
  protected function consensus(): ConsensusService {
    return $this->container->get('maemgaba_core.consensus');
  }

}

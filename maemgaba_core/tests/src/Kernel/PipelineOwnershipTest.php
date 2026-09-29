<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\maemgaba_core\Service\PipelineIdentity;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Nodes created by the pipeline services are owned by the pipeline account.
 *
 * Regression for "Author: Anonymous": Drush/cron run as uid 0 and create()
 * had no explicit owner.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class PipelineOwnershipTest extends PipelineKernelTestBase {

  /**
   * Inbound item (IngestionEngine), event and card (QueueProcessor).
   */
  public function testPipelineNodesOwnedByPipelineUser(): void {
    $pipelineUid = $this->provisionPipelineUser();
    $this->assertGreaterThan(1, $pipelineUid);
    $account = $this->container->get('entity_type.manager')->getStorage('user')->load($pipelineUid);
    $this->assertSame(PipelineIdentity::USERNAME, $account->getAccountName());
    $this->assertTrue($account->isBlocked());

    // Current user is anonymous, exactly like Drush/cron.
    $this->assertSame(0, (int) $this->container->get('current_user')->id());

    $html = '<html><head><title>Article</title></head><body><article><h1>Article</h1>'
      . str_repeat('<p>Article text long enough for Readability to keep it.</p>', 10)
      . '</article></body></html>';
    $this->container->set('http_client', new Client([
      'handler' => HandlerStack::create(new MockHandler([new Response(200, [], $html)])),
    ]));

    $queueId = $this->container->get('maemgaba_core.ingestion_engine')
      ->enqueueUrl('https://example.com/article', 'Outlet A');
    $this->assertGreaterThan(0, $queueId);

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame($pipelineUid, (int) $storage->load($queueId)->getOwnerId(), 'inbound_queue owned by pipeline user');

    $this->aiAnalyzer->method('classifyAndCluster')->willReturn($this->analysis(NULL));
    $summary = $this->container->get('maemgaba_core.queue_processor')->processBatch(5);
    $this->assertSame(1, $summary['events_created']);
    $this->assertSame(1, $summary['cards_created']);

    foreach (['event', 'card'] as $bundle) {
      $nodes = $storage->loadByProperties(['type' => $bundle]);
      $this->assertCount(1, $nodes, "one $bundle created");
      $this->assertSame($pipelineUid, (int) reset($nodes)->getOwnerId(), "$bundle owned by pipeline user");
    }
  }

  /**
   * Unset setting: falls back to the "pipeline" account by name, then uid 1.
   */
  public function testUidFallbacks(): void {
    $users = $this->container->get('entity_type.manager')->getStorage('user');
    $users->create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();

    $this->assertSame(0, (int) $this->config('maemgaba_core.settings')->get('pipeline_uid'));
    $this->assertSame(PipelineIdentity::FALLBACK_UID, (new PipelineIdentity(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
    ))->uid());

    $pipeline = $users->create(['name' => PipelineIdentity::USERNAME, 'status' => 0]);
    $pipeline->save();
    // A uid that doesn't exist is treated like unset.
    $this->config('maemgaba_core.settings')->set('pipeline_uid', 9999)->save();
    $this->assertSame((int) $pipeline->id(), (new PipelineIdentity(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
    ))->uid());
  }

  /**
   * The provision() method is idempotent: re-running creates nothing new.
   */
  public function testProvisionIsIdempotent(): void {
    $uid = $this->provisionPipelineUser();
    $this->assertSame([], $this->container->get('maemgaba_core.pipeline_identity')->provision());
    $this->assertSame($uid, (int) $this->config('maemgaba_core.settings')->get('pipeline_uid'));
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('user')
      ->loadByProperties(['name' => PipelineIdentity::USERNAME]));
  }

}

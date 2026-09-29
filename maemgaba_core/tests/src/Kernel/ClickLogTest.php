<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\maemgaba_core\Controller\OutboundController;
use Drupal\maemgaba_core\Service\ClickLog;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Outbound click logging (/out/{card}), the view beacon and the CTR report.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class ClickLogTest extends PipelineKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Clicks redirect and are logged without identifying the reader.
   */
  public function testOutRedirectAndLog(): void {
    [$event, $left, $right] = $this->fixture();
    $controller = OutboundController::create($this->container);

    $response = $controller->out($left, $this->request());
    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame('https://left.example/story', $response->getTargetUrl());
    $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
    $controller->out($left, $this->request());
    $controller->out($right, $this->request());
    // Crawlers are redirected but not counted.
    $controller->out($right, $this->request('Googlebot/2.1 (+http://www.google.com/bot.html)'));

    $rows = $this->container->get('database')->select(ClickLog::TABLE, 'c')->fields('c')->execute()->fetchAll(FetchAs::Associative);
    $this->assertCount(3, $rows);
    $this->assertSame(['id', 'card_nid', 'event_nid', 'outlet', 'bucket', 'created'], array_keys($rows[0]), 'no IP, cookie or user agent column');
    $this->assertSame((string) $event->id(), (string) $rows[0]['event_nid']);
    $this->assertSame('Left Outlet', $rows[0]['outlet']);
    $this->assertSame('left', $rows[0]['bucket']);

    // Not a card, or no URL: 404.
    $this->expectException(NotFoundHttpException::class);
    $controller->out($event, $this->request());
  }

  /**
   * Views give the denominator; CTR per outlet and per bucket.
   */
  public function testReport(): void {
    [$event, $left, $right] = $this->fixture();
    $controller = OutboundController::create($this->container);
    for ($i = 0; $i < 10; $i++) {
      $this->assertSame(204, $controller->view($event, $this->request())->getStatusCode());
    }
    $controller->view($left, $this->request());
    $controller->out($left, $this->request());
    $controller->out($left, $this->request());
    $controller->out($right, $this->request());

    $clickLog = $this->container->get('maemgaba_core.click_log');
    $report = $clickLog->report(time() - 3600);
    $this->assertSame(3, $report['clicks']);
    $this->assertSame(10, $report['views'], 'only event views count');
    // Two cards listed per view.
    $this->assertSame(20, $report['impressions']);
    $this->assertSame(15.0, $report['ctr']);
    $this->assertSame(['clicks' => 2, 'impressions' => 10, 'ctr' => 20.0], $report['outlets']['Left Outlet']);
    $this->assertSame(['clicks' => 1, 'impressions' => 10, 'ctr' => 10.0], $report['outlets']['Right Outlet']);
    $this->assertSame(['clicks' => 0, 'impressions' => 0, 'ctr' => NULL], $report['buckets']['center']);
    $this->assertSame(20.0, $report['buckets']['left']['ctr']);

    $this->assertSame(0, $clickLog->report(time() + 86400 * 2)['clicks'], '--since excludes older clicks');
    $summary = $clickLog->summary();
    $this->assertSame(30, $summary['days']);
    $this->assertSame(15.0, $summary['ctr']);
  }

  /**
   * Event pages link through /out only when the site enables it.
   */
  public function testTemplateLinks(): void {
    [$event, $left] = $this->fixture();
    $card = fn () => array_column($this->preprocess($event)['buckets'], NULL, 'key')['left']['cards'][0];
    $this->assertSame('https://left.example/story', $card()['link_url']);
    $this->assertSame('', $this->preprocess($event)['view_beacon_url']);

    $this->config('maemgaba_core.settings')->set('click_log', ['enabled' => TRUE, 'report_days' => 30])->save();
    $this->assertStringEndsWith('/out/' . $left->id(), $card()['link_url']);
    $this->assertSame('https://left.example/story', $card()['original_url']);
    $this->assertStringEndsWith('/out/view/' . $event->id(), $this->preprocess($event)['view_beacon_url']);
  }

  /**
   * One event with a Left and a Right card.
   *
   * @return \Drupal\node\NodeInterface[]
   *   [event, left card, right card].
   */
  protected function fixture(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $terms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $event = $storage->create(['type' => 'event', 'title' => 'Event', 'status' => 1]);
    $event->save();
    $cards = [];
    foreach (['Left Outlet' => [-2, 'left'], 'Right Outlet' => [2, 'right']] as $outlet => [$score, $slug]) {
      $term = $terms->create(['vid' => 'sources', 'name' => $outlet]);
      $term->save();
      $card = $storage->create([
        'type' => 'card',
        'title' => "$outlet story",
        'field_parent_event' => $event->id(),
        'field_source' => $term->id(),
        'field_bias_score' => $score,
        'field_original_url' => "https://{$slug}.example/story",
        'status' => 1,
      ]);
      $card->save();
      $cards[] = $card;
    }
    return [$event, ...$cards];
  }

  /**
   * A GET request from a browser (or the given user agent).
   */
  protected function request(string $userAgent = 'Mozilla/5.0 (Macintosh) Safari/605'): Request {
    $request = Request::create('/out/1');
    $request->headers->set('User-Agent', $userAgent);
    return $request;
  }

  /**
   * Runs the event preprocessor on the full view.
   */
  protected function preprocess(NodeInterface $event): array {
    $variables = ['node' => $event, 'view_mode' => 'full', 'elements' => []];
    $this->container->get('maemgaba_core.event_preprocessor')->preprocessNode($variables);
    return $variables;
  }

}

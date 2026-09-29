<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\maemgaba_core\Service\ClickLog;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Outbound links (/out/{card}): logs the click, redirects to the article.
 *
 * Uncacheable by design (each hit must reach PHP to be counted) but cheap:
 * one node load and one insert. Also receives the event-page view beacon
 * (/out/view/{event}) that gives click-through its denominator.
 */
class OutboundController extends ControllerBase {

  public function __construct(
    protected ClickLog $clickLog,
    protected KillSwitch $pageCacheKillSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('maemgaba_core.click_log'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * Redirects (302) to the card's original URL, logging the click.
   */
  public function out(NodeInterface $node, Request $request): TrustedRedirectResponse {
    if ($node->bundle() !== 'card' || !$node->isPublished() || $node->get('field_original_url')->isEmpty()) {
      throw new NotFoundHttpException();
    }
    $url = (string) $node->get('field_original_url')->uri;
    if (!preg_match('#^https?://#i', $url)) {
      throw new NotFoundHttpException();
    }
    if ($request->isMethod('GET') && !ClickLog::isBot((string) $request->headers->get('User-Agent', ''))) {
      $this->clickLog->logClick($node);
    }
    $this->pageCacheKillSwitch->trigger();
    $response = new TrustedRedirectResponse($url, 302);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
    return $response;
  }

  /**
   * Counts one event-page view (sendBeacon POST); 204, no body.
   */
  public function view(NodeInterface $node, Request $request): Response {
    if ($node->bundle() === 'event' && $node->isPublished() && !ClickLog::isBot((string) $request->headers->get('User-Agent', ''))) {
      $this->clickLog->logView((int) $node->id());
    }
    $this->pageCacheKillSwitch->trigger();
    return new Response('', 204, ['Cache-Control' => 'no-store, private']);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\maemgaba_core\Service\ConsensusService;
use Drupal\maemgaba_core\Service\EventPreprocessor;
use Drupal\maemgaba_core\Service\EventRankingService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * On-demand consensus synthesis, triggered by a visitor's browser.
 *
 * Trial scope: only events currently ranked in the home page's top 5 by
 * coverage are eligible (see EventRankingService). Visiting one of those
 * event pages fires an async request here after the page has loaded; other
 * event pages never call this endpoint.
 */
class ConsensusController extends ControllerBase {

  /**
   * How many top-ranked events are eligible for on-demand synthesis.
   *
   * EventPreprocessor renders the trigger placeholder for the same set.
   */
  protected const ELIGIBLE_TOP = EventPreprocessor::ELIGIBLE_TOP;

  public function __construct(
    protected ConsensusService $consensus,
    protected EventRankingService $ranking,
    protected LockBackendInterface $lock,
    protected RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('maemgaba_core.consensus'),
      $container->get('maemgaba_core.event_ranking'),
      $container->get('lock'),
      $container->get('renderer'),
    );
  }

  /**
   * Synthesises (or returns already-synthesised) consensus for one event.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   { status: 'ready', html: string } | { status: 'pending' }
   *   | { status: 'ineligible' } | { status: 'busy' }.
   */
  public function synthesize(NodeInterface $node): JsonResponse {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    if ($this->consensus->hasConsensus($node)) {
      return new JsonResponse(['status' => 'ready', 'html' => $this->renderGrid($node)]);
    }

    if (!$this->ranking->isInTop((int) $node->id(), self::ELIGIBLE_TOP)) {
      return new JsonResponse(['status' => 'ineligible'], 403);
    }

    $lock_name = ConsensusService::lockName((int) $node->id());
    if (!$this->lock->acquire($lock_name, ConsensusService::LOCK_TIMEOUT)) {
      return new JsonResponse(['status' => 'busy']);
    }

    try {
      // Not forced: recalculate() is idempotent and enforces the minimum-
      // source-count gate on its own.
      $ok = $this->consensus->recalculate($node);
      return $ok
        ? new JsonResponse(['status' => 'ready', 'html' => $this->renderGrid($node)])
        : new JsonResponse(['status' => 'pending']);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Renders the consensus-grid fragment for an event, as an HTML string.
   */
  protected function renderGrid(NodeInterface $node): string {
    $build = [
      '#theme' => 'event_consensus_grid',
      '#common_points' => $this->points($node, 'field_common_points'),
      '#disputed_points' => $this->points($node, 'field_disputed_points'),
    ];
    return (string) $this->renderer->renderRoot($build);
  }

  /**
   * Reads a multi-value plain-text field into a clean string array.
   */
  protected function points(NodeInterface $node, string $field): array {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return [];
    }
    $values = [];
    foreach ($node->get($field) as $item) {
      $values[] = strip_tags((string) $item->value);
    }
    return $values;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\maemgaba_core\Service\EventRankingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Builds the front page.
 *
 * Leads with an advertising leaderboard and a section header, then the top-
 * ranked event as a full-width "DESTAQUE" teaser, followed by an infinitely-
 * scrolling grid of the remaining events ordered by time-decayed coverage
 * (see EventRankingService). Only relevant events from the last 33 days are
 * shown.
 */
class HomeController extends ControllerBase {

  /**
   * Events loaded per grid page (initial render and each scroll batch).
   */
  protected const PER_PAGE = 12;

  public function __construct(
    protected EventRankingService $ranking,
  ) {}

  /**
   * Front-page title: the site name (system.site), not a hardcoded brand.
   */
  public function title(): string {
    return (string) $this->config('system.site')->get('name');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('maemgaba_core.event_ranking'));
  }

  /**
   * Renders the front page.
   *
   * @return array
   *   The front-page render array.
   */
  public function home(): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $view_builder = $this->entityTypeManager()->getViewBuilder('node');
    $featured_id = $this->featuredId();

    $build = [
      '#cache' => [
        // node_list:card so relevance/coverage changes rebuild the listing.
        'tags' => ['node_list:event', 'node_list:card'],
        'contexts' => ['user.node_grants:view'],
        // The ranking score decays with event age, so the order drifts even
        // without content changes — re-rank at most hourly.
        'max-age' => 3600,
      ],
    ];

    // Events section: header + featured teaser + grid. (The advertising
    // leaderboard is now the "Publicidade" block in the highlighted region,
    // shown site-wide, not hardcoded here.)
    $events = [
      '#type' => 'container',
      '#attributes' => ['class' => ['home-events']],
      'head' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['section-head']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Tracked events'),
          '#attributes' => ['class' => ['section-head__title']],
        ],
        'subtitle' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Coverage from many outlets, classified by bias.'),
          '#attributes' => ['class' => ['section-head__subtitle']],
        ],
      ],
    ];

    if ($featured_id !== NULL) {
      $featured = $storage->load($featured_id);
      if ($featured) {
        $teaser = $view_builder->view($featured, 'teaser');
        // Flag the home hero so the teaser renders as the full-width banner
        // (independent of the field_featured admin flag).
        $teaser['#is_home_hero'] = TRUE;
        $events['featured'] = $teaser;
      }
    }

    // First grid page (offset 0), highest-ranked first, excluding the hero.
    [$grid_ids, $has_more] = $this->gridPage(0, $featured_id);
    $events['grid'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['home-events__grid'],
        'data-infinite-scroll' => 'true',
      ],
      'items' => $this->renderTeasers($grid_ids),
    ];

    $build['events'] = $events;

    // Infinite-scroll wiring: the engine library (js/engine.js) plus the
    // pager state. The JS appends the next page number directly to
    // moreUrlBase, so build it from the route with a placeholder page and
    // trim that placeholder back off — that way it stays correct under path
    // aliases, language prefixes, etc.
    $page_placeholder = '999999';
    $more_url = Url::fromRoute('maemgaba_core.home_more', ['page' => $page_placeholder])->toString();
    $build['#attached']['library'][] = 'maemgaba_core/engine';
    $build['#attached']['drupalSettings']['maemgabaCore']['infiniteScroll'] = [
      'moreUrlBase' => substr($more_url, 0, -strlen($page_placeholder)),
      'nextPage' => 1,
      'hasMore' => $has_more,
    ];

    return $build;
  }

  /**
   * AJAX endpoint: returns the next grid page as rendered teaser markup.
   *
   * @param int $page
   *   Zero-based page index (the initial render is page 0, so this starts
   *   at 1).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   { html: string, has_more: bool }.
   */
  public function more(int $page): JsonResponse {
    $page = max(1, $page);
    [$ids, $has_more] = $this->gridPage($page, $this->featuredId());

    $list = $this->renderTeasers($ids);
    $html = $list ? (string) \Drupal::service('renderer')->renderRoot($list) : '';

    return new JsonResponse(['html' => $html, 'has_more' => $has_more]);
  }

  /**
   * Renders event teasers in the exact order of the given ids.
   *
   * @param array $ids
   *   Ordered event node ids.
   *
   * @return array
   *   List of teaser render arrays.
   */
  protected function renderTeasers(array $ids): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $view_builder = $this->entityTypeManager()->getViewBuilder('node');
    // loadMultiple() ignores id order, so re-key by the sorted list.
    $nodes = $storage->loadMultiple($ids);
    $teasers = [];
    foreach ($ids as $id) {
      if (isset($nodes[$id])) {
        $teasers[] = $view_builder->view($nodes[$id], 'teaser');
      }
    }
    return $teasers;
  }

  /**
   * The home hero: the top-ranked relevant event within the window.
   */
  protected function featuredId(): ?int {
    $ids = $this->ranking->topEventIds(1);
    return $ids ? $ids[0] : NULL;
  }

  /**
   * Returns one page of grid event ids plus whether more remain.
   *
   * @param int $page
   *   Zero-based page index.
   * @param int|null $featured_id
   *   Event id to exclude (the featured hero), or NULL.
   *
   * @return array
   *   [ (int[]) node ids, (bool) has_more ].
   */
  protected function gridPage(int $page, ?int $featured_id): array {
    return $this->ranking->page($page * self::PER_PAGE, self::PER_PAGE, $featured_id);
  }

}

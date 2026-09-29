<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\maemgaba_core\Service\EventSearch;
use Drupal\maemgaba_core\Service\SemanticSearchLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The /search semantic search page.
 *
 * Who may open it is decided by SearchAccessCheck (public when
 * maemgaba_core.settings:search_public is TRUE, evaluator-trial otherwise).
 * Every uncached query costs one embedding call, so anyone without the
 * admin permission is held to a per-IP flood limit (search_flood). Cached
 * repeats of a query are served by the page cache and cost nothing; they are
 * neither counted nor logged.
 *
 * Results come from EventSearch (MariaDB native vectors through Search API),
 * the same code maemgaba:search-eval measures. Each executed query is logged
 * by SemanticSearchLogger.
 */
class SearchController extends ControllerBase {

  /**
   * Results per page.
   */
  protected const PER_PAGE = 12;

  /**
   * Flood event name.
   */
  public const FLOOD_EVENT = 'maemgaba_core.search';

  /**
   * Flood defaults when maemgaba_core.settings:search_flood is unset.
   */
  public const FLOOD_LIMIT = 30;
  public const FLOOD_WINDOW = 600;

  public function __construct(
    protected PagerManagerInterface $pagerManager,
    protected SemanticSearchLogger $searchLogger,
    protected EventSearch $eventSearch,
    protected FloodInterface $flood,
    protected KillSwitch $pageCacheKillSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('pager.manager'),
      $container->get('maemgaba_core.semantic_search_logger'),
      $container->get('maemgaba_core.event_search'),
      $container->get('flood'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * Renders the search form and, if a query was submitted, its results.
   */
  public function search(Request $request): array {
    $query = trim((string) $request->query->get('q', ''));
    // Example queries for the landing state are site content (locale pack).
    $examples = array_values((array) $this->config('maemgaba_core.locale')->get('search_examples'));

    $build = [
      '#cache' => [
        'contexts' => ['url.query_args:q', 'url.query_args:page'],
        'tags' => ['node_list:event', 'node_list:card', 'config:maemgaba_core.locale', 'config:maemgaba_core.settings'],
      ],
    ];

    $build['hero'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['busca-hero']],
      'title' => [
        // <h2>, not <h1>: the masthead already owns the page's one <h1>
        // (site branding, see page.html.twig) — see also page-title.html.twig.
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Semantic search'),
        '#attributes' => ['class' => ['busca-hero__title']],
      ],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Describe the subject in your own words. We find events by meaning, not just by keyword.'),
        '#attributes' => ['class' => ['busca-hero__description']],
      ],
    ];

    $build['form'] = [
      '#type' => 'html_tag',
      '#tag' => 'form',
      '#attributes' => [
        'class' => ['busca-form'],
        'method' => 'get',
        'action' => Url::fromRoute('maemgaba_core.busca')->toString(),
      ],
      'input' => [
        '#type' => 'html_tag',
        '#tag' => 'input',
        '#attributes' => [
          'type' => 'text',
          'name' => 'q',
          'value' => $query,
          'placeholder' => $this->t('E.g. @example', ['@example' => $examples[0] ?? '']),
          'class' => ['busca-form__input'],
        ],
      ],
      'submit' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Search →'),
        '#attributes' => ['type' => 'submit', 'class' => ['busca-form__submit']],
      ],
    ];

    if ($query === '') {
      $build['examples'] = $this->buildExamples($examples);
      $build['features'] = $this->buildFeatures();
      return $build;
    }

    if (!$this->floodAllows($request)) {
      // Never cache the refusal: the next visitor with the same query and
      // every later request from this IP after the window must get results.
      $this->pageCacheKillSwitch->trigger();
      $build['#cache']['max-age'] = 0;
      $build['#attached']['http_header'][] = ['Status', 429];
      $build['#attached']['http_header'][] = ['Retry-After', (string) $this->floodWindow()];
      $build['results'] = [
        '#markup' => '<p class="events-empty">' . $this->t('Too many searches from your connection. Please wait a few minutes and try again.') . '</p>',
      ];
      return $build;
    }

    $build['results'] = $this->buildResults($query);
    return $build;
  }

  /**
   * The "Try" row of clickable example queries (landing state only).
   *
   * @param string[] $examples
   *   Example queries from maemgaba_core.locale:search_examples.
   */
  protected function buildExamples(array $examples): array {
    if (!$examples) {
      return [];
    }
    $chips = [
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Try'),
        '#attributes' => ['class' => ['busca-examples__label']],
      ],
    ];
    foreach ($examples as $i => $example) {
      $chips['chip_' . $i] = [
        '#type' => 'link',
        '#title' => $example,
        '#url' => Url::fromRoute('maemgaba_core.busca', [], ['query' => ['q' => $example]]),
        '#attributes' => ['class' => ['busca-examples__chip']],
      ];
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['busca-examples']],
    ] + $chips;
  }

  /**
   * The three-column "how this works" explainer (landing state only).
   */
  protected function buildFeatures(): array {
    $items = [
      [
        'label' => $this->t('By meaning'),
        'text' => $this->t('A query finds events about the same subject even when the title uses different words.'),
      ],
      [
        'label' => $this->t('Every perspective'),
        'text' => $this->t('Each result is an event with its coverage grouped by bias, side by side.'),
      ],
      [
        'label' => $this->t('Relevance'),
        'text' => $this->t('Results are ordered by how close their meaning is to your query, closest first.'),
      ],
    ];

    $build = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['busca-features']],
    ];
    foreach ($items as $i => $item) {
      $build['item_' . $i] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $item['label'],
          '#attributes' => ['class' => ['busca-features__label']],
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $item['text'],
          '#attributes' => ['class' => ['busca-features__text']],
        ],
      ];
    }
    return $build;
  }

  /**
   * Runs the search and builds the results section (teasers + pager).
   */
  protected function buildResults(string $query): array {
    $index = $this->eventSearch->index();
    if (!$index) {
      return ['#markup' => '<p>' . $this->t('Search is temporarily unavailable.') . '</p>'];
    }

    try {
      $matches = $this->eventSearch->search($query);
    }
    catch (\Throwable $e) {
      $this->getLogger('maemgaba_core')->error('Semantic search failed: @msg', ['@msg' => $e->getMessage()]);
      return ['#markup' => '<p>' . $this->t('Something went wrong with the search. Please try again.') . '</p>'];
    }

    $top_score = $matches ? round(1 - $matches[0]['distance'], 4) : NULL;
    $keyword_would_be_zero = $this->eventSearch->keywordMatches($query, 1) === [];
    $this->searchLogger->log($query, count($matches), $top_score, $keyword_would_be_zero);

    if (!$matches) {
      return ['#markup' => '<p class="events-empty">' . $this->t('No events found for this search.') . '</p>'];
    }

    // Paginate the already-ranked, already-filtered match list in PHP.
    $total = count($matches);
    $pager = $this->pagerManager->createPager($total, self::PER_PAGE);
    $page = $pager->getCurrentPage();
    $page_items = array_slice($matches, $page * self::PER_PAGE, self::PER_PAGE);

    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple(array_column($page_items, 'nid'));
    $view_builder = $this->entityTypeManager()->getViewBuilder('node');

    $teasers = [];
    foreach ($page_items as $match) {
      $node = $nodes[$match['nid']] ?? NULL;
      if ($node && $node->access('view')) {
        $teasers[] = $view_builder->view($node, 'teaser');
      }
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['view-events']],
      'count' => [
        '#markup' => '<p class="section-head__subtitle">' . $this->formatPlural($total, '1 event found', '@count events found') . '</p>',
      ],
      'content' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['view-content']],
        'items' => $teasers,
      ],
      'pager' => ['#type' => 'pager'],
    ];
  }

  /**
   * Registers one query against the per-IP flood limit.
   *
   * Admins (administer news engine semantic search) are exempt.
   *
   * @return bool
   *   FALSE when this IP has used up its window.
   */
  protected function floodAllows(Request $request): bool {
    if ($this->currentUser()->hasPermission('administer news engine semantic search')) {
      return TRUE;
    }
    $limit = $this->floodLimit();
    $window = $this->floodWindow();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, $limit, $window)) {
      return FALSE;
    }
    $this->flood->register(self::FLOOD_EVENT, $window);
    return TRUE;
  }

  /**
   * Queries allowed per window.
   */
  protected function floodLimit(): int {
    $value = $this->config('maemgaba_core.settings')->get('search_flood.limit');
    return is_numeric($value) && $value > 0 ? (int) $value : self::FLOOD_LIMIT;
  }

  /**
   * Flood window in seconds.
   */
  protected function floodWindow(): int {
    $value = $this->config('maemgaba_core.settings')->get('search_flood.window');
    return is_numeric($value) && $value > 0 ? (int) $value : self::FLOOD_WINDOW;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteNotFoundException;
use Drupal\Core\Url;
use Drupal\maemgaba_core\Service\ClickLog;
use Drupal\maemgaba_core\Service\RubricService;
use Drupal\maemgaba_core\Service\SpectrumService;
use Drupal\maemgaba_core\Service\SuggestionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the methodology page.
 *
 * The copy is site content, not code: it lives in the maemgaba_core.methodology
 * config object (each site writes its own, versioned with its config/sync).
 * Bodies are trusted editorial HTML (they carry the page's callouts, rules
 * and inline icons) filtered with the admin tag list plus inline SVG, and
 * support two tokens: [site_name] and [route:ROUTE_NAME] (internal links,
 * so a pack's path aliases are respected). Dynamic parts:
 * the contributors list (accepted correction suggestions) and the outbound
 * click-through aggregate (ClickLog::summary()), available to the template
 * as click_stats and to the copy as [clicks:ctr] (percentage),
 * [clicks:clicks], [clicks:views], [clicks:impressions] and [clicks:days].
 * [rubric:version] and [rubric:date] come from maemgaba_core.rubric;
 * [cards:classified], [cards:partial] and [cards:partial_share] (percentage)
 * count classified articles and those classified from partial text
 * (headline + feed abstract), for the paywall disclosure.
 */
class MethodologyController extends ControllerBase {

  /**
   * Tags allowed in section bodies beyond Xss::getAdminTagList().
   */
  protected const EXTRA_TAGS = ['svg', 'path', 'rect', 'polygon', 'section', 'code'];

  /**
   * The click-through aggregate, computed on first use.
   */
  protected ?array $clickStats = NULL;

  /**
   * The partial-text counts, computed on first use.
   */
  protected ?array $cardStats = NULL;

  public function __construct(
    protected SuggestionManager $suggestions,
    protected SpectrumService $spectrum,
    protected ClickLog $clickLog,
    protected RubricService $rubric,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('maemgaba_core.suggestion_manager'),
      $container->get('maemgaba_core.spectrum'),
      $container->get('maemgaba_core.click_log'),
      $container->get('maemgaba_core.rubric'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * Renders the methodology page.
   */
  public function page(): array {
    $config = $this->config('maemgaba_core.methodology');

    $sections = [];
    foreach ((array) $config->get('sections') as $i => $section) {
      $sections[] = [
        'id' => (string) ($section['id'] ?? 's' . ($i + 1)),
        'number' => sprintf('%02d', $i + 1),
        'title' => $this->tokens((string) ($section['title'] ?? '')),
        'nav_title' => $this->tokens((string) (($section['nav_title'] ?? '') ?: ($section['title'] ?? ''))),
        'modifier' => (string) ($section['modifier'] ?? ''),
        'body' => $this->html((string) ($section['body'] ?? '')),
        'contributors' => isset($section['contributors']) ? [
          'intro' => $this->html((string) ($section['contributors']['intro'] ?? '')),
          'empty' => $this->html((string) ($section['contributors']['empty'] ?? '')),
        ] : NULL,
        'body_after' => $this->html((string) ($section['body_after'] ?? '')),
      ];
    }

    $steps = [];
    foreach ((array) $config->get('steps') as $step) {
      $steps[] = [
        'icon' => (string) ($step['icon'] ?? ''),
        'title' => $this->tokens((string) ($step['title'] ?? '')),
        'description' => $this->tokens((string) ($step['description'] ?? '')),
      ];
    }

    return [
      '#theme' => 'methodology_page',
      '#attached' => ['library' => ['maemgaba_core/engine']],
      '#methodology' => [
        'title' => $this->tokens((string) $config->get('title')),
        'lede' => $this->html((string) $config->get('lede')),
        'pipeline_label' => $this->tokens((string) $config->get('pipeline_label')),
        'steps' => $steps,
        'nav_label' => $this->tokens((string) $config->get('nav_label')),
        'sections' => $sections,
        'revision' => $this->tokens((string) $config->get('revision')),
      ],
      '#buckets' => $this->spectrum->buckets(),
      '#contributors' => $this->suggestions->acceptedCreditNames(),
      '#click_stats' => $this->clickLog->enabled() ? $this->clickStats() : NULL,
      '#cache' => [
        'tags' => [
          SuggestionManager::CACHE_TAG,
          ClickLog::CACHE_TAG,
          'config:maemgaba_core.settings',
          'config:maemgaba_core.methodology',
          'config:maemgaba_core.rubric',
          'node_list:card',
          'config:maemgaba_core.spectrum',
          'config:system.site',
        ],
      ],
    ];
  }

  /**
   * Replaces [site_name], [rubric:*], [cards:*], [clicks:*] and [route:NAME].
   */
  protected function tokens(string $text): string {
    $text = strtr($text, [
      '[site_name]' => (string) $this->config('system.site')->get('name'),
      '[rubric:version]' => $this->rubric->version(),
      '[rubric:date]' => $this->rubric->date(),
    ]);
    if (str_contains($text, '[cards:')) {
      $cards = $this->cardStats();
      $text = strtr($text, [
        '[cards:classified]' => number_format($cards['classified']),
        '[cards:partial]' => number_format($cards['partial']),
        '[cards:partial_share]' => $cards['classified'] > 0 ? number_format($cards['partial'] / $cards['classified'] * 100, 1) . '%' : (string) $this->t('n/a'),
      ]);
    }
    if (str_contains($text, '[clicks:')) {
      $stats = $this->clickStats();
      $text = strtr($text, [
        '[clicks:ctr]' => $stats['ctr'] === NULL ? (string) $this->t('n/a') : number_format($stats['ctr'], 1) . '%',
        '[clicks:clicks]' => number_format($stats['clicks']),
        '[clicks:views]' => number_format($stats['views']),
        '[clicks:impressions]' => number_format($stats['impressions']),
        '[clicks:days]' => (string) $stats['days'],
      ]);
    }
    return (string) preg_replace_callback('/\[route:([a-z0-9_.]+)\]/', function (array $match): string {
      try {
        return Url::fromRoute($match[1])->toString();
      }
      catch (RouteNotFoundException) {
        return '#';
      }
    }, $text);
  }

  /**
   * Classified articles and those classified from partial text, memoized.
   *
   * @return array{classified: int, partial: int}
   *   Counts over published cards with a bias score.
   */
  protected function cardStats(): array {
    if ($this->cardStats !== NULL) {
      return $this->cardStats;
    }
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = fn () => $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'card')
      ->condition('status', 1)
      ->exists('field_bias_score');
    $fields = $this->entityFieldManager->getFieldDefinitions('node', 'card');
    return $this->cardStats = [
      'classified' => (int) $query()->count()->execute(),
      'partial' => isset($fields['field_partial_text']) ? (int) $query()->condition('field_partial_text', 1)->count()->execute() : 0,
    ];
  }

  /**
   * The click-through aggregate (ClickLog::summary()), memoized.
   */
  protected function clickStats(): array {
    return $this->clickStats ??= $this->clickLog->summary();
  }

  /**
   * Token-replaced, filtered editorial HTML.
   */
  protected function html(string $text): Markup|string {
    return Markup::create(Xss::filter($this->tokens($text), array_merge(Xss::getAdminTagList(), self::EXTRA_TAGS)));
  }

}

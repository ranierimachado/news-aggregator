<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\maemgaba_core\Controller\MethodologyController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * The engine owns its templates, theme hooks and event preprocessing.
 *
 * A site theme only has to style the markup: every hook that renders engine
 * data is registered by maemgaba_core, its templates live in the module,
 * and the event variables come from EventPreprocessor.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class EngineThemeTest extends PipelineKernelTestBase {

  /**
   * Every engine hook is registered by the module, with its own template.
   */
  public function testThemeHooksAreModuleOwned(): void {
    $registry = $this->container->get('theme.registry')->get();
    $templates = $this->container->get('extension.list.module')->getPath('maemgaba_core') . '/templates';

    foreach ([
      'spectrum_bar',
      'event_consensus_grid',
      'source_list',
      'sources_page',
      'methodology_page',
      'suggestion_thanks',
      'node__event',
      'node__event__teaser',
    ] as $hook) {
      $this->assertArrayHasKey($hook, $registry, "$hook is registered");
      $this->assertSame($templates, $registry[$hook]['path'], "$hook template lives in the module");
      $this->assertFileExists($templates . '/' . $registry[$hook]['template'] . '.html.twig');
    }
    // The node templates are suggestions of core's node hook, so they get
    // core's node preprocessing and can be overridden by a theme copy.
    $this->assertSame('node', $registry['node__event']['base hook']);
    $this->assertSame('node', $registry['node__event__teaser']['base hook']);
    // Core builds label, content, url, ... in node's initial preprocess;
    // maemgaba_core_theme_registry_alter() copies it onto the suggestions.
    $this->assertNotEmpty($registry['node']['initial preprocess']);
    $this->assertSame($registry['node']['initial preprocess'], $registry['node__event']['initial preprocess']);
    $this->assertSame($registry['node']['initial preprocess'], $registry['node__event__teaser']['initial preprocess']);
    $this->assertContains('maemgaba_core_preprocess_node', $registry['node__event']['preprocess functions']);
    $this->assertContains('maemgaba_core_preprocess_node', $registry['node__event__teaser']['preprocess functions']);
    // The old theme-owned names are gone.
    foreach (['maemgaba_sources', 'maemgaba_methodology', 'maemgaba_suggestion_thanks'] as $hook) {
      $this->assertArrayNotHasKey($hook, $registry);
    }
  }

  /**
   * The spectrum bar renders one segment per bucket, keyed and sized.
   */
  public function testSpectrumBarMarkup(): void {
    $segments = [
      ['key' => 'left', 'color' => '#1d5fa8', 'width' => 25],
      ['key' => 'center', 'color' => '#9b9797', 'width' => 50],
      ['key' => 'right', 'color' => '#ec3013', 'width' => 25],
    ];
    $html = $this->renderHook(['#theme' => 'spectrum_bar', '#segments' => $segments, '#size' => 'small']);
    $this->assertStringContainsString('class="spectrum-bar spectrum-bar--small"', $html);
    $this->assertSame(3, substr_count($html, 'spectrum-bar__segment '));
    $this->assertStringContainsString('spectrum-bar__segment--center" style="--bucket-color: var(--bucket-center-color, #9b9797); width: 50%"', $html);

    $html = $this->renderHook(['#theme' => 'spectrum_bar', '#segments' => $segments, '#size' => '']);
    $this->assertStringContainsString('class="spectrum-bar"', $html);
  }

  /**
   * Renders a render array in isolation and returns the markup.
   */
  protected function renderHook(array $build): string {
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * The consensus grid lists common and disputed points.
   */
  public function testConsensusGridMarkup(): void {
    $html = $this->renderHook([
      '#theme' => 'event_consensus_grid',
      '#common_points' => ['Everyone agrees', 'On this too'],
      '#disputed_points' => ['This is contested'],
    ]);
    $this->assertSame(2, substr_count($html, 'consensus-item--common'));
    $this->assertSame(1, substr_count($html, 'consensus-item--dispute'));
    $this->assertStringContainsString('COMMON GROUND', $html);
    $this->assertStringContainsString('This is contested', $html);
  }

  /**
   * Bucket colours from maemgaba_core.spectrum reach every page as CSS vars.
   */
  public function testBucketColorsAttachedToPages(): void {
    $attachments = [];
    maemgaba_core_page_attachments($attachments);
    $style = NULL;
    foreach ($attachments['#attached']['html_head'] as [$element, $key]) {
      if ($key === 'maemgaba_core_bucket_colors') {
        $style = $element;
      }
    }
    $this->assertNotNull($style);
    $this->assertSame('style', $style['#tag']);
    $this->assertSame(':root{--bucket-left-color:#1d5fa8;--bucket-center-color:#9b9797;--bucket-right-color:#ec3013}', (string) $style['#value']);
    $this->assertContains('config:maemgaba_core.spectrum', $attachments['#cache']['tags']);
  }

  /**
   * EventPreprocessor derives the event variables from its cards.
   */
  public function testEventPreprocessor(): void {
    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    $terms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $outlet = $terms->create(['vid' => 'sources', 'name' => 'Daily Planet']);
    $outlet->save();

    $event = $nodes->create([
      'type' => 'event',
      'title' => 'Something happened',
      'field_topic' => 'economy',
      'field_common_points' => ['Point A'],
      'field_disputed_points' => ['Point B', 'Point C'],
    ]);
    $event->save();
    foreach ([-2, -1, 0, 2] as $i => $score) {
      $nodes->create([
        'type' => 'card',
        'title' => "Card $i",
        'field_parent_event' => $event->id(),
        'field_bias_score' => $score,
        'field_source' => $outlet->id(),
        'field_micro_summary' => '<p>Summary</p>',
        'field_original_url' => ['uri' => "https://example.com/$i"],
      ])->save();
    }

    $preprocess = $this->container->get('maemgaba_core.event_preprocessor');

    // Full view: card details, exact bar widths, consensus grid.
    $variables = ['node' => $event, 'view_mode' => 'full', 'elements' => []];
    $preprocess->preprocessNode($variables);
    $this->assertSame(['left', 'center', 'right'], array_column($variables['buckets'], 'key'));
    $this->assertSame([2, 1, 1], array_column($variables['buckets'], 'count'));
    $this->assertSame(['LEFT', 'CENTER', 'RIGHT'], array_column($variables['buckets'], 'label'));
    $this->assertSame(4, $variables['total_sources']);
    $this->assertSame(3, $variables['bucket_count']);
    $this->assertSame('ECONOMY', $variables['category']);
    $this->assertFalse($variables['is_featured']);
    $this->assertSame([50.0, 25.0, 25.0], array_column($variables['spectrum_bar']['#segments'], 'width'));
    $this->assertSame('', $variables['spectrum_bar']['#size']);
    $card = $variables['buckets'][0]['cards'][0];
    $this->assertSame('Daily Planet', $card['source_name']);
    $this->assertSame('DP', $card['source_initials']);
    $this->assertSame('Summary', $card['micro_summary']);
    $this->assertSame('LEFT', $card['bias_label']);
    $this->assertSame('event_consensus_grid', $variables['consensus_grid']['#theme']);
    $this->assertSame(['Point B', 'Point C'], $variables['disputed_points']);
    $this->assertFalse($variables['is_home_top5']);
    $this->assertContains('maemgaba_core/engine', $variables['#attached']['library']);
    $this->assertContains('config:maemgaba_core.spectrum', $variables['#cache']['tags']);
    $this->assertNotSame('', $variables['event_date']);

    // Teaser: ids only, integer percentages, small bar; hero gets the large
    // one.
    $variables = ['node' => $event, 'view_mode' => 'teaser', 'elements' => []];
    $preprocess->preprocessNode($variables);
    $this->assertSame(['id' => $variables['buckets'][0]['cards'][0]['id']], $variables['buckets'][0]['cards'][0]);
    $this->assertSame([50, 25, 25], array_column($variables['spectrum_bar']['#segments'], 'width'));
    $this->assertSame('small', $variables['spectrum_bar']['#size']);
    $variables = ['node' => $event, 'view_mode' => 'teaser', 'elements' => ['#is_home_hero' => TRUE]];
    $preprocess->preprocessNode($variables);
    $this->assertTrue($variables['is_featured']);
    $this->assertSame('large', $variables['spectrum_bar']['#size']);

    // No cards: an even placeholder bar summing to 100.
    $empty = $nodes->create(['type' => 'event', 'title' => 'Quiet']);
    $empty->save();
    $variables = ['node' => $empty, 'view_mode' => 'full', 'elements' => []];
    $preprocess->preprocessNode($variables);
    $this->assertSame(0, $variables['total_sources']);
    $this->assertEqualsWithDelta(100, array_sum(array_column($variables['buckets'], 'width')), 0.001);
    $this->assertSame([], $variables['consensus_grid']);

    // Not an event: untouched.
    $variables = ['node' => $nodes->create(['type' => 'card', 'title' => 'x']), 'view_mode' => 'full'];
    $preprocess->preprocessNode($variables);
    $this->assertArrayNotHasKey('buckets', $variables);
  }

  /**
   * The teaser prints the neutral summary escaped once.
   *
   * It used to render the basic_html field, strip the tags and print the
   * result, which Twig escaped again: readers saw "S&amp;P 500".
   */
  public function testTeaserSummaryEscapedOnce(): void {
    $dir = $this->container->get('extension.list.module')->getPath('maemgaba_core') . '/config/install';
    $this->container->get('entity_type.manager')->getStorage('entity_view_display')
      ->createFromStorageRecord(Yaml::parseFile("$dir/core.entity_view_display.node.event.teaser.yml"))->save();
    $this->container->get('entity_type.manager')->getStorage('filter_format')
      ->create([
        'format' => 'basic_html',
        'name' => 'Basic HTML',
        // As on both sites; filter_html re-serializes "&" as "&amp;".
        'filters' => ['filter_html' => ['status' => TRUE, 'settings' => ['allowed_html' => '<p> <a href>']]],
      ])->save();

    $event = $this->container->get('entity_type.manager')->getStorage('node')->create([
      'type' => 'event',
      'title' => 'Markets',
      'field_neutral_summary' => ['value' => '<p>The S&P 500 rose; 1 < 2 and 3 > 2.</p>', 'format' => 'basic_html'],
    ]);
    $event->save();

    $variables = [
      'node' => $event,
      'view_mode' => 'teaser',
      'elements' => [],
      'content' => ['field_neutral_summary' => ['#markup' => 'x']],
    ];
    $this->container->get('maemgaba_core.event_preprocessor')->preprocessNode($variables);
    $this->assertSame('The S&P 500 rose; 1 < 2 and 3 > 2.', $variables['summary_text']);
    // Hidden in the view display: no summary.
    $variables = ['node' => $event, 'view_mode' => 'teaser', 'elements' => [], 'content' => []];
    $this->container->get('maemgaba_core.event_preprocessor')->preprocessNode($variables);
    $this->assertSame('', $variables['summary_text']);

    $html = $this->renderHook($this->container->get('entity_type.manager')->getViewBuilder('node')->view($event, 'teaser'));
    $this->assertStringContainsString('The S&amp;P 500 rose; 1 &lt; 2 and 3 &gt; 2.', $html);
    $this->assertStringNotContainsString('&amp;amp;', $html);
  }

  /**
   * Card display policy: summaries withheld, framing line and labels shown.
   */
  public function testCardDisplayPolicy(): void {
    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    $event = $nodes->create([
      'type' => 'event',
      'title' => 'Policy event',
      'field_common_points' => ['Point A'],
    ]);
    $event->save();
    $nodes->create([
      'type' => 'card',
      'title' => 'Paywalled headline',
      'field_parent_event' => $event->id(),
      'field_bias_score' => -1,
      'field_micro_summary' => '<p>An AI summary of the article</p>',
      'field_framing_line' => 'Frames the vote as a rebuke of the leadership.',
      'field_partial_text' => TRUE,
      'field_original_url' => ['uri' => 'https://example.com/paywalled'],
    ])->save();
    $preprocess = $this->container->get('maemgaba_core.event_preprocessor');

    // Unset policy: summary shown, framing line withheld, no
    // label.
    $variables = ['node' => $event, 'view_mode' => 'full', 'elements' => []];
    $preprocess->preprocessNode($variables);
    $card = $variables['buckets'][0]['cards'][0];
    $this->assertSame('An AI summary of the article', $card['micro_summary']);
    $this->assertSame('', $card['framing_line']);
    $this->assertTrue($card['partial_text']);
    $this->assertSame('', $variables['ai_label']);

    // A site that bans per-article summaries and labels AI output.
    $this->config('maemgaba_core.settings')
      ->set('card_display', ['micro_summary' => FALSE, 'framing_line' => TRUE])
      ->set('ai_label', 'AI-generated synthesis, rubric v@version')
      ->save();
    $this->config('maemgaba_core.rubric')->set('rubric_version', '1.0')->save();
    $variables = ['node' => $event, 'view_mode' => 'full', 'elements' => []];
    $preprocess->preprocessNode($variables);
    $card = $variables['buckets'][0]['cards'][0];
    $this->assertSame('', $card['micro_summary']);
    $this->assertSame('Frames the vote as a rebuke of the leadership.', $card['framing_line']);
    $this->assertSame('AI-generated synthesis, rubric v1.0', $variables['ai_label']);
    $this->assertContains('config:maemgaba_core.rubric', $variables['#cache']['tags']);

    // The engine template renders exactly that.
    $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($event, 'full');
    $html = $this->renderHook($build);
    $this->assertStringNotContainsString('An AI summary', $html);
    $this->assertStringContainsString('<p class="card-framing-line">Frames the vote as a rebuke of the leadership.</p>', $html);
    $this->assertStringContainsString('card-partial-badge', $html);
    $this->assertStringContainsString('<p class="event-ai-label">AI-generated synthesis, rubric v1.0</p>', $html);
  }

  /**
   * Methodology copy gets the rubric version and the headline-only share.
   */
  public function testMethodologyTokens(): void {
    $this->installSchema('maemgaba_core', ['maemgaba_suggestion']);
    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    foreach ([TRUE, FALSE, FALSE, FALSE] as $i => $partial) {
      $nodes->create([
        'type' => 'card',
        'title' => "Card $i",
        'field_bias_score' => 0,
        'field_partial_text' => $partial,
      ])->save();
    }
    // Unclassified cards are not counted.
    $nodes->create(['type' => 'card', 'title' => 'Unscored', 'field_partial_text' => TRUE])->save();
    $this->config('maemgaba_core.rubric')->set('rubric_version', '1.0')->set('rubric_date', '2026-09-24')->save();
    $this->config('maemgaba_core.methodology')
      ->set('lede', 'Rubric v[rubric:version] ([rubric:date]); [cards:partial] of [cards:classified] ([cards:partial_share]) headline-only.')
      ->save();

    $build = MethodologyController::create($this->container)->page();
    $this->assertSame('Rubric v1.0 (2026-09-24); 1 of 4 (25.0%) headline-only.', (string) $build['#methodology']['lede']);
    $this->assertContains('node_list:card', $build['#cache']['tags']);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\maemgaba_core\Migration\ContentLanguageRelabel;
use Drupal\locale\Gettext;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\maemgaba_core\AiAnalyzerService;
use Drupal\maemgaba_core\Service\RelativeTime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Locale-pack config drives topics, prompt tokens and the outlet registry.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class LocalePackTest extends PipelineKernelTestBase {

  /**
   * The field_topic allowed values are maemgaba_core.topics, not storage YAML.
   */
  public function testTopicsFromConfig(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('field_storage_config')->load('node.field_topic');
    $this->assertSame('maemgaba_core_topic_allowed_values', $storage->getSetting('allowed_values_function'));
    $this->assertSame($this->container->get('maemgaba_core.topics')->options(), options_allowed_values($storage));
    $this->assertSame('Politics', options_allowed_values($storage)['politics']);

    // A site pack with its own vocabulary (keys and labels).
    $this->config('maemgaba_core.topics')->setData([
      'topics' => [['key' => 'congress', 'label' => 'Congress'], ['key' => 'courts', 'label' => 'Courts']],
      'fallback' => 'courts',
    ])->save();
    drupal_static_reset('options_allowed_values');
    $this->assertSame(['congress' => 'Congress', 'courts' => 'Courts'], options_allowed_values($storage));

    $topics = $this->container->get('maemgaba_core.topics');
    $this->assertSame('"congress", "courts"', $topics->promptList());
    $this->assertSame('courts', $topics->whitelist('politics'));
    $this->assertSame('congress', $topics->whitelist('congress'));
  }

  /**
   * An unknown topic from the model is stored as the configured fallback.
   */
  public function testPipelineWhitelistsTopic(): void {
    $this->provisionPipelineUser();
    $this->queueItem('Article', 'https://example.com/a', 'Outlet A');
    $this->aiAnalyzer->method('classifyAndCluster')->willReturn(['topic' => 'not-a-topic'] + $this->analysis(NULL));
    $this->container->get('maemgaba_core.queue_processor')->processBatch(5);

    $cards = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['type' => 'card']);
    $this->assertSame('general', reset($cards)->get('field_topic')->value);
  }

  /**
   * Config fills the [output_language], [site_name] and [topic_list] tokens.
   */
  public function testGlobalPromptTokens(): void {
    $this->config('system.site')->set('name', 'Example Site')->save();
    $this->config('maemgaba_core.locale')->set('output_language', 'Klingon')->save();
    $prompt = $this->container->get('entity_type.manager')->getStorage('maemgaba_prompt')->create([
      'id' => 'token_test',
      'label' => 'Token test',
      'operation' => 'token_test',
      'system_prompt' => 'Engine of [site_name].',
      'template' => '[topic_list] | [output_language] | [article_text]',
    ]);

    $analyzer = new AiAnalyzerService(
      $this->container->get('ai.provider'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
      $this->container->get('maemgaba_core.ai_call_logger'),
      $this->container->get('config.factory'),
      $this->container->get('maemgaba_core.topics'),
      $this->container->get('maemgaba_core.spectrum'),
      $this->container->get('maemgaba_core.rubric'),
      $this->container->get('maemgaba_core.outlet_redactor'),
      $this->container->get('maemgaba_core.overlap_guard'),
    );
    $this->assertSame('Engine of Example Site.', $analyzer->systemPrompt($prompt));
    $rendered = $analyzer->renderPrompt($prompt, ['article_text' => 'Body']);
    $this->assertStringStartsWith('"politics", "economy", ', $rendered);
    $this->assertStringEndsWith(' | Klingon | Body', $rendered);
    // A call-specific token wins over a global one of the same name.
    $this->assertStringEndsWith(' | Elvish | Body', $analyzer->renderPrompt($prompt, [
      'article_text' => 'Body',
      'output_language' => 'Elvish',
    ]));
  }

  /**
   * The maemgaba:seed-feeds command.
   *
   * Validation, upsert by URL, dry run, change report.
   */
  public function testFeedSeeder(): void {
    $this->provisionPipelineUser();
    $seeder = $this->container->get('maemgaba_core.feed_seeder');
    $file = $this->siteDirectory . '/feeds.yml';

    file_put_contents($file, "feeds:\n  - url: not-a-url\n    outlet: X\n  - url: https://a.example/rss\n    outlet: A\n    score: 3\n  - url: https://b.example/rss\n    outlet: ''\n  - url: https://c.example/rss\n    outlet: C\n    published_prior: 9\n  - url: https://d.example/rss\n    outlet: D\n    prior_source: \"" . str_repeat('x', 256) . "\"\n");
    $parsed = $seeder->parse($file);
    $this->assertSame([], $parsed['feeds']);
    $this->assertCount(5, $parsed['errors']);

    file_put_contents($file, "feeds:\n  - url: https://a.example/rss\n    outlet: Outlet A\n    score: -2\n    section: news\n    published_prior: -1\n    prior_source: 'AllSides 2026-09'\n  - url: https://b.example/rss\n    outlet: Outlet B\n    score: 1\n    active: false\n");
    $parsed = $seeder->parse($file);
    $this->assertSame([], $parsed['errors']);

    $dry = $seeder->seed($parsed['feeds'], TRUE);
    $this->assertSame(['created', 'created'], array_column($dry, 'action'));
    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame([], $nodes->loadByProperties(['type' => 'feed_source']), 'dry run saves nothing');

    $this->assertSame(['created', 'created'], array_column($seeder->seed($parsed['feeds']), 'action'));
    $this->assertSame(['unchanged', 'unchanged'], array_column($seeder->seed($parsed['feeds']), 'action'));

    $feeds = $nodes->loadByProperties(['type' => 'feed_source', 'field_feed_url' => 'https://b.example/rss']);
    $feed = reset($feeds);
    $this->assertSame('Outlet B', $feed->label());
    $this->assertSame(1, (int) $feed->get('field_default_bias_score')->value);
    $this->assertSame('right', $feed->get('field_article_bias')->value, 'legacy list derived on save');
    $this->assertSame(0, (int) $feed->get('field_active_testing')->value);
    $terms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'sources',
      'name' => 'Outlet A',
    ]);
    $this->assertSame(-2, (int) reset($terms)->get('field_default_bias_score')->value);

    $feedsA = $nodes->loadByProperties(['type' => 'feed_source', 'field_feed_url' => 'https://a.example/rss']);
    $feedA = reset($feedsA);
    $this->assertSame('news', $feedA->get('field_section')->value);
    $this->assertSame(-1, (int) $feedA->get('field_published_prior')->value);
    $this->assertSame('AllSides 2026-09', $feedA->get('field_prior_source')->value);

    // The registry wins: a changed score is applied and reported.
    $parsed['feeds'][0]['score'] = -1;
    $report = $seeder->seed($parsed['feeds']);
    $this->assertSame('updated', $report[0]['action']);
    $this->assertSame(['node.field_default_bias_score', 'taxonomy_term.field_default_bias_score'], $report[0]['changes']);
    $this->assertSame('unchanged', $report[1]['action']);
  }

  /**
   * The P7 US-locale-pack shared fields exist on both bundles and are empty.
   *
   * Optional by default, so pre-P7 content carries them harmlessly.
   */
  public function testUsLocalePackFields(): void {
    $fieldConfig = $this->container->get('entity_type.manager')->getStorage('field_config');

    $this->assertFalse($fieldConfig->load('node.card.field_micro_summary')->isRequired(), 'field_micro_summary is optional so sites that show field_framing_line instead do not need to populate it.');

    $framingLine = $fieldConfig->load('node.card.field_framing_line');
    $this->assertFalse($framingLine->isRequired());
    $this->assertSame('string', $framingLine->getType());

    $syndicatedFrom = $fieldConfig->load('node.card.field_syndicated_from');
    $this->assertSame('entity_reference', $syndicatedFrom->getType());
    $this->assertSame(['card' => 'card'], $syndicatedFrom->getSetting('handler_settings')['target_bundles']);

    $this->assertSame('boolean', $fieldConfig->load('node.card.field_partial_text')->getType());

    $cardSection = $fieldConfig->load('node.card.field_section');
    $feedSourceSection = $fieldConfig->load('node.feed_source.field_section');
    $this->assertSame('list_string', $cardSection->getType());
    $this->assertSame('field_section', $feedSourceSection->getFieldStorageDefinition()->getName(), 'card and feed_source share one field_section storage.');

    $publishedPrior = $fieldConfig->load('node.feed_source.field_published_prior');
    $this->assertSame('integer', $publishedPrior->getType());
    $this->assertSame(-2, $publishedPrior->getSetting('min'));
    $this->assertSame(2, $publishedPrior->getSetting('max'));

    $this->assertSame('string', $fieldConfig->load('node.feed_source.field_prior_source')->getType());
  }

  /**
   * The deploy hook creates the P7 fields on a site that predates them.
   *
   * A second run is a no-op.
   */
  public function testDeployHookCreatesUsLocaleFields(): void {
    require_once $this->container->get('extension.list.module')->getPath('maemgaba_core') . '/maemgaba_core.deploy.php';

    $fieldConfig = $this->container->get('entity_type.manager')->getStorage('field_config');
    $fieldStorage = $this->container->get('entity_type.manager')->getStorage('field_storage_config');
    $micro = $fieldConfig->load('node.card.field_micro_summary');
    $micro->set('required', TRUE)->save();
    // Card is the only bundle on field_framing_line, so deleting its last
    // field instance also deletes the now-unused field storage.
    $fieldConfig->load('node.card.field_framing_line')->delete();

    $this->assertNull($fieldConfig->load('node.card.field_framing_line'));
    $this->assertNull($fieldStorage->load('node.field_framing_line'));
    $this->assertTrue($fieldConfig->load('node.card.field_micro_summary')->isRequired());

    $report = maemgaba_core_deploy_us_locale_fields();
    $this->assertStringContainsString('node.field_framing_line', $report);
    $this->assertStringContainsString('field_micro_summary set to optional', $report);
    $this->assertSame('string', $fieldConfig->load('node.card.field_framing_line')->getType());
    $this->assertFalse($fieldConfig->load('node.card.field_micro_summary')->isRequired());

    // Idempotent: a second run creates nothing more.
    $second = maemgaba_core_deploy_us_locale_fields();
    $this->assertStringContainsString('All US-locale-pack fields already existed', $second);
    $this->assertStringContainsString('already optional', $second);
  }

  /**
   * Route aliases from config keep a site's legacy URLs; idempotent.
   */
  public function testRouteAliasSync(): void {
    $this->enableModules(['path_alias']);
    $this->installEntitySchema('path_alias');
    $this->container->get('router.builder')->rebuild();

    $this->config('maemgaba_core.locale')->set('route_aliases', [
      ['route' => 'maemgaba_core.sources', 'alias' => '/fontes'],
      ['route' => 'maemgaba_core.methodology', 'alias' => 'metodologia'],
      ['route' => 'maemgaba_core.no_such_route', 'alias' => '/x'],
    ])->save();
    $sync = $this->container->get('maemgaba_core.route_alias_sync');

    $this->assertSame([], array_filter(array_column($sync->sync(TRUE), 'action'), fn ($a) => $a === 'unchanged'), 'dry run creates nothing');
    $report = $sync->sync();
    $this->assertSame(['created', 'created'], array_slice(array_column($report, 'action'), 0, 2));
    $this->assertSame('/sources', $report[0]['path']);
    $this->assertSame('/metodologia', $report[1]['alias'], 'leading slash added');
    $this->assertStringStartsWith('error:', $report[2]['action']);
    $this->assertSame('/fontes', $this->container->get('path_alias.manager')->getAliasByPath('/sources'));

    $this->assertSame(['unchanged', 'unchanged'], array_slice(array_column($sync->sync(), 'action'), 0, 2));
    $this->config('maemgaba_core.locale')->set('route_aliases', [
      ['route' => 'maemgaba_core.sources', 'alias' => '/veiculos'],
    ])->save();
    $this->assertSame('updated', $sync->sync()[0]['action']);
  }

  /**
   * Relative-time labels (English source strings).
   */
  public function testRelativeTime(): void {
    $now = 1_800_000_000;
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);
    $relative = new RelativeTime($time, $this->container->get('string_translation'));

    $this->assertSame('1 min', $relative->long($now - 10));
    $this->assertSame('3 hours', $relative->long($now - 3 * 3600 - 5));
    $this->assertSame('1 day', $relative->long($now - 86400));
    $this->assertSame('2 wk', $relative->long($now - 15 * 86400));
    $this->assertSame('2 months', $relative->long($now - 61 * 86400));
    $this->assertSame('5 min ago', $relative->compact($now - 300));
    $this->assertSame('3 h ago', $relative->compact($now - 3 * 3600));
    $this->assertSame('2 d ago', $relative->compactSeconds(2 * 86400 + 5));
  }

  /**
   * The shipped pt-br .po parses and restores the Portuguese interface text.
   */
  public function testPortugueseTranslationFile(): void {
    $this->enableModules(['language', 'locale']);
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location', 'locale_file']);
    $this->installConfig(['language', 'locale']);
    ConfigurableLanguage::createFromLangcode('pt-br')->save();

    $path = $this->container->get('extension.list.module')->getPath('maemgaba_core') . '/translations/maemgaba_core.pt-br.po';
    $report = Gettext::fileToDatabase((object) ['filename' => basename($path), 'uri' => $path, 'langcode' => 'pt-br'], [
      'langcode' => 'pt-br',
      'customized' => LOCALE_NOT_CUSTOMIZED,
      'overwrite_options' => ['not_customized' => TRUE, 'customized' => FALSE],
    ]);
    $this->assertGreaterThan(100, $report['additions']);
    $this->assertSame(0, $report['skips'], 'no malformed entries');

    $t = fn (string $s, array $a = [], array $o = []) => (string) $this->container->get('string_translation')->translate($s, $a, $o + ['langcode' => 'pt-br']);
    $this->container->get('string_translation')->reset();
    $this->assertSame('Fontes', $t('Sources'));
    // Portuguese expectations with escapes: engine code stays ASCII/English.
    $this->assertSame("In\u{ed}cio", $t('Home', [], ['context' => 'maemgaba_nav']));
    $this->assertSame("h\u{e1} 5 min", $t('@count min ago', ['@count' => 5], ['context' => 'maemgaba_time']));
    $this->assertSame('setembro', $t('September', [], ['context' => 'Long month name']));
    $plural = (string) $this->container->get('string_translation')->formatPlural(3, '1 hour', '@count hours', [], [
      'langcode' => 'pt-br',
      'context' => 'maemgaba_time',
    ]);
    $this->assertSame('3 horas', $plural);
  }

  /**
   * Monolingual content stored as "en" is relabelled to the site language.
   */
  public function testContentLanguageRelabel(): void {
    $this->enableModules(['language', 'path_alias']);
    $this->installConfig(['language']);
    $this->installEntitySchema('path_alias');
    ConfigurableLanguage::createFromLangcode('pt-br')->save();

    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    $card = $nodes->create(['type' => 'card', 'title' => 'Card', 'langcode' => 'en', 'field_bias_score' => 2]);
    $card->save();
    $term = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->create([
      'vid' => 'sources',
      'name' => 'Outlet',
      'langcode' => 'en',
    ]);
    $term->save();

    $relabel = new ContentLanguageRelabel($this->container->get('database'), $this->container->get('entity_type.manager'), $this->container->get('module_handler'));
    $this->assertContains('node__field_bias_score', $relabel->tables());
    $this->assertContains('node_field_revision', $relabel->tables());
    $counts = $relabel->run('en', 'pt-br');
    $this->assertGreaterThan(0, $counts['node_field_data']);
    $this->assertGreaterThan(0, $counts['node__field_bias_score']);

    $nodes->resetCache();
    $reloaded = $nodes->load($card->id());
    $this->assertSame('pt-br', $reloaded->language()->getId());
    $this->assertSame(2, (int) $reloaded->get('field_bias_score')->value, 'field values survive');
    $this->assertSame('pt-br', $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadUnchanged($term->id())->language()->getId());
    $this->assertSame([], $relabel->run('pt-br', 'pt-br'));

    // Content that already exists in the target language = translations:
    // refuse.
    $nodes->create(['type' => 'card', 'title' => 'English card', 'langcode' => 'en'])->save();
    $this->expectException(\RuntimeException::class);
    $relabel->run('en', 'pt-br');
  }

}

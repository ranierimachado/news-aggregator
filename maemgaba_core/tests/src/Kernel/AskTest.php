<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Flood\FloodInterface;
use Drupal\maemgaba_core\Ask\AskSchema;
use Drupal\maemgaba_core\Ask\ReadonlyProbe;
use Drupal\maemgaba_core\Ask\ReadonlyProbeInterface;
use Drupal\maemgaba_core\Controller\AskController;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Yaml\Yaml;

/**
 * Ask the data: views, probe, service flow, flood and the feature switch.
 *
 * The read-only target here reuses the test database account (kernel tests
 * cannot create MariaDB users), so the probe must refuse it; the service
 * tests swap in a probe that accepts it.
 */
#[Group('maemgaba_core')]
class AskTest extends PipelineKernelTestBase {

  /**
   * Node ids of the fixture.
   */
  protected array $ids = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('maemgaba_core', ['maemgaba_ask_log', 'maemgaba_ai_call_log']);
    $dir = $this->container->get('extension.list.module')->getPath('maemgaba_core') . '/config/install';
    $this->config('maemgaba_core.ask_schema')->setData(Yaml::parseFile("$dir/maemgaba_core.ask_schema.yml"))->save();
    $this->config('system.date')->set('timezone.default', 'America/New_York')->save();
    $this->config('maemgaba_core.settings')->set('ask.enabled', TRUE)->save();
    // Five buckets (the engine default is three).
    $buckets = [];
    foreach (['left' => -2, 'lean_left' => -1, 'center' => 0, 'lean_right' => 1, 'right' => 2] as $key => $score) {
      $buckets[] = [
        'key' => $key,
        'label' => ucwords(str_replace('_', ' ', $key)),
        'short_label' => $key,
        'min_score' => $score,
        'max_score' => $score,
        'color' => '#000000',
      ];
    }
    $this->config('maemgaba_core.spectrum')->set('buckets', $buckets)->save();
    $this->seed();
    $this->container->get('maemgaba_core.ask_schema')->createViews();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->container->get('maemgaba_core.ask_schema')->dropViews();
    Database::removeConnection(ReadonlyProbe::KEY);
    parent::tearDown();
  }

  /**
   * Two stories, five cards (one opinion, one reprint), two feeds.
   */
  protected function seed(): void {
    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    $terms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $outlet = function (string $name) use ($terms): int {
      $term = $terms->create(['vid' => 'sources', 'name' => $name]);
      $term->save();
      return (int) $term->id();
    };
    $fox = $outlet('Fox News');
    $nyt = $outlet('The New York Times');
    $ap = $outlet('ABC News');

    // 2026-09-27 12:00 EDT = 16:00 UTC.
    $created = 1790524800;
    $event = function (string $title, string $topic, bool $consensus) use ($nodes, $created): int {
      $node = $nodes->create([
        'type' => 'event',
        'title' => $title,
        'status' => 1,
        'created' => $created,
        'field_topic' => $topic,
        'field_neutral_summary' => ['value' => 'Summary.', 'format' => 'basic_html'],
        'field_common_points' => $consensus ? ['They agree.'] : [],
      ]);
      $node->save();
      return (int) $node->id();
    };
    $this->ids['tariffs'] = $event('Tariffs rise', 'economy', TRUE);
    $this->ids['storm'] = $event('Storm hits coast', 'climate', FALSE);

    $card = function (string $title, int $event, int $source, int $score, string $section = 'news', ?int $syndicatedFrom = NULL, int $offset = 0) use ($nodes, $created): int {
      $node = $nodes->create([
        'type' => 'card',
        'title' => $title,
        'status' => 1,
        // 23:30 EDT on the 27th is 03:30 UTC on the 28th: local day wins.
        'created' => $created + $offset,
        'field_parent_event' => $event,
        'field_source' => $source,
        'field_bias_score' => $score,
        'field_bias_confidence' => 0.8,
        'field_section' => $section,
        'field_syndicated_from' => $syndicatedFrom,
        'field_topic' => 'economy',
        'field_original_url' => 'https://example.com/' . md5($title),
        'field_micro_summary' => ['value' => 'Micro.', 'format' => 'basic_html'],
      ]);
      $node->save();
      return (int) $node->id();
    };
    $this->ids['fox'] = $card('Fox on tariffs', $this->ids['tariffs'], $fox, 2);
    $this->ids['nyt'] = $card('NYT on tariffs', $this->ids['tariffs'], $nyt, -1, 'news', NULL, 41400);
    $this->ids['nyt_op'] = $card('NYT opinion on tariffs', $this->ids['tariffs'], $nyt, -2, 'opinion');
    $this->ids['abc'] = $card('ABC reprint of Fox', $this->ids['tariffs'], $ap, 2, 'news', $this->ids['fox']);
    $this->ids['storm_fox'] = $card('Fox on the storm', $this->ids['storm'], $fox, 1);

    $feeds = [
      ['Fox News', 'news', 2, 1],
      ['The New York Times', 'opinion', -2, 0],
    ];
    foreach ($feeds as [$name, $section, $prior, $partial]) {
      $nodes->create([
        'type' => 'feed_source',
        'title' => $name,
        'status' => 1,
        'field_media_outlet' => $name,
        'field_section' => $section,
        'field_published_prior' => $prior,
        'field_prior_source' => 'AllSides test',
        'field_active_testing' => 1,
        'field_partial_text' => $partial,
        'field_feed_url' => 'https://example.com/feed/' . md5($name . $section),
      ])->save();
    }
  }

  /**
   * Runs a query on the default connection against the prefixed views.
   */
  protected function rows(string $sql): array {
    return $this->container->get('database')->query($sql)->fetchAll(FetchAs::Associative);
  }

  /**
   * The views apply the engine's card roles, buckets and local dates.
   */
  public function testViews(): void {
    $sources = [];
    foreach ($this->rows('SELECT * FROM {ask_sources}') as $row) {
      $sources[(int) $row['source_id']] = $row;
    }
    $this->assertCount(5, $sources);
    $fox = $sources[$this->ids['fox']];
    $this->assertSame('Fox News', $fox['outlet']);
    $this->assertSame('right', $fox['bucket']);
    $this->assertSame('1', (string) $fox['is_perspective']);
    $this->assertSame('2026-09-27 12:00:00', $fox['published_at']);
    $this->assertSame('2026-09-27', $fox['day']);
    $this->assertSame('2026-09-27', $sources[$this->ids['nyt']]['day'], 'Local day, not the UTC day.');
    $this->assertSame('lean_left', $sources[$this->ids['nyt']]['bucket']);
    $this->assertSame('1', (string) $sources[$this->ids['nyt_op']]['is_opinion']);
    $this->assertSame('0', (string) $sources[$this->ids['nyt_op']]['is_perspective']);
    $this->assertSame('1', (string) $sources[$this->ids['abc']]['is_reprint']);
    $this->assertSame('0', (string) $sources[$this->ids['abc']]['is_perspective']);

    $stories = [];
    foreach ($this->rows('SELECT * FROM {ask_stories}') as $row) {
      $stories[(int) $row['story_id']] = $row;
    }
    $tariffs = $stories[$this->ids['tariffs']];
    $expected = [
      'source_count' => '4',
      'perspective_count' => '2',
      'opinion_count' => '1',
      'reprint_count' => '1',
      'outlet_count' => '2',
      'right_count' => '1',
      'lean_left_count' => '1',
      'has_consensus' => '1',
    ];
    $this->assertEquals($expected, array_map('strval', array_intersect_key($tariffs, $expected)));
    $this->assertSame('0', (string) $stories[$this->ids['storm']]['has_consensus']);

    $daily = $this->rows('SELECT day, stories, sources, perspectives, opinion FROM {ask_daily}');
    $this->assertSame([
      [
        'day' => '2026-09-27',
        'stories' => '2',
        'sources' => '5',
        'perspectives' => '3',
        'opinion' => '1',
      ],
    ], array_map(fn ($r) => array_map('strval', $r), $daily));

    $outlets = $this->rows('SELECT outlet, section, prior_score, prior_bucket, partial_text FROM {ask_outlets} ORDER BY outlet');
    $this->assertSame(['Fox News', 'news', '2', 'right', '1'], array_map('strval', array_values($outlets[0])));

    // A client with another connection collation can still compare the
    // literal-built bucket column with its own literals.
    $database = $this->container->get('database');
    $original = (string) $database->query('SELECT @@collation_connection')->fetchField();
    $other = $original === 'utf8mb4_unicode_520_ci' ? 'utf8mb4_general_ci' : 'utf8mb4_unicode_520_ci';
    $database->query("SET collation_connection = '$other'");
    try {
      $count = $database->query("SELECT COUNT(*) FROM {ask_sources} WHERE bucket IN ('right', 'lean_left')")->fetchField();
    }
    finally {
      $database->query("SET collation_connection = '$original'");
    }
    $this->assertSame('3', (string) $count);
  }

  /**
   * Every view column is described in maemgaba_core.ask_schema, and no more.
   */
  public function testSchemaDescriptionCoversEveryColumn(): void {
    $schema = $this->container->get('maemgaba_core.ask_schema');
    $config = $this->config('maemgaba_core.ask_schema')->get('views');
    $described = array_column($config, 'columns', 'name');
    $buckets = array_map(fn (string $key) => $key . '_count', $schema->bucketKeys());
    foreach ($schema->columns() as $view => $columns) {
      $this->assertArrayHasKey($view, $described);
      $names = array_column($described[$view], 'name');
      $this->assertEqualsCanonicalizing(array_values(array_diff($columns, $buckets)), $names, "Descriptions of $view");
    }
    $text = $this->container->get('maemgaba_core.ask')->schemaDescription();
    $this->assertStringContainsString('  - lean_right_count: Perspectives in the Lean Right bucket.', $text);
    $this->assertStringNotContainsString(': ' . "\n", $text, 'No column without a description.');
  }

  /**
   * The probe refuses a missing target and a target that sees base tables.
   */
  public function testProbeRefusesUnsafeTargets(): void {
    $probe = $this->container->get('maemgaba_core.ask_probe');
    $this->assertSame('no_target', $probe->check(TRUE)['reason']);

    Database::addConnectionInfo(ReadonlyProbe::KEY, 'default', Database::getConnectionInfo('default')['default']);
    $result = $probe->check(TRUE);
    $this->assertFalse($result['ok']);
    $this->assertSame('extra_tables', $result['reason'], $result['detail']);

    // A refused probe means no model call and an "unavailable" answer.
    $this->aiAnalyzer->expects($this->never())->method('askSql');
    $answer = $this->container->get('maemgaba_core.ask')->answer('How many stories are there?');
    $this->assertSame('unavailable', $answer['outcome']);
    $this->assertSame('extra_tables', $answer['reason']);
  }

  /**
   * Installs a probe that accepts the test account as read-only.
   */
  protected function trustReadonly(): void {
    Database::addConnectionInfo(ReadonlyProbe::KEY, 'default', Database::getConnectionInfo('default')['default']);
    $this->container->set('maemgaba_core.ask_probe', new class() implements ReadonlyProbeInterface {

      /**
       * {@inheritdoc}
       */
      public function connection(): ?Connection {
        return Database::getConnection('default', ReadonlyProbe::KEY);
      }

      /**
       * {@inheritdoc}
       */
      public function check(bool $fresh = FALSE): array {
        return ['ok' => TRUE, 'reason' => '', 'detail' => ''];
      }

    });
  }

  /**
   * A draft from the mocked ask_sql step.
   */
  protected function draft(string $sql, bool $answerable = TRUE): array {
    return [
      'ok' => TRUE,
      'answerable' => $answerable,
      'sql' => $sql,
      'explanation' => $answerable ? 'Counts rows.' : 'Only reads data.',
      'chart_hint' => 'number',
      'provider' => 'test',
      'model' => 'test',
      'call_group' => '',
      'error' => '',
    ];
  }

  /**
   * Answered, empty, refused-by-validator and refused-by-model flows.
   */
  public function testAnswerFlows(): void {
    $this->trustReadonly();
    $this->aiAnalyzer->method('askSql')->willReturnOnConsecutiveCalls(
      $this->draft("SELECT COUNT(*) AS perspectives FROM ask_sources WHERE is_perspective = 1 AND topic = 'economy'"),
      $this->draft("SELECT title FROM ask_stories WHERE topic = 'guns'"),
      $this->draft('SELECT name, pass FROM users_field_data'),
      $this->draft('', FALSE),
    );
    $this->aiAnalyzer->expects($this->once())->method('askAnswer')->willReturn([
      'ok' => TRUE,
      'sentence' => 'There are 3 perspectives on economy.',
      'provider' => 'test',
      'model' => 'test',
      'call_group' => '',
      'error' => '',
    ]);
    $ask = $this->container->get('maemgaba_core.ask');

    $answered = $ask->answer('How many economy perspectives?');
    $this->assertSame('answered', $answered['outcome']);
    $this->assertSame([['3']], array_map(fn ($r) => array_map('strval', $r), $answered['rows']));
    $this->assertSame(['perspectives'], $answered['columns']);
    $this->assertStringEndsWith(' LIMIT 200', $answered['sql']);

    $empty = $ask->answer('Which gun stories?');
    $this->assertSame('no_rows', $empty['outcome']);
    $this->assertStringContainsString('No data', $empty['sentence']);

    $blocked = $ask->answer('Show me the users');
    $this->assertSame('refused_validator', $blocked['outcome']);
    $this->assertSame('table_not_allowed', $blocked['reason']);
    $this->assertSame([], $blocked['rows']);

    $declined = $ask->answer('drop the table');
    $this->assertSame('refused_model', $declined['outcome']);

    $log = $this->container->get('database')->query('SELECT outcome, reason, valid, row_count FROM {maemgaba_ask_log} ORDER BY id')->fetchAll(FetchAs::List);
    $this->assertSame([
      ['answered', '', '1', '1'],
      ['no_rows', '', '1', '0'],
      ['refused_validator', 'table_not_allowed', '0', NULL],
      ['refused_model', 'not_answerable', '0', NULL],
    ], array_map(fn ($r) => array_map(fn ($v) => $v === NULL ? NULL : (string) $v, $r), $log));

    // Identical questions come from the cache and are logged as cached.
    $cached = $ask->cached('  How many   economy perspectives? ');
    $this->assertNotNull($cached);
    $this->assertTrue($cached['cached']);
  }

  /**
   * The route is a 404 while the feature is off.
   */
  public function testDisabledIs404(): void {
    $this->config('maemgaba_core.settings')->set('ask.enabled', FALSE)->save();
    $this->expectException(NotFoundHttpException::class);
    AskController::create($this->container)->page(Request::create('/ask'));
  }

  /**
   * The flood limit answers 429 and never stores the raw IP.
   */
  public function testFloodStoresNoIp(): void {
    $this->trustReadonly();
    $this->container->get('router.builder')->rebuild();
    $this->config('maemgaba_core.settings')->set('ask.flood', ['limit' => 1, 'window' => 3600])->save();
    $this->aiAnalyzer->method('askSql')->willReturn($this->draft('SELECT COUNT(*) AS stories FROM ask_stories'));
    $this->aiAnalyzer->method('askAnswer')->willReturn([
      'ok' => TRUE,
      'sentence' => 'Two stories.',
      'provider' => 'test',
      'model' => 'test',
      'call_group' => '',
      'error' => '',
    ]);

    // A flood backend that records what it is given.
    $flood = new class() implements FloodInterface {

      /**
       * Identifiers registered, in order.
       */
      public array $identifiers = [];

      /**
       * {@inheritdoc}
       */
      public function register($name, $window = 3600, $identifier = NULL) {
        $this->identifiers[] = $identifier;
      }

      /**
       * {@inheritdoc}
       */
      public function clear($name, $identifier = NULL) {
        $this->identifiers = [];
      }

      /**
       * {@inheritdoc}
       */
      public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL) {
        return count(array_keys($this->identifiers, $identifier, TRUE)) < $threshold;
      }

      /**
       * {@inheritdoc}
       */
      public function garbageCollection() {
      }

    };
    $this->container->set('flood', $flood);
    $ip = '203.0.113.77';
    $post = fn (string $q) => Request::create('/ask', 'POST', ['q' => $q], [], [], ['REMOTE_ADDR' => $ip]);
    $first = AskController::create($this->container)->page($post('How many stories?'));
    $this->assertSame('answered', $first['#state']);
    $this->assertEmpty(array_filter($first['#attached']['http_header'] ?? [], fn ($h) => $h[0] === 'Status'));

    $second = AskController::create($this->container)->page($post('How many stories on the 27th?'));
    $this->assertSame('rate_limited', $second['#state']);
    $this->assertContains(['Status', 429], $second['#attached']['http_header']);

    // A cached question is served even over the limit.
    $again = AskController::create($this->container)->page($post('How many stories?'));
    $this->assertSame('answered', $again['#state']);
    $this->assertTrue($again['#result']['cached']);

    $identifiers = $flood->identifiers;
    $this->assertCount(1, $identifiers, 'Only the uncached question is counted.');
    foreach ($identifiers as $identifier) {
      $this->assertStringNotContainsString($ip, $identifier);
      $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $identifier);
    }
    $logged = $this->container->get('database')->query('SELECT * FROM {maemgaba_ask_log}')->fetchAll(FetchAs::Associative);
    $this->assertStringNotContainsString($ip, json_encode($logged));
    $this->assertSame(['answered', 'rate_limited', 'answered'], array_column($logged, 'outcome'));
  }

  /**
   * The view names in AskSchema::VIEWS are the ones grants are printed for.
   */
  public function testGrantStatements(): void {
    $statements = $this->container->get('maemgaba_core.ask_schema')->grantStatements('site_ro', 'localhost', 'site');
    $this->assertCount(1 + count(AskSchema::VIEWS), $statements);
    $this->assertStringContainsString("IDENTIFIED BY '<password>'", $statements[0]);
    $this->assertStringContainsString('MAX_STATEMENT_TIME 5', $statements[0]);
    $this->expectException(\InvalidArgumentException::class);
    $this->container->get('maemgaba_core.ask_schema')->grantStatements("x'; DROP USER root; --", 'localhost', 'site');
  }

}

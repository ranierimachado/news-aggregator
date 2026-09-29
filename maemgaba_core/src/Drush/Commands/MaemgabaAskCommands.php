<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Drush\Commands;

use Drupal\Core\Database\Database;
use Drupal\maemgaba_core\Ask\AskEvalScorer;
use Drupal\maemgaba_core\Ask\AskExecutionException;
use Drupal\maemgaba_core\Ask\AskExecutor;
use Drupal\maemgaba_core\Ask\AskSchema;
use Drupal\maemgaba_core\Ask\AskService;
use Drupal\maemgaba_core\Ask\ReadonlyProbeInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush commands for "Ask the data": views, grants, probe, ask, eval.
 */
class MaemgabaAskCommands extends DrushCommands {

  public function __construct(
    protected AskService $ask,
    protected AskSchema $schema,
    protected ReadonlyProbeInterface $probe,
    protected AskExecutor $executor,
  ) {
    parent::__construct();
  }

  /**
   * Dependency Injection factory for Drush.
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('maemgaba_core.ask'),
      $container->get('maemgaba_core.ask_schema'),
      $container->get('maemgaba_core.ask_probe'),
      $container->get('maemgaba_core.ask_executor'),
    );
  }

  /**
   * Creates or replaces the ask_* views, or prints their SQL.
   */
  #[CLI\Command(name: 'maemgaba:ask-views', aliases: ['mg-ask-views'])]
  #[CLI\Option(name: 'print', description: 'Print the CREATE VIEW statements (for root to run) instead of running them')]
  #[CLI\Option(name: 'drop', description: 'Drop the views instead')]
  public function views(array $options = ['print' => FALSE, 'drop' => FALSE]): int {
    if ($options['print']) {
      $connection = Database::getConnection();
      foreach ($this->schema->createStatements() as $statement) {
        $this->output()->writeln($connection->prefixTables($statement) . ';');
      }
      return self::EXIT_SUCCESS;
    }
    if ($options['drop']) {
      $this->schema->dropViews();
      $this->logger()->success(dt('Dropped the ask views.'));
      return self::EXIT_SUCCESS;
    }
    $this->schema->createViews();
    $this->logger()->success(dt('Created or replaced: @views.', ['@views' => implode(', ', AskSchema::VIEWS)]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Prints the SQL root runs to create the read-only user (no password).
   */
  #[CLI\Command(name: 'maemgaba:ask-grants', aliases: ['mg-ask-grants'])]
  #[CLI\Option(name: 'user', description: 'Read-only account name, e.g. site_ro')]
  #[CLI\Option(name: 'host', description: 'Account host, e.g. localhost (prod) or % (ddev)')]
  #[CLI\Option(name: 'database', description: 'Database name; the site database when omitted')]
  public function grants(array $options = ['user' => 'ask_ro', 'host' => 'localhost', 'database' => NULL]): int {
    $database = (string) ($options['database'] ?: Database::getConnectionInfo()['default']['database']);
    foreach ($this->schema->grantStatements((string) $options['user'], (string) $options['host'], $database) as $statement) {
      $this->output()->writeln($statement . ';');
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Checks the read-only target: exactly the four views, nothing else.
   */
  #[CLI\Command(name: 'maemgaba:ask-probe', aliases: ['mg-ask-probe'])]
  public function probe(): int {
    $result = $this->probe->check(TRUE);
    if ($result['ok']) {
      $this->logger()->success(dt('Read-only target OK: it sees exactly @views.', ['@views' => implode(', ', AskSchema::VIEWS)]));
      return self::EXIT_SUCCESS;
    }
    $this->logger()->error(dt('Read-only target refused (@reason): @detail', [
      '@reason' => $result['reason'],
      '@detail' => $result['detail'],
    ]));
    return self::EXIT_FAILURE;
  }

  /**
   * Asks one question, as the page would (not cached, logged as eval).
   */
  #[CLI\Command(name: 'maemgaba:ask', aliases: ['mg-ask'])]
  #[CLI\Argument(name: 'question', description: 'The question')]
  #[CLI\Option(name: 'model', description: 'provider/model for both AI steps, e.g. anthropic/claude-sonnet-5; operation_models when omitted')]
  public function askOne(string $question, array $options = ['model' => NULL]): int {
    $result = $this->ask->answer($question, [
      'source' => 'eval',
      'route' => $this->route($options['model']),
      'cache' => FALSE,
    ]);
    $this->output()->writeln(sprintf('Outcome: %s %s', $result['outcome'], $result['reason']));
    if ($result['sql'] !== '') {
      $this->output()->writeln('SQL: ' . $result['sql']);
    }
    if ($result['explanation'] !== '') {
      $this->output()->writeln('Explanation: ' . $result['explanation']);
    }
    if ($result['columns']) {
      $this->io()->table($result['columns'], array_slice($result['rows'], 0, 20));
    }
    if ($result['sentence'] !== '') {
      $this->output()->writeln('Answer: ' . $result['sentence']);
    }
    if ($result['detail'] !== '') {
      $this->output()->writeln('Detail: ' . $result['detail']);
    }
    $this->output()->writeln(sprintf('%s · %d ms · US$ %.5f', $result['model'], $result['latency_ms'], $result['cost_usd']));
    return in_array($result['outcome'], ['answered', 'no_rows'], TRUE) ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Runs the eval set: exact-match rate, rejections, latency and cost.
   */
  #[CLI\Command(name: 'maemgaba:ask-eval', aliases: ['mg-ask-eval'])]
  #[CLI\Option(name: 'file', description: 'Eval file (YAML). Without it, the sample set shipped in the package (docs/calibration/sample-ask-eval.yml) is used')]
  #[CLI\Option(name: 'model', description: 'provider/model for both AI steps; operation_models when omitted')]
  #[CLI\Option(name: 'json', description: 'Also write per-question results to this JSON file')]
  #[CLI\Option(name: 'only', description: 'Comma-separated question ids to run')]
  #[CLI\Option(name: 'skip-hostile', description: 'Do not run the hostile items')]
  #[CLI\Usage(name: 'drush maemgaba:ask-eval --model=anthropic/claude-haiku-4-5', description: 'Run the eval with Haiku 4.5 for both steps')]
  public function evaluate(
    array $options = [
      'file' => NULL,
      'model' => NULL,
      'json' => NULL,
      'only' => NULL,
      'skip-hostile' => FALSE,
    ],
  ): int {
    $file = $options['file'] ?: dirname(\Drupal::service('extension.list.module')->getPath('maemgaba_core')) . '/docs/calibration/sample-ask-eval.yml';
    if (!$options['file']) {
      $this->logger()->notice(dt('No --file given: running the shipped sample set @file. A site keeps its own eval set outside the engine.', ['@file' => $file]));
    }
    if (!is_readable($file)) {
      $this->logger()->error(dt('Cannot read @file.', ['@file' => $file]));
      return self::EXIT_FAILURE;
    }
    try {
      $set = AskEvalScorer::parse(Yaml::parseFile($file));
    }
    catch (\Throwable $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $probe = $this->probe->check(TRUE);
    if (!$probe['ok']) {
      $this->logger()->error(dt('Read-only target refused (@reason): @detail', [
        '@reason' => $probe['reason'],
        '@detail' => $probe['detail'],
      ]));
      return self::EXIT_FAILURE;
    }
    $only = $options['only'] ? array_map('trim', explode(',', (string) $options['only'])) : [];
    $route = $this->route($options['model']);
    $settings = $this->ask->settings();

    $rows = [];
    $table = [];
    foreach ($set['questions'] as $item) {
      if ($only && !in_array($item['id'], $only, TRUE)) {
        continue;
      }
      $reference = $this->ask->validator()->validate($item['reference_sql']);
      if (!$reference->ok) {
        $this->logger()->error(dt('Reference SQL of @id is rejected by the validator (@reason: @detail).', [
          '@id' => $item['id'],
          '@reason' => $reference->reason,
          '@detail' => $reference->detail,
        ]));
        return self::EXIT_FAILURE;
      }
      try {
        $expected = $this->executor->run($reference, $settings['max_rows'], $settings['statement_timeout']);
      }
      catch (AskExecutionException $e) {
        $this->logger()->error(dt('Reference SQL of @id failed: @msg', [
          '@id' => $item['id'],
          '@msg' => $e->getMessage(),
        ]));
        return self::EXIT_FAILURE;
      }
      $result = $this->ask->answer($item['question'], ['source' => 'eval', 'route' => $route, 'cache' => FALSE]);
      $ran = in_array($result['outcome'], ['answered', 'no_rows'], TRUE);
      $match = $ran && AskEvalScorer::matches($expected['rows'], $result['rows'], $item['match']);
      $rows[] = [
        'id' => $item['id'],
        'question' => $item['question'],
        'match' => $match,
        'outcome' => $result['outcome'],
        'reason' => $result['reason'],
        'sql' => $result['sql'],
        'reference_sql' => $reference->sql,
        'expected_rows' => count($expected['rows']),
        'got_rows' => $ran ? count($result['rows']) : NULL,
        'expected_sample' => array_slice($expected['rows'], 0, 5),
        'got_sample' => array_slice($result['rows'], 0, 5),
        'sentence' => $result['sentence'],
        'explanation' => $result['explanation'],
        'detail' => $result['detail'],
        'latency_ms' => $result['latency_ms'],
        'cost_usd' => $result['cost_usd'],
        'model' => $result['model'],
      ];
      $table[] = [
        $item['id'],
        $match ? 'yes' : 'NO',
        $result['outcome'] . ($result['reason'] !== '' ? ':' . $result['reason'] : ''),
        count($expected['rows']) . '/' . ($ran ? count($result['rows']) : '-'),
        $result['latency_ms'],
        sprintf('%.5f', $result['cost_usd']),
        mb_strimwidth($item['question'], 0, 60, '…'),
      ];
    }
    $this->io()->table(['id', 'match', 'outcome', 'rows exp/got', 'ms', 'US$', 'question'], $table);

    $hostile = [];
    if (!$options['skip-hostile'] && !$only) {
      $htable = [];
      foreach ($set['hostile'] as $item) {
        $result = $this->ask->answer($item['question'], ['source' => 'eval', 'route' => $route, 'cache' => FALSE]);
        $hostile[] = [
          'id' => $item['id'],
          'question' => $item['question'],
          'outcome' => $result['outcome'],
          'reason' => $result['reason'],
          'sql' => $result['sql'],
          'explanation' => $result['explanation'],
          'detail' => $result['detail'],
          'latency_ms' => $result['latency_ms'],
          'cost_usd' => $result['cost_usd'],
        ];
        $htable[] = [
          $item['id'],
          $result['outcome'] . ($result['reason'] !== '' ? ':' . $result['reason'] : ''),
          mb_strimwidth($item['question'], 0, 70, '…'),
        ];
      }
      if ($htable) {
        $this->io()->table(['id', 'outcome', 'hostile question'], $htable);
      }
    }

    $summary = AskEvalScorer::summarize($rows, $hostile);
    $model = $rows[0]['model'] ?? ($options['model'] ?: 'operation_models');
    $this->output()->writeln(sprintf('Model: %s', $model));
    $this->output()->writeln(sprintf('Exact match: %d/%d (%.1f%%)', $summary['exact'], $summary['questions'], $summary['exact_rate']));
    $this->output()->writeln(sprintf('Validator rejections: %d · model refusals: %d · errors: %d', $summary['validator_rejections'], $summary['model_refusals'], $summary['errors']));
    $this->output()->writeln(sprintf('Latency: mean %d ms, median %d ms, max %d ms', $summary['latency_mean_ms'], $summary['latency_median_ms'], $summary['latency_max_ms']));
    $this->output()->writeln(sprintf('Cost: US$ %.4f total, US$ %.5f per question', $summary['cost_usd'], $summary['cost_per_question_usd']));
    if ($hostile) {
      $this->output()->writeln(sprintf('Hostile refused: %d/%d', $summary['hostile_refused'], $summary['hostile']));
    }

    if ($options['json']) {
      $payload = [
        'model' => $model,
        'file' => $file,
        'run_at' => gmdate('c'),
        'summary' => $summary,
        'questions' => $rows,
        'hostile' => $hostile,
      ];
      file_put_contents((string) $options['json'], json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      $this->logger()->success(dt('Wrote @file.', ['@file' => $options['json']]));
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Parses provider/model into a route, or NULL.
   */
  protected function route(?string $model): ?array {
    if (!$model) {
      return NULL;
    }
    [$provider, $id] = array_pad(explode('/', $model, 2), 2, '');
    if ($provider === '' || $id === '') {
      throw new \InvalidArgumentException('Use --model=provider/model, e.g. anthropic/claude-haiku-4-5.');
    }
    return ['provider_id' => $provider, 'model_id' => $id];
  }

}

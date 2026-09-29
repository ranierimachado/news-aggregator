<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Ask;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\maemgaba_core\AiAnalyzerService;
use Drupal\maemgaba_core\Service\AiPricing;
use Drupal\maemgaba_core\Service\SpectrumService;

/**
 * Ask the data: question → SQL → validator → read-only SELECT → sentence.
 *
 * The flow for one question:
 * 1. The read-only target must pass ReadonlyProbe, or the answer is
 *    "unavailable" and no model is called.
 * 2. ask_sql writes one SELECT over the four ask_* views (the visitor's
 *    words never reach SQL; the model writes constants). The model may
 *    decline (answerable = FALSE).
 * 3. AskSqlValidator accepts or refuses the statement.
 * 4. AskExecutor runs it on the read-only connection with a timeout and a
 *    row cap.
 * 5. With rows, ask_answer writes one sentence from the rows only. With no
 *    rows, the sentence is a fixed "no data" line and no model is called.
 *
 * Every question is logged to maemgaba_ask_log (no IP). Answers are cached
 * for maemgaba_core.settings:ask.cache_ttl seconds, keyed on the normalized
 * question, the prompts, the routed models, the local date and the schema.
 */
class AskService {

  use StringTranslationTrait;

  /**
   * Outcomes, as stored in maemgaba_ask_log.outcome.
   */
  public const OUTCOMES = [
    'answered',
    'no_rows',
    'refused_validator',
    'refused_model',
    'unavailable',
    'rate_limited',
    'error',
  ];

  /**
   * Defaults for maemgaba_core.settings:ask keys that are unset.
   */
  public const DEFAULTS = [
    'enabled' => FALSE,
    'flood' => ['limit' => 20, 'window' => 3600],
    'cache_ttl' => 600,
    'max_rows' => 200,
    'statement_timeout' => 5,
    'max_question_length' => 300,
    'answer_rows' => 50,
    'examples' => [],
  ];

  /**
   * The validator, built once per request from the schema.
   */
  protected ?AskSqlValidator $validator = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected AiAnalyzerService $analyzer,
    protected AskSchema $schema,
    protected ReadonlyProbeInterface $probe,
    protected AskExecutor $executor,
    protected AskLog $log,
    protected CacheBackendInterface $cache,
    protected Connection $database,
    protected AiPricing $pricing,
    protected TimeInterface $time,
    protected SpectrumService $spectrum,
  ) {}

  /**
   * Whether the feature is switched on for this site.
   */
  public function enabled(): bool {
    return (bool) $this->settings()['enabled'];
  }

  /**
   * The ask settings with defaults for unset keys.
   */
  public function settings(): array {
    $ask = (array) ($this->configFactory->get('maemgaba_core.settings')->get('ask') ?? []);
    $settings = $ask + self::DEFAULTS;
    $settings['flood'] = (array) ($ask['flood'] ?? []) + self::DEFAULTS['flood'];
    foreach (['cache_ttl', 'max_rows', 'statement_timeout', 'max_question_length', 'answer_rows'] as $key) {
      $settings[$key] = max(1, (int) $settings[$key]);
    }
    $settings['max_rows'] = min($settings['max_rows'], 1000);
    return $settings;
  }

  /**
   * Trims and collapses whitespace; caps the length.
   */
  public function normalize(string $question): string {
    $question = trim((string) preg_replace('/\s+/u', ' ', $question));
    return mb_substr($question, 0, $this->settings()['max_question_length']);
  }

  /**
   * The validator for the current schema and row cap.
   */
  public function validator(): AskSqlValidator {
    return $this->validator ??= new AskSqlValidator($this->schema->columns(), $this->settings()['max_rows']);
  }

  /**
   * Whether the read-only target passes the probe.
   */
  public function available(): bool {
    return $this->probe->check()['ok'];
  }

  /**
   * A cached answer for this question, logged as a cached hit, or NULL.
   */
  public function cached(string $question, string $source = 'web'): ?array {
    $question = $this->normalize($question);
    if ($question === '') {
      return NULL;
    }
    $hit = $this->cache->get($this->cacheId($question));
    if (!$hit) {
      return NULL;
    }
    $result = $hit->data;
    $result['cached'] = TRUE;
    $result['cost_usd'] = 0.0;
    $result['latency_ms'] = 0;
    $this->logResult($result, $source, []);
    return $result;
  }

  /**
   * The logged result for a question refused by the flood limit.
   */
  public function rateLimited(string $question): array {
    $result = $this->result($this->normalize($question), 'rate_limited');
    $this->logResult($result, 'web', []);
    return $result;
  }

  /**
   * Answers one question (not cached; the caller checks cached() first).
   *
   * @param string $question
   *   The visitor's question.
   * @param array $options
   *   Keys: source ('web', the default, or 'eval'); route (an array with
   *   provider_id and model_id for both AI steps, instead of
   *   operation_models); cache (store the answer, default TRUE).
   *
   * @return array
   *   See result().
   */
  public function answer(string $question, array $options = []): array {
    $start = hrtime(TRUE);
    $source = (string) ($options['source'] ?? 'web');
    $route = $options['route'] ?? NULL;
    $question = $this->normalize($question);
    $groups = [];
    $finish = function (array $result) use ($start, $source, &$groups): array {
      $result['latency_ms'] = (int) round((hrtime(TRUE) - $start) / 1e6);
      [$result['cost_usd'], $result['input_tokens'], $result['output_tokens']] = $this->cost($groups);
      $result['log_id'] = $this->logResult($result, $source, $groups);
      return $result;
    };

    if ($question === '') {
      return $this->result('', 'refused_model', 'empty_question');
    }

    $probe = $this->probe->check();
    if (!$probe['ok']) {
      return $finish($this->result($question, 'unavailable', $probe['reason'], ['detail' => $probe['detail']]));
    }

    $settings = $this->settings();
    $draft = $this->analyzer->askSql($question, $this->promptTokens(), $route);
    $groups[] = $draft['call_group'] ?? '';
    $model = trim(($draft['provider'] ?? '') . '/' . ($draft['model'] ?? ''), '/');
    if (!$draft['ok']) {
      return $finish($this->result($question, 'error', 'model_error', ['detail' => $draft['error'], 'model' => $model]));
    }
    if (!$draft['answerable'] || $draft['sql'] === '') {
      return $finish($this->result($question, 'refused_model', 'not_answerable', [
        'explanation' => $draft['explanation'],
        'detail' => $draft['explanation'],
        'model' => $model,
      ]));
    }

    $validation = $this->validator()->validate($draft['sql']);
    if (!$validation->ok) {
      return $finish($this->result($question, 'refused_validator', $validation->reason, [
        'detail' => $validation->detail . ' | ' . $draft['sql'],
        'sql' => $draft['sql'],
        'model' => $model,
      ]));
    }

    try {
      $data = $this->executor->run($validation, $settings['max_rows'], $settings['statement_timeout']);
    }
    catch (AskExecutionException $e) {
      return $finish($this->result($question, 'error', $e->reason, [
        'detail' => $e->getMessage(),
        'sql' => $validation->sql,
        'valid' => TRUE,
        'model' => $model,
      ]));
    }

    $extra = [
      'sql' => $validation->sql,
      'valid' => TRUE,
      'columns' => $data['columns'],
      'rows' => $data['rows'],
      'row_count' => count($data['rows']),
      'sql_ms' => $data['ms'],
      'explanation' => $draft['explanation'],
      'chart_hint' => $draft['chart_hint'],
      'model' => $model,
    ];
    if (!$data['rows']) {
      $result = $this->result($question, 'no_rows', '', $extra + [
        'sentence' => (string) $this->t('No data matched this question, so there is nothing to report.'),
      ]);
    }
    else {
      $rowsForModel = json_encode([
        'columns' => $data['columns'],
        'rows' => array_slice($data['rows'], 0, $settings['answer_rows']),
        'rows_shown' => min(count($data['rows']), $settings['answer_rows']),
        'rows_total' => count($data['rows']),
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $said = $this->analyzer->askAnswer($question, $validation->sql, (string) $rowsForModel, count($data['rows']), $route);
      $groups[] = $said['call_group'] ?? '';
      $sentence = $said['ok'] ? $said['sentence'] : '';
      $result = $this->result($question, 'answered', $sentence === '' ? 'no_sentence' : '', $extra + ['sentence' => $sentence]);
    }

    $result = $finish($result);
    if (($options['cache'] ?? TRUE) && $source === 'web') {
      $this->cache->set($this->cacheId($question), $result, $this->time->getRequestTime() + $settings['cache_ttl']);
    }
    return $result;
  }

  /**
   * Prompt tokens for ask_sql.
   */
  public function promptTokens(): array {
    $timezone = $this->schema->timezone();
    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone(new \DateTimeZone($timezone));
    $buckets = [];
    foreach ($this->spectrum->buckets() as $bucket) {
      $buckets[] = sprintf("'%s' (%s, scores %d to %d)", $bucket['key'], $bucket['label'], $bucket['min_score'], $bucket['max_score']);
    }
    return [
      'ask_schema' => $this->schemaDescription(),
      'today' => $now->format('l, F j, Y') . ' (' . $now->format('Y-m-d') . ')',
      'timezone' => $timezone . ' (UTC' . $now->format('P') . ' today)',
      'dialect' => 'MariaDB 11 in ANSI mode: strings in single quotes only, || concatenates strings, no backticks or double quotes',
      'max_rows' => (string) $this->settings()['max_rows'],
      'bucket_list' => implode(', ', $buckets),
    ];
  }

  /**
   * The schema description the prompt uses verbatim.
   *
   * Columns come from AskSchema (the views); descriptions from
   * maemgaba_core.ask_schema. Per-bucket count columns share one template.
   */
  public function schemaDescription(): string {
    $config = $this->configFactory->get('maemgaba_core.ask_schema');
    $described = [];
    foreach ((array) $config->get('views') as $view) {
      $described[$view['name']] = [
        'description' => (string) ($view['description'] ?? ''),
        'columns' => array_column((array) ($view['columns'] ?? []), 'description', 'name'),
      ];
    }
    $bucketLabels = array_column($this->spectrum->buckets(), 'label', 'key');
    $template = (string) $config->get('bucket_column');
    $lines = [];
    foreach ($this->schema->columns() as $view => $columns) {
      $lines[] = sprintf('%s: %s', $view, $described[$view]['description'] ?? '');
      foreach ($columns as $column) {
        $text = $described[$view]['columns'][$column] ?? NULL;
        if ($text === NULL && preg_match('/^(.+)_count$/', $column, $m) && isset($bucketLabels[$m[1]])) {
          $text = str_replace('@label', $bucketLabels[$m[1]], $template);
        }
        $lines[] = sprintf('  - %s: %s', $column, $text ?? '');
      }
      $lines[] = '';
    }
    foreach ((array) $config->get('notes') as $note) {
      $lines[] = '- ' . $note;
    }
    return rtrim(implode("\n", $lines));
  }

  /**
   * Builds a result array.
   *
   * @return array
   *   Keys: question, outcome, reason, sentence, explanation, sql, valid,
   *   columns, rows, row_count, sql_ms, chart_hint, model, cached,
   *   latency_ms, cost_usd, input_tokens, output_tokens, detail, log_id.
   *   detail is for the log only.
   */
  protected function result(string $question, string $outcome, string $reason = '', array $extra = []): array {
    return $extra + [
      'question' => $question,
      'outcome' => $outcome,
      'reason' => $reason,
      'sentence' => '',
      'explanation' => '',
      'sql' => '',
      'valid' => FALSE,
      'columns' => [],
      'rows' => [],
      'row_count' => NULL,
      'sql_ms' => NULL,
      'chart_hint' => 'table',
      'model' => '',
      'cached' => FALSE,
      'latency_ms' => 0,
      'cost_usd' => 0.0,
      'input_tokens' => NULL,
      'output_tokens' => NULL,
      'detail' => '',
      'log_id' => 0,
    ];
  }

  /**
   * Writes the log row.
   */
  protected function logResult(array $result, string $source, array $groups): int {
    return $this->log->log([
      'source' => $source,
      'question' => $result['question'],
      'question_hash' => $result['question'] !== '' ? hash('sha256', mb_strtolower($result['question'])) : '',
      'call_groups' => $groups,
    ] + $result);
  }

  /**
   * Estimated cost and tokens of the given call groups.
   *
   * @return array{0: float, 1: int|null, 2: int|null}
   *   US dollars, input tokens, output tokens.
   */
  protected function cost(array $groups): array {
    $groups = array_values(array_filter($groups));
    if (!$groups) {
      return [0.0, NULL, NULL];
    }
    $query = $this->database->select('maemgaba_ai_call_log', 'l')
      ->condition('l.call_group', $groups, 'IN')
      ->condition('l.success', 1);
    $query->addField('l', 'provider');
    $query->addField('l', 'model');
    $query->addExpression('SUM(COALESCE(l.input_tokens, 0))', 'input');
    $query->addExpression('SUM(COALESCE(l.output_tokens, 0))', 'output');
    $query->addExpression('SUM(COALESCE(l.reasoning_tokens, 0))', 'reasoning');
    $query->addExpression('SUM(COALESCE(l.cached_tokens, 0))', 'cached');
    $query->groupBy('l.provider')->groupBy('l.model');
    $usd = 0.0;
    $in = 0;
    $out = 0;
    foreach ($query->execute() as $row) {
      $usd += $this->pricing->estimateUsd((string) $row->provider, (string) $row->model, (int) $row->input, (int) $row->output, (int) $row->reasoning, (int) $row->cached);
      $in += (int) $row->input;
      $out += (int) $row->output;
    }
    return [$usd, $in, $out];
  }

  /**
   * Cache id: question, prompts, routes, local date and schema.
   */
  protected function cacheId(string $question): string {
    $settings = $this->configFactory->get('maemgaba_core.settings');
    $parts = [
      mb_strtolower($question),
      json_encode($settings->get('operation_models.ask_sql')),
      json_encode($settings->get('operation_models.ask_answer')),
      $this->promptTokens()['today'],
      hash('sha256', $this->schemaDescription()),
    ];
    foreach (AiAnalyzerService::ASK_OPERATIONS as $operation) {
      $prompt = $this->analyzer->getPrompt($operation);
      $parts[] = $prompt ? hash('sha256', $prompt->getSystemPrompt() . $prompt->getTemplate()) : '';
    }
    return 'maemgaba_core:ask:' . hash('sha256', implode("\n", $parts));
  }

}

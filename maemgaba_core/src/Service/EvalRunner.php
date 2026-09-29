<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\maemgaba_core\AiAnalyzerService;

/**
 * Replays the golden set through AiAnalyzerService and scores the output.
 *
 * Two operations are eval-able: 'relevance' (classifyRelevance — cheap,
 * title+summary only) and 'classify_cluster' (classifyAndCluster with an
 * empty existing-events list, since the golden set has no clustering
 * context — bias is only meaningful in this mode). Every run persists to
 * maemgaba_eval_run so accuracy is comparable across prompt versions.
 */
class EvalRunner {

  /**
   * Which expected/predicted fields matter for each operation.
   */
  protected const FIELDS_BY_OPERATION = [
    'relevance' => ['social_relevance', 'topic'],
    'classify_cluster' => ['bias', 'social_relevance', 'topic'],
  ];

  public function __construct(
    protected AiAnalyzerService $aiAnalyzer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected GoldenSetExporter $goldenSet,
    protected AiPricing $pricing,
    protected TimeInterface $time,
  ) {}

  /**
   * Runs an eval and persists the result.
   *
   * @param string $operation
   *   Either 'relevance' or 'classify_cluster'.
   * @param int $limit
   *   Maximum golden-set items to replay.
   * @param string|null $promptConfigId
   *   Specific maemgaba_prompt id to evaluate, or NULL for the enabled one.
   *
   * @return array
   *   ['run_id' => int, 'metrics' => array] — see scoreField() for shape.
   *
   * @throws \RuntimeException
   *   If the golden set is missing/empty or no matching prompt is found.
   */
  public function run(string $operation, int $limit, ?string $promptConfigId): array {
    if (!isset(self::FIELDS_BY_OPERATION[$operation])) {
      throw new \RuntimeException("Unknown operation '{$operation}'. Use 'relevance' or 'classify_cluster'.");
    }
    $fields = self::FIELDS_BY_OPERATION[$operation];

    $items = array_slice($this->loadGoldenSet(), 0, $limit);
    if (!$items) {
      throw new \RuntimeException('Golden set is empty or missing — run maemgaba:golden-export and hand-review the fixture first.');
    }

    $prompt = $promptConfigId
      ? $this->entityTypeManager->getStorage('maemgaba_prompt')->load($promptConfigId)
      : $this->aiAnalyzer->getPrompt($operation);
    if (!$prompt) {
      throw new \RuntimeException("No maemgaba_prompt found for operation '{$operation}'" . ($promptConfigId ? " (id: {$promptConfigId})" : ' (none enabled)') . '.');
    }
    if ($prompt->getOperation() !== $operation) {
      throw new \RuntimeException("Prompt '{$prompt->id()}' is configured for operation '{$prompt->getOperation()}', not '{$operation}'.");
    }

    $runStart = $this->time->getRequestTime();
    $byField = array_fill_keys($fields, []);
    $misses = [];

    foreach ($items as $item) {
      $expected = is_array($item['expected'] ?? NULL) ? $item['expected'] : [];
      $title = (string) ($item['title'] ?? '');
      $body = (string) ($item['body_excerpt'] ?? '');

      // context_type 'eval' keeps golden-set replays out of the
      // regular-operation cost reports (maemgaba_monitor).
      $evalContext = ['type' => 'eval', 'label' => $title];
      $prediction = $operation === 'classify_cluster'
        ? $this->aiAnalyzer->classifyAndCluster($body !== '' ? $body : $title, [], $prompt, $evalContext)
        : $this->aiAnalyzer->classifyRelevance($title, $body, $prompt, $evalContext);

      $got = [];
      $isMiss = FALSE;
      foreach ($fields as $field) {
        $expectedValue = $expected[$field] ?? NULL;
        $gotValue = $prediction[$field] ?? NULL;
        $got[$field] = $gotValue;
        $byField[$field][] = ['expected' => $expectedValue, 'got' => $gotValue];
        if ($expectedValue !== $gotValue) {
          $isMiss = TRUE;
        }
      }
      if ($isMiss) {
        $misses[] = ['id' => $item['id'] ?? NULL, 'title' => $title, 'expected' => $expected, 'got' => $got];
      }
    }

    $fieldMetrics = [];
    foreach ($fields as $field) {
      $fieldMetrics[$field] = $this->scoreField($byField[$field]);
    }

    $cost = $this->callCost($operation, $runStart);

    $metrics = [
      'item_count' => count($items),
      'fields' => $fieldMetrics,
      'misses' => array_slice($misses, 0, 30),
      'miss_count' => count($misses),
      'cost' => $cost,
    ];

    $runId = (int) $this->database->insert('maemgaba_eval_run')
      ->fields([
        'operation' => $operation,
        'prompt_config_id' => $prompt->id(),
        'prompt_hash' => hash('sha256', $prompt->getSystemPrompt() . '|' . $prompt->getTemplate()),
        'item_count' => count($items),
        'metrics' => json_encode($metrics, JSON_UNESCAPED_UNICODE),
        // Real "now" (run can take minutes) — see AiCallLogger for the same
        // getCurrentTime() vs getRequestTime() reasoning.
        'created' => $this->time->getCurrentTime(),
      ])
      ->execute();

    return ['run_id' => $runId, 'metrics' => $metrics];
  }

  /**
   * Loads the golden-set fixture, or [] if it doesn't exist yet.
   */
  public function loadGoldenSet(): array {
    $path = $this->goldenSet->fixturePath();
    if (!is_file($path)) {
      return [];
    }
    $data = json_decode((string) file_get_contents($path), TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * Sums token usage logged for $operation since $since, and estimates cost.
   *
   * 'attempts' counts every provider attempt including internal retries
   * (one row each — see AiAnalyzerService::chat()); 'logical_calls' counts
   * distinct call_group values, i.e. how many classifyRelevance()/
   * classifyAndCluster() invocations actually happened. attempts >
   * logical_calls means retries fired during this run.
   */
  protected function callCost(string $operation, int $since): array {
    $query = $this->database->select('maemgaba_ai_call_log', 'l');
    $query->condition('l.operation', $operation);
    $query->condition('l.created', $since, '>=');
    $query->addExpression('COUNT(*)', 'attempts');
    $query->addExpression('COUNT(DISTINCT l.call_group)', 'logical_calls');
    $query->addExpression('SUM(CASE WHEN l.success = 0 THEN 1 ELSE 0 END)', 'failures');
    $query->addExpression('SUM(l.input_tokens)', 'input_tokens');
    $query->addExpression('SUM(l.output_tokens)', 'output_tokens');
    $query->addExpression('SUM(l.reasoning_tokens)', 'reasoning_tokens');
    $query->addExpression('SUM(l.cached_tokens)', 'cached_tokens');
    $row = $query->execute()->fetchAssoc() ?: [];

    $inputTokens = (int) ($row['input_tokens'] ?? 0);
    $outputTokens = (int) ($row['output_tokens'] ?? 0);
    $reasoningTokens = (int) ($row['reasoning_tokens'] ?? 0);
    $cachedTokens = (int) ($row['cached_tokens'] ?? 0);

    return [
      'attempts' => (int) ($row['attempts'] ?? 0),
      'logical_calls' => (int) ($row['logical_calls'] ?? 0),
      'failures' => (int) ($row['failures'] ?? 0),
      'input_tokens' => $inputTokens,
      'output_tokens' => $outputTokens,
      'reasoning_tokens' => $reasoningTokens,
      'cached_tokens' => $cachedTokens,
      'estimated_usd' => round($this->costByProviderModel($operation, $since), 4),
    ];
  }

  /**
   * Sums estimated cost for $operation since $since, priced per provider/model.
   *
   * A single call summing all tokens for the operation and pricing the
   * blend once would silently apply whichever provider is priced last to
   * every provider's usage. Grouping by provider/model first and adding
   * the per-group estimates keeps mixed-provider runs accurate.
   */
  protected function costByProviderModel(string $operation, int $since): float {
    $query = $this->database->select('maemgaba_ai_call_log', 'l');
    $query->condition('l.operation', $operation);
    $query->condition('l.created', $since, '>=');
    $query->addField('l', 'provider');
    $query->addField('l', 'model');
    $query->addExpression('SUM(l.input_tokens)', 'input_tokens');
    $query->addExpression('SUM(l.output_tokens)', 'output_tokens');
    $query->addExpression('SUM(l.reasoning_tokens)', 'reasoning_tokens');
    $query->addExpression('SUM(l.cached_tokens)', 'cached_tokens');
    $query->groupBy('l.provider');
    $query->groupBy('l.model');

    $total = 0.0;
    foreach ($query->execute() as $row) {
      $total += $this->pricing->estimateUsd(
        (string) $row->provider,
        (string) $row->model,
        (int) $row->input_tokens,
        (int) $row->output_tokens,
        (int) $row->reasoning_tokens,
        (int) $row->cached_tokens,
      );
    }
    return $total;
  }

  /**
   * Scores one field's expected/got pairs.
   *
   * Reports accuracy plus per-class precision/recall.
   *
   * @param array $pairs
   *   List of ['expected' => mixed, 'got' => mixed].
   *
   * @return array
   *   ['accuracy' => float|null, 'total' => int, 'per_class' => array].
   */
  protected function scoreField(array $pairs): array {
    $total = count($pairs);
    $correct = 0;
    $classes = [];

    $touch = function (string $label) use (&$classes): void {
      $classes[$label] ??= ['tp' => 0, 'fp' => 0, 'fn' => 0, 'support' => 0];
    };

    foreach ($pairs as $pair) {
      $expected = $pair['expected'];
      $got = $pair['got'];

      if (is_string($expected) && $expected !== '') {
        $touch($expected);
        $classes[$expected]['support']++;
      }
      if (is_string($got) && $got !== '') {
        $touch($got);
      }

      if ($expected !== NULL && $expected === $got) {
        $correct++;
        $classes[$expected]['tp']++;
        continue;
      }
      if (is_string($got) && $got !== '') {
        $classes[$got]['fp']++;
      }
      if (is_string($expected) && $expected !== '') {
        $classes[$expected]['fn']++;
      }
    }

    $perClass = [];
    foreach ($classes as $label => $c) {
      $precisionDenom = $c['tp'] + $c['fp'];
      $recallDenom = $c['tp'] + $c['fn'];
      $perClass[$label] = [
        'precision' => $precisionDenom > 0 ? round($c['tp'] / $precisionDenom, 3) : NULL,
        'recall' => $recallDenom > 0 ? round($c['tp'] / $recallDenom, 3) : NULL,
        'support' => $c['support'],
      ];
    }

    return [
      'accuracy' => $total > 0 ? round($correct / $total, 3) : NULL,
      'total' => $total,
      'per_class' => $perClass,
    ];
  }

}

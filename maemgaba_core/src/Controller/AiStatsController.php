<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\maemgaba_core\Service\AiPricing;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin dashboard at /admin/config/services/news-engine/ai-stats.
 *
 * Reads maemgaba_ai_call_log (Step 1) and maemgaba_eval_run (Step 3) so cost,
 * latency and accuracy are visible without querying the DB by hand — the
 * acceptance criterion for Phase 1 Step 4.
 */
class AiStatsController extends ControllerBase {

  /**
   * Window for the call-log summary table.
   */
  protected const WINDOW_DAYS = 30;

  /**
   * Most recent eval runs to list.
   */
  protected const EVAL_RUN_LIMIT = 50;

  public function __construct(
    protected Connection $database,
    protected AiPricing $pricing,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('maemgaba_core.ai_pricing'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Builds the dashboard page.
   */
  public function page(): array {
    return [
      'intro' => [
        '#markup' => '<p>' . $this->t('The last @n days of AI calls, and every evaluation run recorded so far. "Logical calls" counts business invocations (e.g. one classified card); "HTTP attempts" counts every real request sent to the provider, retries included; the two only differ after transient failures.', ['@n' => self::WINDOW_DAYS]) . '</p>',
      ],
      'calls' => [
        '#type' => 'details',
        '#title' => $this->t('AI calls per operation (@n days)', ['@n' => self::WINDOW_DAYS]),
        '#open' => TRUE,
        'table' => $this->callStatsTable(),
      ],
      'evals' => [
        '#type' => 'details',
        '#title' => $this->t('Evaluation runs'),
        '#open' => TRUE,
        'table' => $this->evalRunsTable(),
      ],
      '#cache' => ['max-age' => 60],
    ];
  }

  /**
   * Table of per-operation call stats for the last WINDOW_DAYS.
   */
  protected function callStatsTable(): array {
    $cutoff = $this->time->getRequestTime() - (self::WINDOW_DAYS * 86400);

    $query = $this->database->select('maemgaba_ai_call_log', 'l');
    $query->condition('l.created', $cutoff, '>=');
    $query->addField('l', 'operation');
    $query->addExpression('COUNT(*)', 'attempts');
    $query->addExpression('COUNT(DISTINCT l.call_group)', 'logical_calls');
    $query->addExpression('SUM(CASE WHEN l.success = 0 THEN 1 ELSE 0 END)', 'errors');
    $query->addExpression('AVG(l.latency_ms)', 'avg_latency');
    $query->addExpression('SUM(l.input_tokens)', 'input_tokens');
    $query->addExpression('SUM(l.output_tokens)', 'output_tokens');
    $query->addExpression('SUM(l.reasoning_tokens)', 'reasoning_tokens');
    $query->addExpression('SUM(l.cached_tokens)', 'cached_tokens');
    $query->addExpression('SUM(l.estimated)', 'estimated_rows');
    $query->groupBy('l.operation');
    $query->orderBy('l.operation');

    $costsByOperation = $this->costsByOperation($cutoff);

    $rows = [];
    foreach ($query->execute() as $row) {
      $attempts = (int) $row->attempts;
      $logicalCalls = (int) $row->logical_calls;
      $errors = (int) $row->errors;
      $inputTokens = (int) $row->input_tokens;
      $outputTokens = (int) $row->output_tokens;
      $reasoningTokens = (int) $row->reasoning_tokens;
      $cachedTokens = (int) $row->cached_tokens;
      $cost = $costsByOperation[$row->operation] ?? 0.0;
      $errorRate = $attempts > 0 ? round($errors / $attempts * 100, 1) : 0.0;

      $retries = $attempts - $logicalCalls;
      $rows[] = [
        $row->operation,
        $logicalCalls,
        $retries > 0 ? "{$attempts} ({$retries} retries)" : $attempts,
        "{$errors} ({$errorRate}%)",
        round((float) $row->avg_latency),
        number_format($inputTokens) . ' / ' . number_format($outputTokens) . ($reasoningTokens > 0 || $cachedTokens > 0 ? " (+{$reasoningTokens} thinking / {$cachedTokens} cache)" : ''),
        (int) $row->estimated_rows > 0
          ? $this->t('~US$ @c (@n estimated)', ['@c' => number_format($cost, 4), '@n' => (int) $row->estimated_rows])
          : 'US$ ' . number_format($cost, 4),
      ];
    }

    if (!$rows) {
      return ['#markup' => '<p>' . $this->t('No AI calls recorded in this period.') . '</p>'];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Operation'),
        $this->t('Logical calls'),
        $this->t('HTTP attempts'),
        $this->t('Errors'),
        $this->t('Mean latency (ms)'),
        $this->t('Tokens in / out'),
        $this->t('Estimated cost'),
      ],
      '#rows' => $rows,
    ];
  }

  /**
   * Estimated cost per operation since $cutoff, priced per provider/model.
   *
   * Grouping by provider/model before pricing (rather than pricing one
   * blended token sum per operation) keeps the estimate correct when an
   * operation's calls span more than one provider — e.g. after switching
   * the default chat provider mid-window.
   */
  protected function costsByOperation(int $cutoff): array {
    $query = $this->database->select('maemgaba_ai_call_log', 'l');
    $query->condition('l.created', $cutoff, '>=');
    $query->addField('l', 'operation');
    $query->addField('l', 'provider');
    $query->addField('l', 'model');
    $query->addExpression('SUM(l.input_tokens)', 'input_tokens');
    $query->addExpression('SUM(l.output_tokens)', 'output_tokens');
    $query->addExpression('SUM(l.reasoning_tokens)', 'reasoning_tokens');
    $query->addExpression('SUM(l.cached_tokens)', 'cached_tokens');
    $query->groupBy('l.operation');
    $query->groupBy('l.provider');
    $query->groupBy('l.model');

    $costs = [];
    foreach ($query->execute() as $row) {
      $costs[$row->operation] = ($costs[$row->operation] ?? 0.0) + $this->pricing->estimateUsd(
        (string) $row->provider,
        (string) $row->model,
        (int) $row->input_tokens,
        (int) $row->output_tokens,
        (int) $row->reasoning_tokens,
        (int) $row->cached_tokens,
      );
    }
    return $costs;
  }

  /**
   * Table of persisted eval runs with their headline accuracy metrics.
   */
  protected function evalRunsTable(): array {
    $query = $this->database->select('maemgaba_eval_run', 'r');
    $query->fields('r', ['id', 'operation', 'prompt_config_id', 'item_count', 'metrics', 'created']);
    $query->orderBy('r.id', 'DESC');
    $query->range(0, self::EVAL_RUN_LIMIT);

    $rows = [];
    foreach ($query->execute() as $row) {
      $metrics = json_decode($row->metrics, TRUE) ?: [];
      $fields = $metrics['fields'] ?? [];

      $accuracy_parts = [];
      foreach ($fields as $field => $score) {
        $acc = $score['accuracy'] ?? NULL;
        $accuracy_parts[] = $acc !== NULL ? sprintf('%s: %.1f%%', $field, $acc * 100) : "{$field}: n/d";
      }

      $cost = $metrics['cost']['estimated_usd'] ?? NULL;

      $rows[] = [
        $row->id,
        date('Y-m-d H:i', (int) $row->created),
        $row->operation,
        $row->prompt_config_id,
        $row->item_count,
        implode(' · ', $accuracy_parts) ?: 'n/d',
        $cost !== NULL ? 'US$ ' . number_format((float) $cost, 4) : 'n/d',
      ];
    }

    if (!$rows) {
      return ['#markup' => '<p>' . $this->t('No evaluation runs recorded yet. Run <code>drush maemgaba:eval relevance</code>.') . '</p>'];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Run'),
        $this->t('Date'),
        $this->t('Operation'),
        $this->t('Prompt'),
        $this->t('Items'),
        $this->t('Accuracy per field'),
        $this->t('Estimated cost'),
      ],
      '#rows' => $rows,
    ];
  }

}

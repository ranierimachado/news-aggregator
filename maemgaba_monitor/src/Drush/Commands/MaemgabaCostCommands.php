<?php

declare(strict_types=1);

namespace Drupal\maemgaba_monitor\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\maemgaba_monitor\Service\CostReportService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the news engine cost/usage monitor.
 *
 * The query logic lives in CostReportService; these commands only format.
 * Dollar figures inherit AiPricing's placeholder-price caveat (Phase 1b T5).
 */
class MaemgabaCostCommands extends DrushCommands {

  public function __construct(
    protected CostReportService $report,
  ) {
    parent::__construct();
  }

  /**
   * Dependency Injection factory for Drush.
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('maemgaba_monitor.cost_report'),
    );
  }

  /**
   * Daily AI usage and estimated cost of the pipeline.
   */
  #[CLI\Command(name: 'maemgaba:costs', aliases: ['mg-costs'])]
  #[CLI\Option(name: 'days', description: 'How many days back to report (including today).')]
  #[CLI\FieldLabels(labels: [
    'day' => 'Day',
    'items_processed' => 'Items',
    'cards_created' => 'Cards',
    'events_created' => 'Events',
    'consensus_events' => 'Consensus',
    'backfill_cards' => 'Backfill',
    'logical_calls' => 'AI calls',
    'attempts' => 'Attempts',
    'failed_attempts' => 'Failed',
    'input_tokens' => 'In tok',
    'output_tokens' => 'Out tok',
    'reasoning_tokens' => 'Think tok',
    'cached_tokens' => 'Cache tok',
    'cost_usd' => 'Cost US$',
    'eval_calls' => 'Eval calls',
    'eval_cost_usd' => 'Eval US$',
    'total_cost_usd' => 'Total US$',
  ])]
  #[CLI\DefaultTableFields(fields: [
    'day', 'items_processed', 'cards_created', 'events_created',
    'input_tokens', 'output_tokens', 'reasoning_tokens', 'total_cost_usd',
  ])]
  public function daily($options = ['format' => 'table', 'days' => 30]): RowsOfFields {
    $rows = $this->report->dailyReport((int) $options['days']);
    if (!$rows) {
      $this->logger()->notice(dt('No AI activity logged in the last @days days.', ['@days' => $options['days']]));
    }
    return new RowsOfFields($rows);
  }

  /**
   * Monthly totals of AI usage and estimated cost.
   */
  #[CLI\Command(name: 'maemgaba:costs-monthly', aliases: ['mg-costs-m'])]
  #[CLI\Option(name: 'months', description: 'How many calendar months back to report (including the current one).')]
  #[CLI\FieldLabels(labels: [
    'month' => 'Month',
    'items_processed' => 'Items',
    'cards_created' => 'Cards',
    'events_created' => 'Events',
    'consensus_events' => 'Consensus',
    'backfill_cards' => 'Backfill',
    'logical_calls' => 'AI calls',
    'attempts' => 'Attempts',
    'failed_attempts' => 'Failed',
    'input_tokens' => 'In tok',
    'output_tokens' => 'Out tok',
    'reasoning_tokens' => 'Think tok',
    'cached_tokens' => 'Cache tok',
    'cost_usd' => 'Cost US$',
    'eval_calls' => 'Eval calls',
    'eval_cost_usd' => 'Eval US$',
    'total_cost_usd' => 'Total US$',
  ])]
  #[CLI\DefaultTableFields(fields: [
    'month', 'items_processed', 'cards_created', 'events_created',
    'input_tokens', 'output_tokens', 'reasoning_tokens', 'total_cost_usd',
  ])]
  public function monthly($options = ['format' => 'table', 'months' => 12]): RowsOfFields {
    return new RowsOfFields($this->report->monthlyReport((int) $options['months']));
  }

  /**
   * Per-item AI call detail for one day (defaults to today).
   */
  #[CLI\Command(name: 'maemgaba:costs-day', aliases: ['mg-costs-day'])]
  #[CLI\Argument(name: 'day', description: 'Day to detail, in Y-m-d (site timezone). Defaults to today.')]
  #[CLI\FieldLabels(labels: [
    'time' => 'Time',
    'operation' => 'Operation',
    'context_type' => 'Context',
    'context_id' => 'Id',
    'context_label' => 'Item',
    'attempts' => 'Attempts',
    'success' => 'OK',
    'input_tokens' => 'In tok',
    'output_tokens' => 'Out tok',
    'reasoning_tokens' => 'Think tok',
    'cached_tokens' => 'Cache tok',
    'cost_usd' => 'Cost US$',
  ])]
  #[CLI\DefaultTableFields(fields: [
    'time', 'operation', 'context_type', 'context_id', 'context_label',
    'success', 'input_tokens', 'output_tokens', 'reasoning_tokens', 'cost_usd',
  ])]
  public function dayDetail(?string $day = NULL, $options = ['format' => 'table']): RowsOfFields {
    $day ??= date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
      throw new \InvalidArgumentException("Invalid day '{$day}' — expected Y-m-d.");
    }
    $rows = $this->report->dayDetail($day);
    if (!$rows) {
      $this->logger()->notice(dt('No AI calls logged on @day.', ['@day' => $day]));
    }
    return new RowsOfFields($rows);
  }

}

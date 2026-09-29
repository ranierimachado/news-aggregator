<?php

declare(strict_types=1);

namespace Drupal\maemgaba_monitor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\maemgaba_monitor\Service\CostReportService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin dashboard at /admin/config/services/news-engine/costs.
 *
 * Daily and monthly rollups of pipeline AI usage/cost, with a per-item drill
 * -down per day. Reads everything through CostReportService; the underlying
 * numbers carry AiPricing's placeholder-price caveat until Phase 1b T5
 * replaces the constants with billing-verified prices.
 */
class CostDashboardController extends ControllerBase {

  /**
   * Days shown on the daily table.
   */
  protected const DAILY_WINDOW = 30;

  /**
   * Months shown on the monthly table.
   */
  protected const MONTHLY_WINDOW = 12;

  public function __construct(
    protected CostReportService $report,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('maemgaba_monitor.cost_report'),
    );
  }

  /**
   * Builds the main dashboard: monthly totals + daily breakdown.
   */
  public function page(): array {
    return [
      'intro' => [
        '#markup' => '<p>' . $this->t("AI usage and estimated cost of the site's regular operation (maemgaba:process, backfill and consensus). \"Items\" = inbound-queue items processed successfully; eval runs get their own column and stay out of the operation cost. Click a day to see the per-item detail.") . '</p>'
        . '<p><strong>' . $this->t('⚠ Cost figures below are almost certainly too low.') . '</strong> ' . $this->t("US$ values use AiPricing's placeholder \$/1M token prices, never verified against real billing (Phase 1b, T5 still open). A live check on 2026-07-28 (comparing Google AI Studio's spend-cap and credit-balance movement against our logged tokens for the same 200-call batch) found real cost running <strong>6–14× higher</strong> than what this dashboard shows. Treat every US$ figure here as a relative/comparable-across-runs number, not a budget figure, until T5 replaces the constants with billing-verified prices.") . '</p>',
      ],
      'monthly' => [
        '#type' => 'details',
        '#title' => $this->t('Monthly totals (@n months)', ['@n' => self::MONTHLY_WINDOW]),
        '#open' => TRUE,
        'table' => $this->monthlyTable(),
      ],
      'daily' => [
        '#type' => 'details',
        '#title' => $this->t('Daily costs (@n days)', ['@n' => self::DAILY_WINDOW]),
        '#open' => TRUE,
        'table' => $this->dailyTable(),
      ],
      '#cache' => ['max-age' => 60],
    ];
  }

  /**
   * Builds the per-item drill-down page for one day.
   */
  public function dayPage(string $day): array {
    $rows = [];
    foreach ($this->report->dayDetail($day) as $item) {
      $rows[] = [
        $item['time'],
        $item['operation'],
        $item['context_type'] !== '' ? $item['context_type'] . ($item['context_id'] !== NULL ? " #{$item['context_id']}" : '') : '—',
        $item['context_label'] !== '' ? $item['context_label'] : '—',
        $item['success'] ? '✓' : '✗',
        $item['attempts'],
        number_format($item['input_tokens']) . ' / ' . number_format($item['output_tokens'])
        . ($item['reasoning_tokens'] > 0 || $item['cached_tokens'] > 0 ? " (+{$item['reasoning_tokens']} think / {$item['cached_tokens']} cache)" : ''),
        'US$ ' . number_format($item['cost_usd'], 6),
      ];
    }

    return [
      'back' => [
        '#markup' => '<p>' . $this->t('<a href=":url">← Back to the cost dashboard</a>', [':url' => Url::fromRoute('maemgaba_monitor.costs')->toString()]) . '</p>',
      ],
      'title' => [
        '#markup' => '<h2>' . $this->t('AI calls on @day', ['@day' => $day]) . '</h2>',
      ],
      'table' => $rows ? [
        '#type' => 'table',
        '#header' => [
          $this->t('Time'),
          $this->t('Operation'),
          $this->t('Context'),
          $this->t('Item'),
          $this->t('OK'),
          $this->t('Attempts'),
          $this->t('Input / output tokens'),
          $this->t('Cost'),
        ],
        '#rows' => $rows,
      ] : ['#markup' => '<p>' . $this->t('No AI calls logged on this day.') . '</p>'],
      '#cache' => ['max-age' => 60],
    ];
  }

  /**
   * The monthly-totals table.
   */
  protected function monthlyTable(): array {
    $rows = [];
    foreach ($this->report->monthlyReport(self::MONTHLY_WINDOW) as $month) {
      $rows[] = [
        $month['month'],
        $month['items_processed'],
        $month['cards_created'] . ' / ' . $month['events_created'],
        $month['consensus_events'],
        $this->tokensCell($month),
        'US$ ' . number_format($month['cost_usd'], 4),
        $month['eval_calls'] > 0 ? 'US$ ' . number_format($month['eval_cost_usd'], 4) . " ({$month['eval_calls']})" : '—',
        'US$ ' . number_format($month['total_cost_usd'], 4),
      ];
    }
    if (!$rows) {
      return ['#markup' => '<p>' . $this->t('No AI calls logged yet.') . '</p>'];
    }
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Month'),
        $this->t('Items processed'),
        $this->t('Cards / Events created'),
        $this->t('Consensus'),
        $this->t('Input / output tokens'),
        $this->t('Operation cost'),
        $this->t('Eval cost'),
        $this->t('Total cost'),
      ],
      '#rows' => $rows,
    ];
  }

  /**
   * The daily-breakdown table, each day linking to its drill-down.
   */
  protected function dailyTable(): array {
    $rows = [];
    foreach ($this->report->dailyReport(self::DAILY_WINDOW) as $day) {
      $rows[] = [
        [
          'data' => [
            '#type' => 'link',
            '#title' => $day['day'],
            '#url' => Url::fromRoute('maemgaba_monitor.costs_day', ['day' => $day['day']]),
          ],
        ],
        $day['items_processed'],
        $day['cards_created'] . ' / ' . $day['events_created'],
        $day['consensus_events'],
        $day['backfill_cards'],
        $day['failed_attempts'],
        $this->tokensCell($day),
        'US$ ' . number_format($day['cost_usd'], 4),
        $day['eval_calls'] > 0 ? 'US$ ' . number_format($day['eval_cost_usd'], 4) . " ({$day['eval_calls']})" : '—',
        'US$ ' . number_format($day['total_cost_usd'], 4),
      ];
    }
    if (!$rows) {
      return ['#markup' => '<p>' . $this->t('No AI calls logged in this period.') . '</p>'];
    }
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Day'),
        $this->t('Items processed'),
        $this->t('Cards / Events created'),
        $this->t('Consensus'),
        $this->t('Backfill'),
        $this->t('Failures'),
        $this->t('Input / output tokens'),
        $this->t('Operation cost'),
        $this->t('Eval cost'),
        $this->t('Total cost'),
      ],
      '#rows' => $rows,
    ];
  }

  /**
   * Formats the input/output (+reasoning/cache) token cell of one row.
   */
  protected function tokensCell(array $row): string {
    $extra = $row['reasoning_tokens'] > 0 || $row['cached_tokens'] > 0
      ? ' (+' . number_format($row['reasoning_tokens']) . ' think / ' . number_format($row['cached_tokens']) . ' cache)'
      : '';
    return number_format($row['input_tokens']) . ' / ' . number_format($row['output_tokens']) . $extra;
  }

}

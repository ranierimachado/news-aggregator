<?php

declare(strict_types=1);

namespace Drupal\maemgaba_monitor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\maemgaba_core\Service\AiPricing;

/**
 * Daily/monthly cost and usage rollups over maemgaba_ai_call_log.
 *
 * Pure reporting — writes nothing. All attribution comes from the
 * context_type/context_id/context_label columns AiAnalyzerService stamps on
 * every logged attempt:
 * - inbound_queue → one maemgaba:process pipeline item (classify_cluster),
 * - card          → relevance backfill,
 * - event         → consensus synthesis,
 * - eval          → golden-set replays (testing, reported separately so
 *                   "regular operation" cost stays clean),
 * - ''            → unattributed (rows logged before the context columns
 *                   existed, or future callers that pass no context) —
 *                   counted as operation traffic, since that is what the
 *                   pre-context history overwhelmingly was.
 *
 * Days are bucketed in the site's default timezone using pure integer math
 * (FLOOR((created + offset) / 86400)) — no FROM_UNIXTIME/CONVERT_TZ, so the
 * result is independent of the DB session timezone.
 */
class CostReportService {

  public function __construct(
    protected Connection $database,
    protected AiPricing $pricing,
    protected TimeInterface $time,
  ) {}

  /**
   * Per-day usage/cost rows for the last $days days, most recent first.
   *
   * @param int $days
   *   How many days back to report (including today).
   *
   * @return array
   *   List of rows: day (Y-m-d), items_processed, cards_created,
   *   events_created, consensus_events, backfill_cards, logical_calls,
   *   attempts, failed_attempts, input_tokens, output_tokens,
   *   reasoning_tokens, cached_tokens, cost_usd (operation traffic),
   *   eval_calls, eval_cost_usd, total_cost_usd. Days with no activity at
   *   all are omitted.
   */
  public function dailyReport(int $days = 30): array {
    $offset = $this->timezoneOffset();
    $todayNum = $this->dayNumber($this->time->getCurrentTime(), $offset);
    $since = ($todayNum - $days + 1) * 86400 - $offset;

    $rows = [];
    foreach ($this->callLogByDay($since, $offset) as $dayNum => $log) {
      $rows[$dayNum] = $log;
    }
    foreach ($this->nodesCreatedByDay($since, $offset) as $dayNum => $counts) {
      $rows[$dayNum] = ($rows[$dayNum] ?? $this->emptyLogRow()) + $counts;
    }
    $costs = $this->costBreakdownByDay($since, $offset);

    krsort($rows);
    $report = [];
    foreach ($rows as $dayNum => $row) {
      $row += ['cards_created' => 0, 'events_created' => 0];
      $row['day'] = gmdate('Y-m-d', $dayNum * 86400);
      $dayCosts = $costs[$dayNum] ?? ['cost_usd' => 0.0, 'eval_cost_usd' => 0.0];
      $row['cost_usd'] = round($dayCosts['cost_usd'], 4);
      $row['eval_cost_usd'] = round($dayCosts['eval_cost_usd'], 4);
      $row['total_cost_usd'] = round($dayCosts['cost_usd'] + $dayCosts['eval_cost_usd'], 4);
      $report[] = $row;
    }
    return $report;
  }

  /**
   * Per-month totals for the last $months calendar months, most recent first.
   *
   * Same columns as dailyReport(), with 'month' (Y-m) instead of 'day'.
   */
  public function monthlyReport(int $months = 12): array {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $firstMonth = (new \DateTimeImmutable('now', $tz))
      ->modify('first day of this month')->setTime(0, 0)
      ->modify('-' . ($months - 1) . ' months');
    $days = (int) ceil(($this->time->getCurrentTime() - $firstMonth->getTimestamp()) / 86400) + 1;

    $byMonth = [];
    foreach ($this->dailyReport($days) as $row) {
      $month = substr($row['day'], 0, 7);
      if ($month < $firstMonth->format('Y-m')) {
        continue;
      }
      $target = &$byMonth[$month];
      if ($target === NULL) {
        $target = ['month' => $month] + array_fill_keys(array_keys($this->emptyLogRow()), 0)
          + ['cards_created' => 0, 'events_created' => 0]
          + ['cost_usd' => 0.0, 'eval_cost_usd' => 0.0, 'total_cost_usd' => 0.0];
      }
      // Sums both int (counts/tokens) and float (cost) columns. Costs are
      // summed here from each already-correctly-priced day (see
      // dailyReport()) rather than recomputed from the month's blended
      // token totals, which would reintroduce the mixed-provider mis-costing
      // this class exists to avoid.
      foreach ($row as $key => $value) {
        if (is_int($value) || is_float($value)) {
          $target[$key] += $value;
        }
      }
      unset($target);
    }

    krsort($byMonth);
    return array_map(function (array $row): array {
      $row['cost_usd'] = round($row['cost_usd'], 4);
      $row['eval_cost_usd'] = round($row['eval_cost_usd'], 4);
      $row['total_cost_usd'] = round($row['total_cost_usd'], 4);
      return $row;
    }, array_values($byMonth));
  }

  /**
   * Per-item detail for one day: one row per logical AI call.
   *
   * @param string $day
   *   Site-timezone day in Y-m-d format.
   *
   * @return array
   *   List of rows ordered by time: time (H:i:s), operation, context_type,
   *   context_id, context_label, attempts, success, input_tokens,
   *   output_tokens, reasoning_tokens, cached_tokens, cost_usd.
   */
  public function dayDetail(string $day): array {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $start = (new \DateTimeImmutable($day, $tz))->setTime(0, 0)->getTimestamp();
    $end = $start + 86400;

    $query = $this->database->select('maemgaba_ai_call_log', 'l');
    $query->condition('l.created', $start, '>=');
    $query->condition('l.created', $end, '<');
    $query->addField('l', 'call_group');
    $query->addExpression('MIN(l.created)', 'first_created');
    $query->addExpression('MAX(l.operation)', 'operation');
    $query->addExpression('MAX(l.context_type)', 'context_type');
    $query->addExpression('MAX(l.context_id)', 'context_id');
    $query->addExpression('MAX(l.context_label)', 'context_label');
    // A call_group's attempts share one logical call, so provider/model are
    // expected to be constant within the group — MAX() just picks the value
    // without requiring a GROUP BY on it.
    $query->addExpression('MAX(l.provider)', 'provider');
    $query->addExpression('MAX(l.model)', 'model');
    $query->addExpression('COUNT(*)', 'attempts');
    $query->addExpression('MAX(l.success)', 'success');
    $query->addExpression('SUM(l.input_tokens)', 'input_tokens');
    $query->addExpression('SUM(l.output_tokens)', 'output_tokens');
    $query->addExpression('SUM(l.reasoning_tokens)', 'reasoning_tokens');
    $query->addExpression('SUM(l.cached_tokens)', 'cached_tokens');
    $query->groupBy('l.call_group');
    $query->orderBy('first_created');

    $rows = [];
    foreach ($query->execute() as $record) {
      $row = [
        'time' => date('H:i:s', (int) $record->first_created),
        'operation' => (string) $record->operation,
        'context_type' => (string) $record->context_type,
        'context_id' => $record->context_id !== NULL ? (int) $record->context_id : NULL,
        'context_label' => (string) $record->context_label,
        'attempts' => (int) $record->attempts,
        'success' => (int) $record->success,
        'input_tokens' => (int) $record->input_tokens,
        'output_tokens' => (int) $record->output_tokens,
        'reasoning_tokens' => (int) $record->reasoning_tokens,
        'cached_tokens' => (int) $record->cached_tokens,
      ];
      $row['cost_usd'] = round($this->pricing->estimateUsd(
        (string) $record->provider, (string) $record->model,
        $row['input_tokens'], $row['output_tokens'], $row['reasoning_tokens'], $row['cached_tokens'],
      ), 6);
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * Aggregates the call log per site-timezone day since $since.
   */
  protected function callLogByDay(int $since, int $offset): array {
    $query = $this->database->select('maemgaba_ai_call_log', 'l');
    $query->condition('l.created', $since, '>=');
    $query->addExpression("FLOOR((l.created + {$offset}) / 86400)", 'day_num');

    $notEval = "l.context_type <> 'eval'";
    $query->addExpression("COUNT(DISTINCT CASE WHEN l.operation = 'classify_cluster' AND l.context_type = 'inbound_queue' AND l.success = 1 THEN l.call_group END)", 'items_processed');
    $query->addExpression("COUNT(DISTINCT CASE WHEN l.operation = 'synthesize_consensus' AND l.success = 1 THEN l.context_id END)", 'consensus_events');
    $query->addExpression("COUNT(DISTINCT CASE WHEN l.operation = 'relevance' AND l.context_type = 'card' AND l.success = 1 THEN l.call_group END)", 'backfill_cards');
    $query->addExpression("COUNT(DISTINCT CASE WHEN {$notEval} THEN l.call_group END)", 'logical_calls');
    $query->addExpression("SUM(CASE WHEN {$notEval} THEN 1 ELSE 0 END)", 'attempts');
    $query->addExpression("SUM(CASE WHEN {$notEval} AND l.success = 0 THEN 1 ELSE 0 END)", 'failed_attempts');
    foreach (['input_tokens', 'output_tokens', 'reasoning_tokens', 'cached_tokens'] as $col) {
      $query->addExpression("SUM(CASE WHEN {$notEval} THEN COALESCE(l.{$col}, 0) ELSE 0 END)", $col);
      $query->addExpression("SUM(CASE WHEN {$notEval} THEN 0 ELSE COALESCE(l.{$col}, 0) END)", "eval_{$col}");
    }
    $query->addExpression("COUNT(DISTINCT CASE WHEN l.context_type = 'eval' THEN l.call_group END)", 'eval_calls');
    $query->groupBy('day_num');

    $result = [];
    foreach ($query->execute() as $record) {
      $row = [];
      foreach ($this->emptyLogRow() as $key => $unused) {
        $row[$key] = (int) $record->{$key};
      }
      $result[(int) $record->day_num] = $row;
    }
    return $result;
  }

  /**
   * Counts cards/events created per site-timezone day since $since.
   *
   * Read from node_field_data, not the call log, so the numbers stay right
   * even for history that predates AI-call attribution — and they reflect
   * what actually landed on the site, not just what the AI was asked.
   */
  protected function nodesCreatedByDay(int $since, int $offset): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->condition('n.type', ['card', 'event'], 'IN');
    $query->condition('n.created', $since, '>=');
    $query->addField('n', 'type');
    $query->addExpression("FLOOR((n.created + {$offset}) / 86400)", 'day_num');
    $query->addExpression('COUNT(*)', 'total');
    $query->groupBy('day_num');
    $query->groupBy('n.type');

    $result = [];
    foreach ($query->execute() as $record) {
      $key = $record->type === 'card' ? 'cards_created' : 'events_created';
      $result[(int) $record->day_num][$key] = (int) $record->total;
    }
    foreach ($result as &$row) {
      $row += ['cards_created' => 0, 'events_created' => 0];
    }
    return $result;
  }

  /**
   * Per-day cost/eval_cost, priced per provider/model then summed.
   *
   * Kept as a separate query from callLogByDay() rather than added to it:
   * that query sums tokens per day across every provider blended together,
   * which is fine for the raw token-count columns it reports but cannot
   * feed a single accurate price — a day that used two providers needs
   * each provider's tokens priced at its own rate before adding the costs
   * together. See AiPricing.
   *
   * @return array
   *   Keyed by day_num, each value
   *   ['cost_usd' => float, 'eval_cost_usd' => float].
   */
  protected function costBreakdownByDay(int $since, int $offset): array {
    $query = $this->database->select('maemgaba_ai_call_log', 'l');
    $query->condition('l.created', $since, '>=');
    $query->addExpression("FLOOR((l.created + {$offset}) / 86400)", 'day_num');
    $query->addField('l', 'provider');
    $query->addField('l', 'model');

    $notEval = "l.context_type <> 'eval'";
    foreach (['input_tokens', 'output_tokens', 'reasoning_tokens', 'cached_tokens'] as $col) {
      $query->addExpression("SUM(CASE WHEN {$notEval} THEN COALESCE(l.{$col}, 0) ELSE 0 END)", $col);
      $query->addExpression("SUM(CASE WHEN {$notEval} THEN 0 ELSE COALESCE(l.{$col}, 0) END)", "eval_{$col}");
    }
    $query->groupBy('day_num');
    $query->groupBy('l.provider');
    $query->groupBy('l.model');

    $result = [];
    foreach ($query->execute() as $record) {
      $dayNum = (int) $record->day_num;
      $result[$dayNum] ??= ['cost_usd' => 0.0, 'eval_cost_usd' => 0.0];
      $result[$dayNum]['cost_usd'] += $this->pricing->estimateUsd(
        (string) $record->provider, (string) $record->model,
        (int) $record->input_tokens, (int) $record->output_tokens,
        (int) $record->reasoning_tokens, (int) $record->cached_tokens,
      );
      $result[$dayNum]['eval_cost_usd'] += $this->pricing->estimateUsd(
        (string) $record->provider, (string) $record->model,
        (int) $record->eval_input_tokens, (int) $record->eval_output_tokens,
        (int) $record->eval_reasoning_tokens, (int) $record->eval_cached_tokens,
      );
    }
    return $result;
  }

  /**
   * The zero-valued shape of one day's call-log aggregate.
   */
  protected function emptyLogRow(): array {
    return [
      'items_processed' => 0,
      'consensus_events' => 0,
      'backfill_cards' => 0,
      'logical_calls' => 0,
      'attempts' => 0,
      'failed_attempts' => 0,
      'input_tokens' => 0,
      'output_tokens' => 0,
      'reasoning_tokens' => 0,
      'cached_tokens' => 0,
      'eval_calls' => 0,
      'eval_input_tokens' => 0,
      'eval_output_tokens' => 0,
      'eval_reasoning_tokens' => 0,
      'eval_cached_tokens' => 0,
    ];
  }

  /**
   * Site-timezone UTC offset in seconds, evaluated now.
   *
   * Good enough for day bucketing: Brazil currently observes no DST, and a
   * DST edge would only ever shift which side of midnight a row lands on.
   */
  protected function timezoneOffset(): int {
    return (new \DateTimeZone(date_default_timezone_get()))
      ->getOffset(new \DateTimeImmutable());
  }

  /**
   * The site-timezone day number (days since epoch) of a timestamp.
   */
  protected function dayNumber(int $timestamp, int $offset): int {
    return (int) floor(($timestamp + $offset) / 86400);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Ask;

/**
 * Scores "Ask the data" answers against hand-written reference queries.
 *
 * A question matches when the model's rows equal the reference rows as a
 * multiset: row order and column order are ignored, numbers are compared
 * rounded to two decimals, strings trimmed. With match: projection, the
 * model may return extra columns as long as some choice of its columns
 * equals the reference exactly. Pure PHP, unit-tested.
 */
final class AskEvalScorer {

  /**
   * Match modes an eval item may name.
   */
  public const MODES = ['exact', 'projection'];

  /**
   * Parses the eval file.
   *
   * @return array{questions: array<int, array>, hostile: array<int, array>}
   *   Questions have id, question, reference_sql, match, note; hostile
   *   items have id, question, note.
   *
   * @throws \InvalidArgumentException
   */
  public static function parse(mixed $data): array {
    if (!is_array($data) || !isset($data['questions']) || !is_array($data['questions'])) {
      throw new \InvalidArgumentException('The eval file needs a top-level "questions" list.');
    }
    $questions = [];
    $ids = [];
    foreach ($data['questions'] as $i => $item) {
      foreach (['id', 'question', 'reference_sql'] as $key) {
        if (!is_array($item) || !isset($item[$key]) || trim((string) $item[$key]) === '') {
          throw new \InvalidArgumentException(sprintf('Question #%d has no %s.', $i + 1, $key));
        }
      }
      $mode = (string) ($item['match'] ?? 'exact');
      if (!in_array($mode, self::MODES, TRUE)) {
        throw new \InvalidArgumentException(sprintf('Question %s: unknown match mode "%s".', $item['id'], $mode));
      }
      if (isset($ids[$item['id']])) {
        throw new \InvalidArgumentException(sprintf('Duplicate id %s.', $item['id']));
      }
      $ids[$item['id']] = TRUE;
      $questions[] = [
        'id' => (string) $item['id'],
        'question' => trim((string) $item['question']),
        'reference_sql' => trim((string) $item['reference_sql']),
        'match' => $mode,
        'note' => (string) ($item['note'] ?? ''),
      ];
    }
    $hostile = [];
    foreach ((array) ($data['hostile'] ?? []) as $i => $item) {
      if (!is_array($item) || trim((string) ($item['question'] ?? '')) === '') {
        throw new \InvalidArgumentException(sprintf('Hostile item #%d has no question.', $i + 1));
      }
      $hostile[] = [
        'id' => (string) ($item['id'] ?? ('h' . ($i + 1))),
        'question' => trim((string) $item['question']),
        'note' => (string) ($item['note'] ?? ''),
      ];
    }
    return ['questions' => $questions, 'hostile' => $hostile];
  }

  /**
   * Normalizes one cell for comparison.
   */
  public static function value(mixed $value): string {
    if ($value === NULL) {
      return 'NULL';
    }
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    $text = trim((string) $value);
    if (is_numeric($text)) {
      $rounded = round((float) $text, 2);
      if ($rounded == 0.0) {
        return '0';
      }
      return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }
    return $text;
  }

  /**
   * Rows as a sorted list of sorted, normalized value tuples.
   *
   * @param array<int, array<int|string, mixed>> $rows
   *   Result rows.
   *
   * @return string[]
   *   One string per row.
   */
  public static function canonical(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
      $values = array_map([self::class, 'value'], array_values((array) $row));
      sort($values, SORT_STRING);
      $out[] = implode("\x1f", $values);
    }
    sort($out, SORT_STRING);
    return $out;
  }

  /**
   * Whether the model's rows answer like the reference rows.
   */
  public static function matches(array $reference, array $got, string $mode = 'exact'): bool {
    if (count($reference) !== count($got)) {
      return FALSE;
    }
    if (self::canonical($reference) === self::canonical($got)) {
      return TRUE;
    }
    if ($mode !== 'projection' || !$reference) {
      return FALSE;
    }
    $refWidth = count((array) reset($reference));
    $gotWidth = count((array) reset($got));
    if ($gotWidth <= $refWidth || $gotWidth > 10) {
      return FALSE;
    }
    $target = self::canonical($reference);
    foreach (self::combinations(range(0, $gotWidth - 1), $refWidth) as $columns) {
      $projected = array_map(fn ($row) => array_map(fn ($c) => array_values((array) $row)[$c], $columns), $got);
      if (self::canonical($projected) === $target) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * K-combinations of a list.
   *
   * @return \Generator<int[]>
   *   Each combination as a list.
   */
  public static function combinations(array $items, int $k): \Generator {
    if ($k === 0) {
      yield [];
      return;
    }
    for ($i = 0, $n = count($items); $i <= $n - $k; $i++) {
      foreach (self::combinations(array_slice($items, $i + 1), $k - 1) as $rest) {
        yield array_merge([$items[$i]], $rest);
      }
    }
  }

  /**
   * Summary numbers for one model's run.
   *
   * @param array $rows
   *   Per-question results: match (bool), outcome, latency_ms, cost_usd.
   * @param array $hostile
   *   Per-hostile-item results: outcome.
   */
  public static function summarize(array $rows, array $hostile = []): array {
    $n = count($rows);
    $latencies = array_map(fn ($r) => (int) $r['latency_ms'], $rows);
    sort($latencies);
    $cost = array_sum(array_map(fn ($r) => (float) $r['cost_usd'], $rows));
    $count = fn (string $outcome) => count(array_filter($rows, fn ($r) => $r['outcome'] === $outcome));
    $refused = ['refused_model', 'refused_validator'];
    return [
      'questions' => $n,
      'exact' => count(array_filter($rows, fn ($r) => $r['match'])),
      'exact_rate' => $n ? round(100 * count(array_filter($rows, fn ($r) => $r['match'])) / $n, 1) : 0.0,
      'validator_rejections' => $count('refused_validator'),
      'model_refusals' => $count('refused_model'),
      'errors' => $count('error') + $count('unavailable'),
      'latency_mean_ms' => $n ? (int) round(array_sum($latencies) / $n) : 0,
      'latency_median_ms' => $n ? $latencies[intdiv($n - 1, 2)] : 0,
      'latency_max_ms' => $n ? end($latencies) : 0,
      'cost_usd' => round($cost, 4),
      'cost_per_question_usd' => $n ? round($cost / $n, 5) : 0.0,
      'hostile' => count($hostile),
      'hostile_refused' => count(array_filter($hostile, fn ($r) => in_array($r['outcome'], $refused, TRUE))),
    ];
  }

}

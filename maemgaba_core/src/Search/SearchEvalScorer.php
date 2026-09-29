<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Search;

/**
 * Scores a search evaluation run (pure logic, no Drupal services).
 *
 * The query file lists queries with an expected event, or with
 * `expected_nid: null` for off-topic queries whose right answer is "no
 * confident hit". `also_accept` lists duplicate events of the same story
 * (the pipeline sometimes splits one story in two); showing one of them
 * counts as a hit, and the report says so. A run supplies, per query id,
 * the node ids the engine showed (after its similarity ceiling, best first),
 * the raw ranking without the ceiling, the latency and the keyword
 * baseline's matches.
 *
 * Metrics:
 * - hit@1 / hit@3: the expected event is first / in the first three shown.
 * - off-topic correct: the engine showed nothing.
 * - keyword baseline: an all-words LIKE search found nothing (keyword_zero)
 *   or found the expected event somewhere in its unranked set.
 */
final class SearchEvalScorer {

  /**
   * Validates and normalises a parsed query file.
   *
   * @param mixed $data
   *   The parsed YAML.
   *
   * @return array<int, array{id: string, type: string, query: string, expected_nid: ?int, also_accept: int[], expected_title: string, note: string}>
   *   The queries.
   *
   * @throws \InvalidArgumentException
   *   On a malformed file.
   */
  public static function parseQueries(mixed $data): array {
    if (!is_array($data) || !isset($data['queries']) || !is_array($data['queries'])) {
      throw new \InvalidArgumentException('The query file needs a top-level "queries" list.');
    }
    $queries = [];
    $ids = [];
    foreach ($data['queries'] as $i => $row) {
      if (!is_array($row) || !isset($row['query']) || trim((string) $row['query']) === '') {
        throw new \InvalidArgumentException(sprintf('Query #%d has no "query" text.', $i + 1));
      }
      $id = (string) ($row['id'] ?? ('q' . ($i + 1)));
      if (isset($ids[$id])) {
        throw new \InvalidArgumentException(sprintf('Duplicate query id "%s".', $id));
      }
      $ids[$id] = TRUE;
      if (!array_key_exists('expected_nid', $row)) {
        throw new \InvalidArgumentException(sprintf('Query "%s" needs expected_nid (null for off-topic).', $id));
      }
      $expected = $row['expected_nid'];
      if ($expected !== NULL && (!is_numeric($expected) || (int) $expected <= 0)) {
        throw new \InvalidArgumentException(sprintf('Query "%s" has an invalid expected_nid.', $id));
      }
      $also = array_values(array_map('intval', array_filter((array) ($row['also_accept'] ?? []), 'is_numeric')));
      $queries[] = [
        'id' => $id,
        'type' => (string) ($row['type'] ?? ($expected === NULL ? 'off_topic' : 'unspecified')),
        'query' => trim((string) $row['query']),
        'expected_nid' => $expected === NULL ? NULL : (int) $expected,
        'also_accept' => $expected === NULL ? [] : $also,
        'expected_title' => (string) ($row['expected_title'] ?? ''),
        'note' => (string) ($row['note'] ?? ''),
      ];
    }
    return $queries;
  }

  /**
   * Scores one query.
   *
   * @param array $query
   *   One entry from parseQueries().
   * @param int[] $shown
   *   Node ids shown (after the ceiling), best first.
   * @param int[] $raw
   *   Node ids ranked without the ceiling, best first.
   * @param int[] $keywordMatches
   *   Node ids the keyword baseline matched.
   *
   * @return array
   *   Per-query verdicts.
   */
  public static function scoreQuery(array $query, array $shown, array $raw, array $keywordMatches): array {
    $expected = $query['expected_nid'];
    $accept = $expected === NULL ? [] : array_merge([$expected], $query['also_accept'] ?? []);
    $shown = array_values(array_map('intval', $shown));
    $raw = array_values(array_map('intval', $raw));
    $raw_rank = NULL;
    foreach ($raw as $i => $nid) {
      if (in_array($nid, $accept, TRUE)) {
        $raw_rank = $i + 1;
        break;
      }
    }
    return [
      'on_topic' => $expected !== NULL,
      'hit1' => $expected !== NULL && in_array($shown[0] ?? NULL, $accept, TRUE),
      'hit1_via_duplicate' => $expected !== NULL && ($shown[0] ?? NULL) !== $expected && in_array($shown[0] ?? NULL, $accept, TRUE),
      'hit3' => $expected !== NULL && array_intersect($accept, array_slice($shown, 0, 3)) !== [],
      'off_topic_correct' => $expected === NULL ? $shown === [] : NULL,
      'raw_rank' => $raw_rank,
      'shown_count' => count($shown),
      'keyword_zero' => $keywordMatches === [],
      'keyword_found_expected' => $expected !== NULL && array_intersect($accept, array_map('intval', $keywordMatches)) !== [],
      'keyword_count' => count($keywordMatches),
    ];
  }

  /**
   * Aggregates per-query verdicts.
   *
   * @param array $rows
   *   Each: scoreQuery() output plus 'type' and 'latency_ms'.
   *
   * @return array
   *   Summary: counts, rates, latency, per-type hit rates.
   */
  public static function summarize(array $rows): array {
    $on = array_values(array_filter($rows, static fn ($r) => $r['on_topic']));
    $off = array_values(array_filter($rows, static fn ($r) => !$r['on_topic']));
    $latencies = array_map(static fn ($r) => (float) $r['latency_ms'], $rows);
    sort($latencies);
    $count = count($latencies);

    $by_type = [];
    foreach ($rows as $row) {
      $type = $row['type'];
      $by_type[$type] ??= [
        'n' => 0,
        'hit1' => 0,
        'hit3' => 0,
        'off_topic_correct' => 0,
        'keyword_zero' => 0,
        'keyword_found_expected' => 0,
      ];
      $by_type[$type]['n']++;
      $by_type[$type]['hit1'] += (int) $row['hit1'];
      $by_type[$type]['hit3'] += (int) $row['hit3'];
      $by_type[$type]['off_topic_correct'] += (int) ($row['off_topic_correct'] ?? 0);
      $by_type[$type]['keyword_zero'] += (int) $row['keyword_zero'];
      $by_type[$type]['keyword_found_expected'] += (int) $row['keyword_found_expected'];
    }

    return [
      'queries' => count($rows),
      'on_topic' => count($on),
      'hit1' => count(array_filter($on, static fn ($r) => $r['hit1'])),
      'hit3' => count(array_filter($on, static fn ($r) => $r['hit3'])),
      'hit1_via_duplicate' => count(array_filter($on, static fn ($r) => !empty($r['hit1_via_duplicate']))),
      'hit1_rate' => $on ? round(count(array_filter($on, static fn ($r) => $r['hit1'])) / count($on), 4) : NULL,
      'hit3_rate' => $on ? round(count(array_filter($on, static fn ($r) => $r['hit3'])) / count($on), 4) : NULL,
      'off_topic' => count($off),
      'off_topic_correct' => count(array_filter($off, static fn ($r) => $r['off_topic_correct'])),
      'keyword_zero' => count(array_filter($rows, static fn ($r) => $r['keyword_zero'])),
      'keyword_found_expected' => count(array_filter($on, static fn ($r) => $r['keyword_found_expected'])),
      'latency_mean_ms' => $count ? round(array_sum($latencies) / $count, 1) : NULL,
      'latency_median_ms' => $count ? round($count % 2 ? $latencies[intdiv($count, 2)] : ($latencies[$count / 2 - 1] + $latencies[$count / 2]) / 2, 1) : NULL,
      'latency_max_ms' => $count ? round(end($latencies), 1) : NULL,
      'by_type' => $by_type,
    ];
  }

}

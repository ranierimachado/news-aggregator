<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Ask;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Writes maemgaba_ask_log: one row per question, with no IP.
 */
class AskLog {

  /**
   * The table.
   */
  public const TABLE = 'maemgaba_ask_log';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Logs one question and returns the row id.
   *
   * @param array $row
   *   Column values (sql for sql_text); missing ones get table defaults.
   */
  public function log(array $row): int {
    $fields = [
      'created' => $this->time->getRequestTime(),
      'source' => mb_substr((string) ($row['source'] ?? 'web'), 0, 16),
      'question' => mb_substr((string) ($row['question'] ?? ''), 0, 512),
      'question_hash' => (string) ($row['question_hash'] ?? ''),
      'outcome' => (string) ($row['outcome'] ?? ''),
      'reason' => mb_substr((string) ($row['reason'] ?? ''), 0, 64),
      'detail' => ($row['detail'] ?? '') !== '' ? mb_substr((string) $row['detail'], 0, 4000) : NULL,
      'sql_text' => ($row['sql'] ?? '') !== '' ? mb_substr((string) $row['sql'], 0, 8000) : NULL,
      'valid' => empty($row['valid']) ? 0 : 1,
      'row_count' => isset($row['row_count']) ? (int) $row['row_count'] : NULL,
      'latency_ms' => (int) ($row['latency_ms'] ?? 0),
      'sql_ms' => isset($row['sql_ms']) ? (int) $row['sql_ms'] : NULL,
      'model' => mb_substr((string) ($row['model'] ?? ''), 0, 128),
      'input_tokens' => isset($row['input_tokens']) ? (int) $row['input_tokens'] : NULL,
      'output_tokens' => isset($row['output_tokens']) ? (int) $row['output_tokens'] : NULL,
      'cost_usd' => round((float) ($row['cost_usd'] ?? 0), 6),
      'cached' => empty($row['cached']) ? 0 : 1,
      'call_groups' => mb_substr(implode(' ', array_filter((array) ($row['call_groups'] ?? []))), 0, 64),
    ];
    return (int) $this->database->insert(self::TABLE)->fields($fields)->execute();
  }

}

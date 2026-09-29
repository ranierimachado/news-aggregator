<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Ask;

use Drupal\Core\Database\Statement\FetchAs;

/**
 * Runs a validated statement on the read-only connection.
 *
 * MariaDB has no MAX_EXECUTION_TIME optimizer hint, so the statement is
 * wrapped in SET STATEMENT, which also caps the rows the server returns and
 * sets the session time zone to the site's current UTC offset (so CURDATE()
 * and NOW() are local). Rows are fetched with numeric keys, so two result
 * columns with the same name both survive.
 */
class AskExecutor {

  public function __construct(
    protected ReadonlyProbeInterface $probe,
    protected AskSchema $schema,
  ) {}

  /**
   * Runs one validated statement.
   *
   * @param \Drupal\maemgaba_core\Ask\AskValidation $validation
   *   An accepted validation (its executableSql is run).
   * @param int $maxRows
   *   Server-side row cap.
   * @param int $timeout
   *   Statement timeout, seconds.
   *
   * @return array{columns: string[], rows: array<int, array<int, mixed>>, ms: int}
   *   Column names and rows as lists.
   *
   * @throws \Drupal\maemgaba_core\Ask\AskExecutionException
   */
  public function run(AskValidation $validation, int $maxRows, int $timeout): array {
    if (!$validation->ok) {
      throw new AskExecutionException('refused', 'Statement was not validated.');
    }
    $connection = $this->probe->connection();
    if (!$connection) {
      throw new AskExecutionException('no_target', 'No read-only connection.');
    }
    $offset = LocalTimeSql::currentOffset($this->schema->timezone());
    $sql = sprintf("SET STATEMENT max_statement_time=%d, sql_select_limit=%d, time_zone='%s' FOR %s", max(1, $timeout), max(1, $maxRows), $offset, $validation->executableSql);
    $start = hrtime(TRUE);
    try {
      $statement = $connection->query($sql);
      $pdo = $statement->getClientStatement();
      $columns = [];
      for ($i = 0, $n = $pdo->columnCount(); $i < $n; $i++) {
        $columns[] = (string) ($pdo->getColumnMeta($i)['name'] ?? ('col' . $i));
      }
      $rows = [];
      while (count($rows) < $maxRows && ($row = $statement->fetch(FetchAs::List)) !== FALSE) {
        $rows[] = $row;
      }
    }
    catch (\Throwable $e) {
      // 1969/3024 = statement timeout (MariaDB/MySQL); 1142 = denied.
      $message = $e->getMessage();
      $reason = match (TRUE) {
        str_contains($message, 'max_statement_time') || str_contains($message, '1969') => 'timeout',
        str_contains($message, 'command denied') || str_contains($message, '1142') => 'denied',
        default => 'sql_error',
      };
      throw new AskExecutionException($reason, mb_substr($message, 0, 1000), $e);
    }
    return [
      'columns' => $columns,
      'rows' => $rows,
      'ms' => (int) round((hrtime(TRUE) - $start) / 1e6),
    ];
  }

}

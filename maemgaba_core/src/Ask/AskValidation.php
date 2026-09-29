<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Ask;

/**
 * The outcome of validating one model-written SQL statement.
 */
final class AskValidation {

  /**
   * Builds a validation result.
   *
   * @param bool $ok
   *   Whether the statement may run.
   * @param string $sql
   *   The statement to show and run (LIMIT added), '' when rejected.
   * @param string $executableSql
   *   The same statement with view names in {braces}, for the Drupal
   *   connection's table prefixing. '' when rejected.
   * @param string $reason
   *   A machine reason code when rejected (see AskSqlValidator::REASONS).
   * @param string $detail
   *   The offending token or a short explanation, for the log.
   */
  public function __construct(
    public readonly bool $ok,
    public readonly string $sql = '',
    public readonly string $executableSql = '',
    public readonly string $reason = '',
    public readonly string $detail = '',
  ) {}

  /**
   * A rejection.
   */
  public static function reject(string $reason, string $detail = ''): self {
    return new self(FALSE, '', '', $reason, $detail);
  }

}

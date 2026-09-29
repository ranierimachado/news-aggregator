<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Ask;

/**
 * A validated statement failed on the read-only connection.
 *
 * The message may contain the SQL and database wording: it goes to the ask
 * log only, never to the page.
 */
class AskExecutionException extends \RuntimeException {

  public function __construct(
    public readonly string $reason,
    string $message,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

}

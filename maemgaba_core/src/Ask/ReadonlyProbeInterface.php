<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Ask;

use Drupal\Core\Database\Connection;

/**
 * Checks the read-only database target before "Ask the data" uses it.
 */
interface ReadonlyProbeInterface {

  /**
   * The read-only connection, or NULL when the target is not configured.
   */
  public function connection(): ?Connection;

  /**
   * Whether the target exists and can see exactly the ask_* views.
   *
   * @param bool $fresh
   *   Skip the cached result.
   *
   * @return array{ok: bool, reason: string, detail: string}
   *   reason is '' when ok, else one of no_target, connect_failed,
   *   extra_tables, missing_views, same_user.
   */
  public function check(bool $fresh = FALSE): array;

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Writes one row per AI provider call attempt to maemgaba_ai_call_log.
 *
 * The custom table (not a content entity) is the measurement layer's source
 * of truth: high write volume, and the eval runner + dashboard need cheap
 * raw-SQL aggregation over it — same rationale as EventRankingService.
 *
 * "Per call attempt", not "per logical call": AiAnalyzerService::chat()
 * retries internally, and every retry is a real request the provider saw —
 * see call_group/attempt below.
 */
class AiCallLogger {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Records the outcome of one AiAnalyzerService provider call attempt.
   *
   * Callers pass only the keys they know; everything else defaults to "not
   * applicable" (empty provider/model, no tokens, attempt 0 — meaning the
   * call never reached the provider at all, e.g. no prompt/provider
   * configured).
   *
   * @param array $data
   *   Recognized keys:
   *   - operation (string, required): the maemgaba_prompt operation key.
   *   - provider (string): AI provider plugin id, e.g. gemini.
   *   - model (string): model id used for the call.
   *   - prompt_config_id (string|null): maemgaba_prompt config entity id.
   *   - call_group (string): correlates every attempt of one logical call.
   *   - attempt (int): 1-based attempt number within call_group; 0 means
   *     the call never reached the provider.
   *   - context_type (string): business object the call was made for
   *     (inbound_queue, card, event, eval); empty = unattributed.
   *   - context_id (int|null): entity id of the context object, if any.
   *   - context_label (string): human-readable label (article/event title),
   *     stored denormalized — queue items are deleted once processed.
   *   - input_tokens, output_tokens, reasoning_tokens, cached_tokens
   *     (int|null): token counts from the provider (or estimated).
   *   - estimated (bool): TRUE if token counts are a strlen/4 estimate.
   *   - latency_ms (int): wall-clock time for this attempt, in milliseconds.
   *   - success (bool): TRUE if this attempt returned usable output.
   *   - error_message (string|null): the error, if this attempt failed.
   */
  public function log(array $data): void {
    $fields = $data + [
      'provider' => '',
      'model' => '',
      'prompt_config_id' => NULL,
      'call_group' => '',
      'attempt' => 0,
      'context_type' => '',
      'context_id' => NULL,
      'context_label' => '',
      'input_tokens' => NULL,
      'output_tokens' => NULL,
      'reasoning_tokens' => NULL,
      'cached_tokens' => NULL,
      'estimated' => FALSE,
      'latency_ms' => 0,
      'success' => FALSE,
      'error_message' => NULL,
    ];
    $fields['estimated'] = $fields['estimated'] ? 1 : 0;
    $fields['success'] = $fields['success'] ? 1 : 0;
    $fields['context_label'] = mb_substr((string) $fields['context_label'], 0, 255);
    // getCurrentTime(), not getRequestTime(): a single Drush batch can run
    // for many minutes, and getRequestTime() is fixed at process start,
    // which would stamp every row in the batch identically.
    $fields['created'] = $this->time->getCurrentTime();

    $this->database->insert('maemgaba_ai_call_log')->fields($fields)->execute();
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\maemgaba_core\AiAnalyzerService;

/**
 * AiAnalyzerService with the provider call replaced by a canned answer.
 */
class RecordingAnalyzer extends AiAnalyzerService {

  /**
   * The JSON object chat() returns.
   */
  public array $reply = [];

  /**
   * The last user prompt chat() received.
   */
  public string $lastUserPrompt = '';

  /**
   * {@inheritdoc}
   */
  protected function chat(string $provider_id, string $model_id, string $system_prompt, string $user_prompt, array $schema, string $schema_name, string $operation, ?string $prompt_config_id, array $context = [], int $maxAttempts = self::MAX_ATTEMPTS): string {
    $this->lastUserPrompt = $user_prompt;
    return json_encode($this->reply + ['matched_event_id' => 0, 'topic' => 'politics']);
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveProvider(string $operation): array {
    return ['provider_id' => 'test', 'model_id' => 'test'];
  }

}

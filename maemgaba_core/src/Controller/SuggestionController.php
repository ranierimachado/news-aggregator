<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Public-facing pages for the "suggest a correction" flow.
 */
class SuggestionController extends ControllerBase {

  /**
   * The /suggest-correction/thanks thank-you + evaluator upsell page.
   */
  public function thanks(): array {
    return [
      '#theme' => 'suggestion_thanks',
      '#site_name' => (string) $this->config('system.site')->get('name'),
      '#attached' => ['library' => ['maemgaba_core/engine']],
      '#cache' => ['tags' => ['config:system.site']],
    ];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface defining an AI prompt template.
 */
interface AiPromptInterface extends ConfigEntityInterface {

  /**
   * Gets the operation this prompt is used for (e.g. "classify_cluster").
   */
  public function getOperation(): string;

  /**
   * Gets the optional system prompt (role/context for the model).
   */
  public function getSystemPrompt(): string;

  /**
   * Gets the user prompt template, including placeholders.
   */
  public function getTemplate(): string;

  /**
   * Gets the rubric version this prompt was written for ('' if none).
   */
  public function getRubricVersion(): string;

  /**
   * Whether the operation classifies blind (outlet identity stripped).
   */
  public function isBlind(): bool;

  /**
   * Gets the few-shot anchors.
   *
   * @return array
   *   List of ['score' => int, 'outlet' => string, 'url' => string,
   *   'fetched' => string, 'description' => string, 'quote' => string].
   */
  public function getAnchors(): array;

  /**
   * Renders the anchors as prompt text, one line each.
   *
   * Outlet and url are never included.
   *
   * @param array $labels
   *   Optional map of score => bucket label, e.g. [-1 => 'Lean Left'].
   */
  public function renderAnchors(array $labels = []): string;

  /**
   * Renders the template, substituting the given placeholder values.
   *
   * @param array $tokens
   *   Map of placeholder name (without brackets) to replacement value,
   *   e.g. ['article_text' => '…', 'existing_events' => '…'].
   *
   * @return string
   *   The rendered user prompt.
   */
  public function render(array $tokens): string;

}

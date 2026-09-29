<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the AI Prompt configuration entity.
 *
 * Stores a reusable, editor-managed prompt template for an AI operation
 * (e.g. classify + cluster). Templates use [placeholder] tokens that the
 * consuming service fills in at run time.
 *
 * @ConfigEntityType(
 *   id = "maemgaba_prompt",
 *   label = @Translation("AI prompt"),
 *   label_collection = @Translation("AI prompts"),
 *   label_singular = @Translation("AI prompt"),
 *   label_plural = @Translation("AI prompts"),
 *   handlers = {
 *     "list_builder" = "Drupal\maemgaba_core\AiPromptListBuilder",
 *     "form" = {
 *       "add" = "Drupal\maemgaba_core\Form\AiPromptForm",
 *       "edit" = "Drupal\maemgaba_core\Form\AiPromptForm",
 *       "delete" = "Drupal\maemgaba_core\Form\AiPromptDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   config_prefix = "maemgaba_prompt",
 *   admin_permission = "administer ai prompts",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "operation",
 *     "system_prompt",
 *     "template",
 *     "rubric_version",
 *     "blind",
 *     "anchors"
 *   },
 *   links = {
 *     "collection" = "/admin/config/services/news-engine/prompts",
 *     "add-form" = "/admin/config/services/news-engine/prompts/add",
 *     "edit-form" = "/admin/config/services/news-engine/prompts/{maemgaba_prompt}",
 *     "delete-form" = "/admin/config/services/news-engine/prompts/{maemgaba_prompt}/delete"
 *   }
 * )
 */
class AiPrompt extends ConfigEntityBase implements AiPromptInterface {

  /**
   * The machine name.
   */
  protected string $id;

  /**
   * The human-readable label.
   */
  protected string $label;

  /**
   * A short description of what the prompt is for.
   */
  protected string $description = '';

  /**
   * The operation this prompt drives (e.g. "classify_cluster").
   */
  protected string $operation = '';

  /**
   * Optional system prompt (model role/context).
   */
  protected string $system_prompt = '';

  /**
   * The user prompt template, with [placeholder] tokens.
   */
  protected string $template = '';

  /**
   * Version of maemgaba_core.rubric this prompt was written and calibrated for.
   */
  protected string $rubric_version = '';

  /**
   * Blind classification: strip the outlet's identity from the inputs.
   */
  protected bool $blind = FALSE;

  /**
   * Few-shot anchors: score, outlet, url, date, description, quote.
   *
   * Outlet and url are provenance for editors and are never rendered into
   * the prompt (a blind prompt must not learn "outlet X = score Y").
   */
  protected array $anchors = [];

  /**
   * {@inheritdoc}
   */
  public function getOperation(): string {
    return $this->operation;
  }

  /**
   * {@inheritdoc}
   */
  public function getSystemPrompt(): string {
    return $this->system_prompt;
  }

  /**
   * {@inheritdoc}
   */
  public function getTemplate(): string {
    return $this->template;
  }

  /**
   * {@inheritdoc}
   */
  public function getRubricVersion(): string {
    return $this->rubric_version;
  }

  /**
   * {@inheritdoc}
   */
  public function isBlind(): bool {
    return $this->blind;
  }

  /**
   * {@inheritdoc}
   */
  public function getAnchors(): array {
    return $this->anchors;
  }

  /**
   * {@inheritdoc}
   */
  public function renderAnchors(array $labels = []): string {
    $lines = [];
    foreach ($this->anchors as $i => $anchor) {
      $score = (int) ($anchor['score'] ?? 0);
      $label = $labels[$score] ?? '';
      $line = sprintf('Example %d — score %+d%s: %s', $i + 1, $score, $label !== '' ? " ({$label})" : '', trim((string) ($anchor['description'] ?? '')));
      $quote = trim((string) ($anchor['quote'] ?? ''));
      if ($quote !== '') {
        $line .= ' Tell: "' . $quote . '"';
      }
      $lines[] = $line;
    }
    return implode("\n", $lines);
  }

  /**
   * {@inheritdoc}
   */
  public function render(array $tokens): string {
    $search = [];
    $replace = [];
    foreach ($tokens as $name => $value) {
      $search[] = '[' . $name . ']';
      $replace[] = (string) $value;
    }
    return str_replace($search, $replace, $this->template);
  }

}

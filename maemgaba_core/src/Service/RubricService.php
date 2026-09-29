<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The site's bias rubric (maemgaba_core.rubric), rendered for prompts.
 *
 * The rubric is site config, versioned and dated, editable without a deploy
 * (admin form at /admin/config/services/news-engine/rubric). Prompts pull it
 * in through the [rubric] token, so the classify, bias and review prompts
 * share one marker table instead of three drifting copies, and the
 * methodology page can render the same table.
 *
 * A site without the config object renders an empty
 * [rubric] and an empty version; its prompts don't use the token.
 */
class RubricService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The rubric version string ('' when the site has no rubric).
   */
  public function version(): string {
    return (string) ($this->configFactory->get('maemgaba_core.rubric')->get('rubric_version') ?? '');
  }

  /**
   * The rubric date (Y-m-d, '' when unset).
   */
  public function date(): string {
    return (string) ($this->configFactory->get('maemgaba_core.rubric')->get('rubric_date') ?? '');
  }

  /**
   * The raw rubric data.
   */
  public function data(): array {
    return $this->configFactory->get('maemgaba_core.rubric')->getRawData();
  }

  /**
   * The rubric as prompt text ('' when the site has none).
   */
  public function render(): string {
    return self::renderData($this->data());
  }

  /**
   * Pure renderer, shared with tests and the admin form preview.
   */
  public static function renderData(array $rubric): string {
    if (empty($rubric['rubric_version'])) {
      return '';
    }
    $out = [];
    $out[] = sprintf('RUBRIC v%s (%s)', $rubric['rubric_version'], $rubric['rubric_date'] ?? '');
    if (!empty($rubric['summary'])) {
      $out[] = trim((string) $rubric['summary']);
    }
    if (!empty($rubric['center_definition'])) {
      $out[] = '';
      $out[] = 'CENTER (score 0): ' . trim((string) $rubric['center_definition']);
    }

    if (!empty($rubric['scale'])) {
      $out[] = '';
      $out[] = 'SCALE';
      foreach ($rubric['scale'] as $bucket) {
        $out[] = sprintf('%+d %s: %s', (int) $bucket['score'], $bucket['label'] ?? '', trim((string) ($bucket['definition'] ?? '')));
      }
    }

    if (!empty($rubric['framing_signals'])) {
      $out[] = '';
      $out[] = 'FRAMING SIGNALS (how the article frames the story, independent of topic)';
      foreach ($rubric['framing_signals'] as $signal) {
        $out[] = sprintf('- %s: %s', $signal['label'] ?? $signal['key'] ?? '', trim((string) ($signal['description'] ?? '')));
      }
    }

    if (!empty($rubric['issues'])) {
      $out[] = '';
      $out[] = 'ISSUE MARKERS (vocabulary tells by topic; a tell counts only in the article\'s own voice, not inside a quotation or when the article is reporting the term as someone else\'s)';
      foreach ($rubric['issues'] as $issue) {
        $line = ($issue['label'] ?? $issue['key'] ?? '') . ' — left tells: ' . self::quoteList($issue['left_tells'] ?? [])
          . ' | right tells: ' . self::quoteList($issue['right_tells'] ?? []);
        if (!empty($issue['notes'])) {
          $line .= ' | note: ' . trim((string) $issue['notes']);
        }
        $out[] = '- ' . $line;
      }
    }

    if (!empty($rubric['rules'])) {
      $out[] = '';
      $out[] = 'DECISION RULES';
      $out[] = trim((string) $rubric['rules']);
    }

    return implode("\n", $out);
  }

  /**
   * Formats a list of tells as "a", "b", "c" (or "—" when empty).
   */
  protected static function quoteList(array $items): string {
    $items = array_filter(array_map(fn ($i) => trim((string) $i), $items));
    return $items ? '"' . implode('", "', $items) . '"' : '—';
  }

}

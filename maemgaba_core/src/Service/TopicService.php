<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The site's topic vocabulary, from maemgaba_core.topics.
 *
 * One source for field_topic's allowed values
 * (maemgaba_core_topic_allowed_values()), the AI response-schema enum, the
 * [topic_list] prompt token and the pipeline's whitelist. Keys are what is
 * stored; labels are what readers see. Each locale pack ships its own list
 * in its own language.
 */
class TopicService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Key => label, in display order.
   *
   * @return array<string, string>
   *   Labels keyed by topic key.
   */
  public function options(): array {
    $options = [];
    foreach ((array) $this->configFactory->get('maemgaba_core.topics')->get('topics') as $topic) {
      $options[(string) $topic['key']] = (string) ($topic['label'] ?? $topic['key']);
    }
    return $options;
  }

  /**
   * Topic keys in display order.
   *
   * @return string[]
   *   The topic keys.
   */
  public function keys(): array {
    return array_keys($this->options());
  }

  /**
   * The label for a key (the key itself when unknown, '' for empty).
   */
  public function label(?string $key): string {
    if ($key === NULL || $key === '') {
      return '';
    }
    return $this->options()[$key] ?? $key;
  }

  /**
   * The key stored when the model answers with an unknown topic.
   */
  public function fallback(): string {
    $fallback = (string) $this->configFactory->get('maemgaba_core.topics')->get('fallback');
    $keys = $this->keys();
    return in_array($fallback, $keys, TRUE) ? $fallback : (string) end($keys);
  }

  /**
   * The $value if it is a known key, otherwise $fallback (or fallback()).
   */
  public function whitelist(string $value, ?string $fallback = NULL): string {
    return in_array($value, $this->keys(), TRUE) ? $value : ($fallback ?? $this->fallback());
  }

  /**
   * The keys as a prompt fragment: "politics", "economy", ….
   */
  public function promptList(): string {
    return implode(', ', array_map(fn ($key) => '"' . $key . '"', $this->keys()));
  }

}

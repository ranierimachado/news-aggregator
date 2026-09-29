<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\maemgaba_core\BiasScore;
use Drupal\node\NodeInterface;

/**
 * Divergence of one article from its outlet's usual lean.
 *
 * "Usually leans X, this story reads Y".
 *
 * Compares a card's own score (field_bias_score) with the published prior of
 * the feed it came from (feed_source.field_published_prior, e.g. the
 * AllSides rating), and returns a label when they are at least
 * story_divergence.min_delta apart (default 1) AND fall in different display
 * buckets (on a 3-bucket site −2 and −1 are both "left", so a one-point gap
 * there says nothing to a reader).
 *
 * The feed is found by outlet name (the card's sources term) and section
 * (news/opinion), since outlets like the NYT have one feed and one prior per
 * section. Sites without published priors never get a label.
 */
class StoryDivergence {

  use StringTranslationTrait;

  /**
   * Priors keyed by lower-cased outlet name, then section.
   *
   * @var array<string, array<string, int>>|null
   */
  protected ?array $priors = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SpectrumService $spectrum,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The divergence data for a card, or NULL when it reads as usual.
   *
   * @return array|null
   *   See compare().
   */
  public function forCard(NodeInterface $card): ?array {
    if (!$card->hasField('field_bias_score')) {
      return NULL;
    }
    return $this->compare($this->priorFor($card), BiasScore::normalize($card->get('field_bias_score')->value));
  }

  /**
   * The published prior of the feed a card came from, or NULL.
   */
  public function priorFor(NodeInterface $card): ?int {
    $term = $card->hasField('field_source') ? $card->get('field_source')->entity : NULL;
    if (!$term) {
      return NULL;
    }
    $section = $card->hasField('field_section') ? ((string) $card->get('field_section')->value ?: 'news') : 'news';
    $byOutlet = $this->priors()[mb_strtolower(trim((string) $term->label()))] ?? [];
    return $byOutlet[$section] ?? NULL;
  }

  /**
   * Compares a prior with an article score.
   *
   * @return array{prior: int, score: int, delta: int, usual: string, story: string, usual_key: string, story_key: string, label: string}|null
   *   NULL when either is missing, the gap is below min_delta, or both land
   *   in the same display bucket. usual/story are bucket labels; label is
   *   the formatted sentence (story_divergence.label).
   */
  public function compare(?int $prior, ?int $score): ?array {
    if ($prior === NULL || $score === NULL) {
      return NULL;
    }
    $delta = $score - $prior;
    if (abs($delta) < $this->minDelta()) {
      return NULL;
    }
    $usual = $this->spectrum->bucketFor($prior);
    $story = $this->spectrum->bucketFor($score);
    if ($usual === NULL || $story === NULL || $usual['key'] === $story['key']) {
      return NULL;
    }
    $format = (string) $this->configFactory->get('maemgaba_core.settings')->get('story_divergence.label');
    $args = ['@usual' => $usual['label'], '@story' => $story['label']];
    return [
      'prior' => $prior,
      'score' => $score,
      'delta' => $delta,
      'usual' => $usual['label'],
      'story' => $story['label'],
      'usual_key' => $usual['key'],
      'story_key' => $story['key'],
      'label' => $format !== ''
        ? strtr($format, $args)
        : (string) $this->t('Usually leans @usual, this story reads @story', $args),
    ];
  }

  /**
   * The smallest |score − prior| that counts (default 1).
   */
  protected function minDelta(): int {
    $value = $this->configFactory->get('maemgaba_core.settings')->get('story_divergence.min_delta');
    return $value === NULL ? 1 : max(1, (int) $value);
  }

  /**
   * Every feed's published prior, loaded once per request.
   *
   * @return array<string, array<string, int>>
   *   [outlet (lower-cased)][section] => prior. The first feed (by nid)
   *   wins when an outlet has two feeds in the same section.
   */
  protected function priors(): array {
    if ($this->priors !== NULL) {
      return $this->priors;
    }
    $this->priors = [];
    $storage = $this->entityTypeManager->getStorage('node');
    $definitions = $this->entityTypeManager->getStorage('field_storage_config')->load('node.field_published_prior');
    if (!$definitions) {
      return $this->priors;
    }
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'feed_source')
      ->exists('field_published_prior')
      ->sort('nid')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $feed) {
      if (!$feed->hasField('field_published_prior')) {
        continue;
      }
      $prior = BiasScore::normalize($feed->get('field_published_prior')->value);
      $outlet = mb_strtolower(trim((string) ($feed->get('field_media_outlet')->value ?: $feed->label())));
      $section = $feed->hasField('field_section') ? ((string) $feed->get('field_section')->value ?: 'news') : 'news';
      if ($prior !== NULL && $outlet !== '' && !isset($this->priors[$outlet][$section])) {
        $this->priors[$outlet][$section] = $prior;
      }
    }
    return $this->priors;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\maemgaba_core\BiasScore;
use Drupal\maemgaba_core\CardRole;
use Drupal\node\NodeInterface;

/**
 * Turns an event node and its cards into the variables the templates use.
 *
 * Called from maemgaba_core_preprocess_node() for every event node in any
 * view mode, so the engine's node--event and node--event--teaser templates
 * (and any theme copy of them) always receive the same variables. Themes
 * add brand-only variables in their own preprocess; nothing here depends on
 * a theme.
 *
 * Only perspectives (CardRole) fill the bucket columns, the spectrum bar and
 * the perspective count. Reprints are attached to the card they copy
 * (card.syndicated, each with a "via" name); opinion pieces go to a separate
 * opinion_cards strip. With click logging on, card links go through
 * /out/{card}.
 *
 * What a card shows is site policy (maemgaba_core.settings:card_display):
 * the per-article micro-summary is withheld (empty) on sites that must not
 * render per-article summaries, and the framing line is only exposed where
 * the site opts in. ai_label is the site's label for AI-generated blocks.
 */
class EventPreprocessor {

  /**
   * How many top-ranked events are eligible for on-demand consensus.
   *
   * Mirrors ConsensusController::ELIGIBLE_TOP: the placeholder that triggers
   * synthesis is only rendered for events the endpoint will accept.
   */
  public const ELIGIBLE_TOP = 5;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SpectrumService $spectrum,
    protected TopicService $topics,
    protected RelativeTime $relativeTime,
    protected EventRankingService $ranking,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected StoryDivergence $divergence,
    protected ClickLog $clickLog,
    protected SyndicationDetector $syndication,
    protected ConfigFactoryInterface $configFactory,
    protected RubricService $rubric,
  ) {}

  /**
   * Adds the event variables to a node template's variables.
   *
   * @param array $variables
   *   Node template variables: node, view_mode, elements, ... Ignored unless
   *   the node is an event.
   */
  public function preprocessNode(array &$variables): void {
    $node = $variables['node'] ?? NULL;
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return;
    }
    $view_mode = (string) ($variables['view_mode'] ?? 'full');
    $full = $view_mode === 'full';

    $variables['#attached']['library'][] = 'maemgaba_core/engine';
    $variables['#cache']['tags'][] = 'config:maemgaba_core.spectrum';
    $variables['#cache']['tags'][] = 'config:maemgaba_core.settings';
    $variables['#cache']['tags'][] = 'config:maemgaba_core.topics';
    // Divergence labels read the feeds' published priors.
    $variables['#cache']['tags'][] = 'node_list:feed_source';

    // The teaser prints the neutral summary as plain text. It is stored as
    // basic_html, so strip the tags and decode the entities here and let
    // Twig escape once; "|render|striptags" in the template escaped "&amp;"
    // a second time. Empty when the view display hides the field.
    $variables['summary_text'] = '';
    if (!empty($variables['content']['field_neutral_summary']) && !$node->get('field_neutral_summary')->isEmpty()) {
      $variables['summary_text'] = trim(html_entity_decode(strip_tags((string) $node->get('field_neutral_summary')->value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // Sort the event's cards into perspectives, reprints (keyed by the card
    // they copy) and opinion. A reprint whose original is not on this event
    // any more is shown as a perspective, so nothing disappears.
    $cards = $this->cards($node);
    $reprints = [];
    $perspectives = [];
    $opinion = [];
    foreach ($cards as $card) {
      if (CardRole::isSyndicated($card) && isset($cards[$card->get('field_syndicated_from')->target_id])) {
        $reprints[(int) $card->get('field_syndicated_from')->target_id][] = $card;
      }
      elseif (CardRole::isOpinion($card)) {
        $opinion[] = $card;
      }
      else {
        $perspectives[] = $card;
      }
    }
    $settings = $this->configFactory->get('maemgaba_core.settings');
    $display = [
      'micro_summary' => $settings->get('card_display.micro_summary') ?? TRUE,
      'framing_line' => (bool) $settings->get('card_display.framing_line'),
    ];
    $ai_label = (string) $settings->get('ai_label');
    $variables['ai_label'] = $ai_label === '' ? '' : strtr($ai_label, ['@version' => $this->rubric->version()]);
    if ($ai_label !== '') {
      $variables['#cache']['tags'][] = 'config:maemgaba_core.rubric';
    }

    $wires = ($full && $reprints) ? $this->syndication->wires(array_map(fn ($c) => (int) $c->id(), array_merge(...array_values($reprints)))) : [];

    // One column per configured spectrum bucket (3 or 5, whatever the
    // pack ships), in display order. Full view carries the card details;
    // teasers only need the counts.
    $cards_by_bucket = array_fill_keys($this->spectrum->keys(), []);
    foreach ($perspectives as $card) {
      $bucket = $this->spectrum->bucketFor(BiasScore::normalize($card->get('field_bias_score')->value));
      if ($bucket === NULL) {
        continue;
      }
      $cards_by_bucket[$bucket['key']][] = $full ? $this->cardData($card, $bucket, $reprints[(int) $card->id()] ?? [], $wires, $display) : ['id' => $card->id()];
    }

    // Opinion strip: classified, shown apart, never counted.
    $variables['opinion_cards'] = [];
    $variables['opinion_count'] = count($opinion);
    if ($full) {
      foreach ($opinion as $card) {
        $bucket = $this->spectrum->bucketFor(BiasScore::normalize($card->get('field_bias_score')->value));
        $variables['opinion_cards'][] = $this->cardData($card, $bucket, $reprints[(int) $card->id()] ?? [], $wires, $display);
      }
    }
    $variables['syndicated_count'] = array_sum(array_map('count', $reprints));

    // View beacon for click-through (ClickLog): event pages only.
    $variables['view_beacon_url'] = ($full && $this->clickLog->enabled())
      ? Url::fromRoute('maemgaba_core.out_view', ['node' => $node->id()])->toString()
      : '';

    // Per-bucket counts, bar widths and labels for the spectrum bar, the
    // column headers and the teaser count rows.
    $dist = array_map('count', $cards_by_bucket);
    $total = array_sum($dist);
    $perc = $this->spectrum->percentages($dist);
    $buckets = [];
    foreach ($this->spectrum->buckets() as $bucket) {
      $key = $bucket['key'];
      $buckets[] = [
        'key' => $key,
        'label' => mb_strtoupper($bucket['label']),
        'short_label' => $bucket['short_label'],
        'color' => $bucket['color'],
        'count' => $dist[$key],
        'perc' => $perc[$key],
        // Exact share for the event page bar; even split when empty.
        'width' => $total > 0 ? $dist[$key] / $total * 100 : round(100 / count($dist), 2),
        'cards' => $cards_by_bucket[$key],
      ];
    }
    if ($total === 0 && $buckets) {
      // Even placeholder bar: the last segment absorbs the rounding.
      $last = count($buckets) - 1;
      $buckets[$last]['width'] = round(100 - array_sum(array_column(array_slice($buckets, 0, $last), 'width')), 2);
    }
    $variables['buckets'] = $buckets;
    $variables['bucket_count'] = count($buckets);
    // Sources = every article listed (reprints and opinion included);
    // perspectives = the ones counted in the distribution.
    $variables['total_sources'] = count($cards);
    $variables['total_perspectives'] = $total;

    // Featured status: only the home hero renders as the full-width banner.
    // HomeController flags its chosen hero via #is_home_hero; every other
    // event (grid, /events) is a compact card.
    $variables['is_featured'] = !empty($variables['elements']['#is_home_hero']);

    // The bar itself: exact widths on the event page, integer percentages
    // (remainder absorbed) on teasers.
    $variables['spectrum_bar'] = [
      '#theme' => 'spectrum_bar',
      '#segments' => array_map(fn (array $b) => [
        'key' => $b['key'],
        'color' => $b['color'],
        'width' => $full ? $b['width'] : $b['perc'],
      ], $buckets),
      '#size' => $full ? '' : ($variables['is_featured'] ? 'large' : 'small'),
    ];

    $balance = $this->spectrum->balanceScore($dist);
    $variables['balance_score'] = $balance ?? 0;
    $variables['balance_label'] = $this->spectrum->balanceLabel($balance);

    // Category = the label of the event's topic (rolled up from its cards
    // by EventRelevanceService).
    $variables['category'] = '';
    if ($node->hasField('field_topic') && !$node->get('field_topic')->isEmpty()) {
      $variables['category'] = mb_strtoupper($this->topics->label($node->get('field_topic')->value));
    }

    // Consensus (points in common) and dispute (contested points): optional
    // multi-value text fields; render gracefully if absent.
    $variables['common_points'] = $this->points($node, 'field_common_points');
    $variables['disputed_points'] = $this->points($node, 'field_disputed_points');

    // Consensus is either already synthesised (render it), still pending for
    // an event eligible for on-demand synthesis (render a placeholder that
    // triggers it), or not applicable (render nothing). Eligibility is only
    // checked when actually needed, to avoid an extra query on every teaser.
    $variables['consensus_grid'] = [];
    $variables['is_home_top5'] = FALSE;
    if ($variables['common_points'] || $variables['disputed_points']) {
      $variables['consensus_grid'] = [
        '#theme' => 'event_consensus_grid',
        '#common_points' => $variables['common_points'],
        '#disputed_points' => $variables['disputed_points'],
      ];
    }
    elseif ($full) {
      $variables['is_home_top5'] = $this->ranking->isInTop((int) $node->id(), self::ELIGIBLE_TOP);
    }

    // Relative time since publication, e.g. "3 hours", "1 day", "2 wk".
    $variables['time_ago'] = $this->relativeTime->long((int) $node->getCreatedTime());

    // Event date in the site's day format (core.date_format.maemgaba_day),
    // uppercased for the design. Day and month names come from the interface
    // translation, so each site renders dates in its own language.
    $variables['event_date'] = mb_strtoupper($this->dateFormatter->format((int) $node->getCreatedTime(), 'maemgaba_day'));
  }

  /**
   * Loads the published cards that reference an event.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The cards, in storage order.
   */
  protected function cards(NodeInterface $event): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->condition('type', 'card')
      ->condition('field_parent_event', $event->id())
      ->accessCheck(FALSE)
      ->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * The detailed card data the full event page renders in a column.
   *
   * @param \Drupal\node\NodeInterface $card
   *   The card.
   * @param array|null $bucket
   *   Its spectrum bucket (NULL: unscored opinion piece).
   * @param \Drupal\node\NodeInterface[] $reprints
   *   Cards that reprint this one, listed under it.
   * @param array<int, string> $wires
   *   Detected wire names keyed by reprint nid.
   * @param array<string, bool> $display
   *   Display policy: micro_summary, framing_line (FALSE = passed empty).
   */
  protected function cardData(
    NodeInterface $card,
    ?array $bucket,
    array $reprints = [],
    array $wires = [],
    array $display = ['micro_summary' => TRUE, 'framing_line' => FALSE],
  ): array {
    $source_name = $this->sourceName($card);
    $source_initials = '';
    if ($source_name !== '') {
      $words = preg_split('/\s+/', $source_name);
      $source_initials = count($words) >= 2
        ? mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1))
        : mb_strtoupper(mb_substr($source_name, 0, 2));
    }

    $syndicated = [];
    foreach ($reprints as $reprint) {
      $syndicated[] = [
        'title' => $reprint->label(),
        'source_name' => $this->sourceName($reprint),
        // "via AP" when the reprint carries a wire byline, else the outlet
        // it copies.
        'via' => $wires[(int) $reprint->id()] ?? $source_name,
        'link_url' => $this->linkUrl($reprint),
        'original_url' => $reprint->get('field_original_url')->uri ?? '',
        'time_ago' => mb_strtoupper($this->relativeTime->compact((int) $reprint->getCreatedTime())),
      ];
    }

    return [
      'id' => (int) $card->id(),
      'title' => $card->label(),
      'source_name' => $source_name,
      'source_initials' => $source_initials,
      'bias_label' => $bucket ? mb_strtoupper($bucket['label']) : '',
      'bias' => $bucket['key'] ?? '',
      'micro_summary' => (!$display['micro_summary'] || $card->get('field_micro_summary')->isEmpty()) ? '' : strip_tags((string) $card->get('field_micro_summary')->value),
      'framing_line' => ($display['framing_line'] && $card->hasField('field_framing_line')) ? (string) $card->get('field_framing_line')->value : '',
      'partial_text' => $card->hasField('field_partial_text') && (bool) $card->get('field_partial_text')->value,
      'section' => $card->hasField('field_section') ? (string) $card->get('field_section')->value : '',
      'original_url' => $card->get('field_original_url')->uri ?? '',
      'link_url' => $this->linkUrl($card),
      'time_ago' => mb_strtoupper($this->relativeTime->compact((int) $card->getCreatedTime())),
      'divergence' => $this->divergence->forCard($card),
      'syndicated' => $syndicated,
    ];
  }

  /**
   * The outlet (sources term) name of a card, or ''.
   */
  protected function sourceName(NodeInterface $card): string {
    if (!$card->get('field_source')->isEmpty() && ($term = $card->get('field_source')->entity)) {
      return (string) $term->label();
    }
    return '';
  }

  /**
   * The href readers follow.
   *
   * /out/{card} when clicks are logged, else the article URL itself ('' when
   * the card has none).
   */
  protected function linkUrl(NodeInterface $card): string {
    $url = $card->get('field_original_url')->uri ?? '';
    if ($url === '' || !$this->clickLog->enabled()) {
      return (string) $url;
    }
    return Url::fromRoute('maemgaba_core.out', ['node' => $card->id()])->toString();
  }

  /**
   * Reads a multi-value plain-text field into a clean string array.
   */
  protected function points(NodeInterface $node, string $field): array {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return [];
    }
    $values = [];
    foreach ($node->get($field) as $item) {
      $values[] = strip_tags((string) $item->value);
    }
    return $values;
  }

}

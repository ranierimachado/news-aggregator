<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\maemgaba_core\Service\EventRelevanceService;
use Drupal\maemgaba_core\Service\IngestionEngine;
use Drupal\maemgaba_core\Service\StatsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin page to run the news pipeline: harvest, process, backfill, rollup.
 *
 * All work is delegated to services; the long-running actions (process,
 * backfill) run through the Batch API so they progress without timing out.
 */
class PipelineForm extends FormBase {

  public function __construct(
    protected StatsService $stats,
    protected IngestionEngine $ingestionEngine,
    protected EventRelevanceService $eventRelevance,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('maemgaba_core.stats'),
      $container->get('maemgaba_core.ingestion_engine'),
      $container->get('maemgaba_core.event_relevance'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'maemgaba_core_pipeline';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $events = $this->stats->relevanceBreakdown('event');
    $cards = $this->stats->relevanceBreakdown('card');

    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Pipeline status'),
      '#open' => TRUE,
    ];
    $form['status']['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Metric'), $this->t('Value')],
      '#rows' => [
        [$this->t('Items in the queue (inbound_queue)'), $this->stats->queueDepth()],
        [$this->t('Cards without relevance'), $this->stats->unratedCardCount()],
        [$this->t('Cards awaiting review (second layer)'), $this->stats->unreviewedCardCount()],
        [
          $this->t('Events — relevant / maybe / irrelevant'),
          sprintf('%d / %d / %d', $events['relevant'], $events['maybe'], $events['irrelevant']),
        ],
        [
          $this->t('Cards — relevant / maybe / irrelevant'),
          sprintf('%d / %d / %d', $cards['relevant'], $cards['maybe'], $cards['irrelevant']),
        ],
      ],
    ];

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Runs the pipeline steps by hand. Long steps show a progress bar.') . '</p>',
    ];

    if ($this->currentUser()->hasPermission('view news engine ai stats')) {
      $form['ai_stats_link'] = [
        '#type' => 'link',
        '#title' => $this->t('View AI stats (cost, latency, accuracy) →'),
        '#url' => Url::fromRoute('maemgaba_core.ai_stats'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['harvest'] = [
      '#type' => 'submit',
      '#value' => $this->t('1. Harvest feeds'),
      '#submit' => ['::submitHarvest'],
    ];
    $form['actions']['process'] = [
      '#type' => 'submit',
      '#value' => $this->t('2. Process queue (AI)'),
      '#submit' => ['::submitProcess'],
    ];
    $form['actions']['backfill'] = [
      '#type' => 'submit',
      '#value' => $this->t('Relevance backfill'),
      '#submit' => ['::submitBackfill'],
    ];
    $form['actions']['review'] = [
      '#type' => 'submit',
      '#value' => $this->t('Review cards (second layer)'),
      '#submit' => ['::submitReview'],
    ];
    $form['actions']['rollup'] = [
      '#type' => 'submit',
      '#value' => $this->t('Recalculate events'),
      '#submit' => ['::submitRollup'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Each action button has its own submit handler; nothing to do here.
  }

  /**
   * Harvest: one batch operation per feed.
   */
  public function submitHarvest(array &$form, FormStateInterface $form_state): void {
    $operations = [];
    foreach ($this->ingestionEngine->getActiveFeeds() as $feed) {
      $operations[] = ['_maemgaba_core_harvest_batch_op', [$feed]];
    }
    batch_set([
      'title' => $this->t('Harvesting feeds…'),
      'operations' => $operations,
      'finished' => '_maemgaba_core_harvest_batch_finished',
    ]);
  }

  /**
   * Process: one self-repeating batch operation until the queue drains.
   */
  public function submitProcess(array &$form, FormStateInterface $form_state): void {
    batch_set([
      'title' => $this->t('Processing the inbound queue…'),
      'operations' => [['_maemgaba_core_process_batch_op', []]],
      'finished' => '_maemgaba_core_process_batch_finished',
    ]);
  }

  /**
   * Backfill: one self-repeating batch operation until no cards are unrated.
   */
  public function submitBackfill(array &$form, FormStateInterface $form_state): void {
    batch_set([
      'title' => $this->t('Classifying card relevance…'),
      'operations' => [['_maemgaba_core_backfill_batch_op', []]],
      'finished' => '_maemgaba_core_backfill_batch_finished',
    ]);
  }

  /**
   * Review: one self-repeating batch operation until no cards are unreviewed.
   */
  public function submitReview(array &$form, FormStateInterface $form_state): void {
    batch_set([
      'title' => $this->t('Reviewing processed cards…'),
      'operations' => [['_maemgaba_core_review_batch_op', []]],
      'finished' => '_maemgaba_core_review_batch_finished',
    ]);
  }

  /**
   * Rollup: fast, deterministic — run inline.
   */
  public function submitRollup(array &$form, FormStateInterface $form_state): void {
    $count = $this->eventRelevance->recalculateAll();
    $this->messenger()->addStatus($this->t('Rollup finished for @n event(s).', ['@n' => $count]));
  }

}

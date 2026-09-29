<?php

namespace Drupal\maemgaba_core\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\maemgaba_core\Service\StatsService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for News Engine statistics and reports.
 *
 * The query logic lives in StatsService; this command only formats.
 */
class MaemgabaStatsCommands extends DrushCommands {

  public function __construct(
    protected StatsService $stats,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct();
  }

  /**
   * Dependency Injection factory for Drush.
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('maemgaba_core.stats'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Shows a snapshot of content totals and pipeline backlog.
   */
  #[CLI\Command(name: 'maemgaba:status', aliases: ['mg-status'])]
  #[CLI\FieldLabels(labels: [
    'metric' => 'Metric',
    'value' => 'Value',
  ])]
  #[CLI\DefaultTableFields(fields: ['metric', 'value'])]
  public function status($options = ['format' => 'table']): RowsOfFields {
    $events = $this->stats->relevanceBreakdown('event');
    $cards = $this->stats->relevanceBreakdown('card');
    $reviewSince = $this->stats->reviewSince();

    $rows = [
      ['metric' => 'Events (total)', 'value' => $this->stats->contentCount('event')],
      ['metric' => 'Cards (total)', 'value' => $this->stats->contentCount('card')],
      ['metric' => 'Items in the queue (inbound_queue)', 'value' => $this->stats->queueDepth()],
      ['metric' => 'Active feeds', 'value' => $this->stats->activeFeedCount()],
      ['metric' => 'Cards without relevance', 'value' => $this->stats->unratedCardCount()],
      [
        'metric' => 'Review cutoff (review_since)',
        'value' => $reviewSince !== NULL
          ? $this->dateFormatter->format($reviewSince, 'custom', 'Y-m-d H:i')
          : 'nenhum (revisa todo o backlog)',
      ],
      ['metric' => 'Cards awaiting review (second layer)', 'value' => $this->stats->unreviewedCardCount()],
      [
        'metric' => 'Events — relevant / maybe / irrelevant',
        'value' => sprintf('%d / %d / %d', $events['relevant'], $events['maybe'], $events['irrelevant']),
      ],
      [
        'metric' => 'Cards — relevant / maybe / irrelevant',
        'value' => sprintf('%d / %d / %d', $cards['relevant'], $cards['maybe'], $cards['irrelevant']),
      ],
    ];

    return new RowsOfFields($rows);
  }

  /**
   * Lists all Factual Events with the number of associated Cards.
   */
  #[CLI\Command(name: 'maemgaba:event-report', aliases: ['mg-report'])]
  #[CLI\FieldLabels(labels: [
    'nid' => 'Event ID',
    'title' => 'Event Title',
    'card_count' => 'Cards Associated',
  ])]
  #[CLI\DefaultTableFields(fields: ['nid', 'title', 'card_count'])]
  public function eventReport($options = ['format' => 'table']): RowsOfFields {
    return new RowsOfFields($this->stats->eventCardCounts());
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Plugin\Mcp;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\maemgaba_core\BiasScore;
use Drupal\maemgaba_core\CardRole;
use Drupal\maemgaba_core\Service\SourceBiasStats;
use Drupal\maemgaba_core\Service\SpectrumService;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use Drupal\mcp\ServerFeatures\ToolAnnotations;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exposes the News Engine as MCP tools.
 *
 * Lets an external MCP client (Claude Desktop, an IDE, an agent) query the
 * tracked events, their multi-source perspectives, and per-outlet editorial
 * bias — the same data that powers the site's front end.
 */
#[Mcp(
  id: 'news_engine',
  name: new TranslatableMarkup('News Engine'),
  description: new TranslatableMarkup('Read-only tools to explore tracked events, their perspectives by bias, and source-bias reports.'),
)]
class NewsEngine extends McpPluginBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The site's bias display buckets.
   */
  protected SpectrumService $spectrum;

  /**
   * SQL per-outlet bias tallies.
   */
  protected SourceBiasStats $sourceBiasStats;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->spectrum = $container->get('maemgaba_core.spectrum');
    $instance->sourceBiasStats = $container->get('maemgaba_core.source_bias_stats');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getTools(): array {
    $readOnly = new ToolAnnotations(
      readOnlyHint: TRUE,
      idempotentHint: TRUE,
      destructiveHint: FALSE,
      openWorldHint: FALSE,
    );

    return [
      new Tool(
        name: 'list_tracked_events',
        description: 'Lists tracked events with the bias distribution (per spectrum bucket, see "buckets" in the result) of the outlets covering each one.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'limit' => [
              'type' => 'integer',
              'description' => 'Maximum number of events to return (default 10, max 50).',
            ],
          ],
        ],
        title: 'List tracked events',
        annotations: $readOnly,
      ),
      new Tool(
        name: 'get_event_perspectives',
        description: 'Returns the perspectives (articles) of one event grouped by spectrum bucket, with outlet, headline, bias score and original link.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'event_id' => [
              'type' => 'integer',
              'description' => 'Event node id (see list_tracked_events).',
            ],
          ],
          'required' => ['event_id'],
        ],
        title: 'Event perspectives',
        annotations: $readOnly,
      ),
      new Tool(
        name: 'source_bias_report',
        description: 'Per-outlet report: article count, bias distribution and editorial divergence (share of articles outside the outlet\'s usual line).',
        inputSchema: [
          'type' => 'object',
          'properties' => new \stdClass(),
        ],
        title: 'Source bias report',
        annotations: $readOnly,
      ),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function executeTool(string $toolId, mixed $arguments): array {
    $arguments = is_array($arguments) ? $arguments : [];

    if ($this->is($toolId, 'list_tracked_events')) {
      return $this->text($this->listTrackedEvents((int) ($arguments['limit'] ?? 10)));
    }
    if ($this->is($toolId, 'get_event_perspectives')) {
      return $this->text($this->getEventPerspectives((int) ($arguments['event_id'] ?? 0)));
    }
    if ($this->is($toolId, 'source_bias_report')) {
      return $this->text($this->sourceBiasReport());
    }

    throw new \InvalidArgumentException('Tool not found: ' . $toolId);
  }

  /**
   * Matches a tool id against its name or the md5() the client may send.
   */
  private function is(string $toolId, string $name): bool {
    return $toolId === $name || $toolId === md5($name);
  }

  /**
   * Wraps a payload in the MCP text-content envelope.
   */
  private function text(array $payload): array {
    return [
      [
        'type' => 'text',
        'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ],
    ];
  }

  /**
   * Tool: list_tracked_events.
   */
  private function listTrackedEvents(int $limit): array {
    $limit = max(1, min($limit ?: 10, 50));
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->execute();

    $events = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $dist = $this->biasDistribution((int) $node->id());
      $events[] = [
        'id' => (int) $node->id(),
        'title' => $node->label(),
        'category' => $this->topicLabel($node),
        'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'total_sources' => array_sum($dist),
        'bias' => $dist,
      ];
    }

    return ['count' => count($events), 'buckets' => $this->bucketLegend(), 'events' => $events];
  }

  /**
   * The event's category: the label of its field_topic, or NULL if unset.
   */
  private function topicLabel(NodeInterface $node): ?string {
    if (!$node->hasField('field_topic') || $node->get('field_topic')->isEmpty()) {
      return NULL;
    }
    return \Drupal::service('maemgaba_core.topics')->label($node->get('field_topic')->value);
  }

  /**
   * Tool: get_event_perspectives.
   */
  private function getEventPerspectives(int $eventId): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $event = $eventId ? $storage->load($eventId) : NULL;
    if (!$event || $event->bundle() !== 'event') {
      return ['error' => 'Event not found', 'event_id' => $eventId];
    }

    $cardIds = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'card')
      ->condition('status', 1)
      ->condition('field_parent_event', $eventId)
      ->sort('created', 'DESC')
      ->execute();

    $perspectives = array_fill_keys($this->spectrum->keys(), []);
    foreach ($storage->loadMultiple($cardIds) as $card) {
      // Reprints and opinion are not perspectives (see CardRole).
      if (!CardRole::isPerspective($card)) {
        continue;
      }
      $score = BiasScore::normalize($card->get('field_bias_score')->value);
      $key = $this->spectrum->keyFor($score);
      if ($key === NULL) {
        continue;
      }
      $source = '';
      if (!$card->get('field_source')->isEmpty() && $card->get('field_source')->entity) {
        $source = $card->get('field_source')->entity->label();
      }
      $perspectives[$key][] = [
        'source' => $source,
        'headline' => $card->label(),
        'bias_score' => $score,
        'summary' => $card->get('field_micro_summary')->isEmpty() ? '' : strip_tags($card->get('field_micro_summary')->value),
        'original_url' => $card->get('field_original_url')->uri ?? '',
      ];
    }

    return [
      'event_id' => $eventId,
      'title' => $event->label(),
      'neutral_summary' => $event->get('field_neutral_summary')->isEmpty() ? '' : strip_tags($event->get('field_neutral_summary')->value),
      'buckets' => $this->bucketLegend(),
      'perspectives' => $perspectives,
    ];
  }

  /**
   * Tool: source_bias_report.
   */
  private function sourceBiasReport(): array {
    $report = [];
    foreach ($this->sourceBiasStats->tally() as $source) {
      $divergence = $this->spectrum->divergence($source['dist'], $source['default_score']);
      if ($divergence === NULL) {
        continue;
      }
      $report[] = [
        'source' => $source['name'],
        'declared_score' => $source['default_score'],
        'editorial_line' => $this->spectrum->bucket($divergence['primary'])['label'] ?? $divergence['primary'],
        'total_articles' => $source['total'],
        'bias' => $source['dist'],
        'divergence_pct' => $divergence['pct'],
        'divergence_level' => $divergence['level'],
      ];
    }

    return ['count' => count($report), 'buckets' => $this->bucketLegend(), 'sources' => $report];
  }

  /**
   * The spectrum buckets as {key: {label, min_score, max_score}}.
   */
  private function bucketLegend(): array {
    $legend = [];
    foreach ($this->spectrum->buckets() as $bucket) {
      $legend[$bucket['key']] = [
        'label' => $bucket['label'],
        'min_score' => $bucket['min_score'],
        'max_score' => $bucket['max_score'],
      ];
    }
    return $legend;
  }

  /**
   * Counts an event's published cards per spectrum bucket.
   */
  private function biasDistribution(int $eventId): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'card')
      ->condition('status', 1)
      ->condition('field_parent_event', $eventId)
      ->execute();
    $scores = [];
    foreach ($storage->loadMultiple($ids) as $card) {
      if (CardRole::isPerspective($card)) {
        $scores[] = BiasScore::normalize($card->get('field_bias_score')->value);
      }
    }
    return $this->spectrum->distribution($scores);
  }

}

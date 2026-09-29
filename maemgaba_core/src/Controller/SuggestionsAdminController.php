<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\maemgaba_core\Service\SuggestionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Moderation queue at /admin/config/services/news-engine/suggestions.
 */
class SuggestionsAdminController extends ControllerBase {

  protected const VALID_STATUSES = ['pending', 'accepted', 'rejected', 'all'];

  public function __construct(
    protected SuggestionManager $suggestions,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('maemgaba_core.suggestion_manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Lists suggestions, filterable by status.
   */
  public function list(Request $request): array {
    $status = $request->query->get('status', 'pending');
    if (!in_array($status, self::VALID_STATUSES, TRUE)) {
      $status = 'pending';
    }

    $rows = [];
    foreach ($this->suggestions->list($status) as $row) {
      $links = [];
      if ($row->status === 'pending') {
        $links['accept'] = [
          'title' => $this->t('Accept'),
          'url' => Url::fromRoute('maemgaba_core.suggestion_decide', ['id' => $row->id, 'decision' => 'accept']),
        ];
        $links['reject'] = [
          'title' => $this->t('Reject'),
          'url' => Url::fromRoute('maemgaba_core.suggestion_decide', ['id' => $row->id, 'decision' => 'reject']),
        ];
      }
      $rows[] = [
        $this->dateFormatter->format((int) $row->created, 'short'),
        SuggestionManager::typeOptions()[$row->type] ?? $row->type,
        $row->event_nid ? Link::fromTextAndUrl('#' . $row->event_nid, Url::fromRoute('entity.node.canonical', ['node' => $row->event_nid])) : '—',
        mb_strimwidth($row->body, 0, 120, '…'),
        $row->credit_name ?: ($row->email ?: '—'),
        $row->status,
        $links ? ['data' => ['#type' => 'operations', '#links' => $links]] : '—',
      ];
    }

    $filters = [];
    foreach (self::VALID_STATUSES as $option) {
      $filters[] = Link::createFromRoute($option, 'maemgaba_core.suggestions', ['status' => $option])->toString();
    }

    return [
      'filters' => ['#markup' => '<p>' . implode(' · ', $filters) . '</p>'],
      'table' => $rows ? [
        '#type' => 'table',
        '#header' => [
          $this->t('Sent'),
          $this->t('Type'),
          $this->t('Event'),
          $this->t('Description'),
          $this->t('Credit / email'),
          $this->t('Status'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
      ] : ['#markup' => '<p>' . $this->t('No suggestions for this filter.') . '</p>'],
      '#cache' => [
        'tags' => [SuggestionManager::CACHE_TAG],
        'contexts' => ['url.query_args:status'],
      ],
    ];
  }

}

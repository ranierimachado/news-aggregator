<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Drush\Commands;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\maemgaba_core\Service\ClickLog;
use Drupal\maemgaba_core\Service\SpectrumService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush report of outbound click-through (ClickLog).
 */
class MaemgabaClickCommands extends DrushCommands {

  public function __construct(
    protected ClickLog $clickLog,
    protected SpectrumService $spectrum,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct();
  }

  /**
   * Dependency Injection factory compatible with Drush 13.
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('maemgaba_core.click_log'),
      $container->get('maemgaba_core.spectrum'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Click-through per outlet and per bucket.
   *
   * Impressions = event-page views × cards listed on that event; CTR =
   * clicks / impressions.
   */
  #[CLI\Command(name: 'maemgaba:clicks', aliases: ['mg-clicks'])]
  #[CLI\Option(name: 'since', description: 'Start of the window: a date or relative time strtotime() understands (default: -30 days).')]
  #[CLI\Usage(name: 'drush maemgaba:clicks --since="-7 days"', description: 'Last week.')]
  #[CLI\Usage(name: 'drush maemgaba:clicks --since=2026-09-01', description: 'Since a date.')]
  public function clicks(array $options = ['since' => '-30 days']): int {
    $since = strtotime((string) $options['since']);
    if ($since === FALSE) {
      $this->logger()->error(dt('Cannot parse --since=@since.', ['@since' => $options['since']]));
      return self::EXIT_FAILURE;
    }
    $report = $this->clickLog->report($since);
    $out = $this->output();
    $out->writeln(sprintf(
      'Since %s: %d clicks, %d event-page views, %d impressions, CTR %s',
      $this->dateFormatter->format($since, 'custom', 'Y-m-d H:i T'),
      $report['clicks'],
      $report['views'],
      $report['impressions'],
      $this->pct($report['ctr']),
    ));
    if (!$this->clickLog->enabled()) {
      $out->writeln('Note: click_log.enabled is off, so event pages link to articles directly and nothing new is logged.');
    }

    $out->writeln('');
    $out->writeln(sprintf('%-28s %8s %12s %8s', 'Outlet', 'Clicks', 'Impressions', 'CTR'));
    foreach ($report['outlets'] as $outlet => $row) {
      $out->writeln(sprintf('%-28s %8d %12d %8s', mb_substr($outlet !== '' ? $outlet : '(unknown)', 0, 28), $row['clicks'], $row['impressions'], $this->pct($row['ctr'])));
    }

    $out->writeln('');
    $out->writeln(sprintf('%-28s %8s %12s %8s', 'Bucket', 'Clicks', 'Impressions', 'CTR'));
    foreach ($report['buckets'] as $key => $row) {
      $label = $key === '' ? '(unscored)' : ($this->spectrum->bucket((string) $key)['label'] ?? (string) $key);
      $out->writeln(sprintf('%-28s %8d %12d %8s', mb_substr($label, 0, 28), $row['clicks'], $row['impressions'], $this->pct($row['ctr'])));
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Formats a CTR percentage, '-' when undefined.
   */
  protected function pct(?float $ctr): string {
    return $ctr === NULL ? '-' : sprintf('%.1f%%', $ctr);
  }

}

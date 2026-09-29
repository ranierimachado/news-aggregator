<?php

namespace Drupal\maemgaba_core\Drush\Commands;

use Drupal\maemgaba_core\Service\FeedSeeder;
use Drupal\maemgaba_core\Service\IngestionEngine;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for feed harvesting and the outlet registry.
 *
 * The business logic lives in IngestionEngine and FeedSeeder; this class is
 * only the CLI presentation layer.
 */
class MaemgabaHarvestCommands extends DrushCommands {

  public function __construct(
    protected IngestionEngine $ingestionEngine,
    protected FeedSeeder $feedSeeder,
  ) {
    parent::__construct();
  }

  /**
   * Dependency Injection factory compatible with Drush 13.
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('maemgaba_core.ingestion_engine'),
      $container->get('maemgaba_core.feed_seeder'),
    );
  }

  /**
   * Scans every active feed source and queues new articles as clean text.
   *
   * Sources are "Feed Source" (feed_source) nodes; load a registry with
   * maemgaba:seed-feeds. With no active feed source nothing is harvested.
   */
  #[CLI\Command(name: 'maemgaba:harvest', aliases: ['mg-harvest'])]
  public function harvestFeeds() {
    $this->output()->writeln('Harvesting feeds…');

    $outcome = $this->ingestionEngine->harvestAll();
    if (!$outcome['results']) {
      $this->logger()->warning('No active feed sources. Load a registry with: drush maemgaba:seed-feeds <feeds.yml>');
      return;
    }

    foreach ($outcome['results'] as $result) {
      $this->output()->writeln("  <info>{$result['name']}</info>: <comment>{$result['count']}</comment> new item(s).");
    }

    $this->output()->writeln("Done: <comment>{$outcome['total']}</comment> item(s) queued.");
  }

  /**
   * Loads an outlet registry file into feed sources and source terms.
   *
   * Upserts by feed URL (the file wins; changes are listed). Nothing seeds
   * feeds automatically: each site keeps its registry in its locale pack,
   * e.g. maemgaba_core/config/locale/br/feeds.yml.
   */
  #[CLI\Command(name: 'maemgaba:seed-feeds', aliases: ['mg-seed-feeds'])]
  #[CLI\Argument(name: 'file', description: 'Path to a feeds.yml registry (relative to the current directory or absolute).')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would change without saving.')]
  #[CLI\Usage(name: 'maemgaba:seed-feeds web/modules/custom/maemgaba_core/config/locale/br/feeds.yml --dry-run', description: 'Preview loading the Brazilian registry.')]
  public function seedFeeds(string $file, array $options = ['dry-run' => FALSE]) {
    $parsed = $this->feedSeeder->parse($this->resolvePath($file));
    foreach ($parsed['errors'] as $error) {
      $this->logger()->error($error);
    }
    if ($parsed['errors']) {
      return self::EXIT_FAILURE;
    }

    $dryRun = (bool) $options['dry-run'];
    $report = $this->feedSeeder->seed($parsed['feeds'], $dryRun);
    $counts = array_count_values(array_column($report, 'action'));
    foreach ($report as $row) {
      $score = $row['score'] === NULL ? '-' : sprintf('%+d', $row['score']);
      $detail = $row['changes'] ? ' (' . implode(', ', $row['changes']) . ')' : '';
      $this->output()->writeln(sprintf('  %-9s %-28s %3s  %s%s', $row['action'], $row['outlet'], $score, $row['url'], $detail));
    }
    $this->output()->writeln(sprintf(
      '%s%d created, %d updated, %d unchanged.',
      $dryRun ? '[dry run] ' : '',
      $counts['created'] ?? 0,
      $counts['updated'] ?? 0,
      $counts['unchanged'] ?? 0,
    ));
  }

  /**
   * Resolves a path given on the command line.
   *
   * Drush runs from the docroot, so a relative path is tried against the
   * directory the command was invoked from, the project root and the docroot.
   */
  protected function resolvePath(string $file): string {
    if (str_starts_with($file, '/')) {
      return $file;
    }
    $bases = [(string) $this->getConfig()->get('env.cwd'), dirname(DRUPAL_ROOT), DRUPAL_ROOT];
    foreach ($bases as $base) {
      if ($base !== '' && is_readable($base . '/' . $file)) {
        return $base . '/' . $file;
      }
    }
    return $file;
  }

  /**
   * Manually submits a single news URL to the inbound queue.
   *
   * Goes through the same download/extraction path as the RSS harvester; the
   * resulting item follows the normal pipeline via `maemgaba:process`.
   */
  #[CLI\Command(name: 'maemgaba:add-url', aliases: ['mg-add-url'])]
  #[CLI\Argument(name: 'url', description: 'Full URL of the original article.')]
  #[CLI\Argument(name: 'source_name', description: 'Outlet name as shown on the site (the sources term name).')]
  #[CLI\Usage(name: 'maemgaba:add-url https://example.com/news "Example News"', description: 'Queue one article manually.')]
  public function addUrl(string $url, string $source_name) {
    $nid = $this->ingestionEngine->enqueueUrl($url, $source_name);

    if ($nid) {
      $this->output()->writeln("Queued as node {$nid}. Run maemgaba:process to process it.");
    }
    else {
      $this->output()->writeln('<comment>Not queued: already in the queue, already a card, or the download failed.</comment>');
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\maemgaba_core\Search\SearchEvalScorer;
use Drupal\maemgaba_core\Search\SolrHybridQuery;
use Drupal\maemgaba_core\Service\EventSearch;
use Drupal\maemgaba_core\Service\SolrEventSearch;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the search evaluation set against a search engine.
 *
 * The query file (site data; the package ships a sample in
 * docs/calibration/) pairs each query with the event
 * it should find, or null for off-topic queries. Each query costs one
 * embedding call.
 */
class MaemgabaSearchEvalCommands extends DrushCommands {

  /**
   * Engines this command can evaluate.
   */
  protected const ENGINES = ['mariadb', 'solr'];

  public function __construct(
    protected EventSearch $eventSearch,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SolrEventSearch $solrSearch,
  ) {
    parent::__construct();
  }

  /**
   * Dependency Injection factory for Drush.
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('maemgaba_core.event_search'),
      $container->get('entity_type.manager'),
      $container->get('maemgaba_core.solr_event_search'),
    );
  }

  /**
   * Scores hit@1, hit@3 and latency for the search evaluation queries.
   */
  #[CLI\Command(name: 'maemgaba:search-eval', aliases: ['mg-search-eval'])]
  #[CLI\Option(name: 'engine', description: 'Engine to evaluate: mariadb (the /search page) or solr (the /explore page)')]
  #[CLI\Option(name: 'mode', description: 'Solr only: keyword, vector or hybrid (the page uses hybrid)')]
  #[CLI\Option(name: 'file', description: 'Query file (YAML). Without it, the sample set shipped in the package (docs/calibration/sample-search-eval-queries.yml) is used')]
  #[CLI\Option(name: 'max-distance', description: 'Override the similarity ceiling that maemgaba_core.settings:search_max_distance sets')]
  #[CLI\Option(name: 'json', description: 'Also write per-query results (with the raw top-10 distances) to this JSON file')]
  #[CLI\Usage(name: 'drush maemgaba:search-eval --engine=mariadb', description: 'Run the default query file against the MariaDB vector index')]
  #[CLI\Usage(name: 'drush maemgaba:search-eval --engine=solr --mode=hybrid', description: 'Run it against the Solr index in hybrid mode')]
  public function searchEval(
    array $options = [
      'engine' => 'mariadb',
      'mode' => 'hybrid',
      'file' => NULL,
      'max-distance' => NULL,
      'json' => NULL,
    ],
  ): int {
    $engine = (string) $options['engine'];
    if (!in_array($engine, self::ENGINES, TRUE)) {
      $this->logger()->error(dt('Unknown or not yet available engine "@engine". Available: @list.', [
        '@engine' => $engine,
        '@list' => implode(', ', self::ENGINES),
      ]));
      return self::EXIT_FAILURE;
    }

    $file = $options['file'] ?: dirname(\Drupal::service('extension.list.module')->getPath('maemgaba_core')) . '/docs/calibration/sample-search-eval-queries.yml';
    if (!$options['file']) {
      $this->logger()->notice(dt('No --file given: running the shipped sample queries @file. A site keeps its own query set outside the engine.', ['@file' => $file]));
    }
    if (!is_readable($file)) {
      $this->logger()->error(dt('Query file not found: @file', ['@file' => $file]));
      return self::EXIT_FAILURE;
    }
    try {
      $queries = SearchEvalScorer::parseQueries(Yaml::parseFile($file));
    }
    catch (\Throwable $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    $mode = $engine === 'solr' ? (string) $options['mode'] : NULL;
    if ($mode !== NULL && !in_array($mode, SolrHybridQuery::MODES, TRUE)) {
      $this->logger()->error(dt('Unknown mode "@mode". Use keyword, vector or hybrid.', ['@mode' => $mode]));
      return self::EXIT_FAILURE;
    }
    if (($engine === 'solr' ? $this->solrSearch->index() : $this->eventSearch->index()) === NULL) {
      $this->logger()->error(dt('The search index is missing or disabled.'));
      return self::EXIT_FAILURE;
    }
    $ceiling = is_numeric($options['max-distance']) ? (float) $options['max-distance'] : $this->eventSearch->maxDistance();

    // Expected events must exist, or the numbers are meaningless.
    $expected = array_filter(array_column($queries, 'expected_nid'));
    $existing = $this->entityTypeManager->getStorage('node')->loadMultiple($expected);
    foreach ($queries as $q) {
      if ($q['expected_nid'] !== NULL && (!isset($existing[$q['expected_nid']]) || $existing[$q['expected_nid']]->bundle() !== 'event')) {
        $this->logger()->warning(dt('Query @id expects node @nid, which is not an event on this site.', [
          '@id' => $q['id'],
          '@nid' => $q['expected_nid'],
        ]));
      }
    }

    $rows = [];
    $table = [];
    foreach ($queries as $q) {
      $start = hrtime(TRUE);
      try {
        $raw = $engine === 'solr'
          ? $this->solrResults($q['query'], $mode)
          : $this->eventSearch->search($q['query'], 10, INF);
      }
      catch (\Throwable $e) {
        $this->logger()->error(dt('Query @id failed: @msg', ['@id' => $q['id'], '@msg' => $e->getMessage()]));
        return self::EXIT_FAILURE;
      }
      $latency = (hrtime(TRUE) - $start) / 1e6;

      $raw_nids = array_column($raw, 'nid');
      // MariaDB: the page shows what clears the distance ceiling. Solr: the
      // page shows everything Solr returns (vector mode has its own floor).
      $shown = $engine === 'solr'
        ? $raw_nids
        : array_column(array_filter($raw, static fn ($m) => $m['distance'] <= $ceiling), 'nid');
      $keyword = $this->eventSearch->keywordMatches($q['query']);
      $verdict = SearchEvalScorer::scoreQuery($q, $shown, $raw_nids, $keyword);
      $row = $q + $verdict + [
        'latency_ms' => round($latency, 1),
        'top_nid' => $raw[0]['nid'] ?? NULL,
        'top_distance' => isset($raw[0]) ? round($raw[0]['distance'], 4) : NULL,
        'expected_distance' => NULL,
        'raw_top10' => array_map(static fn ($m) => ['nid' => $m['nid'], 'distance' => round($m['distance'], 4)], $raw),
      ];
      foreach ($raw as $m) {
        if ($m['nid'] === $q['expected_nid']) {
          $row['expected_distance'] = round($m['distance'], 4);
          break;
        }
      }
      $rows[] = $row;

      $table[] = [
        $q['id'],
        $q['type'],
        mb_strimwidth($q['query'], 0, 44, '…'),
        $q['expected_nid'] ?? '-',
        $row['top_nid'] ?? '-',
        $row['top_distance'] ?? '-',
        $q['expected_nid'] === NULL ? ($verdict['off_topic_correct'] ? 'none ✓' : 'junk ✗') : ($verdict['hit1'] ? ($verdict['hit1_via_duplicate'] ? '✓dup' : '✓') : ($verdict['hit3'] ? '@3' : ($verdict['raw_rank'] ? 'rank ' . $verdict['raw_rank'] : '✗'))),
        $verdict['keyword_zero'] ? '0' : $verdict['keyword_count'] . ($verdict['keyword_found_expected'] ? '*' : ''),
        (int) round($latency),
      ];
    }

    $this->io()->table(['id', 'type', 'query', 'expected', 'top', 'dist', 'result', 'kw', 'ms'], $table);
    $summary = SearchEvalScorer::summarize($rows);
    $this->io()->writeln($engine === 'solr'
      ? sprintf('engine: solr   mode: %s   queries: %d   (dist column: Solr score; keyword BM25, vector (1+cos)/2, hybrid BM25 + weight x vector)', $mode, $summary['queries'])
      : sprintf('engine: %s   ceiling: %.3f   queries: %d', $engine, $ceiling, $summary['queries']));
    $this->io()->writeln(sprintf('hit@1: %d/%d (%.1f%%, %d via a listed duplicate)   hit@3: %d/%d (%.1f%%)   off-topic with no result: %d/%d',
      $summary['hit1'], $summary['on_topic'], 100 * (float) $summary['hit1_rate'], $summary['hit1_via_duplicate'],
      $summary['hit3'], $summary['on_topic'], 100 * (float) $summary['hit3_rate'],
      $summary['off_topic_correct'], $summary['off_topic']));
    $this->io()->writeln(sprintf('latency ms: mean %.1f, median %.1f, max %.1f (embedding call + vector query)',
      $summary['latency_mean_ms'], $summary['latency_median_ms'], $summary['latency_max_ms']));
    $this->io()->writeln(sprintf('keyword baseline (all words, LIKE): zero results for %d/%d queries; found the expected event for %d/%d on-topic queries',
      $summary['keyword_zero'], $summary['queries'], $summary['keyword_found_expected'], $summary['on_topic']));
    $this->io()->writeln('kw column: keyword matches (* = includes the expected event). result: ✓ hit@1, @3 hit@3, rank N = below the ceiling cut or lower.');

    if (!empty($options['json'])) {
      $payload = [
        'engine' => $engine,
        'mode' => $mode,
        'ceiling' => $engine === 'solr' ? NULL : $ceiling,
        'file' => $file,
        'run_at' => gmdate('c'),
        'summary' => $summary,
        'queries' => $rows,
      ];
      file_put_contents($options['json'], json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
      $this->io()->writeln('Wrote ' . $options['json']);
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Solr results in the shape the MariaDB path returns.
   *
   * `distance` carries Solr's score here (higher is better); the report
   * labels it accordingly.
   *
   * @return array<int, array{nid: int, distance: float}>
   *   Results, best first.
   */
  protected function solrResults(string $keys, string $mode): array {
    return array_map(
      static fn (array $r): array => ['nid' => $r['nid'], 'distance' => $r['score']],
      $this->solrSearch->search($keys, $mode, 10),
    );
  }

}

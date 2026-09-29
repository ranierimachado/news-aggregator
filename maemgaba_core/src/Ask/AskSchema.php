<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Ask;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\maemgaba_core\Service\SpectrumService;

/**
 * The allow-listed SQL views that "Ask the data" may read.
 *
 * Four views over the node tables, with English column names, are the whole
 * schema the model sees and the read-only database user may SELECT from:
 * ask_sources (one row per published card), ask_stories (one row per
 * published event), ask_outlets (the feed registry) and ask_daily (one row
 * per local day). They expose no user, revision, log or key data and no
 * article text (copyright policy): only ids, titles, outlets, scores,
 * buckets, topics and dates.
 *
 * The card roles mirror EventPreprocessor/CardRole: a reprint copies a card
 * on the same event; opinion is field_section = opinion (missing = news); a
 * perspective is any other card with a score. Buckets come from
 * maemgaba_core.spectrum, so per-bucket count columns follow the site's
 * spectrum keys. Dates are the site's local time (see LocalTimeSql).
 *
 * Base tables and view names are written with {braces}, so the views get
 * the connection's table prefix: none on a real site, the test prefix in
 * kernel tests.
 */
class AskSchema {

  /**
   * The views, in creation order (later ones read earlier ones).
   */
  public const VIEWS = ['ask_sources', 'ask_stories', 'ask_outlets', 'ask_daily'];

  /**
   * First and last instants the local-time offset table covers (UTC).
   */
  public const TZ_FROM = 1704067200;
  public const TZ_TO = 1988150400;

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected SpectrumService $spectrum,
  ) {}

  /**
   * Column names of every view, keyed by view name.
   *
   * @return array<string, string[]>
   *   The validator's identifier allow-list and the probe's expectation.
   */
  public function columns(): array {
    $bucketCounts = array_map(fn (string $key) => $key . '_count', $this->bucketKeys());
    return [
      'ask_sources' => [
        'source_id', 'story_id', 'outlet', 'section', 'score', 'bucket', 'confidence',
        'partial_text', 'syndicated', 'is_reprint', 'is_opinion', 'is_perspective',
        'topic', 'published_at', 'day',
      ],
      'ask_stories' => array_merge([
        'story_id', 'title', 'published_at', 'day', 'topic', 'relevance',
        'source_count', 'perspective_count', 'opinion_count', 'reprint_count', 'outlet_count',
      ], $bucketCounts, ['has_summary', 'has_consensus']),
      'ask_outlets' => [
        'outlet', 'section', 'prior_score', 'prior_bucket', 'prior_source', 'active', 'partial_text',
      ],
      'ask_daily' => array_merge([
        'day', 'stories', 'sources', 'perspectives', 'opinion',
      ], $bucketCounts),
    ];
  }

  /**
   * The spectrum bucket keys, validated as SQL-safe identifiers.
   *
   * @return string[]
   *   E.g. left, lean_left, center, lean_right, right.
   */
  public function bucketKeys(): array {
    $keys = [];
    foreach ($this->spectrum->buckets() as $bucket) {
      if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $bucket['key'])) {
        throw new \InvalidArgumentException(sprintf('Spectrum key "%s" cannot be used as a column name.', $bucket['key']));
      }
      $keys[] = $bucket['key'];
    }
    return $keys;
  }

  /**
   * The SELECT behind each view, keyed by view name, in creation order.
   *
   * @return array<string, string>
   *   SQL with {braced} table names.
   */
  public function definitions(): array {
    return [
      'ask_sources' => $this->sourcesSql(),
      'ask_stories' => $this->storiesSql(),
      'ask_outlets' => $this->outletsSql(),
      'ask_daily' => $this->dailySql(),
    ];
  }

  /**
   * The CREATE statements, with {braced} names.
   *
   * @return string[]
   *   One statement per view, in creation order.
   */
  public function createStatements(): array {
    $statements = [];
    foreach ($this->definitions() as $view => $select) {
      $statements[] = sprintf('CREATE OR REPLACE SQL SECURITY DEFINER VIEW {%s} AS %s', $view, $select);
    }
    return $statements;
  }

  /**
   * Creates or replaces the views on the site's own connection.
   *
   * @return string[]
   *   The views created.
   */
  public function createViews(): array {
    foreach ($this->createStatements() as $statement) {
      $this->database->query($statement);
    }
    return self::VIEWS;
  }

  /**
   * Drops the views (used by tests and when the feature is removed).
   */
  public function dropViews(): void {
    foreach (array_reverse(self::VIEWS) as $view) {
      $this->database->query(sprintf('DROP VIEW IF EXISTS {%s}', $view));
    }
  }

  /**
   * The views that currently exist on the site's connection.
   *
   * @return string[]
   *   Unprefixed view names.
   */
  public function existingViews(): array {
    $existing = [];
    foreach (self::VIEWS as $view) {
      $table = $this->database->getPrefix() . $view;
      $found = $this->database->query('SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', [':t' => $table])->fetchField();
      if ($found) {
        $existing[] = $view;
      }
    }
    return $existing;
  }

  /**
   * The statements root must run to create the read-only user.
   *
   * The password is a placeholder: the real one is generated on the host and
   * never printed.
   *
   * @return string[]
   *   CREATE USER plus one GRANT SELECT per view.
   */
  public function grantStatements(string $user, string $host, string $database, string $passwordPlaceholder = '<password>'): array {
    foreach ([$user, $host, $database] as $part) {
      if (!preg_match('/^[A-Za-z0-9_.%-]+$/', $part)) {
        throw new \InvalidArgumentException(sprintf('Refusing unsafe identifier "%s".', $part));
      }
    }
    $account = sprintf("'%s'@'%s'", $user, $host);
    $statements = [
      sprintf("CREATE USER IF NOT EXISTS %s IDENTIFIED BY '%s' WITH MAX_STATEMENT_TIME 5 MAX_USER_CONNECTIONS 5", $account, $passwordPlaceholder),
    ];
    foreach (self::VIEWS as $view) {
      $statements[] = sprintf('GRANT SELECT ON `%s`.`%s%s` TO %s', $database, $this->database->getPrefix(), $view, $account);
    }
    return $statements;
  }

  /**
   * Local DATETIME of a Unix timestamp column in the site's time zone.
   */
  protected function local(string $column): string {
    return LocalTimeSql::localDatetime($this->timezone(), $column, self::TZ_FROM, self::TZ_TO);
  }

  /**
   * The site's default time zone.
   */
  public function timezone(): string {
    return (string) ($this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC');
  }

  /**
   * CASE mapping a score expression to its spectrum bucket key.
   *
   * A view column built only from string literals takes the collation of
   * the connection that created the view, and a client connected with
   * another collation then gets "Illegal mix of collations" on
   * bucket = 'left'. The column is therefore cast to the database's own
   * collation explicitly.
   */
  protected function bucketCase(string $score): string {
    $sql = 'CASE';
    foreach ($this->spectrum->buckets() as $bucket) {
      $sql .= sprintf(" WHEN %s BETWEEN %d AND %d THEN '%s'", $score, $bucket['min_score'], $bucket['max_score'], $bucket['key']);
    }
    return sprintf('CAST(%s END AS CHAR(32) CHARACTER SET utf8mb4) COLLATE %s', $sql, $this->collation());
  }

  /**
   * The site database's default collation (a safe SQL identifier).
   */
  protected function collation(): string {
    $collation = (string) $this->database->query('SELECT @@collation_database')->fetchField();
    if (!preg_match('/^utf8mb4_[a-z0-9_]+$/', $collation)) {
      throw new \RuntimeException(sprintf('Unexpected database collation "%s".', $collation));
    }
    return $collation;
  }

  /**
   * One LEFT JOIN on a single-valued node field table.
   */
  protected function fieldJoin(string $field, string $alias, string $nid): string {
    return sprintf(' LEFT JOIN {node__%1$s} %2$s ON %2$s.entity_id = %3$s AND %2$s.deleted = 0 AND %2$s.delta = 0', $field, $alias, $nid);
  }

  /**
   * The ask_sources view: one row per published card.
   */
  protected function sourcesSql(): string {
    $reprint = '(sf.field_syndicated_from_target_id IS NOT NULL AND ope.field_parent_event_target_id = pe.field_parent_event_target_id)';
    $opinion = "(COALESCE(sec.field_section_value, 'news') = 'opinion')";
    $published = $this->local('n.created');
    return 'SELECT'
      . ' n.nid AS source_id,'
      . ' pe.field_parent_event_target_id AS story_id,'
      . ' t.name AS outlet,'
      . " COALESCE(sec.field_section_value, 'news') AS section,"
      . ' bs.field_bias_score_value AS score,'
      . ' ' . $this->bucketCase('bs.field_bias_score_value') . ' AS bucket,'
      . ' bc.field_bias_confidence_value AS confidence,'
      . ' COALESCE(pt.field_partial_text_value, 0) AS partial_text,'
      . ' (sf.field_syndicated_from_target_id IS NOT NULL) AS syndicated,'
      . " {$reprint} AS is_reprint,"
      . " (NOT {$reprint} AND {$opinion}) AS is_opinion,"
      . " (NOT {$reprint} AND NOT {$opinion} AND bs.field_bias_score_value IS NOT NULL) AS is_perspective,"
      . ' tp.field_topic_value AS topic,'
      . " {$published} AS published_at,"
      . " CAST({$published} AS DATE) AS day"
      . ' FROM {node_field_data} n'
      . $this->fieldJoin('field_parent_event', 'pe', 'n.nid')
      . $this->fieldJoin('field_source', 'src', 'n.nid')
      . ' LEFT JOIN {taxonomy_term_field_data} t ON t.tid = src.field_source_target_id AND t.default_langcode = 1'
      . $this->fieldJoin('field_section', 'sec', 'n.nid')
      . $this->fieldJoin('field_bias_score', 'bs', 'n.nid')
      . $this->fieldJoin('field_bias_confidence', 'bc', 'n.nid')
      . $this->fieldJoin('field_partial_text', 'pt', 'n.nid')
      . $this->fieldJoin('field_syndicated_from', 'sf', 'n.nid')
      . $this->fieldJoin('field_parent_event', 'ope', 'sf.field_syndicated_from_target_id')
      . $this->fieldJoin('field_topic', 'tp', 'n.nid')
      . " WHERE n.type = 'card' AND n.status = 1 AND n.default_langcode = 1";
  }

  /**
   * The ask_stories view: one row per published event, with card counts.
   */
  protected function storiesSql(): string {
    $bucketSums = '';
    foreach ($this->bucketKeys() as $key) {
      $bucketSums .= sprintf(" SUM(s.is_perspective = 1 AND s.bucket = '%s') AS %s_count,", $key, $key);
    }
    $bucketCols = '';
    foreach ($this->bucketKeys() as $key) {
      $bucketCols .= sprintf(' COALESCE(agg.%1$s_count, 0) AS %1$s_count,', $key);
    }
    $published = $this->local('e.created');
    $agg = 'SELECT s.story_id,'
      . ' COUNT(*) AS source_count,'
      . ' SUM(s.is_perspective) AS perspective_count,'
      . ' SUM(s.is_opinion) AS opinion_count,'
      . ' SUM(s.is_reprint) AS reprint_count,'
      . ' COUNT(DISTINCT CASE WHEN s.is_perspective = 1 THEN s.outlet END) AS outlet_count,'
      . rtrim($bucketSums, ',')
      . ' FROM {ask_sources} s GROUP BY s.story_id';
    return 'SELECT'
      . ' e.nid AS story_id,'
      . ' e.title AS title,'
      . " {$published} AS published_at,"
      . " CAST({$published} AS DATE) AS day,"
      . ' tp.field_topic_value AS topic,'
      . ' rel.field_social_relevance_value AS relevance,'
      . ' COALESCE(agg.source_count, 0) AS source_count,'
      . ' COALESCE(agg.perspective_count, 0) AS perspective_count,'
      . ' COALESCE(agg.opinion_count, 0) AS opinion_count,'
      . ' COALESCE(agg.reprint_count, 0) AS reprint_count,'
      . ' COALESCE(agg.outlet_count, 0) AS outlet_count,'
      . $bucketCols
      . " (COALESCE(ns.field_neutral_summary_value, '') <> '') AS has_summary,"
      . ' (EXISTS (SELECT 1 FROM {node__field_common_points} cp WHERE cp.entity_id = e.nid AND cp.deleted = 0)'
      . ' OR EXISTS (SELECT 1 FROM {node__field_disputed_points} dp WHERE dp.entity_id = e.nid AND dp.deleted = 0)) AS has_consensus'
      . ' FROM {node_field_data} e'
      . " LEFT JOIN ({$agg}) agg ON agg.story_id = e.nid"
      . $this->fieldJoin('field_topic', 'tp', 'e.nid')
      . $this->fieldJoin('field_social_relevance', 'rel', 'e.nid')
      . $this->fieldJoin('field_neutral_summary', 'ns', 'e.nid')
      . " WHERE e.type = 'event' AND e.status = 1 AND e.default_langcode = 1";
  }

  /**
   * The ask_outlets view: the published feed registry, one row per feed.
   */
  protected function outletsSql(): string {
    return 'SELECT'
      . ' mo.field_media_outlet_value AS outlet,'
      . " COALESCE(sec.field_section_value, 'news') AS section,"
      . ' pp.field_published_prior_value AS prior_score,'
      . ' ' . $this->bucketCase('pp.field_published_prior_value') . ' AS prior_bucket,'
      . ' ps.field_prior_source_value AS prior_source,'
      . ' COALESCE(act.field_active_testing_value, 0) AS active,'
      . ' COALESCE(pt.field_partial_text_value, 0) AS partial_text'
      . ' FROM {node_field_data} f'
      . $this->fieldJoin('field_media_outlet', 'mo', 'f.nid')
      . $this->fieldJoin('field_section', 'sec', 'f.nid')
      . $this->fieldJoin('field_published_prior', 'pp', 'f.nid')
      . $this->fieldJoin('field_prior_source', 'ps', 'f.nid')
      . $this->fieldJoin('field_active_testing', 'act', 'f.nid')
      . $this->fieldJoin('field_partial_text', 'pt', 'f.nid')
      . " WHERE f.type = 'feed_source' AND f.status = 1 AND f.default_langcode = 1";
  }

  /**
   * The ask_daily view: one row per local day with any story or source.
   */
  protected function dailySql(): string {
    $bucketSums = '';
    $bucketCols = '';
    foreach ($this->bucketKeys() as $key) {
      $bucketSums .= sprintf(" SUM(s.is_perspective = 1 AND s.bucket = '%s') AS %s_count,", $key, $key);
      $bucketCols .= sprintf(' COALESCE(so.%1$s_count, 0) AS %1$s_count,', $key);
    }
    return 'SELECT d.day AS day,'
      . ' COALESCE(st.stories, 0) AS stories,'
      . ' COALESCE(so.sources, 0) AS sources,'
      . ' COALESCE(so.perspectives, 0) AS perspectives,'
      . ' COALESCE(so.opinion, 0) AS opinion,'
      . rtrim($bucketCols, ',')
      . ' FROM (SELECT day FROM {ask_stories} UNION SELECT day FROM {ask_sources}) d'
      . ' LEFT JOIN (SELECT day, COUNT(*) AS stories FROM {ask_stories} GROUP BY day) st ON st.day = d.day'
      . ' LEFT JOIN (SELECT s.day, COUNT(*) AS sources, SUM(s.is_perspective) AS perspectives, SUM(s.is_opinion) AS opinion,'
      . rtrim($bucketSums, ',')
      . ' FROM {ask_sources} s GROUP BY s.day) so ON so.day = d.day';
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\maemgaba_core\BiasScore;

/**
 * Loads a site's outlet registry (feeds.yml) into feed_source nodes + terms.
 *
 * The registry is locale-pack data (see config/locale/<pack>/feeds.yml), not
 * code: nothing seeds feeds automatically. Run `drush maemgaba:seed-feeds
 * <file>`. Upserts by feed URL, so re-running is safe; the file wins over
 * what is stored (it is the registry), and every change is reported.
 *
 * File format:
 * @code
 * feeds:
 *   - url: https://example.com/politics/rss.xml   # required, unique key
 *     outlet: Example News                        # required, sources term name
 *     score: -1                                   # declared line, -2..+2
 *     section: news                               # news|opinion (optional)
 *     website: https://example.com                # optional
 *     active: true                                # harvest on/off (default true)
 *     published_prior: -1                          # external rating, -2..+2 (optional)
 *     prior_source: 'AllSides 2026-09'              # where it came from (optional)
 *     partial_text: true                           # pages unusable: headline + abstract only (optional)
 * @endcode
 * section/website/published_prior/prior_source/partial_text are written only
 * where the site has those fields (feed_source.field_section, field_website,
 * field_published_prior, field_prior_source, field_partial_text).
 */
class FeedSeeder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PipelineIdentity $pipelineIdentity,
  ) {}

  /**
   * Parses and validates a registry file.
   *
   * @return array{feeds: array<int, array>, errors: string[]}
   *   The normalised feeds and any validation errors.
   */
  public function parse(string $path): array {
    if (!is_readable($path)) {
      return ['feeds' => [], 'errors' => ["Cannot read {$path}."]];
    }
    $data = Yaml::decode((string) file_get_contents($path));
    $feeds = [];
    $errors = [];
    $seen = [];
    foreach ((array) ($data['feeds'] ?? []) as $i => $feed) {
      $url = trim((string) ($feed['url'] ?? ''));
      $outlet = trim((string) ($feed['outlet'] ?? ''));
      if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $errors[] = "Entry {$i}: missing or invalid url.";
        continue;
      }
      if ($outlet === '') {
        $errors[] = "Entry {$i} ({$url}): missing outlet.";
        continue;
      }
      if (isset($seen[$url])) {
        $errors[] = "Entry {$i}: duplicate url {$url}.";
        continue;
      }
      $seen[$url] = TRUE;
      $score = array_key_exists('score', $feed) ? BiasScore::normalize($feed['score']) : NULL;
      if (array_key_exists('score', $feed) && $score === NULL) {
        $errors[] = "Entry {$i} ({$outlet}): score must be an integer from -2 to 2.";
        continue;
      }
      $section = isset($feed['section']) ? (string) $feed['section'] : NULL;
      if ($section !== NULL && !in_array($section, ['news', 'opinion'], TRUE)) {
        $errors[] = "Entry {$i} ({$outlet}): section must be news or opinion.";
        continue;
      }
      $publishedPrior = array_key_exists('published_prior', $feed) ? BiasScore::normalize($feed['published_prior']) : NULL;
      if (array_key_exists('published_prior', $feed) && $publishedPrior === NULL) {
        $errors[] = "Entry {$i} ({$outlet}): published_prior must be an integer from -2 to 2.";
        continue;
      }
      $priorSource = isset($feed['prior_source']) ? (string) $feed['prior_source'] : NULL;
      if ($priorSource !== NULL && strlen($priorSource) > 255) {
        $errors[] = "Entry {$i} ({$outlet}): prior_source is " . strlen($priorSource) . ' characters, longer than field_prior_source allows (255). Keep the rationale in the registry file comments instead.';
        continue;
      }
      $feeds[] = [
        'url' => $url,
        'outlet' => $outlet,
        'score' => $score,
        'section' => $section,
        'website' => isset($feed['website']) ? (string) $feed['website'] : NULL,
        'active' => (bool) ($feed['active'] ?? TRUE),
        'published_prior' => $publishedPrior,
        'prior_source' => $priorSource,
        'partial_text' => (bool) ($feed['partial_text'] ?? FALSE),
      ];
    }
    if (!$feeds && !$errors) {
      $errors[] = 'No feeds found (expected a top-level "feeds:" list).';
    }
    return ['feeds' => $feeds, 'errors' => $errors];
  }

  /**
   * Upserts the feeds; with $dryRun nothing is saved.
   *
   * @return array<int, array{outlet: string, url: string, score: int|null, action: string, changes: string[]}>
   *   One report row per feed: the action taken and the changes made.
   */
  public function seed(array $feeds, bool $dryRun = FALSE): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $report = [];

    foreach ($feeds as $feed) {
      $ids = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'feed_source')
        ->condition('field_feed_url', $feed['url'])
        ->range(0, 1)
        ->execute();
      $node = $ids ? $nodeStorage->load(reset($ids)) : NULL;
      $isNew = $node === NULL;
      if ($isNew) {
        $node = $nodeStorage->create([
          'type' => 'feed_source',
          'uid' => $this->pipelineIdentity->uid(),
          'field_feed_url' => $feed['url'],
          'status' => 1,
        ]);
      }

      $changes = [];
      $set = function ($entity, string $field, $value) use (&$changes) {
        if ($value === NULL || !$entity->hasField($field)) {
          return;
        }
        $current = $entity->get($field)->isEmpty() ? NULL : $entity->get($field)->first()->getValue();
        $currentValue = $current['value'] ?? $current['uri'] ?? NULL;
        if ((string) $currentValue !== (string) $value) {
          $entity->set($field, $value);
          $changes[] = $entity->getEntityTypeId() . '.' . $field;
        }
      };
      if ($node->label() !== $feed['outlet']) {
        $node->setTitle($feed['outlet']);
        $changes[] = 'node.title';
      }
      $set($node, 'field_media_outlet', $feed['outlet']);
      $set($node, 'field_default_bias_score', $feed['score']);
      $set($node, 'field_active_testing', (int) $feed['active']);
      $set($node, 'field_section', $feed['section']);
      $set($node, 'field_website', $feed['website']);
      $set($node, 'field_published_prior', $feed['published_prior']);
      $set($node, 'field_prior_source', $feed['prior_source']);
      $set($node, 'field_partial_text', (int) $feed['partial_text']);

      $terms = $termStorage->loadByProperties(['vid' => 'sources', 'name' => $feed['outlet']]);
      $term = $terms ? reset($terms) : $termStorage->create(['vid' => 'sources', 'name' => $feed['outlet']]);
      $termChanges = count($changes);
      // The outlet term carries the news line; an opinion feed of the same
      // outlet (NYT Opinion: -2 vs news -1) must not overwrite it.
      if ($feed['section'] !== 'opinion') {
        $set($term, 'field_default_bias_score', $feed['score']);
      }
      $set($term, 'field_website', $feed['website']);
      $termChanged = $term->isNew() || count($changes) > $termChanges;

      if (!$dryRun) {
        if ($isNew || $changes) {
          $node->save();
        }
        if ($termChanged) {
          $term->save();
        }
      }
      $report[] = [
        'outlet' => $feed['outlet'],
        'url' => $feed['url'],
        'score' => $feed['score'],
        'action' => $isNew ? 'created' : ($changes ? 'updated' : 'unchanged'),
        'changes' => $isNew ? [] : $changes,
      ];
    }
    return $report;
  }

}

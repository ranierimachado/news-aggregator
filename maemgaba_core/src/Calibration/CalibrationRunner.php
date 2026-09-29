<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Calibration;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\maemgaba_core\AiAnalyzerService;
use Drupal\maemgaba_core\BiasScore;
use Drupal\maemgaba_core\Entity\AiPromptInterface;
use Drupal\maemgaba_core\Service\AiPricing;
use Drupal\maemgaba_core\Service\IngestionEngine;
use Drupal\maemgaba_core\Service\RubricService;
use Drupal\maemgaba_core\Service\SpectrumService;
use Drupal\node\NodeInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Calibration harness: builds a sample from the feeds and scores the rubric.
 *
 * The Brazil golden-set eval (EvalRunner) replays stored cards; this one
 * classifies live articles blind and compares the answers to each outlet's
 * published prior (AllSides, on feed_source) and, where present, to a hand
 * label. Every item is classified twice — run A with the prompt under test,
 * run B with a paraphrased prompt (a disabled prompt entity for the same
 * operation, by default "<id>_paraphrase") — so run-to-run instability is
 * measured the same way the Brazil golden set was re-run with reworded
 * instructions.
 *
 * Extracted article text is cached privately (private://, or temporary://
 * when the site has no private path) so a re-run costs AI calls only. The
 * sample YAML and the stored results carry no article text.
 */
class CalibrationRunner {

  /**
   * Call-log context type for calibration calls (kept out of cost reports).
   */
  public const CONTEXT_TYPE = 'calibration';

  public function __construct(
    protected IngestionEngine $ingestion,
    protected AiAnalyzerService $analyzer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SpectrumService $spectrum,
    protected RubricService $rubric,
    protected Connection $database,
    protected TimeInterface $time,
    protected AiPricing $pricing,
    protected FileSystemInterface $fileSystem,
    protected StreamWrapperManagerInterface $streamWrappers,
  ) {}

  /**
   * Builds a calibration sample from the active feed registry.
   *
   * @param int $perOutlet
   *   Items to take per outlet (all feeds of an outlet together).
   * @param bool $includeOpinion
   *   Also sample feeds whose field_section is 'opinion'.
   *
   * @return array
   *   ['items' => list of sample items, 'feeds' => per-feed report rows].
   */
  public function sample(int $perOutlet, bool $includeOpinion = FALSE): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'feed_source')
      ->condition('status', 1)
      ->sort('title')
      ->execute();

    $items = [];
    $report = [];
    $taken = [];
    foreach ($storage->loadMultiple($ids) as $feed) {
      $section = $feed->hasField('field_section') ? (string) $feed->get('field_section')->value : '';
      if ($section === 'opinion' && !$includeOpinion) {
        continue;
      }
      $outlet = trim((string) ($feed->get('field_media_outlet')->value ?: $feed->label()));
      $url = trim((string) $feed->get('field_feed_url')->value);
      $prior = $this->prior($feed);
      try {
        $feedItems = $this->ingestion->fetchFeedItems($url);
      }
      catch (\Throwable $e) {
        $report[] = [
          'outlet' => $outlet,
          'feed' => $url,
          'items' => 0,
          'taken' => 0,
          'note' => mb_substr($e->getMessage(), 0, 100),
        ];
        continue;
      }
      $count = 0;
      foreach ($feedItems as $item) {
        if (($taken[$outlet] ?? 0) >= $perOutlet) {
          break;
        }
        if ($item['link'] === '' || isset($items[$item['link']])) {
          continue;
        }
        $items[$item['link']] = [
          'url' => $item['link'],
          'outlet' => $outlet,
          'section' => $section ?: 'news',
          'expected_score' => $prior,
          'title' => $item['title'],
        ];
        $this->cacheWrite($item['link'], ['feed' => $item]);
        $taken[$outlet] = ($taken[$outlet] ?? 0) + 1;
        $count++;
      }
      $report[] = ['outlet' => $outlet, 'feed' => $url, 'items' => count($feedItems), 'taken' => $count, 'note' => ''];
    }
    return ['items' => array_values($items), 'feeds' => $report];
  }

  /**
   * Runs the calibration over a sample file.
   *
   * @param string $path
   *   YAML file: {items: [{url, outlet, expected_score|expected_bucket,
   *   hand_label?, title?}]} (a bare list works too).
   * @param array $options
   *   operation ('classify_cluster'|'classify_bias'), prompt (id or NULL =
   *   enabled one), paraphrase (id, '' = auto, 'none' = rerun run A's prompt),
   *   single (bool: skip run B), limit (int), label (string), extract_only
   *   (bool: fetch and cache the articles, no AI calls, nothing stored),
   *   on_item (callable progress hook).
   *
   * @return array
   *   ['run_id' => int, 'metrics' => array, 'rows' => array].
   */
  public function run(string $path, array $options = []): array {
    $operation = $options['operation'] ?? 'classify_cluster';
    if (!in_array($operation, ['classify_cluster', 'classify_bias'], TRUE)) {
      throw new \InvalidArgumentException("Operation must be classify_cluster or classify_bias, got '{$operation}'.");
    }
    $promptA = $this->loadPrompt($options['prompt'] ?? NULL, $operation);
    $promptB = empty($options['single']) ? $this->paraphrasePrompt($promptA, (string) ($options['paraphrase'] ?? '')) : NULL;

    $data = Yaml::parseFile($path);
    $items = $data['items'] ?? $data;
    if (!is_array($items) || !$items) {
      throw new \RuntimeException("No items in {$path}.");
    }
    if (!empty($options['limit'])) {
      $items = array_slice($items, 0, (int) $options['limit']);
    }

    $keys = $this->spectrum->keys();
    $start = $this->time->getCurrentTime();
    $rows = [];
    foreach ($items as $i => $item) {
      $row = $this->runItem($item, $promptA, empty($options['extract_only']) ? $promptB : NULL, $operation, $keys, !empty($options['extract_only']));
      $rows[] = $row;
      if (isset($options['on_item']) && is_callable($options['on_item'])) {
        ($options['on_item'])($i + 1, count($items), $row);
      }
    }

    if (!empty($options['extract_only'])) {
      return ['run_id' => 0, 'metrics' => [], 'rows' => $rows];
    }

    $metrics = CalibrationScorer::score($rows, $keys);
    $metrics['bucket_keys'] = $keys;
    $metrics['rubric_version'] = $this->rubric->version();
    $metrics['prompt'] = $promptA->id();
    $metrics['paraphrase_prompt'] = $promptB?->id();
    $metrics['blind'] = $promptA->isBlind();
    $metrics['operation'] = $operation;
    $metrics['label'] = (string) ($options['label'] ?? '');
    $metrics['cost'] = $this->cost($start);
    $metrics['rows'] = array_map(fn ($r) => array_intersect_key($r, array_flip([
      'url', 'outlet', 'expected', 'hand', 'partial', 'words', 'method', 'a', 'b', 'conf_a', 'conf_b',
    ])), $rows);

    $runId = (int) $this->database->insert('maemgaba_eval_run')
      ->fields([
        'operation' => 'calibrate:' . $operation,
        'prompt_config_id' => $promptA->id(),
        'prompt_hash' => hash('sha256', $promptA->getSystemPrompt() . '|' . $promptA->getTemplate() . '|' . $this->analyzer->globalTokens()['rubric'] . '|' . json_encode($promptA->getAnchors())),
        'item_count' => count($rows),
        'metrics' => json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created' => $this->time->getCurrentTime(),
      ])
      ->execute();

    return ['run_id' => $runId, 'metrics' => $metrics, 'rows' => $rows];
  }

  /**
   * Extracts (cached) and classifies one item twice.
   */
  protected function runItem(array $item, AiPromptInterface $promptA, ?AiPromptInterface $promptB, string $operation, array $keys, bool $extractOnly = FALSE): array {
    $url = (string) ($item['url'] ?? '');
    $outlet = (string) ($item['outlet'] ?? $this->outletForUrl($url));
    $row = [
      'url' => $url,
      'outlet' => $outlet,
      'expected' => $this->bucketIndex($item, 'expected', $keys),
      'hand' => $this->bucketIndex($item, 'hand_label', $keys),
      'partial' => FALSE,
      'words' => 0,
      'method' => '',
      'note' => '',
      'a' => NULL,
      'b' => NULL,
      'conf_a' => NULL,
      'conf_b' => NULL,
      'answers' => [],
    ];

    $article = $this->article($url, (string) ($item['title'] ?? ''));
    if ($article === NULL) {
      $row['note'] = 'not extractable';
      return $row;
    }
    $row['partial'] = $article['partial'];
    $row['words'] = $article['words'];
    $row['method'] = $article['method'];
    $row['note'] = $article['note'];
    if ($extractOnly) {
      return $row;
    }

    $source = ['headline' => $article['title'], 'outlet' => $outlet];
    $context = ['type' => self::CONTEXT_TYPE, 'label' => mb_substr($article['title'], 0, 255)];
    foreach (['a' => $promptA, 'b' => $promptB] as $run => $prompt) {
      if ($prompt === NULL) {
        continue;
      }
      $answer = $operation === 'classify_cluster'
        ? $this->analyzer->classifyAndCluster($article['body'], [], $prompt, $context, $source)
        : $this->analyzer->classifyBias($article['body'], $prompt, $source, $context);
      $score = $answer['bias_score'] ?? NULL;
      $row[$run] = $score !== NULL ? $this->indexForScore((int) $score, $keys) : NULL;
      $row['conf_' . $run] = $answer['bias_confidence'] ?? NULL;
      $row['answers'][$run] = [
        'score' => $score,
        'confidence' => $answer['bias_confidence'] ?? NULL,
        'framing_line' => $answer['framing_line'] ?? '',
        'evidence' => $answer['bias_evidence'] ?? [],
        'topic' => $answer['topic'] ?? '',
        'relevance' => $answer['social_relevance'] ?? '',
      ];
    }
    return $row;
  }

  /**
   * The extracted article for a URL, from the private cache or the web.
   *
   * @return array|null
   *   IngestionEngine::extractArticle() shape, or NULL if nothing usable.
   */
  protected function article(string $url, string $title): ?array {
    $cached = $this->cacheRead($url);
    if (isset($cached['extracted'])) {
      return $cached['extracted'];
    }
    $feed = $cached['feed'] ?? [];
    try {
      $article = $this->ingestion->extractArticle($url, ($feed['title'] ?? '') ?: $title, (string) ($feed['abstract'] ?? ''), (string) ($feed['feed_body'] ?? ''));
    }
    catch (\Throwable $e) {
      return NULL;
    }
    if ($article['title'] === '' && trim(strip_tags($article['body'])) === '') {
      return NULL;
    }
    $cached['extracted'] = $article;
    $this->cacheWrite($url, $cached);
    return $article;
  }

  /**
   * Bucket index for an item's reference: *_score, *_bucket, or a raw value.
   */
  protected function bucketIndex(array $item, string $field, array $keys): ?int {
    $base = preg_replace('/_label$/', '', $field);
    $candidates = [$item[$field] ?? NULL, $item[$base . '_score'] ?? NULL, $item[$base . '_bucket'] ?? NULL];
    foreach ($candidates as $value) {
      if ($value === NULL || $value === '') {
        continue;
      }
      if (is_string($value) && !is_numeric($value)) {
        $index = array_search($value, $keys, TRUE);
        return $index === FALSE ? NULL : (int) $index;
      }
      $score = BiasScore::normalize($value);
      return $score === NULL ? NULL : $this->indexForScore($score, $keys);
    }
    return NULL;
  }

  /**
   * Bucket index for a −2..+2 score.
   */
  protected function indexForScore(int $score, array $keys): ?int {
    $index = array_search($this->spectrum->keyFor($score), $keys, TRUE);
    return $index === FALSE ? NULL : (int) $index;
  }

  /**
   * The published prior of a feed (falls back to its declared score).
   */
  protected function prior(NodeInterface $feed): ?int {
    foreach (['field_published_prior', 'field_default_bias_score'] as $field) {
      if ($feed->hasField($field) && !$feed->get($field)->isEmpty()) {
        return BiasScore::normalize($feed->get($field)->value);
      }
    }
    return NULL;
  }

  /**
   * The registry outlet whose feed host matches the URL's host, or ''.
   */
  protected function outletForUrl(string $url): string {
    $host = preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));
    $storage = $this->entityTypeManager->getStorage('node');
    foreach ($storage->loadByProperties(['type' => 'feed_source']) as $feed) {
      $feedHost = preg_replace('/^(www|rss|feeds|moxie|api)\./', '', (string) parse_url((string) $feed->get('field_feed_url')->value, PHP_URL_HOST));
      if ($feedHost !== '' && str_ends_with((string) $host, $feedHost)) {
        return trim((string) ($feed->get('field_media_outlet')->value ?: $feed->label()));
      }
    }
    return '';
  }

  /**
   * Loads the prompt under test (by id, or the enabled one).
   */
  protected function loadPrompt(?string $id, string $operation): AiPromptInterface {
    $prompt = $id
      ? $this->entityTypeManager->getStorage('maemgaba_prompt')->load($id)
      : $this->analyzer->getPrompt($operation);
    if (!$prompt instanceof AiPromptInterface) {
      throw new \RuntimeException("No prompt for {$operation}" . ($id ? " (id {$id})" : ' (none enabled)') . '.');
    }
    return $prompt;
  }

  /**
   * The paraphrased prompt for run B.
   *
   * '' = "<A id>_paraphrase" if it exists, else run A's prompt again (pure
   * sampling instability); 'none' = run A's prompt again; any other value
   * is a prompt id.
   */
  protected function paraphrasePrompt(AiPromptInterface $promptA, string $id): AiPromptInterface {
    $storage = $this->entityTypeManager->getStorage('maemgaba_prompt');
    if ($id === 'none') {
      return $promptA;
    }
    $prompt = $storage->load($id !== '' ? $id : $promptA->id() . '_paraphrase');
    if ($prompt instanceof AiPromptInterface) {
      return $prompt;
    }
    if ($id !== '') {
      throw new \RuntimeException("Paraphrase prompt '{$id}' not found.");
    }
    return $promptA;
  }

  /**
   * Token usage and estimated cost of calibration calls since $since.
   */
  protected function cost(int $since): array {
    $query = $this->database->select('maemgaba_ai_call_log', 'l');
    $query->condition('l.context_type', self::CONTEXT_TYPE);
    $query->condition('l.created', $since, '>=');
    $query->addField('l', 'provider');
    $query->addField('l', 'model');
    $query->addExpression('COUNT(*)', 'attempts');
    $query->addExpression('SUM(CASE WHEN l.success = 0 THEN 1 ELSE 0 END)', 'failures');
    $query->addExpression('SUM(l.input_tokens)', 'input_tokens');
    $query->addExpression('SUM(l.output_tokens)', 'output_tokens');
    $query->groupBy('l.provider');
    $query->groupBy('l.model');
    $out = [
      'attempts' => 0,
      'failures' => 0,
      'input_tokens' => 0,
      'output_tokens' => 0,
      'estimated_usd' => 0.0,
      'models' => [],
    ];
    foreach ($query->execute() as $row) {
      $out['attempts'] += (int) $row->attempts;
      $out['failures'] += (int) $row->failures;
      $out['input_tokens'] += (int) $row->input_tokens;
      $out['output_tokens'] += (int) $row->output_tokens;
      $out['estimated_usd'] += $this->pricing->estimateUsd((string) $row->provider, (string) $row->model, (int) $row->input_tokens, (int) $row->output_tokens);
      $out['models'][] = $row->provider . '/' . $row->model;
    }
    $out['estimated_usd'] = round($out['estimated_usd'], 4);
    return $out;
  }

  /**
   * The private cache directory, created on demand.
   */
  protected function cacheDir(): string {
    $scheme = $this->streamWrappers->isValidScheme('private') ? 'private' : 'temporary';
    $dir = $scheme . '://maemgaba-calibration';
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    return $dir;
  }

  /**
   * Reads a URL's cache entry.
   */
  protected function cacheRead(string $url): array {
    $file = $this->cacheDir() . '/' . sha1($url) . '.json';
    if (!is_file($file)) {
      return [];
    }
    $data = json_decode((string) file_get_contents($file), TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * Merges data into a URL's cache entry.
   */
  protected function cacheWrite(string $url, array $data): void {
    $file = $this->cacheDir() . '/' . sha1($url) . '.json';
    $data = $data + $this->cacheRead($url) + ['url' => $url];
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

}

<?php

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\maemgaba_core\BiasScore;
use Drupal\maemgaba_core\TextStats;
use GuzzleHttp\ClientInterface;
use fivefilters\Readability\Readability;
use fivefilters\Readability\Configuration;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service to manage automatic feed harvesting for the News Engine.
 *
 * With maemgaba_core.settings:partial_text.min_words > 0, the
 * stored body is the first of: the article page (Readability), the feed's
 * content:encoded, that reaches min_words; otherwise the feed abstract
 * (headline + abstract only = partial text, see extractArticle()). The page
 * is only fetched when robots.txt allows it (RobotsTxtPolicy) and always
 * with the site's User-Agent (http_user_agent). With min_words unset
 * (the pre-P8 behaviour), extraction is unchanged: page via Readability, else
 * the
 * page's stripped text.
 *
 * Two registry flags travel with each feed (feed_source fields, set from
 * feeds.yml by FeedSeeder): field_section (news/opinion) is stamped on the
 * queue item and from there on the card; field_partial_text marks a feed
 * whose pages are known to be unusable (paywall, timeout, Readability
 * failure), so its articles go straight to headline + abstract without a
 * doomed page fetch.
 */
class IngestionEngine {

  /**
   * User-Agent used when http_user_agent is unset (pre-P8 behaviour).
   */
  public const LEGACY_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)';

  /**
   * RSS content module namespace (content:encoded).
   */
  protected const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

  public function __construct(
    protected ClientInterface $httpClient,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected PipelineIdentity $pipelineIdentity,
    protected ConfigFactoryInterface $configFactory,
    protected RobotsTxtPolicy $robots,
  ) {}

  /**
   * The User-Agent for every feed and article request.
   */
  public function userAgent(): string {
    $ua = trim((string) $this->configFactory->get('maemgaba_core.settings')->get('http_user_agent'));
    return $ua !== '' ? $ua : self::LEGACY_USER_AGENT;
  }

  /**
   * Parses a feed into items: link, title, abstract, feed_body.
   *
   * @return array
   *   List of ['link' => string, 'title' => string, 'abstract' => string
   *   (plain text), 'feed_body' => string (HTML, '' if the feed has none)].
   *
   * @throws \Exception
   *   On HTTP failure; returns [] when the XML isn't an RSS channel.
   */
  public function fetchFeedItems(string $feedUrl): array {
    // 15 s: some feeds (Washington Post national, 2026-09) take 9-11 s.
    $response = $this->httpClient->request('GET', $feedUrl, [
      'connect_timeout' => 5,
      'timeout' => 15,
      'headers' => ['User-Agent' => $this->userAgent()],
    ]);
    $xml = @simplexml_load_string($response->getBody()->getContents());
    if (!$xml || !isset($xml->channel->item)) {
      return [];
    }
    $items = [];
    foreach ($xml->channel->item as $item) {
      $content = $item->children(self::NS_CONTENT);
      $items[] = [
        'link' => trim((string) $item->link),
        'title' => trim((string) $item->title),
        'abstract' => TextStats::plain((string) $item->description),
        'feed_body' => isset($content->encoded) ? (string) $content->encoded : '',
      ];
    }
    return $items;
  }

  /**
   * Processes an RSS feed.
   *
   * Extracts the plain text and pushes it to the inbound queue.
   */
  public function harvestRssFeed(string $feedUrl, string $sourceName, ?string $section = NULL, bool $abstractOnly = FALSE): int {
    $harvestedCount = 0;
    $logger = $this->loggerFactory->get('maemgaba_ingestion');

    try {
      $items = $this->fetchFeedItems($feedUrl);
      if (!$items) {
        $logger->warning('Could not parse the RSS feed of @source', ['@source' => $sourceName]);
        return 0;
      }

      foreach ($items as $item) {
        // Limit harvesting to at most 8 new articles per portal per run
        // This avoids memory overflow and keeps the Drush command ultra fast.
        if ($harvestedCount >= 8) {
          break;
        }
        if ($item['link'] === '') {
          continue;
        }
        if ($this->queueArticle($item['link'], $sourceName, $item['title'], $logger, $item['abstract'], $item['feed_body'], $section, $abstractOnly)) {
          $harvestedCount++;
        }
      }
    }
    catch (\Exception $e) {
      $logger->error('Feed of @source failed: @msg', [
        '@source' => $sourceName,
        '@msg' => $e->getMessage(),
      ]);
    }

    return $harvestedCount;
  }

  /**
   * Queues a single manually-submitted URL, same path as RSS harvesting.
   *
   * @return int
   *   Created inbound_queue node id, or 0 if deduped/skipped.
   */
  public function enqueueUrl(string $url, string $sourceName): int {
    $logger = $this->loggerFactory->get('maemgaba_ingestion');
    return $this->queueArticle($url, $sourceName, NULL, $logger);
  }

  /**
   * Downloads, extracts and stages a single article link, deduping first.
   *
   * Shared by harvestRssFeed() (per RSS item) and enqueueUrl() (manual
   * single-URL submission).
   *
   * @return int
   *   Created inbound_queue node id, or 0 if deduped, skipped, or failed.
   */
  protected function queueArticle(string $link, string $sourceName, ?string $title, $logger, string $abstract = '', string $feedBody = '', ?string $section = NULL, bool $abstractOnly = FALSE): int {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Already in the inbound queue?
    $duplicateCheck = $nodeStorage->getQuery()
      ->condition('type', 'inbound_queue')
      ->condition('field_source_url', $link)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($duplicateCheck)) {
      return 0;
    }

    // Already turned into a card? The queue item is deleted after processing,
    // so without this check an already-published article would be reprocessed
    // and would generate a duplicate card.
    $cardCheck = $nodeStorage->getQuery()
      ->condition('type', 'card')
      ->condition('field_original_url', $link)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($cardCheck)) {
      return 0;
    }

    try {
      $article = $this->extractArticle($link, $title, $abstract, $feedBody, !$abstractOnly);
      $cleanBody = $article['body'];
      $resolvedTitle = $article['title'];

      $queueNode = $nodeStorage->create([
        'type' => 'inbound_queue',
        'uid' => $this->pipelineIdentity->uid(),
        'title' => $resolvedTitle ?: (string) t('Untitled article'),
        'field_source_url' => $link,
        'field_source_name' => $sourceName,
        'field_raw_html_body' => [
          'value' => $cleanBody,
          'format' => 'basic_html',
        ],
        // Keep unpublished as an internal control draft.
        'status' => 0,
      ]);
      if ($section !== NULL && $section !== '' && $queueNode->hasField('field_section')) {
        $queueNode->set('field_section', $section);
      }
      $queueNode->save();

      return (int) $queueNode->id();

    }
    catch (\Exception $itemException) {
      // If the article fails due to a timeout or parser error, log the warning
      // and return 0 — the caller decides whether this counts as "skipped".
      $logger->notice('Pulando link @link devido a erro: @msg', [
        '@link' => $link,
        '@msg' => $itemException->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Fetches and extracts one article: title, body, and whether it is partial.
   *
   * @param string $link
   *   Article URL.
   * @param string|null $title
   *   Title from the feed, if known.
   * @param string $abstract
   *   Plain-text feed abstract (RSS description), if any.
   * @param string $feedBody
   *   Full body carried by the feed (content:encoded), if any.
   * @param bool $fetchPage
   *   FALSE for feeds the registry marks partial_text: the page is not
   *   fetched (only used when partial_text.min_words is set).
   *
   * @return array
   *   ['title' => string, 'body' => string, 'partial' => bool, 'words' =>
   *   int, 'method' => 'page'|'feed_body'|'abstract'|'legacy', 'note' =>
   *   string (why the page wasn't used, '' if it was)].
   *
   * @throws \Exception
   *   Only in legacy mode (min_words unset), when the page can't be fetched —
   *   the pre-P8 contract queueArticle() relies on to skip the item.
   */
  public function extractArticle(string $link, ?string $title, string $abstract = '', string $feedBody = '', bool $fetchPage = TRUE): array {
    $min = (int) $this->configFactory->get('maemgaba_core.settings')->get('partial_text.min_words');
    $resolvedTitle = (string) $title;

    if ($min <= 0) {
      $html = $this->fetchPage($link);
      $readability = new Readability(new Configuration());
      if ($readability->parse($html, $link)) {
        $body = (string) $readability->getContent();
        $resolvedTitle = $resolvedTitle ?: (string) $readability->getTitle();
      }
      else {
        $body = strip_tags($html);
      }
      return $this->extracted($resolvedTitle, $body, FALSE, 'legacy', '');
    }

    $note = '';
    $pageBody = '';
    if (!$fetchPage) {
      $note = 'registry marks the feed partial_text';
    }
    elseif (!$this->robots->allowed($link, $this->userAgent())) {
      $note = 'robots.txt disallows the page';
    }
    else {
      try {
        $html = $this->fetchPage($link);
        $readability = new Readability(new Configuration());
        if ($readability->parse($html, $link)) {
          $pageBody = (string) $readability->getContent();
          $resolvedTitle = $resolvedTitle ?: (string) $readability->getTitle();
        }
        else {
          $note = 'Readability found no article';
        }
      }
      catch (\Throwable $e) {
        $note = 'fetch failed: ' . mb_substr($e->getMessage(), 0, 120);
      }
    }

    foreach (['page' => $pageBody, 'feed_body' => $feedBody] as $method => $candidate) {
      if (TextStats::wordCount($candidate) >= $min) {
        return $this->extracted($resolvedTitle, $candidate, FALSE, $method, $method === 'page' ? '' : $note);
      }
    }
    if ($note === '' && $pageBody !== '') {
      $note = sprintf('page body only %d words', TextStats::wordCount($pageBody));
    }
    return $this->extracted($resolvedTitle, $abstract, TRUE, 'abstract', $note);
  }

  /**
   * The extractArticle() result array.
   */
  protected function extracted(string $title, string $body, bool $partial, string $method, string $note): array {
    return [
      'title' => $title,
      'body' => $body,
      'partial' => $partial,
      'words' => TextStats::wordCount($body),
      'method' => $method,
      'note' => $note,
    ];
  }

  /**
   * GETs an article page with the site's User-Agent and tight timeouts.
   */
  protected function fetchPage(string $link): string {
    $response = $this->httpClient->request('GET', $link, [
      'connect_timeout' => 3,
      'timeout' => 5,
      'headers' => ['User-Agent' => $this->userAgent()],
    ]);
    return $response->getBody()->getContents();
  }

  /**
   * Harvests every active feed source into the queue.
   *
   * There is no built-in fallback list: a site with no active feed_source
   * harvests nothing (load its registry with maemgaba:seed-feeds).
   *
   * @return array
   *   ['results' => [['name' => string, 'count' => int], ...],
   *    'total' => int].
   */
  public function harvestAll(): array {
    $feeds = $this->loadFeedSources();

    $results = [];
    $total = 0;
    foreach ($feeds as $feed) {
      $count = $this->harvestSingle($feed);
      $results[] = ['name' => $feed['name'], 'count' => $count];
      $total += $count;
    }

    return ['results' => $results, 'total' => $total];
  }

  /**
   * Returns the active feed sources.
   *
   * @return array
   *   List of ['name' => string, 'url' => string, 'score' => int|null].
   */
  public function getActiveFeeds(): array {
    return $this->loadFeedSources();
  }

  /**
   * Harvests a single feed: seeds its source term, then queues its articles.
   *
   * @param array $feed
   *   Keys: 'name' => string, 'url' => string, 'score' => int|null,
   *   optional 'section' => string|null, 'partial_text' => bool.
   *
   * @return int
   *   Number of new queue entries created.
   */
  public function harvestSingle(array $feed): int {
    // Ensure the sources taxonomy term exists with the declared bias, so the
    // Fontes page can measure divergence against the outlet's stated line.
    $this->ensureSourceTerm($feed['name'], $feed['score'] ?? NULL);
    return $this->harvestRssFeed($feed['url'], $feed['name'], $feed['section'] ?? NULL, !empty($feed['partial_text']));
  }

  /**
   * Loads active feed sources from the feed_source content type.
   *
   * @return array
   *   List of ['name' => string, 'url' => string, 'score' => int|null,
   *   'section' => string|null, 'partial_text' => bool].
   */
  protected function loadFeedSources(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'feed_source')
      ->condition('status', 1)
      ->condition('field_active_testing', 1)
      ->sort('title')
      ->execute();

    $feeds = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $url = trim((string) $node->get('field_feed_url')->value);
      if ($url === '') {
        continue;
      }
      $name = trim((string) ($node->get('field_media_outlet')->value ?: $node->label()));
      $score = $node->hasField('field_default_bias_score')
        ? BiasScore::normalize($node->get('field_default_bias_score')->value) : NULL;
      $feeds[] = [
        'name' => $name,
        'url' => $url,
        'score' => $score,
        'section' => $node->hasField('field_section') ? ($node->get('field_section')->value ?: NULL) : NULL,
        'partial_text' => $node->hasField('field_partial_text') && (bool) $node->get('field_partial_text')->value,
      ];
    }
    return $feeds;
  }

  /**
   * Ensures a 'sources' taxonomy term exists, seeding its declared score.
   *
   * Never overwrites a score that already exists (respects manual edits).
   */
  protected function ensureSourceTerm(string $name, ?int $score): void {
    if ($name === '') {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $storage->loadByProperties(['name' => $name, 'vid' => 'sources']);

    if ($existing) {
      $term = reset($existing);
      if ($score !== NULL && $term->hasField('field_default_bias_score') && $term->get('field_default_bias_score')->isEmpty()) {
        $term->set('field_default_bias_score', $score)->save();
      }
      return;
    }

    $values = ['vid' => 'sources', 'name' => $name];
    if ($score !== NULL) {
      $values['field_default_bias_score'] = $score;
    }
    $storage->create($values)->save();
  }

}

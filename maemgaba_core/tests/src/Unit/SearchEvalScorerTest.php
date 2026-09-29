<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Unit;

use Drupal\maemgaba_core\EventSubscriber\EmbeddingDimensionSubscriber;
use Drupal\maemgaba_core\Search\SearchEvalScorer;
use Drupal\maemgaba_core\Service\EventSearch;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Search eval scoring, keyword terms and embedding truncation.
 */
#[Group('maemgaba_core')]
class SearchEvalScorerTest extends UnitTestCase {

  /**
   * A small query file.
   */
  protected function queries(): array {
    return SearchEvalScorer::parseQueries([
      'queries' => [
      ['id' => 'a', 'type' => 'paraphrase', 'query' => 'lawmakers avoid a shutdown', 'expected_nid' => 10],
      ['id' => 'b', 'type' => 'keyword', 'query' => 'Senate stopgap bill', 'expected_nid' => '20'],
      ['id' => 'c', 'type' => 'off_topic', 'query' => 'best sourdough starter', 'expected_nid' => NULL],
      ],
    ]);
  }

  /**
   * The file format is validated and normalised.
   */
  public function testParse(): void {
    $q = $this->queries();
    $this->assertCount(3, $q);
    $this->assertSame(20, $q[1]['expected_nid']);
    $this->assertNull($q[2]['expected_nid']);

    $this->expectException(\InvalidArgumentException::class);
    SearchEvalScorer::parseQueries(['queries' => [['query' => 'no expectation']]]);
  }

  /**
   * Duplicate ids are rejected.
   */
  public function testDuplicateIds(): void {
    $this->expectException(\InvalidArgumentException::class);
    SearchEvalScorer::parseQueries([
      'queries' => [
      ['id' => 'x', 'query' => 'one', 'expected_nid' => 1],
      ['id' => 'x', 'query' => 'two', 'expected_nid' => 2],
      ],
    ]);
  }

  /**
   * Hit@1, hit@3, off-topic and keyword verdicts, then the summary.
   */
  public function testScoreAndSummary(): void {
    [$a, $b, $c] = $this->queries();

    $ra = SearchEvalScorer::scoreQuery($a, [10, 11], [10, 11, 12], []);
    $this->assertTrue($ra['hit1']);
    $this->assertTrue($ra['hit3']);
    $this->assertTrue($ra['keyword_zero']);

    // Expected is third among shown: hit@3 only. Keyword found it.
    $rb = SearchEvalScorer::scoreQuery($b, [21, 22, 20], [21, 22, 20], [20, 99]);
    $this->assertFalse($rb['hit1']);
    $this->assertTrue($rb['hit3']);
    $this->assertSame(3, $rb['raw_rank']);
    $this->assertTrue($rb['keyword_found_expected']);

    // Off-topic with a result under the ceiling is junk.
    $rc = SearchEvalScorer::scoreQuery($c, [5], [5, 6], []);
    $this->assertFalse($rc['off_topic_correct']);
    $this->assertNull($rc['raw_rank']);
    $this->assertTrue(SearchEvalScorer::scoreQuery($c, [], [5, 6], [])['off_topic_correct']);

    // A listed duplicate of the story counts, and is flagged.
    $dup = SearchEvalScorer::parseQueries(['queries' => [['query' => 'x', 'expected_nid' => 10, 'also_accept' => [12]]]])[0];
    $rd = SearchEvalScorer::scoreQuery($dup, [12, 10], [12, 10], []);
    $this->assertTrue($rd['hit1']);
    $this->assertTrue($rd['hit1_via_duplicate']);
    $this->assertSame(1, $rd['raw_rank']);

    $rows = [
      $ra + ['type' => 'paraphrase', 'latency_ms' => 100.0],
      $rb + ['type' => 'keyword', 'latency_ms' => 300.0],
      $rc + ['type' => 'off_topic', 'latency_ms' => 200.0],
    ];
    $s = SearchEvalScorer::summarize($rows);
    $this->assertSame(2, $s['on_topic']);
    $this->assertSame(1, $s['hit1']);
    $this->assertSame(2, $s['hit3']);
    $this->assertSame(0.5, $s['hit1_rate']);
    $this->assertSame(0, $s['off_topic_correct']);
    $this->assertSame(200.0, $s['latency_mean_ms']);
    $this->assertSame(200.0, $s['latency_median_ms']);
    $this->assertSame(1, $s['by_type']['keyword']['hit3']);
  }

  /**
   * Keyword baseline terms and item-id parsing.
   */
  public function testKeywordTermsAndItemIds(): void {
    $this->assertSame(['senate', 'passes', 'bill'], EventSearch::keywordTerms('Senate passes a  bill!'));
    $this->assertSame(123, EventSearch::nidFromItemId('entity:node/123:en'));
    $this->assertNull(EventSearch::nidFromItemId('entity:user/5:en'));
  }

  /**
   * Truncation keeps the prefix and returns a unit vector.
   */
  public function testTruncate(): void {
    $v = EmbeddingDimensionSubscriber::truncate([3.0, 4.0, 12.0], 2);
    $this->assertEqualsWithDelta([0.6, 0.8], $v, 1e-12);
    $this->assertSame([0.0, 0.0], EmbeddingDimensionSubscriber::truncate([0.0, 0.0, 1.0], 2));
  }

}

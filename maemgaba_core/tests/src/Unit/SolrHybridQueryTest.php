<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Unit;

use Drupal\maemgaba_core\Search\SolrHybridQuery;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Keyword, vector and hybrid Solr query construction.
 */
#[Group('maemgaba_core')]
class SolrHybridQueryTest extends UnitTestCase {

  /**
   * Keyword mode leaves the query alone and adds nothing.
   */
  public function testKeyword(): void {
    $built = SolrHybridQuery::build('keyword', '+tm_title:"tariffs"', [0.1, 0.2], 'knns_embedding', 10, 0.8);
    $this->assertSame('+tm_title:"tariffs"', $built['q']);
    $this->assertSame([], $built['params']);
  }

  /**
   * Vector mode: similarity floor, literal without exponents.
   */
  public function testVector(): void {
    $built = SolrHybridQuery::build('vector', 'ignored', [0.5, -0.25, 1.0E-8, 0.0], 'knns_embedding', 10, 0.8);
    $this->assertSame('{!query v=$mg_vq}', $built['q']);
    $this->assertSame('{!vectorSimilarity f=knns_embedding minReturn=0.8}[0.5,-0.25,0,0]', $built['params']['mg_vq']);
    $this->assertArrayNotHasKey('mg_kw', $built['params']);
  }

  /**
   * Hybrid mode: union of keyword and weighted vector.
   */
  public function testHybrid(): void {
    $built = SolrHybridQuery::build('hybrid', '+tm_title:"tariffs"', [0.6, 0.8], 'knns_embedding', 10, 0.75);
    $this->assertSame('{!bool should=$mg_kw should=$mg_vb}', $built['q']);
    $this->assertSame('+tm_title:"tariffs"', $built['params']['mg_kw']);
    $this->assertSame('{!boost b=10 v=$mg_vq}', $built['params']['mg_vb']);
    $this->assertStringStartsWith('{!vectorSimilarity f=knns_embedding minReturn=0.75}[0.6,0.8]', $built['params']['mg_vq']);
  }

  /**
   * Bad mode or field name is refused.
   */
  public function testRejects(): void {
    $this->expectException(\InvalidArgumentException::class);
    SolrHybridQuery::build('vector', 'q', [0.1], 'knn} OR {!x', 10, 0.8);
  }

  /**
   * Unknown mode is refused.
   */
  public function testUnknownMode(): void {
    $this->expectException(\InvalidArgumentException::class);
    SolrHybridQuery::build('semantic', 'q', [0.1], 'knns_embedding', 10, 0.8);
  }

}

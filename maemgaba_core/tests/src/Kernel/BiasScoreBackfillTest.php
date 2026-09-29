<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\maemgaba_core\Migration\BiasScoreBackfill;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Legacy list → numeric score backfill, and the presave derivation.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class BiasScoreBackfillTest extends PipelineKernelTestBase {

  /**
   * Creates a node and returns its id.
   */
  protected function node(array $values): int {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->create($values + ['status' => 1]);
    $node->save();
    return (int) $node->id();
  }

  /**
   * Reloads a node or term bypassing the static/persistent entity cache.
   */
  protected function reload(string $entityType, int $id) {
    $storage = $this->container->get('entity_type.manager')->getStorage($entityType);
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

  /**
   * Existing data without a score (as on prod before this release).
   */
  public function testBackfillFromLegacyLists(): void {
    $cards = [];
    foreach (['left', 'center', 'right'] as $legacy) {
      $cards[$legacy] = $this->node(['type' => 'card', 'title' => "Card $legacy", 'field_article_bias' => $legacy]);
    }
    // A card that already carries an extreme score must keep it.
    $extreme = $this->node(['type' => 'card', 'title' => 'Extreme', 'field_bias_score' => -2]);
    $feed = $this->node(['type' => 'feed_source', 'title' => 'Feed', 'field_article_bias' => 'right']);
    $term = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')
      ->create(['vid' => 'sources', 'name' => 'Outlet', 'field_default_bias' => 'left']);
    $term->save();

    // Simulate pre-release rows: legacy set, score tables empty.
    $db = $this->container->get('database');
    foreach (['node__field_bias_score', 'node_revision__field_bias_score'] as $table) {
      $db->delete($table)->condition('entity_id', $extreme, '<>')->execute();
    }
    foreach ([
      'node__field_default_bias_score',
      'node_revision__field_default_bias_score',
      'taxonomy_term__field_default_bias_score',
      'taxonomy_term_revision__field_default_bias_score',
    ] as $table) {
      $db->truncate($table)->execute();
    }
    $this->assertNull($this->reload('node', $cards['left'])->get('field_bias_score')->value);

    $backfill = new BiasScoreBackfill($db, $this->container->get('cache.entity'));
    $counts = $backfill->run();
    $this->assertSame(3, $counts['node.card.node__field_bias_score']);
    $this->assertSame(3, $counts['node.card.node_revision__field_bias_score']);
    $this->assertSame(1, $counts['node.feed_source.node__field_default_bias_score']);
    $this->assertSame(1, $counts['taxonomy_term.sources.taxonomy_term__field_default_bias_score']);

    $this->assertSame(-1, (int) $this->reload('node', $cards['left'])->get('field_bias_score')->value);
    $this->assertSame(0, (int) $this->reload('node', $cards['center'])->get('field_bias_score')->value);
    $this->assertSame(1, (int) $this->reload('node', $cards['right'])->get('field_bias_score')->value);
    $this->assertSame(-2, (int) $this->reload('node', $extreme)->get('field_bias_score')->value);
    $this->assertSame(1, (int) $this->reload('node', $feed)->get('field_default_bias_score')->value);
    $this->assertSame(-1, (int) $this->reload('taxonomy_term', (int) $term->id())->get('field_default_bias_score')->value);

    // Idempotent: a second run inserts nothing.
    $this->assertSame([0], array_values(array_unique($backfill->run())));
  }

  /**
   * Score → legacy list on every save; legacy → score only when unscored.
   */
  public function testPresaveDerivesLegacyFromScore(): void {
    $expected = [-2 => 'left', -1 => 'left', 0 => 'center', 1 => 'right', 2 => 'right'];
    foreach ($expected as $score => $legacy) {
      $id = $this->node(['type' => 'card', 'title' => "Card $score", 'field_bias_score' => $score]);
      $this->assertSame($legacy, $this->reload('node', $id)->get('field_article_bias')->value, "score $score");
    }

    $id = $this->node(['type' => 'card', 'title' => 'Legacy writer', 'field_article_bias' => 'right']);
    $card = $this->reload('node', $id);
    $this->assertSame(1, (int) $card->get('field_bias_score')->value);

    // Re-scoring moves the legacy value with it; the score is canonical.
    $card->set('field_bias_score', -2)->save();
    $this->assertSame('left', $this->reload('node', $id)->get('field_article_bias')->value);

    $term = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')
      ->create(['vid' => 'sources', 'name' => 'Outlet', 'field_default_bias_score' => 2]);
    $term->save();
    $this->assertSame('right', $this->reload('taxonomy_term', (int) $term->id())->get('field_default_bias')->value);
  }

  /**
   * The pipeline stores score, confidence and capped evidence on the card.
   */
  public function testPipelineWritesScoreConfidenceEvidence(): void {
    $this->provisionPipelineUser();
    $this->queueItem('Article', 'https://example.com/a', 'Outlet A');
    $this->aiAnalyzer->method('classifyAndCluster')->willReturn([
      'bias_score' => 2,
      'bias' => 'right',
      'bias_confidence' => 0.75,
      'bias_evidence' => ['"quote one"', 'quote two'],
    ] + $this->analysis(NULL));

    $this->container->get('maemgaba_core.queue_processor')->processBatch(5);
    $cards = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['type' => 'card']);
    $card = reset($cards);
    $this->assertSame(2, (int) $card->get('field_bias_score')->value);
    $this->assertSame('right', $card->get('field_article_bias')->value);
    $this->assertEquals(0.75, (float) $card->get('field_bias_confidence')->value);
    $this->assertSame(['"quote one"', 'quote two'], array_column($card->get('field_bias_evidence')->getValue(), 'value'));
  }

}

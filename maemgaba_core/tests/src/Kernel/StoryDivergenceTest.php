<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The "Usually leans X, this story reads Y" divergence label.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class StoryDivergenceTest extends PipelineKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A five-bucket spectrum.
    $buckets = [];
    $labels = [
      'left' => 'Left',
      'lean_left' => 'Lean Left',
      'center' => 'Center',
      'lean_right' => 'Lean Right',
      'right' => 'Right',
    ];
    foreach ($labels as $key => $label) {
      $score = count($buckets) - 2;
      $buckets[] = [
        'key' => $key,
        'label' => $label,
        'short_label' => $label,
        'min_score' => $score,
        'max_score' => $score,
        'color' => '#999999',
      ];
    }
    $this->config('maemgaba_core.spectrum')->set('buckets', $buckets)->save();
  }

  /**
   * The comparison itself: thresholds, buckets, label.
   */
  public function testCompare(): void {
    $service = $this->container->get('maemgaba_core.story_divergence');
    $this->assertNull($service->compare(NULL, 1), 'no prior');
    $this->assertNull($service->compare(2, NULL), 'unscored card');
    $this->assertNull($service->compare(-1, -1));

    $result = $service->compare(2, 0);
    $this->assertSame(-2, $result['delta']);
    $this->assertSame('right', $result['usual_key']);
    $this->assertSame('center', $result['story_key']);
    $this->assertSame('Usually leans Right, this story reads Center', $result['label']);
    $this->assertSame('Usually leans Lean Left, this story reads Center', $service->compare(-1, 0)['label'], '|Δ| = 1 counts');

    $this->config('maemgaba_core.settings')
      ->set('story_divergence', ['min_delta' => 2, 'label' => 'Usually @usual; here @story'])
      ->save();
    $this->assertNull($service->compare(-1, 0), 'below min_delta');
    $this->assertSame('Usually Lean Left; here Lean Right', $service->compare(-1, 1)['label']);
  }

  /**
   * Same score gap, same bucket on a 3-bucket site: nothing to say.
   */
  public function testSameBucketIsSilent(): void {
    $this->config('maemgaba_core.spectrum')->setData([
      'buckets' => [
        ['key' => 'left', 'label' => 'Left', 'min_score' => -2, 'max_score' => -1],
        ['key' => 'center', 'label' => 'Center', 'min_score' => 0, 'max_score' => 0],
        ['key' => 'right', 'label' => 'Right', 'min_score' => 1, 'max_score' => 2],
      ],
    ])->save();
    $service = $this->container->get('maemgaba_core.story_divergence');
    $this->assertNull($service->compare(-2, -1));
    $this->assertSame('Left', $service->compare(-1, 0)['usual']);
  }

  /**
   * The prior comes from the card's own feed: outlet + section.
   */
  public function testForCardAndTemplateData(): void {
    $etm = $this->container->get('entity_type.manager');
    $nodes = $etm->getStorage('node');
    foreach ([['news', -1], ['opinion', -2]] as [$section, $prior]) {
      $nodes->create([
        'type' => 'feed_source',
        'title' => "Times $section",
        'field_media_outlet' => 'The Times',
        'field_feed_url' => "https://times.example/$section.xml",
        'field_section' => $section,
        'field_published_prior' => $prior,
        'status' => 1,
      ])->save();
    }
    $term = $etm->getStorage('taxonomy_term')->create(['vid' => 'sources', 'name' => 'The Times']);
    $term->save();
    $event = $nodes->create(['type' => 'event', 'title' => 'Event', 'status' => 1]);
    $event->save();
    $card = function (string $url, int $score, ?string $section) use ($nodes, $term, $event) {
      $node = $nodes->create([
        'type' => 'card',
        'title' => $url,
        'field_parent_event' => $event->id(),
        'field_source' => $term->id(),
        'field_bias_score' => $score,
        'field_section' => $section,
        'field_original_url' => $url,
        'status' => 1,
      ]);
      $node->save();
      return $node;
    };
    $news = $card('https://times.example/news/1', 1, 'news');
    $unsectioned = $card('https://times.example/news/2', -1, NULL);
    $column = $card('https://times.example/opinion/1', -1, 'opinion');

    $service = $this->container->get('maemgaba_core.story_divergence');
    $this->assertSame(-1, $service->priorFor($news));
    $this->assertSame(-1, $service->priorFor($unsectioned), 'no section = news');
    $this->assertSame(-2, $service->priorFor($column), 'opinion feed has its own prior');
    $this->assertSame('Usually leans Lean Left, this story reads Lean Right', $service->forCard($news)['label']);
    $this->assertNull($service->forCard($unsectioned));
    $this->assertSame('Lean Left', $service->forCard($column)['story']);

    $variables = ['node' => $event, 'view_mode' => 'full', 'elements' => []];
    $this->container->get('maemgaba_core.event_preprocessor')->preprocessNode($variables);
    $buckets = array_column($variables['buckets'], NULL, 'key');
    $this->assertSame('Lean Right', $buckets['lean_right']['cards'][0]['divergence']['story']);
    $this->assertNull($buckets['lean_left']['cards'][0]['divergence']);
    $this->assertSame(-2, $variables['opinion_cards'][0]['divergence']['prior']);
    $this->assertContains('node_list:feed_source', $variables['#cache']['tags']);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\maemgaba_core\Migration\ModelTextDecode;
use PHPUnit\Framework\Attributes\Group;

/**
 * The v0.8.1 repair of text that HostnameFilter stored entity-encoded.
 */
#[Group('maemgaba_core')]
class ModelTextDecodeTest extends PipelineKernelTestBase {

  /**
   * Decodes every target, keeps "changed" and revisions, runs once.
   */
  public function testDecodesStoredModelText(): void {
    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    $event = $nodes->create([
      'type' => 'event',
      'title' => 'Texas A&amp;M vs. LSU',
      'field_neutral_summary' => ['value' => 'The S&amp;P 500 rose; 1 &lt; 2 &amp; 3 &gt; 2.', 'format' => 'basic_html'],
      'field_common_points' => ['Both say &quot;soon&quot;', 'Clean point'],
      'field_disputed_points' => ['Who&#039;s next'],
      // In step with its one card, so the card's rollup doesn't re-save it.
      'field_card_count' => 1,
      'changed' => 1_700_000_000,
    ]);
    $event->save();
    $card = $nodes->create([
      'type' => 'card',
      'title' => 'Plain title',
      'status' => 1,
      'field_parent_event' => $event->id(),
      'field_framing_line' => 'Uses a Q&amp;A format',
      // Decoding this one would put a real tag into a basic_html field.
      'field_micro_summary' => ['value' => 'Explains &lt;b&gt; tags', 'format' => 'basic_html'],
      'changed' => 1_700_000_000,
    ]);
    $card->save();
    $untouched = $nodes->create(['type' => 'event', 'title' => 'Nothing & nobody', 'changed' => 1_700_000_000]);
    $untouched->save();
    $revisions = (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {node_revision}')->fetchField();

    $decode = new ModelTextDecode($this->container->get('database'), $this->container->get('entity_type.manager'));
    $result = $decode->run();

    $this->assertSame([
      'title' => 1,
      'field_neutral_summary' => 1,
      'field_common_points' => 1,
      'field_disputed_points' => 1,
      'field_framing_line' => 1,
      'field_micro_summary' => 1,
    ], $result['before']);
    $this->assertSame([
      'title' => 1,
      'field_neutral_summary' => 1,
      'field_common_points' => 1,
      'field_disputed_points' => 1,
      'field_framing_line' => 1,
    ], $result['values']);
    $this->assertSame(2, $result['nodes']);
    $this->assertSame([$card->id() . ' field_micro_summary'], $result['skipped']);
    $this->assertSame(0, $result['after']['title']);
    $this->assertSame(1, $result['after']['field_micro_summary']);

    $nodes->resetCache();
    $event = $nodes->load($event->id());
    $card = $nodes->load($card->id());
    $this->assertSame('Texas A&M vs. LSU', $event->getTitle());
    $this->assertSame('The S&P 500 rose; 1 < 2 & 3 > 2.', $event->get('field_neutral_summary')->value);
    $this->assertSame('basic_html', $event->get('field_neutral_summary')->format);
    $this->assertSame(['Both say "soon"', 'Clean point'], array_column($event->get('field_common_points')->getValue(), 'value'));
    $this->assertSame("Who's next", $event->get('field_disputed_points')->value);
    $this->assertSame('Uses a Q&A format', $card->get('field_framing_line')->value);
    $this->assertSame('Explains &lt;b&gt; tags', $card->get('field_micro_summary')->value);
    $this->assertSame('Nothing & nobody', $nodes->load($untouched->id())->getTitle());

    // A data repair, not an edit: same "changed" time, no new revision, and
    // the current revision carries the new text.
    $this->assertSame(1_700_000_000, (int) $event->getChangedTime());
    $this->assertSame($revisions, (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {node_revision}')->fetchField());
    $this->assertSame('Texas A&M vs. LSU', $nodes->loadRevision($event->getRevisionId())->getTitle());

    // Idempotent: only the skipped value is still counted.
    $again = $decode->run();
    $this->assertSame(0, $again['nodes']);
    $this->assertSame([], $again['values']);
  }

  /**
   * Only the listed entities trigger a decode; markup is refused in HTML.
   */
  public function testDecode(): void {
    $this->assertNull(ModelTextDecode::decode('No entities & no change', FALSE));
    $this->assertNull(ModelTextDecode::decode('Only &hellip; here', FALSE));
    $this->assertSame('A&M … done', ModelTextDecode::decode('A&amp;M &hellip; done', FALSE));
    $this->assertSame('<b>', ModelTextDecode::decode('&lt;b&gt;', FALSE));
    $this->assertNull(ModelTextDecode::decode('&lt;b&gt;', TRUE));
    $this->assertSame('1 < 2', ModelTextDecode::decode('1 &lt; 2', TRUE));
    $this->assertSame('<p>A&B</p>', ModelTextDecode::decode('<p>A&amp;B</p>', TRUE));
  }

}

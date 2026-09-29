<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Unit;

use Drupal\maemgaba_core\Syndication\MinHash;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * MinHash fingerprints on the three syndication fixtures.
 */
#[Group('maemgaba_core')]
class MinHashTest extends UnitTestCase {

  /**
   * Reads a fixture text.
   */
  protected function fixture(string $name): string {
    return (string) file_get_contents(__DIR__ . '/../../fixtures/syndication/' . $name . '.txt');
  }

  /**
   * Identical texts agree everywhere; signatures survive pack/unpack.
   */
  public function testIdenticalAndStorage(): void {
    $a = MinHash::fingerprint($this->fixture('wire_original'));
    $this->assertCount(MinHash::HASHES, $a['signature']);
    $this->assertGreaterThan(250, $a['shingles']);
    $b = MinHash::fingerprint(strtoupper($this->fixture('wire_original')) . ' ');
    $this->assertSame(1.0, MinHash::jaccard($a['signature'], $b['signature']), 'case and spacing ignored');
    $this->assertSame($a['signature'], MinHash::unpack(MinHash::pack($a['signature'])));
    $this->assertSame([], MinHash::fingerprint('four words only here')['signature']);
  }

  /**
   * Trimmed reprint: caught by containment; independent story: far below.
   */
  public function testFixtures(): void {
    $original = MinHash::fingerprint($this->fixture('wire_original'));
    $reprint = MinHash::fingerprint($this->fixture('wire_reprint'));
    $other = MinHash::fingerprint($this->fixture('same_event_independent'));

    $j = MinHash::jaccard($original['signature'], $reprint['signature']);
    $c = MinHash::containment($j, $original['shingles'], $reprint['shingles']);
    // Three paragraphs cut and boilerplate added: Jaccard alone would miss
    // it, containment (default threshold 0.85) does not.
    $this->assertGreaterThan(0.55, $j);
    $this->assertLessThan(0.8, $j);
    $this->assertGreaterThanOrEqual(0.85, $c);

    $j2 = MinHash::jaccard($original['signature'], $other['signature']);
    $this->assertLessThan(0.1, $j2);
    $this->assertLessThan(0.2, MinHash::containment($j2, $original['shingles'], $other['shingles']));
  }

  /**
   * A light copy edit of the whole text stays above the Jaccard threshold.
   */
  public function testLightEdit(): void {
    $text = $this->fixture('wire_original');
    $edited = str_replace(['on Tuesday', 'more than', 'said on the Senate floor'], ['Tuesday', 'over', 'said'], $text);
    $j = MinHash::jaccard(MinHash::fingerprint($text)['signature'], MinHash::fingerprint($edited)['signature']);
    $this->assertGreaterThanOrEqual(0.8, $j);
  }

}

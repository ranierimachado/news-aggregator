<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Unit;

use Drupal\maemgaba_core\BiasScore;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The shared −2..+2 scale helpers.
 */
#[Group('maemgaba_core')]
class BiasScoreTest extends UnitTestCase {

  /**
   * Stored values: only in-range integers (or their strings) are scores.
   */
  public function testNormalize(): void {
    $this->assertSame(-2, BiasScore::normalize(-2));
    $this->assertSame(1, BiasScore::normalize('1'));
    $this->assertSame(-1, BiasScore::normalize(' -1 '));
    $this->assertSame(0, BiasScore::normalize(0.0));
    $this->assertNull(BiasScore::normalize(3));
    $this->assertNull(BiasScore::normalize('left'));
    $this->assertNull(BiasScore::normalize(NULL));
    $this->assertNull(BiasScore::normalize(0.5));
  }

  /**
   * Model output: integers are clamped, anything else is dropped.
   */
  public function testFromModel(): void {
    $this->assertSame(2, BiasScore::fromModel(5));
    $this->assertSame(-2, BiasScore::fromModel('-3'));
    $this->assertSame(1, BiasScore::fromModel(1.0));
    $this->assertNull(BiasScore::fromModel(1.5));
    $this->assertNull(BiasScore::fromModel('right'));
    $this->assertNull(BiasScore::fromModel(NULL));
  }

  /**
   * Legacy mapping: never an extreme when backfilling; sign when deriving.
   */
  public function testLegacyMapping(): void {
    $this->assertSame(-1, BiasScore::fromLegacy('left'));
    $this->assertSame(0, BiasScore::fromLegacy('center'));
    $this->assertSame(1, BiasScore::fromLegacy('right'));
    $this->assertNull(BiasScore::fromLegacy(''));
    $this->assertNull(BiasScore::fromLegacy(NULL));
    $this->assertSame(
      ['left', 'left', 'center', 'right', 'right'],
      array_map([BiasScore::class, 'toLegacy'], [-2, -1, 0, 1, 2]),
    );
  }

  /**
   * Confidence clamps to 0..1; evidence keeps 3 trimmed, capped quotes.
   */
  public function testConfidenceAndEvidence(): void {
    $this->assertSame(1.0, BiasScore::normalizeConfidence(1.7));
    $this->assertSame(0.0, BiasScore::normalizeConfidence(-0.2));
    $this->assertSame(0.8, BiasScore::normalizeConfidence('0.8'));
    $this->assertNull(BiasScore::normalizeConfidence('high'));

    $quotes = BiasScore::normalizeEvidence([' a ', '', 42, 'b', 'c', 'd']);
    $this->assertSame(['a', 'b', 'c'], $quotes);
    $this->assertSame([], BiasScore::normalizeEvidence('not a list'));
    $long = BiasScore::normalizeEvidence([str_repeat('x', 900)]);
    $this->assertSame(BiasScore::MAX_EVIDENCE_LENGTH, mb_strlen($long[0]));
  }

}

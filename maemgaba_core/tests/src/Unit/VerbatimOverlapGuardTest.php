<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Unit;

use Drupal\maemgaba_core\Service\VerbatimOverlapGuard;
use Drupal\maemgaba_core\TextStats;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The verbatim-overlap guard and the word helpers it relies on.
 */
#[Group('maemgaba_core')]
class VerbatimOverlapGuardTest extends UnitTestCase {

  /**
   * A source article used by several cases.
   */
  protected const SOURCE = '<p>The Senate voted 52 to 48 on Tuesday to approve the stopgap spending bill, sending it to the president.</p><p>Democrats objected to the border provisions.</p>';

  /**
   * An 8-word run copied from the source is caught; 7 words are not.
   */
  public function testRunLengthThreshold(): void {
    $copied8 = 'Coverage agrees that the senate voted 52 to 48 on tuesday to approve funding.';
    $this->assertSame('the senate voted 52 to 48 on tuesday', VerbatimOverlapGuard::findRun([$copied8], [self::SOURCE], 8));

    // Only seven consecutive source words ("senate voted 52 to 48 on tuesday").
    $copied7 = 'Outlets agree the upper chamber: Senate voted 52 to 48 on Tuesday, then adjourned.';
    $this->assertNull(VerbatimOverlapGuard::findRun([$copied7], [self::SOURCE], 8));
    $this->assertNotNull(VerbatimOverlapGuard::findRun([$copied7], [self::SOURCE], 7));
  }

  /**
   * Case, punctuation and HTML don't hide a copied run.
   */
  public function testNormalisation(): void {
    $shouted = 'THE SENATE — VOTED 52 TO 48, ON TUESDAY, TO APPROVE THE STOPGAP!';
    $this->assertNotNull(VerbatimOverlapGuard::findRun([$shouted], [self::SOURCE], 8));
  }

  /**
   * A run spanning two paragraphs of the source still counts.
   */
  public function testAcrossParagraphs(): void {
    $candidate = 'sending it to the president democrats objected to the border provisions';
    $this->assertNotNull(VerbatimOverlapGuard::findRun([$candidate], [self::SOURCE], 8));
  }

  /**
   * A paraphrase passes; any one of several candidates/sources can trip it.
   */
  public function testParaphraseAndMultipleInputs(): void {
    $paraphrase = 'Fox and the New York Post frame the stopgap as a Democratic retreat on border security.';
    $this->assertNull(VerbatimOverlapGuard::findRun([$paraphrase], [self::SOURCE], 8));

    $other = 'An unrelated source text about the weather in Denver this weekend and next week.';
    $this->assertNotNull(VerbatimOverlapGuard::findRun(
      [$paraphrase, 'the weather in denver this weekend and next week'],
      [self::SOURCE, $other],
      8,
    ));
  }

  /**
   * Degenerate inputs never match.
   */
  public function testEmpty(): void {
    $this->assertNull(VerbatimOverlapGuard::findRun(['anything at all here is fine'], [], 8));
    $this->assertNull(VerbatimOverlapGuard::findRun([''], [self::SOURCE], 8));
    $this->assertNull(VerbatimOverlapGuard::findRun(['short'], ['short'], 8));
  }

  /**
   * Word counting and capping.
   */
  public function testTextStats(): void {
    $this->assertSame(3, TextStats::wordCount('<p>Don’t</p><p>panic</p>'));
    $this->assertSame('One two. Three four.', TextStats::capWords('One two. Three four. Five six seven.', 5));
    $this->assertSame('One two three…', TextStats::capWords('One two three four five', 3));
    $this->assertSame('Short text.', TextStats::capWords('Short text.', 60));
  }

}

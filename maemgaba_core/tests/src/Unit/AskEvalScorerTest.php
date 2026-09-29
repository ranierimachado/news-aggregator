<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Unit;

use Drupal\maemgaba_core\Ask\AskEvalScorer;
use Drupal\maemgaba_core\Ask\AskSqlValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Result-set comparison of the "Ask the data" eval, and the prompt examples.
 */
#[Group('maemgaba_core')]
class AskEvalScorerTest extends UnitTestCase {

  /**
   * Row order, column order and number formatting don't matter.
   */
  public function testExactIgnoresOrderAndFormatting(): void {
    $reference = [['Fox News', '210'], ['Axios', '106']];
    $this->assertTrue(AskEvalScorer::matches($reference, [['106', 'Axios'], ['210.00', 'Fox News']]));
    $this->assertTrue(AskEvalScorer::matches([['-0.27', '0']], [['-0.270', '0.00']]));
    $this->assertTrue(AskEvalScorer::matches([], []));
    $this->assertFalse(AskEvalScorer::matches($reference, [['Fox News', '210']]), 'Missing row.');
    $this->assertFalse(AskEvalScorer::matches($reference, [['Fox News', '211'], ['Axios', '106']]), 'Wrong number.');
    $this->assertFalse(AskEvalScorer::matches([['49']], [['1']]));
    $this->assertFalse(AskEvalScorer::matches([['x']], [['x'], ['x']]), 'Duplicates count.');
    $this->assertFalse(AskEvalScorer::matches([['Fox News']], [['Fox News', '13']]), 'Extra column needs projection.');
  }

  /**
   * Projection accepts extra columns, never extra or missing rows.
   */
  public function testProjection(): void {
    $this->assertTrue(AskEvalScorer::matches([['Breitbart']], [['Breitbart', '13']], 'projection'));
    $this->assertTrue(AskEvalScorer::matches([['a'], ['b']], [['2', 'b', 'x'], ['1', 'a', 'y']], 'projection'));
    $this->assertFalse(AskEvalScorer::matches([['Breitbart']], [['New York Post', '13']], 'projection'));
    $this->assertFalse(AskEvalScorer::matches([['a']], [['a', '1'], ['b', '2']], 'projection'));
  }

  /**
   * Cell normalization.
   */
  public function testValue(): void {
    $this->assertSame('NULL', AskEvalScorer::value(NULL));
    $this->assertSame('0.5', AskEvalScorer::value('0.500'));
    $this->assertSame('0', AskEvalScorer::value('-0.001'));
    $this->assertSame('57.62', AskEvalScorer::value(57.6203));
    $this->assertSame('2026-09-28', AskEvalScorer::value(' 2026-09-28 '));
  }

  /**
   * File parsing rejects incomplete items.
   */
  public function testParse(): void {
    $set = AskEvalScorer::parse([
      'questions' => [['id' => 'q1', 'question' => 'How many?', 'reference_sql' => 'SELECT 1', 'match' => 'projection']],
      'hostile' => [['question' => 'drop the table']],
    ]);
    $this->assertSame('projection', $set['questions'][0]['match']);
    $this->assertSame('h1', $set['hostile'][0]['id']);
    $this->expectException(\InvalidArgumentException::class);
    AskEvalScorer::parse(['questions' => [['id' => 'q1', 'question' => 'How many?']]]);
  }

  /**
   * Summary numbers.
   */
  public function testSummarize(): void {
    $summary = AskEvalScorer::summarize([
      ['match' => TRUE, 'outcome' => 'answered', 'latency_ms' => 1000, 'cost_usd' => 0.004],
      ['match' => FALSE, 'outcome' => 'refused_validator', 'latency_ms' => 3000, 'cost_usd' => 0.002],
    ], [['outcome' => 'refused_model'], ['outcome' => 'answered']]);
    $this->assertSame(50.0, $summary['exact_rate']);
    $this->assertSame(1, $summary['validator_rejections']);
    $this->assertSame(2000, $summary['latency_mean_ms']);
    $this->assertSame(0.006, $summary['cost_usd']);
    $this->assertSame(1, $summary['hostile_refused']);
  }

  /**
   * Every SQL example in the shipped ask_sql prompt passes the validator.
   */
  public function testPromptExamplesPassTheValidator(): void {
    $prompt = Yaml::parseFile(dirname(__DIR__, 3) . '/config/install/maemgaba_core.maemgaba_prompt.ask_sql.yml');
    preg_match_all('/^\{.*\}$/m', $prompt['template'], $m);
    $this->assertCount(6, $m[0], 'Six few-shot examples.');
    $validator = new AskSqlValidator(AskSqlValidatorTest::views());
    $answerable = 0;
    foreach ($m[0] as $line) {
      $example = json_decode($line, TRUE);
      $this->assertIsArray($example, $line);
      if (!$example['answerable']) {
        $this->assertSame('', $example['sql']);
        continue;
      }
      $answerable++;
      $result = $validator->validate($example['sql']);
      $this->assertTrue($result->ok, "{$result->reason} ({$result->detail}): {$example['sql']}");
    }
    $this->assertSame(5, $answerable);
  }

}

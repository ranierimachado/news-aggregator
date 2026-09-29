<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\maemgaba_core\Entity\AiPrompt;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Blind prompts, partial text and the per-article output caps.
 *
 * Runs the real AiAnalyzerService with chat() stubbed, so the test sees the
 * exact prompt the provider would get and the post-processing of its answer.
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class BlindClassificationTest extends PipelineKernelTestBase {

  /**
   * The analyzer under test.
   */
  protected RecordingAnalyzer $analyzer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $c = $this->container;
    $this->analyzer = new RecordingAnalyzer(
      $c->get('ai.provider'),
      $c->get('entity_type.manager'),
      $c->get('logger.factory'),
      $c->get('maemgaba_core.ai_call_logger'),
      $c->get('config.factory'),
      $c->get('maemgaba_core.topics'),
      $c->get('maemgaba_core.spectrum'),
      $c->get('maemgaba_core.rubric'),
      $c->get('maemgaba_core.outlet_redactor'),
      $c->get('maemgaba_core.overlap_guard'),
    );

    $c->get('entity_type.manager')->getStorage('node')->create([
      'type' => 'feed_source',
      'title' => 'Northgate News',
      'field_media_outlet' => 'Northgate News',
      'field_feed_url' => 'https://feeds.northgatenews.example/latest.xml',
      'status' => 1,
    ])->save();

    $this->config('maemgaba_core.settings')
      ->set('partial_text', ['min_words' => 20, 'max_confidence' => 0.5])
      ->set('evidence_max_words', 5)
      ->set('blind_aliases', [['outlet' => 'Northgate News', 'terms' => ['Northgate News Digital']]])
      ->set('overlap_guard', ['enabled' => TRUE, 'ngram' => 8, 'retention_days' => 30])
      ->save();
    $this->config('maemgaba_core.rubric')->setData([
      'rubric_version' => '1.0',
      'rubric_date' => '2026-09-24',
      'center_definition' => 'Wire-service register.',
    ])->save();
  }

  /**
   * A blind prompt never shows the outlet; tokens and caps apply.
   */
  public function testBlindFullText(): void {
    $prompt = $this->prompt(TRUE);
    $body = '<p>Northgate News Digital has learned that the Senate will vote Tuesday on the stopgap bill, which Democrats say shortchanges disaster relief while Republicans call it a clean extension. Read more at northgatenews.example.</p>';
    $this->analyzer->reply = [
      'bias_score' => 1,
      'bias_confidence' => 0.9,
      'bias_evidence' => ['Republicans call it a clean extension of funding'],
      'framing_line' => 'Frames the bill as a routine extension and gives Republicans the last word.',
      'partial_text' => TRUE,
    ];

    $out = $this->analyzer->classifyAndCluster($body, [], $prompt, [], [
      'headline' => 'Northgate News: Senate to vote',
      'outlet' => 'Northgate News',
    ]);

    $sent = $this->analyzer->lastUserPrompt;
    $this->assertStringNotContainsStringIgnoringCase('northgate', $sent, 'outlet identity redacted from body and headline');
    $this->assertStringContainsString('[outlet] has learned', $sent);
    $this->assertStringContainsString('HEADLINE: [outlet]: Senate to vote', $sent);
    $this->assertStringContainsString('STATUS: FULL TEXT', $sent);
    $this->assertStringContainsString('RUBRIC v1.0 (2026-09-24)', $sent);
    // Label from the engine's default three-bucket spectrum.
    $this->assertStringContainsString('Example 1 — score +1 (Right): Leans on business groups.', $sent);
    $this->assertStringNotContainsString('example.com/anchor', $sent, 'anchor provenance is never sent');

    // partial_text is computed, not trusted; evidence capped at 5 words.
    $this->assertFalse($out['partial_text']);
    $this->assertSame(0.9, $out['bias_confidence']);
    $this->assertSame(['Republicans call it a clean…'], $out['bias_evidence']);
    $this->assertSame('Frames the bill as a routine extension and gives Republicans the last word.', $out['framing_line']);
  }

  /**
   * The same prompt with blind off keeps the outlet name.
   */
  public function testNotBlind(): void {
    $this->analyzer->reply = ['bias_score' => 0, 'bias_confidence' => 0.5, 'bias_evidence' => [], 'framing_line' => ''];
    $this->analyzer->classifyAndCluster('Northgate News Digital reports the vote.', [], $this->prompt(FALSE), [], ['outlet' => 'Northgate News']);
    $this->assertStringContainsString('Northgate News Digital reports', $this->analyzer->lastUserPrompt);
  }

  /**
   * Short body = partial: notice in the prompt, confidence capped.
   */
  public function testPartialText(): void {
    $this->analyzer->reply = [
      'bias_score' => -1,
      'bias_confidence' => 0.8,
      'bias_evidence' => [],
      'framing_line' => 'Frames the ruling around its cost to renters.',
      'partial_text' => FALSE,
    ];
    $source = ['headline' => 'Court rules', 'outlet' => 'Northgate News'];
    $out = $this->analyzer->classifyAndCluster('A short feed abstract about the ruling.', [], $this->prompt(TRUE), [], $source);
    $this->assertStringContainsString('STATUS: PARTIAL TEXT', $this->analyzer->lastUserPrompt);
    $this->assertStringContainsString('at or below 0.5', $this->analyzer->lastUserPrompt);
    $this->assertTrue($out['partial_text']);
    $this->assertSame(0.5, $out['bias_confidence']);
  }

  /**
   * Framing lines that quote or copy the article are dropped.
   */
  public function testFramingLinePolicy(): void {
    $body = 'The governor said the plan would cut property taxes for every homeowner in the state starting next year, aides said.';
    $this->analyzer->reply = [
      'bias_score' => 0,
      'bias_confidence' => 0.7,
      'bias_evidence' => [],
      'framing_line' => 'Says the plan would cut property taxes for every homeowner in the state.',
    ];
    $this->assertSame('', $this->analyzer->classifyAndCluster($body, [], $this->prompt(TRUE))['framing_line'], 'copied 8-word run');

    $this->analyzer->reply['framing_line'] = 'Relays the claim "cut property taxes for every homeowner" without a rebuttal.';
    $this->assertSame('', $this->analyzer->classifyAndCluster($body, [], $this->prompt(TRUE))['framing_line'], 'quoted clause');

    $this->analyzer->reply['framing_line'] = '“Relays the governor’s tax pitch without a rebuttal.”';
    $this->assertSame('Relays the governor’s tax pitch without a rebuttal.', $this->analyzer->classifyAndCluster($body, [], $this->prompt(TRUE))['framing_line'], 'wrapping quotes stripped');
  }

  /**
   * An unsaved classify_cluster prompt using every new token.
   */
  protected function prompt(bool $blind): AiPrompt {
    return AiPrompt::create([
      'id' => 'test_classify',
      'label' => 'Test',
      'operation' => 'classify_cluster',
      'system_prompt' => '[rubric]',
      'template' => "[rubric]\nHEADLINE: [headline]\nSTATUS: [text_status]\nTEXT: [article_text]\nEXAMPLES:\n[anchors]",
      'rubric_version' => '1.0',
      'blind' => $blind,
      'anchors' => [
        [
          'score' => 1,
          'outlet' => 'Example Outlet',
          'url' => 'https://example.com/anchor',
          'fetched' => '2026-09-24',
          'description' => 'Leans on business groups.',
          'quote' => 'job creators',
        ],
      ],
    ]);
  }

}

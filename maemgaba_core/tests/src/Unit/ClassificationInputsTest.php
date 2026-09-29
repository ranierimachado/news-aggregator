<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Unit;

use Drupal\maemgaba_core\AiAnalyzerService;
use Drupal\maemgaba_core\Service\OutletRedactor;
use Drupal\maemgaba_core\Service\RobotsTxtPolicy;
use Drupal\maemgaba_core\Service\RubricService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pure helpers behind blind classification, fetching policy and the rubric.
 */
#[Group('maemgaba_core')]
class ClassificationInputsTest extends UnitTestCase {

  /**
   * Own-outlet names and domains are redacted, whole words only.
   */
  public function testRedactTerms(): void {
    $text = 'Northgate News Digital has learned that NORTHGATE NEWS host X said… Northgate Steel shares rose. Read more at northgatenews.example/politics.';
    $terms = array_merge(OutletRedactor::variants('Northgate News'), ['Northgate News Digital', 'northgatenews.example']);
    $this->assertSame(
      '[outlet] has learned that [outlet] host X said… Northgate Steel shares rose. Read more at [outlet]/politics.',
      OutletRedactor::redactTerms($text, $terms),
    );

    $this->assertSame(['The Capital Ledger', 'Capital Ledger'], OutletRedactor::variants('The Capital Ledger'));
    $this->assertSame(
      'Copyright 2026 [outlet]. Subscribe to [outlet].',
      OutletRedactor::redactTerms(
        'Copyright 2026 The Capital Ledger. Subscribe to The Ledger.',
        ['The Capital Ledger', 'Capital Ledger', 'The Ledger'],
      ),
    );
    // Terms under three characters are ignored.
    $this->assertSame('AP reported', OutletRedactor::redactTerms('AP reported', ['AP']));
  }

  /**
   * Feed URLs map to the outlet domain; feed services map to nothing.
   */
  public function testDomainFromUrl(): void {
    $this->assertSame('capitalledger.example', OutletRedactor::domainFromUrl('https://rss.capitalledger.example/services/xml/rss/HomePage.xml'));
    $this->assertSame('northgatenews.example', OutletRedactor::domainFromUrl('https://feeds.northgatenews.example/latest.xml'));
    $this->assertSame('', OutletRedactor::domainFromUrl('http://feeds.feedburner.com/northgatenews'));
    $this->assertSame('', OutletRedactor::domainFromUrl('https://feeds.feedblitz.com/example-news'));
  }

  /**
   * RFC 9309: specific group over "*", longest match, Allow wins ties.
   */
  public function testRobots(): void {
    $robots = <<<TXT
      User-agent: *
      Disallow: /search
      Disallow: /*.json$
      Allow: /search/about

      User-agent: GPTBot
      User-agent: ExampleNews
      Disallow: /private/
      TXT;
    $this->assertSame('examplenews', RobotsTxtPolicy::productToken('ExampleNews/1.0 (+https://example.com/methodology)'));
    // ExampleNews has its own group, so the "*" rules don't apply to it.
    $this->assertTrue(RobotsTxtPolicy::isAllowed($robots, 'examplenews', '/search?q=x'));
    $this->assertFalse(RobotsTxtPolicy::isAllowed($robots, 'examplenews', '/private/a'));
    // Anyone else gets "*".
    $this->assertFalse(RobotsTxtPolicy::isAllowed($robots, 'otherbot', '/search?q=x'));
    $this->assertTrue(RobotsTxtPolicy::isAllowed($robots, 'otherbot', '/search/about'));
    $this->assertFalse(RobotsTxtPolicy::isAllowed($robots, 'otherbot', '/feed/data.json'));
    $this->assertTrue(RobotsTxtPolicy::isAllowed($robots, 'otherbot', '/feed/data.json?x=1'));
    $this->assertTrue(RobotsTxtPolicy::isAllowed($robots, 'otherbot', '/politics/story'));
    // Empty file / blanket block.
    $this->assertTrue(RobotsTxtPolicy::isAllowed('', 'examplenews', '/a'));
    $this->assertFalse(RobotsTxtPolicy::isAllowed("User-agent: *\nDisallow: /", 'examplenews', '/a'));
    $this->assertTrue(RobotsTxtPolicy::isAllowed("User-agent: *\nDisallow:", 'examplenews', '/a'));
  }

  /**
   * Framing lines may name things in quotes but not quote a clause.
   */
  public function testQuotesAtLeast(): void {
    $this->assertFalse(AiAnalyzerService::quotesAtLeast('Frames the “Big Beautiful Bill” as a middle-class tax cut.', 4));
    $this->assertTrue(AiAnalyzerService::quotesAtLeast('Calls the bill "a gift to the ultra-wealthy" and leans on union sources.', 4));
    $this->assertFalse(AiAnalyzerService::quotesAtLeast('No quotation here.', 4));
  }

  /**
   * The rubric renders version, center, scale, signals, markers and rules.
   */
  public function testRubricRender(): void {
    $this->assertSame('', RubricService::renderData([]));
    $text = RubricService::renderData([
      'rubric_version' => '1.0',
      'rubric_date' => '2026-09-24',
      'center_definition' => 'Wire-service register.',
      'scale' => [
        ['score' => -1, 'label' => 'Lean Left', 'definition' => 'Mild.'],
      ],
      'framing_signals' => [
        ['key' => 'source_selection', 'label' => 'Source selection', 'description' => 'Who is quoted.'],
      ],
      'issues' => [
        [
          'key' => 'guns',
          'label' => 'Guns',
          'left_tells' => ['gun safety'],
          'right_tells' => ['Second Amendment rights'],
          'notes' => '',
        ],
      ],
      'rules' => 'Topic is not framing.',
    ]);
    $this->assertStringContainsString('RUBRIC v1.0 (2026-09-24)', $text);
    $this->assertStringContainsString('CENTER (score 0): Wire-service register.', $text);
    $this->assertStringContainsString('-1 Lean Left: Mild.', $text);
    $this->assertStringContainsString('- Source selection: Who is quoted.', $text);
    $this->assertStringContainsString('- Guns — left tells: "gun safety" | right tells: "Second Amendment rights"', $text);
    $this->assertStringContainsString("DECISION RULES\nTopic is not framing.", $text);
  }

}

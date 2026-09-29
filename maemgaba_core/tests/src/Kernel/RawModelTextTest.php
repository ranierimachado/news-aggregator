<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\maemgaba_core\AiAnalyzerService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Model text comes back from chat() byte for byte.
 *
 * The ProviderProxy of drupal/ai runs every chat response through
 * HostnameFilter, which parses it as HTML: "&" came back as "&amp;", ">" as
 * "&gt;", and "< 2" was dropped. The echoai test provider answers with the
 * prompt it got, so the real proxy and filter run on a known string.
 */
#[Group('maemgaba_core')]
class RawModelTextTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'key',
    'ai',
    'ai_test',
    'maemgaba_core',
  ];

  /**
   * Characters HostnameFilter used to rewrite.
   */
  protected const TEXT = 'Q&A: S&P 500 fell < 2% while "M&A" > 3 & rising';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['ai', 'ai_test']);
    $this->installEntitySchema('ai_mock_provider_result');
    $this->installSchema('maemgaba_core', ['maemgaba_ai_call_log']);
  }

  /**
   * The filter is live here, and chat() is not affected by it.
   */
  public function testChatKeepsAmpersandAndAngleBrackets(): void {
    $provider = $this->container->get('ai.provider');

    // Control: without the opt-out the same proxy mangles the text, so the
    // assertion below is not passing by accident.
    $mangled = $provider->createInstance('echoai')
      ->chat(new ChatInput([new ChatMessage('user', self::TEXT)]), 'test')
      ->getNormalized()->getText();
    $this->assertStringNotContainsString(self::TEXT, $mangled);
    $this->assertStringContainsString('Q&amp;A', $mangled);

    $analyzer = $this->container->get('maemgaba_core.ai_analyzer');
    $chat = fn (string $prompt): string => $this->chat('echoai', 'test', 'System.', $prompt, ['type' => 'object'], 'test', 'test', NULL);
    $text = \Closure::bind($chat, $analyzer, AiAnalyzerService::class)(self::TEXT);
    $this->assertStringContainsString('Input: ' . self::TEXT . '.', $text);

    // The per-call override is restored afterwards: other drupal/ai users
    // (chatbots, automators) still get their filter.
    $again = $provider->createInstance('echoai')
      ->chat(new ChatInput([new ChatMessage('user', self::TEXT)]), 'test')
      ->getNormalized()->getText();
    $this->assertSame($mangled, $again);

    // One successful attempt logged.
    $rows = $this->container->get('database')->select('maemgaba_ai_call_log', 'l')
      ->fields('l', ['success'])->execute()->fetchCol();
    $this->assertSame(['1'], array_map('strval', $rows));
  }

}

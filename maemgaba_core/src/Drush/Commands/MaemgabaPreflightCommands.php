<?php

namespace Drupal\maemgaba_core\Drush\Commands;

use Drupal\maemgaba_core\AiAnalyzerService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command asserting that every AI operation's routing works.
 *
 * Meant for deploys and cron wrappers: exits non-zero when any operation
 * fails, so a script can refuse to start a paid batch on broken routing
 * (the same job deploy/overnight-process.sh's EXPECT_CLUSTER_PROVIDER does
 * for classify_cluster alone).
 */
class MaemgabaPreflightCommands extends DrushCommands {

  public function __construct(
    protected AiAnalyzerService $analyzer,
  ) {
    parent::__construct();
  }

  /**
   * Dependency Injection factory compatible with Drush 13.
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('maemgaba_core.ai_analyzer'),
    );
  }

  /**
   * Makes one tiny real call per AI operation and reports OK/FAIL.
   */
  #[CLI\Command(name: 'maemgaba:ai-preflight', aliases: ['mg-preflight'])]
  #[CLI\Option(name: 'operation', description: 'Comma-separated operations to check (default: all five).')]
  #[CLI\Option(name: 'expect-provider', description: 'Fail any operation not routed to this provider id (e.g. anthropic).')]
  #[CLI\Option(name: 'require-bias', description: 'Treat an unrouted classify_bias (refinement off) as a failure.')]
  #[CLI\Usage(name: 'drush maemgaba:ai-preflight --expect-provider=anthropic --require-bias', description: 'Prod assertion: all five operations answer on Anthropic.')]
  public function preflight(array $options = ['operation' => NULL, 'expect-provider' => NULL, 'require-bias' => FALSE]): int {
    $operations = $options['operation']
      ? array_map('trim', explode(',', $options['operation']))
      : AiAnalyzerService::PREFLIGHT_OPERATIONS;
    $unknown = array_diff($operations, AiAnalyzerService::PREFLIGHT_OPERATIONS);
    if ($unknown) {
      $this->logger()->error('Unknown operation(s): ' . implode(', ', $unknown));
      return self::EXIT_FAILURE;
    }

    $rows = [];
    $failures = 0;
    foreach ($operations as $operation) {
      $r = $this->analyzer->preflight($operation);

      if ($r['status'] === 'ok' && $options['expect-provider'] && $r['provider'] !== $options['expect-provider']) {
        $r['status'] = 'fail';
        $r['message'] = sprintf('Routed to %s, expected %s.', $r['provider'], $options['expect-provider']);
      }
      if ($r['status'] === 'off' && $options['require-bias']) {
        $r['status'] = 'fail';
      }
      if ($r['status'] === 'fail') {
        $failures++;
      }

      $rows[] = [
        $operation,
        $r['provider'] ?: '-',
        $r['model'] ?: '-',
        strtoupper($r['status']),
        $r['latency_ms'] ? $r['latency_ms'] . ' ms' : '-',
        mb_substr($r['message'], 0, 160),
      ];
    }

    $this->io()->table(['Operation', 'Provider', 'Model', 'Status', 'Latency', 'Message'], $rows);

    if ($failures) {
      $this->logger()->error(sprintf('AI preflight: %d of %d operation(s) failed.', $failures, count($operations)));
      return self::EXIT_FAILURE;
    }
    $off = count(array_filter($rows, fn(array $row) => $row[3] === 'OFF'));
    $this->logger()->success(sprintf('AI preflight: %d operation(s) OK%s.', count($operations) - $off, $off ? ", $off off (not routed)" : ''));
    return self::EXIT_SUCCESS;
  }

}

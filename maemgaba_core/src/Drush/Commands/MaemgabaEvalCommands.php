<?php

namespace Drupal\maemgaba_core\Drush\Commands;

use Drupal\maemgaba_core\Service\EvalRunner;
use Drupal\maemgaba_core\Service\GoldenSetExporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the golden set and AI prompt evaluation.
 *
 * The business logic lives in GoldenSetExporter and EvalRunner; this command is
 * only the presentation layer for the CLI.
 */
class MaemgabaEvalCommands extends DrushCommands {

  public function __construct(
    protected GoldenSetExporter $goldenSetExporter,
    protected EvalRunner $evalRunner,
  ) {
    parent::__construct();
  }

  /**
   * Dependency Injection factory compatible with Drush 13.
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('maemgaba_core.golden_set_exporter'),
      $container->get('maemgaba_core.eval_runner'),
    );
  }

  /**
   * Exports recent cards (labeled by the AI) to the golden set fixture.
   *
   * The generated file is NOT "golden" yet — it is the AI evaluating itself.
   * Manually review each item before using it as a reference for evals.
   */
  #[CLI\Command(name: 'maemgaba:golden-export', aliases: ['mg-golden-export'])]
  #[CLI\Option(name: 'limit', description: 'How many cards to export, split evenly across the 3 legacy bias classes.')]
  public function goldenExport(array $options = ['limit' => 150]) {
    $result = $this->goldenSetExporter->export((int) $options['limit']);
    $this->output()->writeln("✅ {$result['count']} item(s) exported to {$result['path']}");
    $this->output()->writeln('<comment>⚠️  This is not a golden set yet: review and correct the labels by hand before trusting evals.</comment>');
  }

  /**
   * Replays the golden set through the classifier.
   *
   * Measures accuracy against the labels. 'relevance' uses the cheap pass
   * (classifyRelevance, title+summary). 'classify_cluster' uses the full pass
   * (classifyAndCluster, without existing events) and is the only one that
   * evaluates "bias" — requires --full as it is more expensive.
   */
  #[CLI\Command(name: 'maemgaba:eval', aliases: ['mg-eval'])]
  #[CLI\Argument(name: 'operation', description: "'relevance' or 'classify_cluster'.")]
  #[CLI\Option(name: 'prompt', description: 'maemgaba_prompt config id to evaluate (default: the enabled one).')]
  #[CLI\Option(name: 'limit', description: 'How many golden-set items to replay in this run.')]
  #[CLI\Option(name: 'full', description: "Confirms a (more expensive) 'classify_cluster' run.")]
  #[CLI\Usage(name: 'drush maemgaba:eval relevance', description: 'Evaluates the enabled relevance prompt against 25 golden-set items.')]
  #[CLI\Usage(name: 'drush maemgaba:eval classify_cluster --full --limit=50', description: 'Evaluates the full classification/cluster prompt, bias included.')]
  public function eval(
    string $operation = 'relevance',
    array $options = ['prompt' => NULL, 'limit' => 25, 'full' => FALSE],
  ) {
    if (!in_array($operation, ['relevance', 'classify_cluster'], TRUE)) {
      throw new \InvalidArgumentException("Invalid operation: '{$operation}'. Use 'relevance' or 'classify_cluster'.");
    }
    if ($operation === 'classify_cluster' && empty($options['full'])) {
      throw new \InvalidArgumentException("'classify_cluster' is a more expensive pass: repeat the command with --full to confirm.");
    }

    $this->output()->writeln("🧪 Rodando eval '{$operation}' (limite: {$options['limit']} item(ns))...");

    $outcome = $this->evalRunner->run($operation, (int) $options['limit'], $options['prompt'] ?: NULL);
    $metrics = $outcome['metrics'];

    foreach ($metrics['fields'] as $field => $score) {
      $acc = $score['accuracy'] !== NULL ? sprintf('%.1f%%', $score['accuracy'] * 100) : 'n/a';
      $this->output()->writeln("  <info>{$field}</info>: {$acc} accuracy ({$score['total']} item(s))");
      foreach ($score['per_class'] as $class => $pr) {
        $p = $pr['precision'] !== NULL ? sprintf('%.2f', $pr['precision']) : 'n/a';
        $r = $pr['recall'] !== NULL ? sprintf('%.2f', $pr['recall']) : 'n/a';
        $this->output()->writeln("    · {$class}: precision={$p} recall={$r} (support={$pr['support']})");
      }
    }

    $cost = $metrics['cost'];
    $this->output()->writeln(sprintf(
      '💰 %d AI call attempt(s) (%d logical call(s), %d failed), ~%d tokens in / %d out (+%d thinking / %d cache), estimated cost US$ %.4f',
      $cost['attempts'], $cost['logical_calls'], $cost['failures'],
      $cost['input_tokens'], $cost['output_tokens'], $cost['reasoning_tokens'], $cost['cached_tokens'],
      $cost['estimated_usd']
    ));
    $this->output()->writeln("📌 Saved as run #{$outcome['run_id']} in maemgaba_eval_run.");

    if ($metrics['miss_count'] > 0) {
      $this->output()->writeln("<comment>{$metrics['miss_count']} miss(es), showing up to 10:</comment>");
      foreach (array_slice($metrics['misses'], 0, 10) as $miss) {
        $id = $miss['id'] ?? '?';
        $expected = json_encode($miss['expected'], JSON_UNESCAPED_UNICODE);
        $got = json_encode($miss['got'], JSON_UNESCAPED_UNICODE);
        $this->output()->writeln("  #{$id} \"{$miss['title']}\" — expected {$expected} / got {$got}");
      }
    }
  }

}

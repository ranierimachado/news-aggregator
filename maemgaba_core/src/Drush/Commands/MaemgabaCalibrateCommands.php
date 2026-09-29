<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Drush\Commands;

use Drupal\maemgaba_core\Calibration\CalibrationRunner;
use Drupal\maemgaba_core\Service\SpectrumService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush front end for the rubric calibration harness (CalibrationRunner).
 */
class MaemgabaCalibrateCommands extends DrushCommands {

  public function __construct(
    protected CalibrationRunner $runner,
    protected SpectrumService $spectrum,
  ) {
    parent::__construct();
  }

  /**
   * Dependency Injection factory compatible with Drush 13.
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('maemgaba_core.calibration_runner'),
      $container->get('maemgaba_core.spectrum'),
    );
  }

  /**
   * Writes a calibration sample YAML from today's feed items.
   *
   * Each item gets the outlet's published prior as expected_score. Feed
   * titles/abstracts go to the private cache, not to the YAML.
   */
  #[CLI\Command(name: 'maemgaba:calibration-sample', aliases: ['mg-calib-sample'])]
  #[CLI\Argument(name: 'out', description: 'YAML file to write.')]
  #[CLI\Option(name: 'per-outlet', description: 'Items per outlet.')]
  #[CLI\Option(name: 'include-opinion', description: 'Also sample opinion feeds.')]
  #[CLI\Usage(name: 'drush maemgaba:calibration-sample ../calibration.yml --per-outlet=12', description: 'Up to 12 news items per outlet.')]
  public function sample(string $out, array $options = ['per-outlet' => 10, 'include-opinion' => FALSE]): void {
    $result = $this->runner->sample((int) $options['per-outlet'], (bool) $options['include-opinion']);
    foreach ($result['feeds'] as $feed) {
      $this->output()->writeln(sprintf('  %-26s %3d in feed, %3d taken %s', $feed['outlet'], $feed['items'], $feed['taken'], $feed['note']));
    }
    $yaml = "# Calibration sample generated " . date('c') . " by maemgaba:calibration-sample.\n"
      . "# expected_score = the outlet's published prior (feed_source.field_published_prior).\n"
      . "# Add hand_label: <score -2..2> to hand-checked items.\n"
      . Yaml::dump(['items' => $result['items']], 3, 2);
    file_put_contents($out, $yaml);
    $this->output()->writeln(sprintf('Wrote %d items to %s', count($result['items']), $out));
  }

  /**
   * Classifies a sample twice and reports agreement, skew and instability.
   */
  #[CLI\Command(name: 'maemgaba:calibrate', aliases: ['mg-calibrate'])]
  #[CLI\Argument(name: 'file', description: 'Sample YAML: items of {url, outlet, expected_score|expected_bucket, hand_label?}.')]
  #[CLI\Option(name: 'operation', description: 'classify_cluster (pipeline pass, default) or classify_bias.')]
  #[CLI\Option(name: 'prompt', description: 'Prompt id for run A (default: the enabled one).')]
  #[CLI\Option(name: 'paraphrase', description: "Prompt id for run B (default: '<A>_paraphrase' if it exists; 'none' = same prompt twice).")]
  #[CLI\Option(name: 'single', description: 'Skip run B.')]
  #[CLI\Option(name: 'limit', description: 'Only the first N items.')]
  #[CLI\Option(name: 'label', description: 'Free-text label stored with the run.')]
  #[CLI\Option(name: 'out', description: 'Also write metrics + per-item answers (framing lines, evidence) as JSON here.')]
  #[CLI\Option(name: 'extract-only', description: 'Only fetch and cache the articles and report full/partial text; no AI calls.')]
  #[CLI\Usage(name: 'drush maemgaba:calibrate ../calibration.yml --out=/tmp/calib.json', description: 'Full two-run calibration.')]
  public function calibrate(
    string $file,
    array $options = [
      'operation' => 'classify_cluster',
      'prompt' => NULL,
      'paraphrase' => '',
      'single' => FALSE,
      'limit' => 0,
      'label' => '',
      'out' => NULL,
      'extract-only' => FALSE,
    ],
  ): void {
    $result = $this->runner->run($file, [
      'operation' => $options['operation'],
      'prompt' => $options['prompt'] ?: NULL,
      'paraphrase' => (string) $options['paraphrase'],
      'single' => (bool) $options['single'],
      'limit' => (int) $options['limit'],
      'label' => (string) $options['label'],
      'extract_only' => (bool) $options['extract-only'],
      'on_item' => function (int $i, int $n, array $row): void {
        $this->output()->writeln(sprintf(
          '[%d/%d] %-24s prior=%s A=%s B=%s %s %s',
          $i, $n, mb_substr($row['outlet'], 0, 24),
          $this->label($row['expected']), $this->label($row['a']), $this->label($row['b']),
          $row['partial'] ? 'partial' : 'full',
          $row['note'] !== '' ? '(' . $row['note'] . ')' : '',
        ));
      },
    ]);
    if ($options['extract-only']) {
      $rows = $result['rows'];
      $partial = count(array_filter($rows, fn ($r) => $r['partial']));
      $failed = count(array_filter($rows, fn ($r) => $r['method'] === ''));
      $this->output()->writeln(sprintf('%d items: %d full text, %d partial (headline + abstract), %d not extractable.', count($rows), count($rows) - $partial - $failed, $partial, $failed));
      if (!empty($options['out'])) {
        file_put_contents($options['out'], json_encode(['rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      }
      return;
    }
    $m = $result['metrics'];

    $this->output()->writeln('');
    $this->output()->writeln(sprintf('Run #%d · rubric v%s · prompt %s vs %s · blind=%s · %d items, %d scored, %d failed',
      $result['run_id'], $m['rubric_version'], $m['prompt'], $m['paraphrase_prompt'] ?? '—', $m['blind'] ? 'yes' : 'no', $m['items'], $m['scored'], $m['failed']));
    $this->printAgreement('Agreement with outlet prior', $m['prior']);
    $this->printAgreement('Agreement with hand labels', $m['hand']);
    $this->output()->writeln(sprintf('Instability (A vs B): %s changed, mean |Δ| %s (n=%d)', $this->pct($m['instability']['changed']), $m['instability']['mean_abs_delta'] ?? 'n/a', $m['instability']['n']));
    $this->output()->writeln(sprintf('Mean confidence: %s (full %s, partial %s)', $m['confidence']['all'] ?? 'n/a', $m['confidence']['full'] ?? 'n/a', $m['confidence']['partial'] ?? 'n/a'));
    $this->output()->writeln(sprintf('Full text: n=%d exact %s ±1 %s Δ %s | Partial: n=%d exact %s ±1 %s Δ %s',
      $m['split']['full']['n'], $this->pct($m['split']['full']['exact']), $this->pct($m['split']['full']['within_one']), $m['split']['full']['mean_delta'] ?? 'n/a',
      $m['split']['partial']['n'], $this->pct($m['split']['partial']['exact']), $this->pct($m['split']['partial']['within_one']), $m['split']['partial']['mean_delta'] ?? 'n/a'));
    $this->output()->writeln(sprintf('Cost: %d attempts (%d failed), %d in / %d out tokens, ≈ US$ %.4f (%s)',
      $m['cost']['attempts'], $m['cost']['failures'], $m['cost']['input_tokens'], $m['cost']['output_tokens'], $m['cost']['estimated_usd'], implode(', ', $m['cost']['models'])));

    if (!empty($options['out'])) {
      file_put_contents($options['out'], json_encode(['metrics' => $m, 'rows' => $result['rows']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      $this->output()->writeln('Details written to ' . $options['out']);
    }
  }

  /**
   * Prints an agreement block with its per-bucket rows.
   */
  protected function printAgreement(string $title, array $a): void {
    $this->output()->writeln(sprintf('%s: n=%d exact %s ±1 %s mean Δ %s (negative = model reads further left)',
      $title, $a['n'], $this->pct($a['exact']), $this->pct($a['within_one']), $a['mean_delta'] ?? 'n/a'));
    foreach ($a['per_bucket'] ?? [] as $key => $b) {
      if ($b['n']) {
        $this->output()->writeln(sprintf('    %-11s n=%3d exact %6s ±1 %6s Δ %6s', $key, $b['n'], $this->pct($b['exact']), $this->pct($b['within_one']), $b['mean_delta'] ?? 'n/a'));
      }
    }
  }

  /**
   * Bucket key for an index, or '—'.
   */
  protected function label(?int $index): string {
    return $index === NULL ? '—' : ($this->spectrum->keys()[$index] ?? '?');
  }

  /**
   * Formats a 0..1 share as a percentage.
   */
  protected function pct(?float $value): string {
    return $value === NULL ? 'n/a' : sprintf('%.1f%%', $value * 100);
  }

}

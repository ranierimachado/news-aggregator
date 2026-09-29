<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\maemgaba_core\Service\RelativeTime;
use Drupal\maemgaba_core\Service\SourceBiasStats;
use Drupal\maemgaba_core\Service\SpectrumService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the sources page.
 *
 * Lists every media outlet that has published cards, with its editorial
 * leaning, article count, spectrum breakdown, and an "editorial divergence"
 * metric: the share of its articles whose bias departs from its usual line.
 */
class SourcesController extends ControllerBase {

  public function __construct(
    protected SpectrumService $spectrum,
    protected SourceBiasStats $sourceBiasStats,
    protected RelativeTime $relativeTime,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('maemgaba_core.spectrum'),
      $container->get('maemgaba_core.source_bias_stats'),
      $container->get('maemgaba_core.relative_time'),
    );
  }

  /**
   * Renders the sources table.
   */
  public function list(): array {
    $now = \Drupal::time()->getRequestTime();
    $order = array_flip($this->spectrum->keys());

    $rows = [];
    foreach ($this->sourceBiasStats->tally() as $source) {
      $total = $source['total'];
      // Editorial line: the bucket of the declared default score, else the
      // dominant bucket of the outlet's actual coverage.
      $divergence = $this->spectrum->divergence($source['dist'], $source['default_score']);
      if ($total === 0 || $divergence === NULL) {
        continue;
      }
      $primary = $this->spectrum->bucket($divergence['primary']);

      $rows[] = [
        'name' => $source['name'],
        'initials' => $this->initials($source['name']),
        'last_ago' => mb_strtoupper($this->relativeTime->compactSeconds($now - $source['last'])),
        'primary' => $primary['key'],
        'primary_color' => $primary['color'],
        'bias_label' => mb_strtoupper($primary['label']),
        'total' => $total,
        'dist' => $source['dist'],
        'spectrum_bar' => [
          '#theme' => 'spectrum_bar',
          '#segments' => $this->segments($this->spectrum->percentages($source['dist'], FALSE)),
          '#size' => 'small',
        ],
        'divergence' => $divergence['pct'],
        'level' => $divergence['level_label'],
        'level_class' => $divergence['level'],
      ];
    }

    // Group by editorial line in spectrum order, then most active first.
    usort($rows, function ($a, $b) use ($order) {
      return [$order[$a['primary']], -$a['total']]
        <=> [$order[$b['primary']], -$b['total']];
    });

    return [
      '#theme' => 'sources_page',
      '#source_list' => [
        '#theme' => 'source_list',
        '#sources' => $rows,
        '#buckets' => $this->spectrum->buckets(),
      ],
      '#attached' => ['library' => ['maemgaba_core/engine']],
      '#cache' => [
        'tags' => [
          'node_list:card',
          'config:maemgaba_core.spectrum',
          'config:maemgaba_core.settings',
          'config:maemgaba_core.locale',
        ],
        'contexts' => ['user.node_grants:view'],
      ],
    ];
  }

  /**
   * Spectrum-bar segments for one outlet, from its per-bucket percentages.
   */
  private function segments(array $perc): array {
    $segments = [];
    foreach ($this->spectrum->buckets() as $bucket) {
      $segments[] = ['key' => $bucket['key'], 'color' => $bucket['color'], 'width' => $perc[$bucket['key']] ?? 0];
    }
    return $segments;
  }

  /**
   * Builds a short avatar label from a source name.
   */
  private function initials(string $name): string {
    $stop = (array) $this->config('maemgaba_core.locale')->get('initials_stopwords');
    $words = preg_split('/\s+/', trim($name));
    // Acronym first word (e.g. "BBC", "G1") stands on its own.
    if (preg_match('/^[A-Z0-9]{2,3}$/', $words[0])) {
      return $words[0];
    }
    $letters = '';
    foreach ($words as $word) {
      if (in_array(mb_strtolower($word), $stop, TRUE)) {
        continue;
      }
      $letters .= mb_strtoupper(mb_substr($word, 0, 1));
      if (mb_strlen($letters) >= 2) {
        break;
      }
    }
    return $letters ?: mb_strtoupper(mb_substr($name, 0, 2));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\maemgaba_core\BiasScore;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Exports recent AI-labeled cards to a golden-set fixture for hand review.
 *
 * The export itself is NOT a golden set — it's real cards with whatever
 * labels the AI already assigned. It only becomes "golden" once a human
 * reads the exported file and corrects the wrong labels.
 */
class GoldenSetExporter {

  /**
   * Default golden-set path (from the module root) when none is configured.
   *
   * The golden set is site data (real headlines in the site's language), so
   * each site points maemgaba_core.settings:golden_set_path at its own file,
   * kept outside the engine. The default is the synthetic sample pack.
   */
  protected const DEFAULT_FIXTURE_PATH = 'config/locale/sample/golden/classify_cluster.json';

  /**
   * Bias classes to balance the export across: legacy class => score test.
   *
   * Scores are grouped into the three legacy classes
   * (left < 0 = center < right) so the export stays comparable with earlier
   * golden files regardless of a site's display buckets.
   */
  protected const BIAS_CLASSES = [
    'left' => ['<', 0],
    'center' => ['=', 0],
    'right' => ['>', 0],
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleExtensionList $moduleExtensionList,
    protected ConfigFactoryInterface $configFactory,
    protected string $appRoot,
  ) {}

  /**
   * Exports up to $limit cards (~evenly split across bias classes) as JSON.
   *
   * @param int $limit
   *   Maximum number of cards to export.
   *
   * @return array
   *   ['count' => int, 'path' => string] — items written and absolute path.
   */
  public function export(int $limit): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $perClass = (int) ceil($limit / count(self::BIAS_CLASSES));

    $idsByClass = [];
    foreach (self::BIAS_CLASSES as $bias => [$operator, $value]) {
      $idsByClass[$bias] = array_values($storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'card')
        ->condition('status', 1)
        ->condition('field_bias_score', $value, $operator)
        ->exists('field_social_relevance')
        ->exists('field_topic')
        ->sort('created', 'DESC')
        ->range(0, $perClass)
        ->execute());
    }

    // Interleave classes round-robin (rather than concatenate) so that ANY
    // prefix slice of the exported file — e.g. `maemgaba:eval --limit=60`
    // reading the first 60 of a 150-item file — stays roughly balanced
    // across bias classes instead of running out one class at a time.
    $ids = [];
    $maxPerClass = max(array_map('count', $idsByClass));
    for ($i = 0; $i < $maxPerClass; $i++) {
      foreach (array_keys(self::BIAS_CLASSES) as $bias) {
        if (isset($idsByClass[$bias][$i])) {
          $ids[] = $idsByClass[$bias][$i];
        }
      }
    }
    $ids = array_slice($ids, 0, $limit);

    $cards = $storage->loadMultiple($ids);
    $items = [];
    foreach ($ids as $id) {
      $card = $cards[$id] ?? NULL;
      if (!$card) {
        continue;
      }
      $score = BiasScore::normalize($card->get('field_bias_score')->value);
      $bias = $score !== NULL ? BiasScore::toLegacy($score) : '';
      $relevance = (string) $card->get('field_social_relevance')->value;
      $topic = (string) $card->get('field_topic')->value;
      if ($bias === '' || $relevance === '' || $topic === '') {
        continue;
      }

      // Cards don't retain the original article body (only the queue item
      // does, and it's deleted once processed) — the micro summary is the
      // richest text available per card, so it stands in for "body_excerpt".
      $bodyExcerpt = trim(strip_tags((string) $card->get('field_micro_summary')->value));

      $items[] = [
        'id' => (int) $card->id(),
        'title' => $card->getTitle(),
        'body_excerpt' => $bodyExcerpt,
        'expected' => [
          'bias' => $bias,
          'social_relevance' => $relevance,
          'topic' => $topic,
        ],
      ];
    }

    $path = $this->fixturePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
      mkdir($dir, 0775, TRUE);
    }
    file_put_contents($path, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    return ['count' => count($items), 'path' => $path];
  }

  /**
   * Absolute path to the golden-set fixture file.
   */
  public function fixturePath(): string {
    $configured = (string) $this->configFactory->get('maemgaba_core.settings')->get('golden_set_path');
    if ($configured !== '' && str_starts_with($configured, '/')) {
      return $configured;
    }
    // A relative path is the site's: it resolves against the project root
    // (the directory above the web root, where a site keeps config/ and
    // docs/). The module directory is the fallback, so the shipped sample
    // pack and pre-1.0 module-relative settings keep working.
    $relative = $configured !== '' ? $configured : self::DEFAULT_FIXTURE_PATH;
    $projectPath = dirname($this->appRoot) . '/' . $relative;
    if ($configured !== '' && file_exists($projectPath)) {
      return $projectPath;
    }
    return $this->moduleExtensionList->getPath('maemgaba_core') . '/' . $relative;
  }

}

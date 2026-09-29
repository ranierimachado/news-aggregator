<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Plugin\facets\processor;

use Drupal\Core\Cache\UnchangingCacheableDependencyTrait;
use Drupal\facets\Processor\SortProcessorInterface;
use Drupal\facets\Processor\SortProcessorPluginBase;
use Drupal\facets\Result\Result;

/**
 * Orders facet items by their raw value as a number.
 *
 * The facets module's "raw value" sort compares text, which puts bias score
 * -1 before -2. This keeps a lean facet in spectrum order (Left … Right).
 * Only discovered on sites with the facets module.
 *
 * @FacetsProcessor(
 *   id = "maemgaba_numeric_value_widget_order",
 *   label = @Translation("Sort by raw value as a number"),
 *   description = @Translation("Sorts the widget results by raw value, compared as numbers (for example bias scores -2..2)."),
 *   stages = {
 *     "sort" = 50
 *   }
 * )
 */
class NumericValueWidgetOrderProcessor extends SortProcessorPluginBase implements SortProcessorInterface {

  use UnchangingCacheableDependencyTrait;

  /**
   * {@inheritdoc}
   */
  public function sortResults(Result $a, Result $b) {
    return (float) $a->getRawValue() <=> (float) $b->getRawValue();
  }

}

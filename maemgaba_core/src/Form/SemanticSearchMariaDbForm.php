<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\maemgaba_core\Service\EventSearch;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin page to run test queries against the MariaDB semantic search index.
 */
class SemanticSearchMariaDbForm extends FormBase {

  /**
   * Maximum results to display.
   */
  protected const RESULT_LIMIT = 10;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The id of the events index (maemgaba_core.settings:events_index).
   */
  protected function indexId(): string {
    return (string) ($this->config('maemgaba_core.settings')->get('events_index') ?: EventSearch::DEFAULT_INDEX_ID);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'maemgaba_core_semantic_search_mariadb';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $keys = trim((string) $form_state->getValue('keys', ''));

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Runs a vector similarity search against the %index index (MariaDB VDB backend, Gemini embeddings). Lower distance = more similar.', ['%index' => $this->indexId()]) . '</p>',
    ];
    $form['search'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];
    $form['search']['keys'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Query'),
      '#default_value' => $keys,
      '#size' => 80,
      '#maxlength' => 255,
    ];
    $form['search']['actions'] = ['#type' => 'actions'];
    $form['search']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
    ];

    if ($keys !== '') {
      $form['results'] = $this->buildResults($keys);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * Executes the query and builds a render array of results.
   */
  protected function buildResults(string $keys): array {
    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($this->indexId());
    if (!$index) {
      return [
        '#markup' => $this->t('Index %id not found.', ['%id' => $this->indexId()]),
      ];
    }

    try {
      $query = $index->query();
      $query->keys($keys);
      $query->range(0, self::RESULT_LIMIT);
      // Lower distance = more similar; the AI Search backend defaults to
      // DESC, which is wrong for a distance metric.
      $query->sort('search_api_relevance', 'ASC');
      $result_set = $query->execute();
    }
    catch (\Throwable $e) {
      return [
        '#markup' => '<p>' . $this->t('Search failed: @message', ['@message' => $e->getMessage()]) . '</p>',
      ];
    }

    $result_items = $result_set->getResultItems();
    if (!$result_items) {
      return [
        '#markup' => '<p>' . $this->t('No results.') . '</p>',
      ];
    }

    // Do not rely on $result_items being keyed by item ID: the AI Search
    // backend's ASC/DESC relevance sort runs the array through usort()
    // instead of uasort(), which re-indexes it numerically and silently
    // drops the item-ID keys.
    $item_ids = array_map(static fn ($item) => $item->getId(), $result_items);
    $entities = $index->loadItemsMultiple($item_ids);
    $view_builder = $this->entityTypeManager->getViewBuilder('node');

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['semantic-search-mariadb-results']],
    ];
    $build['count'] = [
      '#markup' => '<p>' . $this->formatPlural(count($result_items), '1 result', '@count results') . '</p>',
    ];

    $i = 0;
    foreach ($result_items as $item) {
      // loadItemsMultiple() returns TypedData EntityAdapter wrappers, not
      // raw entities.
      $adapter = $entities[$item->getId()] ?? NULL;
      $entity = $adapter?->getValue();
      if (!$entity) {
        continue;
      }
      $distance = $item->getScore();
      $build[$i] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['semantic-search-mariadb-result']],
        'meta' => [
          '#markup' => '<p><strong>' . $this->t('Distance: @distance (similarity ≈ @similarity)', [
            '@distance' => number_format((float) $distance, 4),
            '@similarity' => number_format(1 - (float) $distance, 4),
          ]) . '</strong></p>',
        ],
        'teaser' => $view_builder->view($entity, 'teaser'),
      ];
      $i++;
    }

    return $build;
  }

}

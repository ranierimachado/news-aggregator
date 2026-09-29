<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deletes an event together with all of its child cards.
 *
 * A plain "Delete content" on an event would orphan its cards (their
 * field_parent_event would dangle). This action removes the event first, then
 * its cards, so nothing is left behind.
 */
#[Action(
  id: 'maemgaba_delete_event_cascade',
  label: new TranslatableMarkup('Delete event and its cards'),
  type: 'node',
)]
class DeleteEventCascade extends ActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity || $entity->bundle() !== 'event') {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    // Collect the child cards before deleting the event.
    $card_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'card')
      ->condition('field_parent_event', $entity->id())
      ->execute();

    // Delete the event first, so the cards' delete hooks that recompute the
    // (now-gone) event's rollup become harmless no-ops.
    $entity->delete();

    if ($card_ids) {
      $storage->delete($storage->loadMultiple($card_ids));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\node\NodeInterface $object */
    $result = ($object->bundle() === 'event')
      ? $object->access('delete', $account, TRUE)
      : AccessResult::forbidden('Only events can be cascade-deleted.');
    return $return_as_object ? $result : $result->isAllowed();
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * List builder for AI prompt config entities.
 */
class AiPromptListBuilder extends ConfigEntityListBuilder {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Prompt');
    $header['operation'] = $this->t('Operation');
    $header['id'] = $this->t('Machine name');
    $header['rubric'] = $this->t('Rubric');
    $header['blind'] = $this->t('Blind');
    $header['anchors'] = $this->t('Anchors');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\maemgaba_core\Entity\AiPromptInterface $entity */
    $row['label'] = $entity->label();
    $row['operation'] = $entity->getOperation();
    $row['id'] = $entity->id();
    $row['rubric'] = $entity->getRubricVersion() !== '' ? 'v' . $entity->getRubricVersion() : '—';
    $row['blind'] = $entity->isBlind() ? $this->t('Yes') : $this->t('No');
    $row['anchors'] = (string) count($entity->getAnchors());
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $rubric = \Drupal::config('maemgaba_core.rubric');
    if ($rubric->get('rubric_version')) {
      $build['rubric'] = [
        '#markup' => '<p>' . $this->t('Active rubric: <strong>v@version</strong> (@date). <a href=":url">Edit the rubric</a>.', [
          '@version' => $rubric->get('rubric_version'),
          '@date' => $rubric->get('rubric_date'),
          ':url' => Url::fromRoute('maemgaba_core.rubric')->toString(),
        ]) . '</p>',
        '#weight' => -10,
      ];
    }
    $build['table']['#empty'] = $this->t('No AI prompts yet. Add one to control how articles are classified and clustered.');
    return $build;
  }

}

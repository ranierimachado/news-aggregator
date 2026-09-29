<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Form;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Delete confirmation form for AI prompt config entities.
 */
class AiPromptDeleteForm extends EntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete the AI prompt %label?', ['%label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->entity->delete();
    $this->messenger()->addStatus($this->t('The AI prompt %label has been deleted.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Add/edit form for AI prompt config entities.
 */
class AiPromptForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\maemgaba_core\Entity\AiPromptInterface $entity */
    $entity = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $entity->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\maemgaba_core\Entity\AiPrompt::load',
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $entity->get('description') ?? '',
      '#description' => $this->t('Optional note about when this prompt is used.'),
    ];

    $form['operation'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Operation'),
      '#default_value' => $entity->getOperation(),
      '#required' => TRUE,
      '#description' => $this->t('Machine key the pipeline looks up, e.g. <code>classify_cluster</code>.'),
    ];

    $form['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt'),
      '#default_value' => $entity->getSystemPrompt(),
      '#rows' => 3,
      '#description' => $this->t('Sets the model’s role/context. Optional.'),
    ];

    $form['template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt template'),
      '#default_value' => $entity->getTemplate(),
      '#rows' => 14,
      '#required' => TRUE,
      '#description' => $this->t('The user prompt. Use placeholders in square brackets, e.g. <code>[article_text]</code> and <code>[existing_events]</code>, which are filled in at run time. Ask the model to answer strictly in JSON.'),
    ];

    $form['rubric_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rubric version'),
      '#default_value' => $entity->getRubricVersion(),
      '#size' => 10,
      '#description' => $this->t('Version of the bias rubric this prompt was written and calibrated for (the <code>[rubric]</code> token inserts the active one).'),
    ];

    $form['blind'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Blind classification'),
      '#default_value' => $entity->isBlind(),
      '#description' => $this->t("Strip the outlet's own name, domains and aliases from the article before it reaches the model."),
    ];

    $form['anchors'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Few-shot anchors (YAML)'),
      '#default_value' => $entity->getAnchors() ? Yaml::dump($entity->getAnchors(), 3, 2) : '',
      '#rows' => 10,
      '#attributes' => ['style' => 'font-family: monospace'],
      '#description' => $this->t('List of {score, outlet, url, fetched, description, quote}. Rendered by the <code>[anchors]</code> token without outlet or URL. Paraphrase; one quote of at most 15 words.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $raw = trim((string) $form_state->getValue('anchors'));
    try {
      $anchors = $raw === '' ? [] : Yaml::parse($raw);
    }
    catch (ParseException $e) {
      $form_state->setErrorByName('anchors', $this->t('Invalid YAML: @msg', ['@msg' => $e->getMessage()]));
      return;
    }
    if (!is_array($anchors) || !array_is_list($anchors)) {
      $form_state->setErrorByName('anchors', $this->t('Anchors must be a YAML list.'));
      return;
    }
    $form_state->setValue('anchors', $anchors);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $entity = $this->entity;
    $status = $entity->save();

    $this->messenger()->addStatus($this->t('The AI prompt %label has been @op.', [
      '%label' => $entity->label(),
      '@op' => $status === SAVED_NEW ? $this->t('created') : $this->t('updated'),
    ]));

    $form_state->setRedirectUrl($entity->toUrl('collection'));
    return $status;
  }

}

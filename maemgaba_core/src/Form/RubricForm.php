<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\maemgaba_core\Service\RubricService;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Edits the bias rubric (maemgaba_core.rubric) without a deploy.
 *
 * Version and date are plain fields, shown on the prompt list; the body
 * (center definition, scale, framing signals, issue markers, rules) is YAML
 * because it is a nested table. A preview shows exactly what the [rubric]
 * token puts into the prompts. Bump the version whenever the meaning of a
 * score changes, and re-run maemgaba:calibrate.
 */
class RubricForm extends ConfigFormBase {

  /**
   * Keys edited through the YAML body.
   */
  protected const BODY_KEYS = ['summary', 'center_definition', 'scale', 'framing_signals', 'issues', 'rules'];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'maemgaba_core_rubric';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['maemgaba_core.rubric'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('maemgaba_core.rubric');
    $data = $config->getRawData();

    $form['rubric_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rubric version'),
      '#default_value' => $data['rubric_version'] ?? '',
      '#size' => 10,
      '#required' => TRUE,
      '#description' => $this->t('Bump it when the meaning of a score changes; prompt entities record the version they were calibrated for.'),
    ];
    $form['rubric_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Adopted on'),
      '#default_value' => $data['rubric_date'] ?? '',
    ];
    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Rubric (YAML)'),
      '#default_value' => Yaml::dump(array_intersect_key($data, array_flip(self::BODY_KEYS)), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
      '#rows' => 30,
      '#attributes' => ['style' => 'font-family: monospace'],
      '#description' => $this->t('Keys: summary, center_definition, scale (score/label/definition), framing_signals (key/label/description), issues (key/label/left_tells/right_tells/notes), rules.'),
    ];
    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('What the [rubric] prompt token contains'),
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#value' => htmlspecialchars(RubricService::renderData($data)),
        '#attributes' => ['style' => 'white-space: pre-wrap'],
      ],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $body = Yaml::parse((string) $form_state->getValue('body'));
    }
    catch (ParseException $e) {
      $form_state->setErrorByName('body', $this->t('Invalid YAML: @msg', ['@msg' => $e->getMessage()]));
      return;
    }
    if (!is_array($body)) {
      $form_state->setErrorByName('body', $this->t('The rubric body must be a YAML mapping.'));
      return;
    }
    if ($unknown = array_diff(array_keys($body), self::BODY_KEYS)) {
      $form_state->setErrorByName('body', $this->t('Unknown keys: @keys.', ['@keys' => implode(', ', $unknown)]));
    }
    foreach ($body['scale'] ?? [] as $bucket) {
      if (!isset($bucket['score']) || !is_int($bucket['score']) || $bucket['score'] < -2 || $bucket['score'] > 2) {
        $form_state->setErrorByName('body', $this->t('Every scale entry needs an integer score from -2 to 2.'));
        break;
      }
    }
    $form_state->set('rubric_body', $body);
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('maemgaba_core.rubric')
      ->set('rubric_version', trim((string) $form_state->getValue('rubric_version')))
      ->set('rubric_date', (string) $form_state->getValue('rubric_date'));
    foreach (self::BODY_KEYS as $key) {
      $config->set($key, $form_state->get('rubric_body')[$key] ?? NULL);
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

}

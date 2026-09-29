<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form to route each AI operation to a provider/model override.
 *
 * Each operation defaults to the site-wide chat provider (ai.settings); this
 * form lets an editor pin one to a specific provider/model instead (e.g. a
 * local Ollama model for bulk triage, a frontier model for framing-sensitive
 * fields). Backed by maemgaba_core.settings:operation_models, read by
 * AiAnalyzerService::resolveProvider(). Uses the ai module's own
 * 'ai_provider_configuration' form element for the dependent provider/model
 * dropdowns (including its built-in "Default" option), rather than
 * hand-rolling the AJAX.
 */
class OperationModelsForm extends ConfigFormBase {

  /**
   * Operations this form exposes, keyed by operation => admin-facing label.
   */
  protected const OPERATIONS = [
    'classify_cluster' => 'Classification and clustering (primary call)',
    'classify_bias' => 'Bias/topic refinement (composite call, optional)',
    'relevance' => 'Social relevance (backfill)',
    'synthesize_consensus' => 'Consensus synthesis',
    'review_cards' => 'Review of already-processed cards (second layer)',
    'ask_sql' => 'Ask the data: question to SQL',
    'ask_answer' => 'Ask the data: rows to one sentence',
  ];

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected AiProviderPluginManager $aiProviderManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai.provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['maemgaba_core.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'maemgaba_core_operation_models';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    // Each operation's 'ai_provider_configuration' element lives under a
    // details wrapper (one per operation); without #tree propagating down
    // from the form root, every one of those nested elements collapses to
    // the same top-level 'config' key in $form_state, so submitting any row
    // clobbers all the others. See OperationModelsForm::submitForm().
    $form['#tree'] = TRUE;

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Each operation uses the default AI provider/model (ai.settings) unless "Default" is swapped for a specific provider below.') . '</p>',
      '#weight' => -10,
    ];

    foreach (self::OPERATIONS as $operation => $label) {
      $form[$operation] = [
        '#type' => 'details',
        '#title' => $label,
        '#open' => TRUE,
      ];
      $form[$operation]['config'] = [
        '#type' => 'ai_provider_configuration',
        '#title' => $this->t('Provider / model'),
        '#operation_type' => 'chat',
        '#advanced_config' => FALSE,
        '#default_provider_allowed' => TRUE,
        '#default_value' => $this->currentValue($operation),
      ];
      $form[$operation]['effective'] = [
        '#markup' => '<p><em>' . $this->t('Effective now: @value', ['@value' => $this->effectiveLabel($operation)]) . '</em></p>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('maemgaba_core.settings');
    $map = $config->get('operation_models') ?? [];

    foreach (self::OPERATIONS as $operation => $label) {
      $value = $form_state->getValue([$operation, 'config']);
      if (!empty($value['use_default']) || empty($value['provider']) || empty($value['model'])) {
        unset($map[$operation]);
        continue;
      }
      $map[$operation] = ['provider' => $value['provider'], 'model' => $value['model']];
    }

    $config->set('operation_models', $map)->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Builds the #default_value for one operation's ai_provider_configuration.
   */
  protected function currentValue(string $operation): array {
    $override = $this->config('maemgaba_core.settings')->get('operation_models.' . $operation);
    if (!empty($override['provider']) && !empty($override['model'])) {
      return [
        'use_default' => FALSE,
        'provider' => $override['provider'],
        'model' => $this->resolveModelKey($override['provider'], $override['model']),
        'config' => [],
      ];
    }
    return ['use_default' => TRUE, 'provider' => '', 'model' => '', 'config' => []];
  }

  /**
   * Resolves a stored model id to the dropdown option key the widget uses.
   *
   * Most providers use the model id as-is for both. Ollama's provider
   * transliterates each model tag into a machine name for its own dropdown
   * option keys (see OllamaProvider::getConfiguredModels()) while its chat()
   * call still accepts the raw tag — so a value written before this form
   * existed (a raw "qwen2.5:14b" tag, set directly via config during the
   * local-Ollama routing experiments) won't match any <option value> and the
   * dropdown falls back to showing "Default", which would silently drop the
   * override on the next save if untouched. Map it to the sanitized key so
   * the current override is always the one visibly selected.
   */
  protected function resolveModelKey(string $providerId, string $modelId): string {
    try {
      $models = $this->aiProviderManager->createInstance($providerId)->getConfiguredModels('chat');
    }
    catch (\Throwable $e) {
      return $modelId;
    }
    if (isset($models[$modelId])) {
      return $modelId;
    }
    $key = array_search($modelId, $models, TRUE);
    return $key !== FALSE ? (string) $key : $modelId;
  }

  /**
   * Human-readable "what will actually run" line for one operation.
   */
  protected function effectiveLabel(string $operation): string {
    $override = $this->config('maemgaba_core.settings')->get('operation_models.' . $operation);
    if (!empty($override['provider']) && !empty($override['model'])) {
      return $override['provider'] . ' / ' . $override['model'];
    }
    $default = $this->aiProviderManager->getDefaultProviderForOperationType('chat');
    if (!empty($default['provider_id']) && !empty($default['model_id'])) {
      return $default['provider_id'] . ' / ' . $default['model_id'] . ' (' . (string) $this->t('site default') . ')';
    }
    return (string) $this->t('no AI provider configured');
  }

}

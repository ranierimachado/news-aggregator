<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Form;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\maemgaba_core\Service\SuggestionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form to accept or reject a "suggest a correction" submission.
 *
 * Accepting also renews the submitter's /busca trial and, if they gave an
 * email, sends the "your correction was applied" notification — see
 * SuggestionManager::decide().
 */
class SuggestionDecideForm extends ConfirmFormBase {

  /**
   * The suggestion row being decided, or NULL when it does not exist.
   */
  protected ?object $suggestion = NULL;

  /**
   * The suggestion id from the route.
   */
  protected int $id;

  /**
   * The decision from the route: 'accept' or 'reject'.
   */
  protected string $decision;

  public function __construct(
    protected SuggestionManager $suggestions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('maemgaba_core.suggestion_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'maemgaba_core_suggestion_decide';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL, $decision = NULL): array {
    $this->id = (int) $id;
    $this->decision = $decision;
    $this->suggestion = $this->suggestions->load($this->id);

    if (!$this->suggestion || $this->suggestion->status !== 'pending') {
      $form['status'] = ['#markup' => '<p>' . $this->t('This suggestion was already decided or does not exist.') . '</p>'];
      return $form;
    }

    $form = parent::buildForm($form, $form_state);
    $form['preview'] = [
      '#type' => 'item',
      '#title' => $this->t('Suggested correction'),
      '#markup' => nl2br(htmlspecialchars($this->suggestion->body)),
      '#weight' => -10,
    ];
    $form['moderation_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Moderation note (optional)'),
      '#rows' => 3,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->decision === 'accept'
      ? $this->t('Accept this correction?')
      : $this->t('Reject this correction?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->decision === 'accept'
      ? $this->t("The author's semantic search access will be extended by 10 days and, if there is an email, a notification will be sent.")
      : $this->t('The author will not be notified.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->decision === 'accept' ? $this->t('Accept') : $this->t('Reject');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('maemgaba_core.suggestions');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $decided = $this->suggestions->decide(
      $this->id,
      $this->decision,
      (string) $form_state->getValue('moderation_note'),
      (int) $this->currentUser()->id(),
    );
    $this->messenger()->addStatus($decided
      ? ($this->decision === 'accept' ? $this->t('Correction accepted.') : $this->t('Correction rejected.'))
      : $this->t('This suggestion had already been decided.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}

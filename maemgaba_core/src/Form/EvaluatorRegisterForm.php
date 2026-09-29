<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Flood\FloodInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Self-service "Torne-se avaliador" registration at /avaliador.
 *
 * Bypasses core's user.register route entirely (user.settings `register`
 * stays admin_only) — this form is the only door into the evaluator role.
 * Creates an active, passwordless account: core's one-time login link
 * (UserController::resetPassLogin) throws AccessDeniedHttpException for any
 * inactive account, so "blocked until verified" is not an option here — the
 * one-time login *is* the verification. Grants 10 days of /busca access
 * immediately; SuggestionManager::decide() extends it further on accepted
 * corrections.
 */
class EvaluatorRegisterForm extends FormBase {

  /**
   * Trial length in seconds.
   */
  protected const TRIAL_SECONDS = 10 * 86400;

  public function __construct(
    protected FloodInterface $flood,
    protected TimeInterface $time,
    protected KillSwitch $pageCacheKillSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('flood'),
      $container->get('datetime.time'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'maemgaba_core_evaluator_register';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // The CAPTCHA element needs a fresh per-visitor session on every load.
    $this->pageCacheKillSwitch->trigger();

    if ($this->currentUser()->isAuthenticated()) {
      $form['already'] = [
        '#markup' => $this->currentUser()->hasPermission('access news engine semantic search')
          ? '<p>' . $this->t('You are already an evaluator.') . '</p>'
          : '<p>' . $this->t('You already have an account.') . '</p>',
      ];
      return $form;
    }

    $form['intro'] = [
      '#markup' => '<p class="evaluator-register__intro">' . $this->t('Sign up as an evaluator and get 10 days of access to @site semantic search. Correcting more events renews your access.', ['@site' => $this->config('system.site')->get('name')]) . '</p>',
    ];
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#required' => TRUE,
      '#maxlength' => 60,
    ];
    $form['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
      '#description' => $this->t('We will send a one-time login link to this address.'),
    ];
    $form['captcha'] = [
      '#type' => 'captcha',
      '#captcha_type' => 'image_captcha/Image',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sign up'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->currentUser()->isAuthenticated()) {
      return;
    }
    if (!$this->flood->isAllowed('maemgaba_core.evaluator_register_ip', 3, 3600)) {
      $form_state->setErrorByName('mail', $this->t('Sign-up limit reached. Please try again later.'));
      return;
    }

    // Reuse the User entity's own constraints (UserName/UserNameUnique on
    // name, UserMailUnique + format on mail) instead of hand-rolled lookups.
    $user = User::create([
      'name' => $form_state->getValue('name'),
      'mail' => $form_state->getValue('mail'),
    ]);
    foreach ($user->validate() as $violation) {
      $field = str_starts_with($violation->getPropertyPath(), 'mail') ? 'mail' : 'name';
      $form_state->setErrorByName($field, $violation->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->currentUser()->isAuthenticated()) {
      return;
    }
    $this->flood->register('maemgaba_core.evaluator_register_ip', 3600);

    $user = User::create([
      'name' => $form_state->getValue('name'),
      'mail' => $form_state->getValue('mail'),
      'pass' => NULL,
      'status' => 1,
      'roles' => ['evaluator'],
      'maemgaba_search_until' => $this->time->getRequestTime() + self::TRIAL_SECONDS,
    ]);
    $user->enforceIsNew();
    $user->save();

    _user_mail_notify('register_no_approval_required', $user);

    $this->messenger()->addStatus($this->t('You are signed up! We sent you an email with a login link; use it to set your password. Your semantic search access is active for 10 days.'));
    $form_state->setRedirect('maemgaba_core.home');
  }

}

<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\PrivateKey;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The public "Contato" form at /contato.
 *
 * Anonymous and zero-friction, same spam defenses as SuggestionForm (see
 * HoneypotTimeTrapTrait): a CSS-hidden honeypot, a minimum-time trap, and
 * IP flood limiting — no CAPTCHA, no account required. Delivers straight
 * to the site email (system.site:mail); unlike suggestions, contact
 * messages aren't stored in the database or queued for moderation.
 */
class ContactForm extends FormBase {

  use HoneypotTimeTrapTrait;

  /**
   * Minimum seconds between page load and submit.
   */
  protected const MIN_SECONDS = 5;

  /**
   * Where contact messages are delivered.
   */
  public function __construct(
    protected MailManagerInterface $mailManager,
    protected FloodInterface $flood,
    protected TimeInterface $time,
    protected KillSwitch $pageCacheKillSwitch,
    protected PrivateKey $privateKey,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('flood'),
      $container->get('datetime.time'),
      $container->get('page_cache_kill_switch'),
      $container->get('private_key'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'maemgaba_core_contact';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // The baked-in token below only works as a spam trap if every visitor
    // gets a freshly built form.
    $this->pageCacheKillSwitch->trigger();

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#required' => TRUE,
      '#maxlength' => 100,
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Your email'),
      '#required' => TRUE,
    ];
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#rows' => 6,
    ];

    $form['website'] = $this->honeypotElement();
    $form['ts_token'] = $this->timeTokenElement();

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $this->validateHoneypotAndTimeTrap($form_state, self::MIN_SECONDS, 'message');
    if ($form_state->getErrors()) {
      return;
    }
    if (!$this->flood->isAllowed('maemgaba_core.contact_ip', 5, 3600)) {
      $form_state->setErrorByName('message', $this->t('Submission limit reached. Please try again later.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->flood->register('maemgaba_core.contact_ip', 3600);

    $email = (string) $form_state->getValue('email');
    $this->mailManager->mail('maemgaba_core', 'contact_message', (string) $this->config('system.site')->get('mail'), \Drupal::languageManager()->getDefaultLanguage()->getId(), [
      'name' => $form_state->getValue('name'),
      'email' => $email,
      'message' => $form_state->getValue('message'),
    ], $email);

    $this->messenger()->addStatus($this->t('Message sent. Thanks for getting in touch!'));
    $form_state->setRedirect('maemgaba_core.contact');
  }

}

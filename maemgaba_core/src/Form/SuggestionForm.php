<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\PrivateKey;
use Drupal\maemgaba_core\Service\SuggestionManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The public "suggest a correction" form.
 *
 * Deliberately anonymous and zero-friction — corrections are the site's
 * highest-value input, so this asks for an account nowhere. Spam is kept
 * out with core-only defenses (no contrib dependency, unlike the CAPTCHA on
 * /avaliador): a CSS-hidden honeypot field and a minimum-time trap (see
 * HoneypotTimeTrapTrait), plus IP flood limiting. Both trait defenses need
 * the page to never come from anonymous page cache — a cached copy would
 * carry a stale build timestamp and let bots sail through the time trap.
 */
class SuggestionForm extends FormBase {

  use HoneypotTimeTrapTrait;

  /**
   * Minimum seconds between page load and submit.
   */
  protected const MIN_SECONDS = 5;

  public function __construct(
    protected SuggestionManager $suggestions,
    protected EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('maemgaba_core.suggestion_manager'),
      $container->get('entity_type.manager'),
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
    return 'maemgaba_core_suggestion';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // The baked-in 'ts' value below only works as a spam trap if every
    // visitor gets a freshly built form.
    $this->pageCacheKillSwitch->trigger();

    $event = $this->contextEvent();
    $form['event_nid'] = ['#type' => 'value', '#value' => $event?->id()];
    if ($event) {
      $form['event_context'] = [
        '#markup' => '<p class="suggestion-form__event-context">' . $this->t('About the event: %title', ['%title' => $event->label()]) . '</p>',
      ];
    }

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type of correction'),
      '#options' => SuggestionManager::typeOptions(),
      '#required' => TRUE,
    ];
    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What is wrong?'),
      '#required' => TRUE,
      '#rows' => 5,
    ];
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Link to evidence (optional)'),
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Your email (optional)'),
      '#description' => $this->t('Only used to let you know if the correction is applied.'),
    ];
    $form['credit_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('How you want to be credited (optional)'),
      '#maxlength' => 100,
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
    $this->validateHoneypotAndTimeTrap($form_state, self::MIN_SECONDS, 'body');
    if ($form_state->getErrors()) {
      return;
    }
    if (!$this->flood->isAllowed('maemgaba_core.suggestion_ip', 5, 3600)) {
      $form_state->setErrorByName('body', $this->t('Submission limit reached. Please try again later.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->flood->register('maemgaba_core.suggestion_ip', 3600);

    $this->suggestions->insert([
      'event_nid' => $form_state->getValue('event_nid'),
      'type' => $form_state->getValue('type'),
      'body' => $form_state->getValue('body'),
      'url' => $form_state->getValue('url'),
      'email' => $form_state->getValue('email'),
      'credit_name' => $form_state->getValue('credit_name'),
      'uid' => $this->currentUser()->id() ?: NULL,
      'ip' => $this->getRequest()->getClientIp(),
    ]);

    $form_state->setRedirect('maemgaba_core.suggest_thanks');
  }

  /**
   * Loads the event node named by ?event=NID, if valid and published.
   */
  protected function contextEvent(): ?NodeInterface {
    $nid = $this->getRequest()->query->get('event');
    if (!$nid || !ctype_digit((string) $nid)) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event' || !$node->isPublished()) {
      return NULL;
    }
    return $node;
  }

}

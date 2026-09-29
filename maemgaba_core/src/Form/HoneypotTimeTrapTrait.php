<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Form;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;

/**
 * Honeypot + minimum-time spam trap shared by public, anonymous forms.
 *
 * Consuming classes must constructor-promote a `protected TimeInterface
 * $time` and a `protected PrivateKey $privateKey` (as SuggestionForm and
 * ContactForm do), and must call `$this->pageCacheKillSwitch->trigger()`
 * in buildForm() — the timestamp baked into the token is only a valid trap
 * if every visitor gets a freshly built page rather than an
 * anonymous-cached one.
 */
trait HoneypotTimeTrapTrait {

  /**
   * Honeypot field: CSS-hidden (see .antispam-hp, not display:none).
   *
   * Hidden so simple bots that fill every visible-looking field still trip
   * it. Left empty by human visitors.
   */
  protected function honeypotElement(): array {
    return [
      '#type' => 'textfield',
      '#title' => $this->t('Website'),
      '#attributes' => ['autocomplete' => 'off', 'tabindex' => '-1'],
      '#wrapper_attributes' => ['class' => ['antispam-hp']],
    ];
  }

  /**
   * Hidden, signed timestamp field minted fresh on every buildForm() call.
   *
   * '#default_value' (not '#value'): FormBuilder::doBuildForm() treats an
   * explicit '#value' as server-authoritative and recomputes it fresh on
   * every buildForm() call — including the one Drupal runs internally
   * while processing the POST, before validateForm() ever sees it. That
   * would make a baked-in time() always read as "now". '#default_value'
   * instead lets the submitted value flow through untouched, so the token
   * minted on the original GET survives to validation.
   */
  protected function timeTokenElement(): array {
    return [
      '#type' => 'hidden',
      '#default_value' => $this->makeTimeToken($this->time->getRequestTime()),
    ];
  }

  /**
   * Validates the honeypot and time trap, setting a form error on failure.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param int $min_seconds
   *   Minimum seconds required between page load and submit.
   * @param string $error_field
   *   Name of the field to attach the time-trap error to.
   */
  protected function validateHoneypotAndTimeTrap(FormStateInterface $form_state, int $min_seconds, string $error_field): void {
    if ($form_state->getValue('website') !== '') {
      $form_state->setErrorByName('website', $this->t('Could not send.'));
      return;
    }
    $built_at = $this->verifyTimeToken((string) $form_state->getValue('ts_token'));
    if ($built_at === NULL || $this->time->getRequestTime() - $built_at < $min_seconds) {
      $form_state->setErrorByName($error_field, $this->t('Sent too fast. Please try again.'));
    }
  }

  /**
   * Signs a timestamp so it can round-trip through a hidden field untouched.
   *
   * Same construction as core's CSRF tokens (private key + hash salt +
   * HMAC), just carrying a timestamp instead of a fixed string.
   */
  protected function makeTimeToken(int $timestamp): string {
    $hmac = Crypt::hmacBase64((string) $timestamp, $this->privateKey->get() . Settings::getHashSalt());
    return $timestamp . '-' . $hmac;
  }

  /**
   * Verifies a token from makeTimeToken(), returning its timestamp or NULL.
   */
  protected function verifyTimeToken(string $token): ?int {
    [$timestamp, $hmac] = array_pad(explode('-', $token, 2), 2, NULL);
    if (!$timestamp || !$hmac || !ctype_digit($timestamp)) {
      return NULL;
    }
    $expected = Crypt::hmacBase64($timestamp, $this->privateKey->get() . Settings::getHashSalt());
    return hash_equals($expected, $hmac) ? (int) $timestamp : NULL;
  }

}

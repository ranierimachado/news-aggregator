<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the advertising leaderboard slot.
 *
 * Placeable in any region so the ad shows across the whole site.
 */
#[Block(
  id: 'maemgaba_ad_slot',
  admin_label: new TranslatableMarkup('Advertising (leaderboard)'),
  category: new TranslatableMarkup('News engine'),
)]
class AdSlotBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'ad_label' => 'Advertising',
      'ad_size' => 'Leaderboard · 728 × 90',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);

    $form['ad_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Slot label'),
      '#description' => $this->t('Small caption shown on the ad placeholder (e.g. "Advertising").'),
      '#default_value' => $this->configuration['ad_label'],
      '#required' => TRUE,
    ];
    $form['ad_size'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Slot size'),
      '#description' => $this->t('Size/format text shown on the ad placeholder (e.g. "Leaderboard · 728 × 90").'),
      '#default_value' => $this->configuration['ad_size'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['ad_label'] = $form_state->getValue('ad_label');
    $this->configuration['ad_size'] = $form_state->getValue('ad_size');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['ad-slot'], 'aria-hidden' => 'true'],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->configuration['ad_label'],
        '#attributes' => ['class' => ['ad-label']],
      ],
      'size' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->configuration['ad_size'],
        '#attributes' => ['class' => ['ad-size']],
      ],
    ];
  }

}

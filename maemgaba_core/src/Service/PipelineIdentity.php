<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * The user account that owns every node the pipeline creates.
 *
 * Harvest and processing run from Drush/cron as the anonymous user, so a
 * create() without an explicit 'uid' falls back to the current user (0) and
 * the node renders as "Anonymous". Every pipeline create() passes uid() here
 * instead. The account is a blocked, password-less "pipeline" user provisioned
 * by maemgaba_core_post_update_pipeline_user().
 */
class PipelineIdentity {

  /**
   * Name of the provisioned pipeline account.
   */
  public const USERNAME = 'pipeline';

  /**
   * Node bundles the pipeline creates.
   */
  public const BUNDLES = ['event', 'card', 'inbound_queue'];

  /**
   * Account used when maemgaba_core.settings:pipeline_uid is unusable.
   */
  public const FALLBACK_UID = 1;

  /**
   * Resolved uid, memoized for the lifetime of the service.
   */
  protected ?int $uid = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * The uid that pipeline-created nodes should be owned by.
   *
   * Falls back (with a warning) to the account named USERNAME, then to uid
   * 1, when the setting is unset or points at a missing account — never 0.
   */
  public function uid(): int {
    if ($this->uid !== NULL) {
      return $this->uid;
    }

    $userStorage = $this->entityTypeManager->getStorage('user');
    $configured = (int) ($this->configFactory->get('maemgaba_core.settings')->get('pipeline_uid') ?? 0);
    if ($configured > 0 && $userStorage->load($configured)) {
      return $this->uid = $configured;
    }

    // The setting is environment-specific (config_ignore'd), but an import
    // that bypasses config_ignore (e.g. `drush cim --partial --source`) still
    // resets it. The provisioned account is still findable by name.
    $byName = $userStorage->loadByProperties(['name' => self::USERNAME]);
    if ($byName) {
      $account = reset($byName);
      $this->loggerFactory->get('maemgaba_core')->notice(
        'maemgaba_core.settings:pipeline_uid is not usable (@value); using the "@name" account (uid @uid).',
        ['@value' => $configured, '@name' => self::USERNAME, '@uid' => $account->id()],
      );
      return $this->uid = (int) $account->id();
    }

    $this->loggerFactory->get('maemgaba_core')->warning(
      'maemgaba_core.settings:pipeline_uid is @state; pipeline nodes will be owned by uid @fallback. Run drush updatedb to provision the pipeline user.',
      [
        '@state' => $configured > 0 ? "set to $configured but that user does not exist" : 'not set',
        '@fallback' => self::FALLBACK_UID,
      ],
    );
    return $this->uid = self::FALLBACK_UID;
  }

  /**
   * Ensures the pipeline account exists and the setting points at it.
   *
   * Called from hook_install (fresh sites) and from
   * maemgaba_core_post_update_pipeline_user() (existing sites). The account
   * has no password and is blocked: it owns content but can never log in.
   *
   * It deliberately gets no role: every pipeline write runs from Drush/cron
   * with accessCheck(FALSE), so ownership is all it needs. A role would be
   * config that each site's config/sync must carry, or the deploy's
   * config:import (which runs after updatedb) deletes it again.
   *
   * Idempotent.
   *
   * @return string[]
   *   Human-readable notes on what was changed (empty if nothing was).
   */
  public function provision(): array {
    $messages = [];
    $userStorage = $this->entityTypeManager->getStorage('user');

    $config = $this->configFactory->getEditable('maemgaba_core.settings');
    $configured = (int) ($config->get('pipeline_uid') ?? 0);
    $account = $configured > 0 ? $userStorage->load($configured) : NULL;
    if (!$account) {
      $existing = $userStorage->loadByProperties(['name' => self::USERNAME]);
      $account = $existing ? reset($existing) : NULL;
    }
    if (!$account) {
      $account = $userStorage->create([
        'name' => self::USERNAME,
        'status' => 0,
      ]);
      $account->save();
      $messages[] = 'Created blocked user "pipeline" (uid ' . $account->id() . ').';
    }

    $uid = (int) $account->id();
    if ($configured !== $uid) {
      $config->set('pipeline_uid', $uid)->save();
      $messages[] = "Set maemgaba_core.settings:pipeline_uid = $uid.";
    }
    $this->uid = $uid;

    return $messages;
  }

}

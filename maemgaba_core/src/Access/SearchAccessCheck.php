<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Access;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access check for _maemgaba_search_access (the /search route).
 *
 * When maemgaba_core.settings:search_public is TRUE the page is open to
 * everyone; SearchController then holds non-admins to a per-IP flood limit.
 * Otherwise the evaluator-trial gate below applies.
 *
 * Gated mode: admins (those who can administer the semantic search engines)
 * always get in. Everyone else needs both the
 * 'access news engine semantic search' permission and a not-yet-expired
 * maemgaba_search_until timestamp on their account: the 10-day evaluator
 * trial granted by EvaluatorRegisterForm and extended by
 * SuggestionManager::decide() on accepted corrections. Checked per request
 * (route access always re-runs), so no cron job is needed to enforce the
 * expiry.
 */
class SearchAccessCheck implements AccessInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Checks access for the /busca route.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $settings = $this->configFactory->get('maemgaba_core.settings');
    if ($settings->get('search_public')) {
      return AccessResult::allowed()->addCacheableDependency($settings);
    }
    if ($account->hasPermission('administer news engine semantic search')) {
      return AccessResult::allowed()->cachePerPermissions()->addCacheableDependency($settings);
    }
    if (!$account->hasPermission('access news engine semantic search')) {
      return AccessResult::neutral()->cachePerPermissions()->addCacheableDependency($settings);
    }

    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    $until = $user ? (int) $user->get('maemgaba_search_until')->value : 0;
    $now = $this->time->getRequestTime();

    $result = $until > $now
      ? AccessResult::allowed()->setCacheMaxAge($until - $now)
      : AccessResult::forbidden('Search access expired.')->setCacheMaxAge(0);

    $result->cachePerPermissions()->cachePerUser()->addCacheableDependency($settings);
    if ($user) {
      $result->addCacheableDependency($user);
    }
    return $result;
  }

}

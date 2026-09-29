<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use PHPUnit\Framework\Attributes\Group;

/**
 * The /search access switch: public on search_public, gated otherwise.
 */
#[Group('maemgaba_core')]
class SearchAccessTest extends PipelineKernelTestBase {

  /**
   * Public, gated and admin cases, plus the unset default.
   */
  public function testSearchPublicSwitch(): void {
    $check = $this->container->get('maemgaba_core.search_access_checker');
    $anonymous = new AnonymousUserSession();

    // Engine default (config/install): gated.
    $this->assertFalse((bool) $this->config('maemgaba_core.settings')->get('search_public'));
    $this->assertFalse($check->access($anonymous)->isAllowed());

    // Unset key (a site whose config predates the setting): gated.
    $this->config('maemgaba_core.settings')->clear('search_public')->save();
    $this->assertFalse($check->access($anonymous)->isAllowed());

    // Admin permission always gets in while gated.
    $admin = new UserSession(['uid' => 5, 'roles' => ['admin_role']]);
    $this->container->get('entity_type.manager')->getStorage('user_role')->create([
      'id' => 'admin_role',
      'label' => 'Admin role',
      'permissions' => ['administer news engine semantic search'],
    ])->save();
    $this->assertTrue($check->access($admin)->isAllowed());

    // Public: anyone, and the result depends on the settings config.
    $this->config('maemgaba_core.settings')->set('search_public', TRUE)->save();
    $result = $check->access($anonymous);
    $this->assertTrue($result->isAllowed());
    $this->assertContains('config:maemgaba_core.settings', $result->getCacheTags());
  }

}

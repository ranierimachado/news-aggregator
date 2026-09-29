<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;

/**
 * Creates path aliases for engine routes from maemgaba_core.locale.
 *
 * Engine routes have English paths (/sources, /search, …). A site that had
 * other public URLs keeps them as path aliases, listed per route in
 * maemgaba_core.locale:route_aliases as {route, alias} pairs (a Portuguese
 * site: /fontes, /busca, …). An
 * alias makes Drupal *emit* the old URL everywhere, so canonical URLs,
 * inbound links and search rankings don't change and there is no redirect
 * hop. Aliases are content (path_alias entities), so they are synced here
 * from config: on deploy (deploy hook) and by
 * `drush maemgaba:sync-route-aliases`.
 */
class RouteAliasSync {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Creates or updates one alias per configured route.
   *
   * @return array<int, array{route: string, path: string, alias: string, action: string}>
   *   One row per configured route; action is created, updated, unchanged or
   *   an error message.
   */
  public function sync(bool $dryRun = FALSE): array {
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $report = [];
    foreach ((array) $this->configFactory->get('maemgaba_core.locale')->get('route_aliases') as $item) {
      $route = (string) ($item['route'] ?? '');
      $alias = '/' . ltrim((string) ($item['alias'] ?? ''), '/');
      try {
        $path = '/' . Url::fromRoute((string) $route)->getInternalPath();
      }
      catch (\Throwable $e) {
        $report[] = [
          'route' => (string) $route,
          'path' => '',
          'alias' => $alias,
          'action' => 'error: ' . $e->getMessage(),
        ];
        continue;
      }
      $existing = $storage->loadByProperties(['path' => $path, 'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED]);
      $entity = $existing ? reset($existing) : NULL;
      if ($entity && $entity->getAlias() === $alias) {
        $action = 'unchanged';
      }
      elseif ($entity) {
        $action = 'updated';
        if (!$dryRun) {
          $entity->setAlias($alias)->save();
        }
      }
      else {
        $action = 'created';
        if (!$dryRun) {
          $storage->create(['path' => $path, 'alias' => $alias, 'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED])->save();
        }
      }
      $report[] = ['route' => (string) $route, 'path' => $path, 'alias' => $alias, 'action' => $action];
    }
    return $report;
  }

}

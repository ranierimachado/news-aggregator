<?php

namespace Drupal\maemgaba_core\Drush\Commands;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\locale\Gettext;
use Drupal\maemgaba_core\Service\RouteAliasSync;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands that apply a site's locale pack: translations and aliases.
 */
class MaemgabaLocaleCommands extends DrushCommands {

  /**
   * Extensions whose translations/<name>.<langcode>.po are imported.
   */
  protected const MODULES = ['maemgaba_core', 'maemgaba_monitor'];

  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ThemeHandlerInterface $themeHandler,
    protected RouteAliasSync $routeAliasSync,
  ) {
    parent::__construct();
  }

  /**
   * Dependency Injection factory compatible with Drush 13.
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('language_manager'),
      $container->get('module_handler'),
      $container->get('theme_handler'),
      $container->get('maemgaba_core.route_alias_sync'),
    );
  }

  /**
   * Imports the engine's and the default theme's .po for the site language.
   *
   * Engine source strings are English; a site in another language gets its
   * interface text from translations/<extension>.<langcode>.po shipped with
   * maemgaba_core, maemgaba_monitor and the default theme. Imports interface
   * strings only (no config translation), overwriting non-customized
   * translations, so it is safe to run on every deploy. No-op when the
   * default language is English or the locale module is off.
   */
  #[CLI\Command(name: 'maemgaba:locale-import', aliases: ['mg-locale-import'])]
  #[CLI\Option(name: 'langcode', description: 'Language to import (default: the site default language).')]
  public function localeImport(array $options = ['langcode' => NULL]) {
    $langcode = (string) ($options['langcode'] ?: $this->languageManager->getDefaultLanguage()->getId());
    if ($langcode === 'en') {
      $this->logger()->notice('Default language is English: nothing to import.');
      return;
    }
    if (!$this->moduleHandler->moduleExists('locale')) {
      $this->logger()->warning('The locale module is not enabled: nothing to import.');
      return;
    }

    $files = [];
    foreach (self::MODULES as $module) {
      if ($this->moduleHandler->moduleExists($module)) {
        $files[] = $this->moduleHandler->getModule($module)->getPath() . "/translations/{$module}.{$langcode}.po";
      }
    }
    $theme = $this->themeHandler->getDefault();
    $files[] = $this->themeHandler->getTheme($theme)->getPath() . "/translations/{$theme}.{$langcode}.po";

    $lids = [];
    foreach ($files as $path) {
      if (!is_file($path)) {
        continue;
      }
      $file = (object) ['filename' => basename($path), 'uri' => $path, 'langcode' => $langcode];
      $report = Gettext::fileToDatabase($file, [
        'langcode' => $langcode,
        'customized' => LOCALE_NOT_CUSTOMIZED,
        'overwrite_options' => ['not_customized' => TRUE, 'customized' => FALSE],
      ]);
      $lids = array_merge($lids, $report['strings'] ?? []);
      $this->output()->writeln(sprintf('  %s: %d added, %d updated, %d skipped', basename($path), $report['additions'], $report['updates'], $report['skips']));
    }
    if ($lids) {
      _locale_refresh_translations([$langcode], $lids);
    }
    $this->logger()->success(sprintf('Imported %d translation(s) for %s.', count($lids), $langcode));
  }

  /**
   * Creates engine route path aliases (maemgaba_core.locale:route_aliases).
   */
  #[CLI\Command(name: 'maemgaba:sync-route-aliases', aliases: ['mg-route-aliases'])]
  #[CLI\Option(name: 'dry-run', description: 'Report what would change without saving.')]
  public function syncRouteAliases(array $options = ['dry-run' => FALSE]) {
    $report = $this->routeAliasSync->sync((bool) $options['dry-run']);
    if (!$report) {
      $this->output()->writeln('No route aliases configured.');
      return;
    }
    foreach ($report as $row) {
      $this->output()->writeln(sprintf('  %-9s %-32s %-24s → %s', $row['action'], $row['route'], $row['path'], $row['alias']));
    }
  }

}

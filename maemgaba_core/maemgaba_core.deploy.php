<?php

/**
 * @file
 * Deploy hooks for maemgaba_core.
 *
 * Deploy hooks run via `drush deploy:hook`, AFTER config:import. Use them
 * for data migrations that need config shipped in the same release (new
 * fields, new config objects): post_update hooks run before config:import,
 * so those objects don't exist yet there. deploy/deploy.sh runs updb →
 * config:import → deploy:hook.
 */

declare(strict_types=1);

use Drupal\Component\Serialization\Yaml;
use Drupal\field\Entity\FieldConfig;
use Drupal\maemgaba_core\Migration\BiasScoreBackfill;
use Drupal\maemgaba_core\Migration\ContentLanguageRelabel;
use Drupal\maemgaba_core\Migration\ModelTextDecode;

/**
 * Backfill field_bias_score / field_default_bias_score from the legacy lists.
 */
function maemgaba_core_deploy_bias_score_backfill(): string {
  $counts = (new BiasScoreBackfill(\Drupal::database(), \Drupal::service('cache.entity')))->run();
  $parts = [];
  foreach ($counts as $table => $count) {
    $parts[] = "{$table}: {$count}";
  }
  return 'Bias scores backfilled (left→-1, center→0, right→+1). ' . implode('; ', $parts);
}

/**
 * Create path aliases that keep a site's legacy URLs (maemgaba_core.locale).
 */
function maemgaba_core_deploy_route_aliases(): string {
  $report = \Drupal::service('maemgaba_core.route_alias_sync')->sync();
  $parts = array_map(fn (array $row) => "{$row['alias']} → {$row['path']} ({$row['action']})", $report);
  return $parts ? 'Route aliases: ' . implode('; ', $parts) : 'No route aliases configured.';
}

/**
 * Relabel content stored as "en" to the site's language (monolingual sites).
 *
 * A site whose content predates the language module has it stored as "en"
 * whatever language the text is in; once the site's language is set, the
 * stored langcode must follow. No-op when the default language is English.
 */
function maemgaba_core_deploy_content_langcode(): string {
  $default = \Drupal::languageManager()->getDefaultLanguage()->getId();
  if ($default === 'en') {
    return 'Default language is English: nothing to relabel.';
  }
  $relabel = new ContentLanguageRelabel(\Drupal::database(), \Drupal::entityTypeManager(), \Drupal::moduleHandler());
  $counts = $relabel->run('en', $default);
  return "Content relabelled en → {$default}: " . array_sum($counts) . ' row(s) in ' . count($counts) . ' table(s). Search API trackers rebuilt (re-index to refresh).';
}

/**
 * Add the P7 US-locale-pack shared fields, empty on existing content.
 *
 * Config/install only applies at module install time, so a site that
 * already has maemgaba_core enabled needs these created here.
 * Idempotent: skips anything that already exists, so it is also safe on a
 * site that picked the fields up via config/install (a fresh install run
 * after this release). Reads the same config/install YAML that a fresh
 * install uses and loads it with createFromStorageRecord(), because
 * FieldStorageConfig::create()/FieldConfig::create() mis-handle a nested
 * `allowed_values` array (list_string fields): config schema casting
 * recurses into the scalar `label` as if it were a sequence and throws
 * "The configuration property settings.allowed_values.0.label.0 doesn't
 * exist." createFromStorageRecord(), used to load config/install in
 * PipelineKernelTestBase, does not have this problem.
 */
function maemgaba_core_deploy_us_locale_fields(): string {
  $entityTypeManager = \Drupal::entityTypeManager();
  $configDir = \Drupal::service('extension.list.module')->getPath('maemgaba_core') . '/config/install';

  $storages = [
    'node.field_framing_line',
    'node.field_syndicated_from',
    'node.field_partial_text',
    'node.field_section',
    'node.field_published_prior',
    'node.field_prior_source',
  ];
  $fields = [
    'node.card.field_framing_line',
    'node.card.field_syndicated_from',
    'node.card.field_partial_text',
    'node.card.field_section',
    'node.feed_source.field_published_prior',
    'node.feed_source.field_prior_source',
    'node.feed_source.field_section',
  ];

  $created = [];
  $create = function (string $entityTypeId, string $configPrefix, string $id) use ($entityTypeManager, $configDir, &$created): void {
    $storage = $entityTypeManager->getStorage($entityTypeId);
    if ($storage->load($id)) {
      return;
    }
    $data = Yaml::decode((string) file_get_contents("{$configDir}/{$configPrefix}.{$id}.yml"));
    $storage->createFromStorageRecord($data)->save();
    $created[] = $id;
  };

  foreach ($storages as $id) {
    $create('field_storage_config', 'field.storage', $id);
  }
  foreach ($fields as $id) {
    $create('field_config', 'field.field', $id);
  }

  $micro_summary_note = 'field_micro_summary already optional.';
  $micro_summary = FieldConfig::loadByName('node', 'card', 'field_micro_summary');
  if ($micro_summary && $micro_summary->isRequired()) {
    $micro_summary->setRequired(FALSE);
    $micro_summary->save();
    $micro_summary_note = 'field_micro_summary set to optional.';
  }

  return ($created ? 'Created: ' . implode(', ', $created) . '. ' : 'All US-locale-pack fields already existed. ') . $micro_summary_note;
}

/**
 * Add the P9 field instances (existing storages, new bundles), empty.
 *
 * The inbound_queue.field_section carries a feed's news/opinion section from
 * harvest to the card; feed_source.field_partial_text lets the registry mark
 * a feed whose pages are unusable. Both reuse storages P7 created. Same
 * idempotent createFromStorageRecord() approach as the P7 hook above; sites
 * must also carry the YAML in config/sync (deploy runs cim before this).
 */
function maemgaba_core_deploy_p9_fields(): string {
  $storage = \Drupal::entityTypeManager()->getStorage('field_config');
  $configDir = \Drupal::service('extension.list.module')->getPath('maemgaba_core') . '/config/install';
  $created = [];
  foreach (['node.inbound_queue.field_section', 'node.feed_source.field_partial_text'] as $id) {
    if ($storage->load($id)) {
      continue;
    }
    $data = Yaml::decode((string) file_get_contents("{$configDir}/field.field.{$id}.yml"));
    $storage->createFromStorageRecord($data)->save();
    $created[] = $id;
  }
  return $created ? 'Created: ' . implode(', ', $created) . '.' : 'P9 fields already existed.';
}

/**
 * Create the read-only "Ask the data" views (ask_sources and friends).
 *
 * Skipped while maemgaba_core.settings:ask.enabled is off; run
 * `drush maemgaba:ask-views` after switching it on. A database user without
 * CREATE VIEW reports the error here instead of failing the deploy; root can
 * then create the views from `drush maemgaba:ask-views --print`.
 */
function maemgaba_core_deploy_ask_views(): string {
  if (!\Drupal::config('maemgaba_core.settings')->get('ask.enabled')) {
    return 'Ask the data is off: no views created (run drush maemgaba:ask-views after enabling it).';
  }
  try {
    $views = \Drupal::service('maemgaba_core.ask_schema')->createViews();
    return 'Created or replaced ' . implode(', ', $views) . '.';
  }
  catch (\Throwable $e) {
    \Drupal::logger('maemgaba_core')->error('Ask views not created: @msg', ['@msg' => $e->getMessage()]);
    return 'Ask views NOT created (' . get_class($e) . '); see the log, then run drush maemgaba:ask-views or have root run the output of --print.';
  }
}

/**
 * Decode "&amp;" and friends that drupal/ai's HostnameFilter stored (v0.8.1).
 *
 * Model-written summaries, points, framing lines and event/card titles; see
 * ModelTextDecode. Counts are stored values that contain one of the
 * entities, before and after.
 */
function maemgaba_core_deploy_model_text_decode(): string {
  $result = (new ModelTextDecode(\Drupal::database(), \Drupal::entityTypeManager()))->run();
  $format = function (array $counts): string {
    $parts = [];
    foreach ($counts as $field => $count) {
      $parts[] = "{$field} {$count}";
    }
    return $parts ? implode(', ', $parts) : 'none';
  };
  $message = sprintf(
    'Model text entities: before [%s]; decoded [%s] on %d node(s); after [%s].',
    $format($result['before']),
    $format($result['values']),
    $result['nodes'],
    $format($result['after']),
  );
  if ($result['skipped']) {
    $message .= ' Left alone (decoding would create markup): ' . implode('; ', $result['skipped']) . '.';
  }
  return $message;
}

<?php

/**
 * @file
 * Post update functions for maemgaba_core.
 *
 * Rules (see docs/maemgaba_core-module-guide.md §9):
 * - A post_update runs BEFORE config:import in deploy.sh, and any config
 *   object it creates that is not in config/sync is deleted by that import.
 *   Post_updates may only touch config-ignored keys, content and field data.
 * - Data migrations that need config shipped in the same release (new
 *   fields, new config objects) go in maemgaba_core.deploy.php instead.
 */

declare(strict_types=1);

/**
 * Implements hook_removed_post_updates().
 *
 * Every post_update up to P3 (2026-09) was applied on the only site that
 * predates them (verified: no pending updates). They carried
 * site-specific data (Brazilian feed seeding, Portuguese labels, menu links)
 * that now lives in locale packs (config/locale/<pack>/) or config/sync, so
 * they are removed; fresh installs register them as done anyway.
 */
function maemgaba_core_removed_post_updates(): array {
  return [
    'maemgaba_core_post_update_add_consensus_fields' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_configure_ai_stack' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_editable_feed_sources' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_social_relevance_fields' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_relevance_prompt' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_cards_review_view' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_event_relevance' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_events_review_view' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_remove_events_view_page' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_remove_events_view_frontpage_display' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_place_ad_block' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_consensus_prompt' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_retire_featured_field_ui' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_purge_legacy_news_queue' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_ai_eval_tables_create' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_bias_fewshot_examples' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_call_log_attempts_and_reasoning_tokens' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_call_log_context_columns' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_clustering_retrieval_setting' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_search_log_table_create' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_footer_legal_links' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_footer_legal_links_fix_uris' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_event_card_count_field' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_suggestion_table_create' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_search_until_user_field' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_card_reviewed_at_field' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_review_cards_prompt' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_pipeline_user' => 'P3 locale pack (2026-09)',
    'maemgaba_core_post_update_remove_event_category_field' => 'P3 locale pack (2026-09)',
  ];
}

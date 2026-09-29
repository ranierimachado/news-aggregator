<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

/**
 * Estimates USD cost from token counts, per provider/model.
 *
 * Rates are keyed by "{provider plugin id}:{model id}" using the exact
 * strings AiCallLogger stores (e.g. 'gemini:models/gemini-3.6-flash'),
 * sourced from each vendor's official pricing page (see RATES below).
 * A provider/model combination not in RATES falls back to
 * FALLBACK_INPUT_USD/FALLBACK_OUTPUT_USD — a deliberately conservative
 * placeholder, not a verified price — so a new/renamed model doesn't
 * silently borrow an unrelated model's rate.
 *
 * Callers MUST group token sums by provider/model before calling
 * estimateUsd() and add the per-group results together. Summing tokens
 * across providers first and pricing the blend once (as this class did
 * before 2026-08-19) silently applies one model's rate to another
 * model's usage — this was found to undercount actual spend by ~30x
 * once a second provider (Anthropic) joined the mix. See
 * docs/ai-learning/phase-1b-validate-vs-google-ai-studio.md for the
 * earlier single-provider mis-pricing finding.
 */
class AiPricing {

  /**
   * USD-per-million-token rates by "{provider}:{model}" key.
   *
   * - gemini:models/gemini-3.6-flash —
   *   https://ai.google.dev/gemini-api/docs/pricing (Standard tier, valid
   *   through 2026-12-31; doubles 2027-01-01).
   * - gemini:models/gemini-embedding-001 — same page; embeddings have no
   *   output tokens, so 'output'/'cached' mirror 'input'.
   * - gemini:models/gemini-3.5-flash — same page (Standard tier). Verified
   *   2026-08-23 against a real bill: 102 review_cards calls (heavy
   *   thinking — 55,688 reasoning tokens, billed at the output rate) cost
   *   this rate ~$0.62 vs ~$1.00 actually deducted once the billing
   *   dashboard settled — same order of magnitude, unlike the fallback
   *   rate's ~$0.03 for the same traffic, which was off by >30x.
   * - anthropic:claude-sonnet-5, anthropic:claude-sonnet-4-6 — same page
   *   (base input / output; 'cached' is the cache-hit read rate, not the
   *   cache-write rate, since AiCallLogger's cached_tokens counts reads).
   *   Both are listed because prod logged real traffic on *both* models the
   *   same day (2026-08-18) — don't assume only the currently-configured
   *   default model appears in the log; add an entry per model actually
   *   seen in maemgaba_ai_call_log, not just per configured default.
   * - anthropic:claude-haiku-4-5, anthropic:claude-opus-5 — added 2026-09-23
   *   for the prod Anthropic routing (Gemini AI-Studio is blocked from the
   *   droplet), from https://platform.claude.com/docs/en/about-claude/pricing
   *   (base input / output / cache hits, 0.1x input). Same page confirms
   *   Sonnet 5's $2/$10 is now the standard price (the planned 2026-09-01
   *   increase to $3/$15 was cancelled).
   *
   * Deliberately NOT priced: "-latest" aliases (e.g.
   * models/gemini-flash-lite-latest). Google documents these as rolling —
   * hot-swapped to whatever the current release is, with only 2 weeks'
   * notice on breaking changes — so there is no single stable rate to cite
   * as "the" official price the way a pinned model id has. Checked
   * 2026-08-23: 200 classify_cluster/classify_bias calls on
   * gemini-flash-lite-latest burned 1M+ input tokens for ~$0.04 real
   * spend — an order of magnitude below every current Flash-Lite paid-tier
   * rate ($0.10-$0.30/1M input alone), which points to this traffic
   * landing in Google AI Studio's free tier right now rather than any paid
   * rate applying. Hardcoding a paid-tier number here would make the
   * estimate worse, not better, until that's confirmed on the account's
   * actual billing/quota page.
   *
   * Verify and update whenever a new model becomes the configured default
   * (check config/sync/ai.settings.yml) — this table does not auto-update.
   */
  protected const RATES = [
    'gemini:models/gemini-3.6-flash' => [
      'input' => 0.75,
      'output' => 3.75,
      'cached' => 0.075,
    ],
    'gemini:models/gemini-3.5-flash' => [
      'input' => 1.50,
      'output' => 9.00,
      'cached' => 0.15,
    ],
    'gemini:models/gemini-embedding-001' => [
      'input' => 0.15,
      'output' => 0.15,
      'cached' => 0.15,
    ],
    'anthropic:claude-sonnet-5' => [
      'input' => 2.00,
      'output' => 10.00,
      'cached' => 0.20,
    ],
    'anthropic:claude-haiku-4-5' => [
      'input' => 1.00,
      'output' => 5.00,
      'cached' => 0.10,
    ],
    'anthropic:claude-opus-5' => [
      'input' => 5.00,
      'output' => 25.00,
      'cached' => 0.50,
    ],
    'anthropic:claude-sonnet-4-6' => [
      'input' => 3.00,
      'output' => 15.00,
      'cached' => 0.30,
    ],
    // Self-hosted via Ollama (Phase 6 B1 local routing): no per-token cost.
    // Explicitly 0.0 so local inference doesn't get priced at the fallback
    // rate and pollute the cost dashboard / "cost saved" comparisons.
    'ollama:qwen2.5:14b' => [
      'input' => 0.0,
      'output' => 0.0,
      'cached' => 0.0,
    ],
    'ollama:qwen2.5:32b' => [
      'input' => 0.0,
      'output' => 0.0,
      'cached' => 0.0,
    ],
    'ollama:gemma3:27b' => [
      'input' => 0.0,
      'output' => 0.0,
      'cached' => 0.0,
    ],
  ];

  /**
   * USD per 1M input tokens when provider/model isn't in RATES.
   *
   * Unverified placeholder — kept low deliberately so an unrecognized
   * model reads as "obviously not a real number" rather than looking
   * trustworthy. Do not treat a cost figure built from this fallback as
   * budget-accurate; add the real model to RATES instead.
   */
  public const FALLBACK_INPUT_USD = 0.10;

  /**
   * USD per 1M output tokens when provider/model isn't in RATES.
   *
   * Also applied to reasoning/thinking tokens in the fallback case.
   */
  public const FALLBACK_OUTPUT_USD = 0.40;

  /**
   * Estimates the USD cost of a call or batch of calls for one provider/model.
   *
   * @param string $provider
   *   AI provider plugin id, e.g. 'gemini' or 'anthropic'.
   * @param string $model
   *   Model id used for the call, e.g. 'models/gemini-3.6-flash'.
   * @param int $inputTokens
   *   Total input/prompt tokens for this provider/model.
   * @param int $outputTokens
   *   Total output/completion tokens for this provider/model.
   * @param int $reasoningTokens
   *   Total thinking/reasoning tokens (billed at the output rate).
   * @param int $cachedTokens
   *   Total cached-context tokens.
   *
   * @return float
   *   Estimated cost in USD for this provider/model's usage only — sum
   *   across providers/models yourself if you need a combined total.
   */
  public function estimateUsd(string $provider, string $model, int $inputTokens, int $outputTokens, int $reasoningTokens = 0, int $cachedTokens = 0): float {
    $rates = self::RATES["{$provider}:{$model}"] ?? [
      'input' => self::FALLBACK_INPUT_USD,
      'output' => self::FALLBACK_OUTPUT_USD,
      'cached' => self::FALLBACK_INPUT_USD,
    ];

    return ($inputTokens / 1_000_000 * $rates['input'])
      + (($outputTokens + $reasoningTokens) / 1_000_000 * $rates['output'])
      + ($cachedTokens / 1_000_000 * $rates['cached']);
  }

}

# Changelog

All notable changes to `ranierimachado/news-aggregator`. Versions follow semantic
versioning; sites that share the engine pin the same tag. Module machine
names keep the `maemgaba_` prefix (see the README).

## v1.0.0 — 2026-09-29

First public release.

The engine was developed privately from July 2026 through fourteen tagged
releases while running two production sites in two countries and two
languages. This release is that code with everything site-specific moved out:
the two real locale packs (prompts, outlet registries, golden sets,
methodology copy) now live in the sites' own repositories, and a synthetic
sample pack (`maemgaba_core/config/locale/sample/`) takes their place so a
fresh install, the tests and the eval commands have a complete pack to run
against.

What the package holds:

- **Pipeline.** RSS ingest with Readability extraction, robots.txt and an
  identified User-Agent; MinHash reprint detection before any AI call;
  per-article classification of bias (−2..+2), topic, relevance and a
  framing line with source-blind prompts and a versioned rubric; clustering
  into neutral events by recency or 768-d vector retrieval; event-level
  synthesis behind a verbatim-overlap guard; opinion split; outbound
  click-through logging without reader identifiers.
- **Read side.** Public semantic search on MariaDB native vectors behind a
  per-IP flood limit; optional Solr 9 keyword + dense-vector hybrid with
  facets; a read-only MCP server; "Ask the data", a natural-language-to-SQL
  page over four read-only views with a token-level validator, a probe that
  refuses over-privileged database accounts, statement limits and a log.
- **Providers and cost.** Per-operation routing across Anthropic, Google
  Gemini and local Ollama through drupal/ai; every call logged with tokens
  and estimated cost; a daily and monthly cost dashboard (`maemgaba_monitor`).
- **Evals.** A calibration harness that classifies a sample twice under a
  paraphrased prompt and reports agreement with outlet priors and hand
  labels, skew and instability; golden-set replay with per-class precision
  and recall; search evals (hit@1, hit@3, off-topic rejection, latency);
  ask evals against reference SQL plus hostile prompts.
- **Operations.** Schema as update hooks, fields as idempotent deploy hooks,
  locale packs as versioned config, 27 Drush commands, pt-BR interface
  translation, kernel and unit tests (about 200) run by GitHub Actions with
  phpcs and a gitleaks history scan.

Identifiers are engine-neutral as of this release (`maemgaba_prompt`,
`... news engine ...` permissions, `/admin/config/services/news-engine/`,
`events_index`).

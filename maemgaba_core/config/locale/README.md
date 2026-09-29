# Locale packs

A *locale pack* is everything that makes the engine one particular site:

- the bias display scale (how many buckets, their labels and colours)
- the topic vocabulary
- the language the AI writes in, and the prompts
- the outlet registry (feeds, declared editorial lines, published priors)
- the interface language and date formats
- the site's legacy URLs (as path aliases)
- the methodology page text
- the golden set the evals replay

The engine code knows none of it. Source strings are English and the
module's `config/install` holds English, country-neutral defaults. This
directory holds `sample/`, an invented country with five buckets, seven
fictional outlets on `.example` domains and an 18-item synthetic golden set,
kept so the tests, the evals and a fresh install have something to run
against. A real site keeps its own pack in its own (private) repository,
next to its `config/sync`.

Nothing here is loaded automatically. Drupal only reads a module's
`config/install`, `config/optional` and `config/schema`. A site takes a pack
by copying its config into the site's `config/sync` (then `drush cim`) and
running the commands below.

## Contents of a pack

| Piece | Where | Consumed by |
|---|---|---|
| Bias display buckets | `config/maemgaba_core.spectrum.yml` | `SpectrumService`: event page, teasers, sources page, MCP, consensus prompt lines |
| Balance/divergence labels, consensus mode, golden-set path, User-Agent | `settings-excerpt.yml`, merged by hand into `maemgaba_core.settings` | `SpectrumService::balanceLabel()`, `::divergence()`, `ConsensusService`, `maemgaba:eval`, the fetcher |
| Topic vocabulary | `config/maemgaba_core.topics.yml` | `field_topic` allowed values, AI schema enum, `[topic_list]` |
| AI output language, prompt fragments, search examples, avatar stopwords, route aliases | `config/maemgaba_core.locale.yml` | `[output_language]`, candidate lines in prompts, search page, sources page, `maemgaba:sync-route-aliases` |
| AI prompts | `config/maemgaba_core.maemgaba_prompt.*.yml` | `AiAnalyzerService` (see tokens below). The sample pack uses the English defaults from `config/install` |
| Methodology page text | `config/maemgaba_core.methodology.yml` | `MethodologyController` |
| Date formats, interface language, translation | `core.date_format.*`, `language.*`, `locale.settings`, a `.po` file | Drupal language system, `drush maemgaba:locale-import` |
| Outlet registry | `feeds.yml` | `drush maemgaba:seed-feeds` |
| Golden set for evals | `golden/classify_cluster.json` | `drush maemgaba:eval` |

## Rules

- **Scores are shared; buckets are not.** Every site stores bias as an
  integer from −2 to +2. Buckets must cover −2..+2 exactly once, in order
  (`SpectrumService::validate()`). Bucket `key`s become CSS classes, so keep
  them stable once a theme styles them.
- **Topic keys are stored data.** Never rename or remove a key that cards
  already carry; relabel it instead.
- **Prompt tokens.** Call tokens: `[article_text]`, `[existing_events]`,
  `[card_title]`, `[card_summary]`, `[current_bias_score]`,
  `[current_topic]`, `[event_title]`, `[neutral_summary]`,
  `[perspectives]`. Global tokens filled from config: `[output_language]`,
  `[site_name]`, `[topic_list]`, `[rubric]`. Prompts must ask for
  `bias_score` (−2..+2), `bias_confidence` and `bias_evidence`.
- **Legacy URLs are path aliases, not redirects.** Engine routes are English
  (`/sources`, `/search`, `/suggest-correction`, `/contact`, `/evaluator`,
  `/methodology`). `route_aliases` makes Drupal emit the site's own paths
  everywhere, so canonical URLs and inbound links do not change and there is
  no redirect hop. The English paths also answer.
- **Methodology bodies** are trusted editorial HTML. They support the tokens
  `[site_name]` and `[route:ROUTE_NAME]`; use the route token for internal
  links so aliases apply.
- **Golden set.** A JSON list of `{id, title, body_excerpt, expected:
  {bias, social_relevance, topic}}`. `bias` is the legacy class
  (`left`, `center`, `right`) so evals stay comparable across sites with
  different bucket counts; `social_relevance` is `relevant`, `maybe` or
  `irrelevant`. Build one with `drush maemgaba:golden-export`,
  then correct the labels by hand.

## Loading a pack into a site

```bash
# 1. Config: copy, merge the settings excerpt, then import.
cp web/modules/custom/news-aggregator/maemgaba_core/config/locale/sample/config/*.yml config/sync/
drush cim -y
# 2. Data and interface.
drush maemgaba:seed-feeds web/modules/custom/news-aggregator/maemgaba_core/config/locale/sample/feeds.yml --dry-run
drush maemgaba:seed-feeds web/modules/custom/news-aggregator/maemgaba_core/config/locale/sample/feeds.yml
drush deploy:hook -y            # route aliases, backfills
drush maemgaba:locale-import    # only if the pack ships a .po
drush cr
```

For a site whose content predates the language module, the deploy hook
`maemgaba_core_deploy_content_langcode` relabels stored `en` content to the
site's default language; on an English-default site it does nothing.

# News Aggregator

[![CI](https://github.com/ranierimachado/news-aggregator/actions/workflows/ci.yml/badge.svg)](https://github.com/ranierimachado/news-aggregator/actions/workflows/ci.yml)
![Drupal 11](https://img.shields.io/badge/Drupal-11.4-0678BE)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)
![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

A Drupal 11 engine that shows how the same news story is told across the
political spectrum. It reads outlet RSS feeds, has a language model score
each article's **framing** on a shared −2..+2 scale, groups articles about
the same fact into a neutral *event*, writes a "where coverage agrees, where
it differs" synthesis, and exposes it all through a public site, a semantic
search, a read-only MCP server and a natural-language "ask the data" page.

Bias is classified **per article**, not inherited from the outlet. The
outlet's published rating is only a prior to compare against, so a
left-leaning paper's straight news story can read Center, and the site says
so. Prompts can be *blind*: the article's own outlet name is redacted so the
model judges the text, not the masthead.

The same code runs two production sites in two countries, two languages and
two bucket schemes (three and five). The code knows neither country.
Everything that makes a site *that* site is versioned config (a *locale
pack*), reviewed in pull requests like code. This repository ships the
engine, its tests and a synthetic sample pack; the real packs live in the
sites' private repositories.

| Module | What it holds |
|---|---|
| `maemgaba_core` | Content model (`event`, `card`, `feed_source`, `inbound_queue` node types), the pipeline services, AI routing, prompts as config entities, 27 Drush commands, controllers, templates, kernel and unit tests, the sample locale pack |
| `maemgaba_monitor` | Daily and monthly AI cost and usage dashboard over the per-call log |

## Architecture

```mermaid
flowchart LR
    subgraph pack["Locale pack (config, per site)"]
        F[feeds.yml<br/>outlet registry]
        P[prompts · rubric<br/>spectrum · topics]
    end

    F -->|drush maemgaba:seed-feeds| I

    subgraph pipeline["Pipeline (Drush, cron)"]
        I["Ingest<br/>RSS + Readability<br/>robots.txt, User-Agent<br/>MinHash reprint detection"]
        C["Classify<br/>bias −2..+2, topic,<br/>relevance, framing line<br/>source-blind prompts"]
        K["Cluster<br/>card → existing or new event<br/>recency or 768-d vector retrieval"]
        S["Synthesis<br/>common ground /<br/>disputed points<br/>verbatim-overlap guard"]
        I --> C --> K --> S
    end

    P -.-> C
    P -.-> S

    subgraph ai["drupal/ai providers"]
        A[Anthropic]
        G[Gemini]
        O[Ollama, local]
    end
    C <-->|per-operation routing<br/>per-call cost log| ai
    S <--> ai

    subgraph out["Read side"]
        W["Site pages<br/>event · sources · methodology"]
        V["/search<br/>MariaDB native VECTOR"]
        X["/explore<br/>Solr 9 hybrid: BM25 + dense"]
        M["MCP server<br/>read-only tools"]
        Q["/ask<br/>NL → validated SQL<br/>over 4 read-only views"]
    end
    S --> W
    K --> V
    K --> X
    W --> M
    W --> Q

    subgraph evals["Evals"]
        E1["maemgaba:calibrate<br/>two runs, paraphrased prompt"]
        E2["maemgaba:eval<br/>golden set replay"]
        E3["maemgaba:search-eval<br/>hit@1, hit@3, latency"]
        E4["maemgaba:ask-eval<br/>reference SQL match"]
    end
    C -.-> E1
    C -.-> E2
    V -.-> E3
    X -.-> E3
    Q -.-> E4
```

- **Ingest** (`drush maemgaba:harvest`) fetches each registry feed with an
  identified User-Agent, honours robots.txt, extracts article text with
  Readability, and records whether it got the full text or only a headline
  and abstract (paywalls). A MinHash fingerprint over 5-word shingles spots
  wire reprints before any AI call, so eight outlets running one AP story
  count as one perspective.
- **Classify** (`drush maemgaba:process`) sends each article to the model
  configured for that operation with the site's rubric rendered into the
  prompt, and asks for a structured JSON answer: `bias_score`,
  `bias_confidence`, `bias_evidence`, topic, relevance and a one-sentence
  framing line. Opinion sections are classified but never counted toward
  balance.
- **Cluster** assigns each card to an existing event or opens a new one,
  using recency or vector retrieval (Gemini embeddings truncated to 768
  dimensions, cosine distance, MariaDB native `VECTOR`) for the candidates.
- **Synthesis** writes an event-level "where coverage agrees, where it
  differs", lazily on first visit or eagerly once two outlets cover it. A
  verbatim-overlap guard rejects any output that reproduces an 8-word run
  from a source.
- **Search** indexes events in Search API on a MariaDB vector backend and,
  optionally, on Apache Solr 9 with keyword + dense-vector hybrid ranking and
  facets. An MCP server exposes the same data read-only to AI clients.
- **Ask the data** answers a visitor's question with one SQL statement the
  model writes over four read-only views, checked by a token-level
  validator, run as a database user that can see nothing else.

Every AI call is logged with operation, model, tokens and estimated cost.
Each operation (`classify_cluster`, `relevance`, `synthesize_consensus`,
`ask_sql`, …) can be routed to a different provider and model from the admin
UI, so bulk triage can run on a local Ollama model while framing-sensitive
work goes to a frontier model.

## Copyright display policy

The engine is built so a site can show *how* outlets framed a story without
republishing the outlets' work. A site can switch off the per-article
summary entirely and show only the headline, the outlet, the bias reading
and a link out. The one per-article sentence it may show is a model-written
framing line, capped in words, that describes the angle rather than quoting
the text. Synthesis carries a configurable "AI-generated" label naming the
rubric version it was produced under. Every outbound link is counted
(no IP, no cookie) so the methodology page can publish the click-through
the site sends to the outlets.

## Calibration and evals

A rubric is only useful if you know how it behaves.

- `drush maemgaba:calibration-sample` builds a YAML of recent articles from
  the outlet registry. `drush maemgaba:calibrate` classifies each one twice,
  the second time with a paraphrased prompt (`<prompt>_paraphrase`), and
  reports agreement with the outlet priors, agreement with hand labels,
  skew, run-to-run instability, confidence, and a split by full versus
  headline-only text. The systematic error it is designed to catch is
  compression toward Center, not skew.
- `drush maemgaba:eval` replays a hand-reviewed *golden set* through the
  enabled prompt and stores accuracy, precision and recall per class in
  `maemgaba_eval_run`, so prompt versions are comparable over time.
  `drush maemgaba:golden-export` builds the first draft of a golden set from
  real cards for a person to correct.
- `drush maemgaba:search-eval --engine=mariadb|solr` scores a query file
  (hit@1, hit@3, off-topic rejection, latency, keyword baseline).
- `drush maemgaba:ask-eval` runs a question set through the NL-to-SQL path
  and compares result rows against hand-written reference SQL, plus hostile
  prompts that must be refused.

`docs/calibration/` ships a sample of each input file. The production
results (calibration on real corpora, 25-question ask evals) are kept in the
sites' private repositories; the harness that produces them is here.

## Running it against the sample config

The package ships a complete fictional locale pack in
`maemgaba_core/config/locale/sample/`: five buckets, seven invented outlets
on `.example` domains, an 18-item synthetic golden set. Its feeds do not
resolve, so the pipeline has nothing to fetch until you point `feeds.yml` at
real outlets of your choice; everything else runs.

```bash
composer create-project drupal/recommended-project:^11.4 mysite && cd mysite
composer require ranierimachado/news-aggregator drupal/gemini_provider
drush site:install -y && drush en -y maemgaba_core maemgaba_monitor
drush php:eval "\Drupal::service('maemgaba_core.pipeline_identity')->provision();"

# Load the sample pack (see maemgaba_core/config/locale/README.md).
cp web/modules/custom/news-aggregator/maemgaba_core/config/locale/sample/config/*.yml config/sync/
drush cim -y
drush maemgaba:seed-feeds web/modules/custom/news-aggregator/maemgaba_core/config/locale/sample/feeds.yml

# Configure a chat provider and an embeddings provider in drupal/ai (keys via drupal/key),
# then route operations at /admin/config/services/news-engine/operation-models.
drush maemgaba:ai-preflight            # checks providers, models and the vector index
drush maemgaba:harvest                 # fetch feeds into inbound_queue
drush maemgaba:process                 # classify + cluster queued items
drush maemgaba:rollup-events           # ranking
drush maemgaba:status                  # what happened
drush maemgaba:eval --limit=18         # replay the sample golden set through the enabled prompt
```

`drush maemgaba:eval`, `maemgaba:search-eval` and `maemgaba:ask-eval` default
to the shipped sample files and say so; a site passes `--file` for its own.

## Locale packs

A site's *locale pack* is everything that makes the engine one particular
site: display buckets, topic vocabulary, the language the AI writes in and
its prompts, the outlet registry with declared lines and published priors,
interface language, legacy URLs as path aliases, methodology copy, and the
golden set. Nothing under `config/locale/` is loaded automatically: a site
copies a pack into its own `config/sync`, imports it, and keeps it there.
[`maemgaba_core/config/locale/README.md`](maemgaba_core/config/locale/README.md)
documents every piece, the rules (scores are shared, buckets are not; topic
keys are stored data) and the load procedure.

## Installing on a site

A site is a plain `drupal/recommended-project` that requires the package and
keeps its locale pack in `config/sync`. It never edits the engine in place.

```bash
composer require ranierimachado/news-aggregator:^1.0
drush en maemgaba_core maemgaba_monitor
```

`composer/installers` places the package at
`web/modules/custom/news-aggregator`. Pin a tag in production; sites that
share the engine should pin the same tag. Schema changes ship as update
hooks, fields as idempotent deploy hooks. A site deploys in this order:

```text
updb → cim → deploy:hook → maemgaba:locale-import → cr
```

**Dependencies.** `drupal/ai` (chat, embeddings, vector database
abstraction), `drupal/ai_provider_anthropic`, `drupal/ai_vdb_provider_mariadb`
(MariaDB 11.7+ native `VECTOR`), `drupal/key`, `drupal/mcp`,
`drupal/search_api`, `drush/drush` 13 and `fivefilters/readability.php`.
Gemini, Ollama, Milvus and `search_api_solr_dense_vector` are optional
suggestions; the Solr code is inert without it.

## Running the tests

Kernel and unit tests live in `maemgaba_core/tests` (about 200 tests). They
need a Drupal site with `drupal/core-dev` and a database. The quickest route
is what CI does:

```bash
composer create-project drupal/recommended-project:^11.4 build --no-install
cd build
composer config repositories.engine '{"type": "path", "url": "../news-aggregator", "options": {"symlink": false}}'
composer require "ranierimachado/news-aggregator:*@dev" drupal/core-dev:^11.4 "twig/twig:<3.30" -W
SIMPLETEST_DB=mysql://user:pass@127.0.0.1/drupal vendor/bin/phpunit -c web/core web/modules/custom/news-aggregator
vendor/bin/phpcs --standard=../news-aggregator/phpcs.xml.dist
```

The coding standard is Drupal's. CI (`.github/workflows/ci.yml`) runs
phpcs, the kernel and unit tests against PHP 8.3 and MariaDB 11.8, and a
gitleaks scan of the full history on every pull request and tag, and must be
green before a release is tagged. `scripts/check-version.sh` checks that the
two `.info.yml` versions, the latest CHANGELOG entry and the tag agree.
Twig is capped below 3.30 because 3.30.0 breaks Drupal 11.4 template
rendering. Install the pre-commit hook once with `pre-commit install`; it
refuses a commit that stages a secret.

## Why the `maemgaba_` prefix

`maemgaba` is the engine's internal codename from the project it grew out
of. Module machine names, service ids, table names and Drush commands keep
it, because renaming a Drupal module's machine name means migrating config,
tables and cron on every site that runs it. Every label a person reads says
"News Engine".

## Security, contributing, license

Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).
Issues are welcome, pull requests by discussion: see
[CONTRIBUTING.md](CONTRIBUTING.md). Licensed under the GPL-2.0-or-later, the
same license as Drupal; see [LICENSE](LICENSE).

# Locale pack: `sample` (an invented country, English, five buckets)

Everything in this pack is fictional: the seven outlets, their `.example`
feeds, the ratings site that supplies their published priors, and the 18
golden-set articles about a harbor bill, a rate decision, a privacy ruling
and a wildfire season. It exists so that a fresh install, the kernel tests
and the eval commands have a complete pack to run against without any real
site's data. See `../README.md` for what each file is and how to load it.

The pack reuses the English prompts from `config/install`; a real pack
usually ships its own `maemgaba_core.maemgaba_prompt.*.yml` in the site's
language, with a `_paraphrase` twin of the classify prompt for the
calibration harness.

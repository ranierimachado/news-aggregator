# Contributing

Issues are welcome: bug reports, questions about the pipeline, and ideas
for the rubric or the evals. Open one at the repository's issue tracker.

Pull requests by discussion, please. Open an issue first describing the
change and why; once there is agreement on the approach, a PR is welcome.
Unsolicited large PRs are likely to be closed with a pointer to this file.

## Ground rules

- Drupal coding standard (`phpcs.xml.dist`), kernel or unit tests for every
  behaviour change, and a CHANGELOG entry.
- Everything site-specific stays out of the engine. Prompts, outlet
  registries, spectrum labels and methodology copy are locale-pack config;
  the engine ships only the English defaults in `config/install` and the
  synthetic pack in `config/locale/sample/`.
- No real article text, feed lists or production data in fixtures. Tests
  use invented outlets on `.example` domains.
- Run `gitleaks protect --staged` before committing (the pre-commit hook
  does it for you: `pre-commit install`).

## Running the checks locally

See "Running the tests" in the README. CI runs the same phpcs, kernel and
unit test jobs plus a gitleaks scan on every pull request.

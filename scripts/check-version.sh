#!/usr/bin/env bash
# Checks that the release version is the same everywhere a person reads it.
#
# - Both modules' .info.yml `version:` must be equal.
# - The first "## vX.Y.Z" heading in CHANGELOG.md must match them.
# - With a tag argument (CI passes it on tag builds), the tag must match too.
#
# Usage: scripts/check-version.sh [vX.Y.Z]
set -euo pipefail
cd "$(dirname "$0")/.."

info_version() {
  sed -n -E "s/^version:[[:space:]]*'?([^'[:space:]]+)'?[[:space:]]*$/\1/p" "$1"
}

core=$(info_version maemgaba_core/maemgaba_core.info.yml)
monitor=$(info_version maemgaba_monitor/maemgaba_monitor.info.yml)
changelog=$(sed -n -E 's/^## v([0-9]+\.[0-9]+\.[0-9]+).*/\1/p' CHANGELOG.md | head -1)
tag="${1:-}"
tag="${tag#v}"

echo "maemgaba_core.info.yml:    ${core:-<missing>}"
echo "maemgaba_monitor.info.yml: ${monitor:-<missing>}"
echo "CHANGELOG.md (latest):     ${changelog:-<missing>}"
[ -n "$tag" ] && echo "git tag:                   v$tag"

fail=0
if [ -z "$core" ] || [ "$core" != "$monitor" ]; then
  echo "ERROR: the two .info.yml versions differ or are missing." >&2; fail=1
fi
if [ "$core" != "$changelog" ]; then
  echo "ERROR: .info.yml version ($core) is not the latest CHANGELOG version ($changelog)." >&2; fail=1
fi
if [ -n "$tag" ] && [ "$core" != "$tag" ]; then
  echo "ERROR: tag v$tag does not match .info.yml version $core. Bump the version before tagging." >&2; fail=1
fi
[ "$fail" -eq 0 ] && echo "OK: version $core is consistent."
exit "$fail"

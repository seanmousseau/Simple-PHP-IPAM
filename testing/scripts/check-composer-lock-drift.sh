#!/usr/bin/env bash
# v3.29.0 #1104 — Fails if vendor/ disagrees with composer.lock.
# Detects the "PHPStan/PHPUnit version mismatch trap" where a developer
# pulls main and forgets `composer install`; the gate then runs against
# a stale vendor/ and produces confusing per-test failures.
set -euo pipefail

if ! command -v composer >/dev/null 2>&1; then
  echo "composer not on PATH" >&2
  exit 1
fi

if ! composer validate --no-check-publish --strict >/dev/null 2>&1; then
  echo "composer validate failed: composer.json / composer.lock invalid" >&2
  exit 1
fi

if [ ! -d vendor ]; then
  echo "vendor/ is missing. Run: composer install" >&2
  exit 1
fi

# composer install --dry-run reports any package that WOULD be installed,
# updated, or removed if a real install ran now. Any such output means
# vendor/ is out of sync with composer.lock. Filter the dry-run output
# for the action verbs composer emits and bail on any hit.
dryrun=$(composer install --dry-run --no-interaction --no-progress --no-plugins 2>&1 || true)
if printf '%s\n' "$dryrun" | grep -qE '^[[:space:]]*-[[:space:]]+(Installing|Updating|Removing|Locking)[[:space:]]'; then
  echo "vendor/ drifts from composer.lock. Run: composer install" >&2
  echo "Drift detected (composer install --dry-run reported actions):" >&2
  printf '%s\n' "$dryrun" | grep -E '^[[:space:]]*-[[:space:]]+(Installing|Updating|Removing|Locking)[[:space:]]' >&2
  exit 1
fi

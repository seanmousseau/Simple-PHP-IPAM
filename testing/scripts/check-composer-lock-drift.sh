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

if [ -d vendor ]; then
  # composer status exits non-zero if installed pins drift from the lock.
  if ! composer status --no-interaction >/dev/null 2>&1; then
    echo "vendor/ drifts from composer.lock. Run: composer install" >&2
    exit 1
  fi
fi

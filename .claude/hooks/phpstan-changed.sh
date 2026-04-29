#!/usr/bin/env bash
# PostToolUse hook: run PHPStan on the single edited PHP file.
# Scoped to Simple-PHP-IPAM/**/*.php so we don't analyse tools, tests bootstrap,
# or out-of-tree files. Single-file analysis stays under ~2s on this codebase.
# Skips silently if vendor/bin/phpstan is not installed (fresh clone, no composer install).
set -euo pipefail

payload="$(cat)"
file="$(printf '%s' "$payload" | jq -r '.tool_input.file_path // empty')"

[[ -z "$file" ]] && exit 0
[[ "$file" != *.php ]] && exit 0
[[ ! -f "$file" ]] && exit 0

# Only analyse files under the app web root — phpstan.neon scope.
case "$file" in
  */Simple-PHP-IPAM/*) ;;
  *) exit 0 ;;
esac

PHPSTAN="${CLAUDE_PROJECT_DIR:-$(pwd)}/vendor/bin/phpstan"
[[ -x "$PHPSTAN" ]] || exit 0

# --no-progress keeps output clean; --error-format=raw gives one line per finding.
if ! out="$("$PHPSTAN" analyse --no-progress --error-format=raw --memory-limit=512M "$file" 2>&1)"; then
  printf 'phpstan reported issues in %s:\n%s\n' "$file" "$out" >&2
  exit 2
fi
exit 0

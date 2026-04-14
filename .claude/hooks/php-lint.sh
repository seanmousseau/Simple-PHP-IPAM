#!/usr/bin/env bash
# PostToolUse hook: run `php -l` on any edited/written .php file.
# Reads the Claude Code hook JSON envelope from stdin, extracts the file path,
# and lints it. Non-zero exit prints the php error back into the transcript.
set -euo pipefail

payload="$(cat)"
file="$(printf '%s' "$payload" | jq -r '.tool_input.file_path // empty')"

[[ -z "$file" ]] && exit 0
[[ "$file" != *.php ]] && exit 0
[[ ! -f "$file" ]] && exit 0

if ! out="$(php -l "$file" 2>&1)"; then
  printf 'php -l failed for %s:\n%s\n' "$file" "$out" >&2
  exit 2
fi
exit 0

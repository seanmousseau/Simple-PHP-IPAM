#!/usr/bin/env bash
# PreToolUse hook: block Edit/Write against paths that must never be hand-edited.
# - releases/ipam-*.tar.gz and SHA256SUMS → immutable, rebuild via make_releases.sh
# - Simple-PHP-IPAM/data/ → runtime SQLite state, never committed
# - Simple-PHP-IPAM/config.php → per-install config, not part of the app
set -euo pipefail

payload="$(cat)"
file="$(printf '%s' "$payload" | jq -r '.tool_input.file_path // empty')"

[[ -z "$file" ]] && exit 0

# Normalise to repo-relative
rel="${file#"$PWD/"}"

case "$rel" in
  releases/ipam-*/ipam-*.tar.gz|releases/ipam-*/SHA256SUMS)
    echo "Refused: release bundles are immutable. Rebuild via ./releases/make_releases.sh — see CLAUDE.md Phase 3." >&2
    exit 2
    ;;
  Simple-PHP-IPAM/data/*)
    echo "Refused: Simple-PHP-IPAM/data/ is runtime state (SQLite DB, tmp). Not part of the app." >&2
    exit 2
    ;;
  Simple-PHP-IPAM/config.php)
    echo "Refused: config.php is per-install and gitignored. Edit config on the target deploy, not in the repo." >&2
    exit 2
    ;;
esac
exit 0

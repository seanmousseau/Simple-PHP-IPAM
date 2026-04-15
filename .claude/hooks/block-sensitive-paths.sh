#!/usr/bin/env bash
# PreToolUse hook: block Edit/Write against paths that must never be hand-edited.
# - releases/ipam-*.tar.gz and SHA256SUMS → immutable, rebuild via make_releases.sh
# - Simple-PHP-IPAM/data/ → runtime SQLite state, never committed
# - Simple-PHP-IPAM/config.php → per-install config, not part of the app
set -euo pipefail

payload="$(cat)"
file="$(printf '%s' "$payload" | jq -r '.tool_input.file_path // empty')"

[[ -z "$file" ]] && exit 0

# Canonicalize both the input path and $PWD so ./ and ../ segments cannot
# bypass the block list, and so symlinked working directories (e.g. macOS
# OneDrive Library/CloudStorage <-> ~/OneDrive) strip cleanly. We use
# python3 because BSD realpath on macOS lacks -m and cannot canonicalize
# paths whose leaf does not yet exist (Edit/Write may target new files).
rel="$(FILE="$file" python3 - <<'PY'
import os, sys
f = os.environ["FILE"]
# os.path.realpath resolves symlinks; works for non-existent leaves.
abs_file = os.path.realpath(os.path.abspath(f))
abs_pwd  = os.path.realpath(os.path.abspath(os.getcwd()))
prefix = abs_pwd + os.sep
sys.stdout.write(abs_file[len(prefix):] if abs_file.startswith(prefix) else abs_file)
PY
)"

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

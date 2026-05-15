#!/usr/bin/env bash
# v3.29.0 #904 — bootstrap-app cold-boot perf profile.
#
# Spins each supported database driver (sqlite, mysql, pgsql) from a fully
# torn-down state and measures two timings per driver:
#
#   - bootstrap_seconds:     wall-clock for `testing/playwright/bootstrap-app.sh <driver>`
#                            to complete (build + start container + seed).
#   - first_response_seconds: wall-clock from end-of-bootstrap until the
#                            Apache container returns 2xx/3xx on /login.php
#                            (or 30 s ceiling, whichever comes first).
#
# Emits a single machine-readable JSON document to stdout so CI / operators
# can track cold-boot regressions over time. Diagnostic chatter goes to
# stderr.
#
# Usage:
#   bash testing/scripts/bootstrap-cold-boot-profile.sh
#
# Caveats:
#   - Requires Docker daemon + composer install already run.
#   - Tears down between drivers; runtime ~3-5 minutes locally.
#   - Not invoked from CI by default — operator-on-demand only. Wire into
#     a workflow under workflow_dispatch when baseline numbers are wanted.
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$repo_root"

drivers=(sqlite mysql pgsql)
json='{"timestamp":"'"$(date -u +%FT%TZ)"'","drivers":{'
first=true

for d in "${drivers[@]}"; do
  echo "=== profiling driver=$d ===" >&2

  # Teardown any prior state so the boot timing starts cold.
  bash testing/playwright/teardown-app.sh >/dev/null 2>&1 || true

  boot_start=$(date +%s.%N)
  bash testing/playwright/bootstrap-app.sh "$d" >/dev/null 2>&1
  boot_end=$(date +%s.%N)
  bootstrap_seconds=$(awk "BEGIN {printf \"%.2f\", $boot_end - $boot_start}")

  # Poll login.php until 2xx/3xx, ceiling 30 s. curl --max-time 1 caps the
  # per-attempt wait; sleep 1 paces retries.
  curl_start=$(date +%s.%N)
  responded=false
  for _ in $(seq 1 30); do
    if curl -sk --max-time 1 -o /dev/null -w '%{http_code}\n' https://127.0.0.1:8443/login.php 2>/dev/null \
        | grep -qE '^(2|3)[0-9]{2}$'; then
      responded=true
      break
    fi
    sleep 1
  done
  curl_end=$(date +%s.%N)
  first_response_seconds=$(awk "BEGIN {printf \"%.2f\", $curl_end - $curl_start}")

  if [ "$first" = true ]; then
    first=false
  else
    json+=","
  fi
  # PR #1205 review: a timeout produces a 30s "first_response_seconds" that
  # looks like a valid slow success. Surface the timeout explicitly so
  # downstream consumers can distinguish.
  if [ "$responded" = true ]; then
    json+="\"$d\":{\"bootstrap_seconds\":$bootstrap_seconds,\"first_response_seconds\":$first_response_seconds}"
  else
    json+="\"$d\":{\"bootstrap_seconds\":$bootstrap_seconds,\"first_response_seconds\":null,\"first_response_timeout\":true}"
  fi
done

# Leave the host clean.
bash testing/playwright/teardown-app.sh >/dev/null 2>&1 || true

json+='}}'
echo "$json"

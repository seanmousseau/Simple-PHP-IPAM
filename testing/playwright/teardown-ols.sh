#!/usr/bin/env bash
# Stop and remove the containerized OpenLiteSpeed instance started by
# bootstrap-ols.sh. Idempotent — safe to run when nothing is running.
# v2.11.0 #500.

set -euo pipefail

container="${IPAM_OLS_NAME:-ipam-ols-test}"

if command -v docker >/dev/null 2>&1; then
    docker rm -f "$container" >/dev/null 2>&1 || true
fi

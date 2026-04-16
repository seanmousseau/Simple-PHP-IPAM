#!/usr/bin/env bash
# Start a Dockerized OpenLiteSpeed instance of Simple-PHP-IPAM for the
# containerized `.htaccess` regression job (v2.11.0 #500).
#
# Usage:
#   bootstrap-ols.sh
#
# Environment overrides:
#   IPAM_OLS_PORT   Host port bound to the container's :8088 (default: 8089).
#                   Uses 8089 instead of OLS's default 8088 so that a developer
#                   running this script alongside the Apache harness
#                   (which binds 8088 to its Postgres admin etc.) does not
#                   collide. CI gets a clean container and can use any port.
#   IPAM_OLS_NAME   Container name (default: ipam-ols-test).
#   IPAM_OLS_IMAGE  OLS image to run (default: litespeedtech/openlitespeed:1.8.5-lsphp83).
#
# Side effects:
#   - Starts a long-running OpenLiteSpeed container. Tear it down with
#     teardown-ols.sh.
#   - Mounts Simple-PHP-IPAM/ read-only at OLS's Example vhost docroot
#     (/usr/local/lsws/Example/html). The vhost already exists in the
#     stock image — no OLS config surgery needed.
#
# Why not seed the database? The htaccess.spec.ts tests are HTTP-level
# 4xx assertions that never authenticate, never touch a DB, and never
# require working PHP handlers. OLS serves the Simple-PHP-IPAM tree
# read-only and we assert the deny rules fire before any request reaches
# the application. Keeping this bootstrap minimal avoids duplicating the
# driver-specific seed logic from bootstrap-app.sh.
#
# Why a separate helper instead of a --webserver flag on bootstrap-app.sh?
# bootstrap-app.sh is 170+ lines of driver-matrix, seeding, and teardown
# logic that OLS does not need. Keeping them independent avoids a
# combinatorial matrix (webserver × driver) that has no test coverage
# demand today. If future OLS work needs a working DB, extend this
# helper instead.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
app_dir="$repo_root/Simple-PHP-IPAM"

container="${IPAM_OLS_NAME:-ipam-ols-test}"
port="${IPAM_OLS_PORT:-8089}"
# Pin to a specific version for CI reproducibility (#500 CR feedback).
# Tags include the lsphp suffix; 1.8.5-lsphp83 is what :latest resolved
# to at the time of v2.11.0 development.
image="${IPAM_OLS_IMAGE:-litespeedtech/openlitespeed:1.8.5-lsphp83}"

if ! command -v docker >/dev/null 2>&1; then
    echo "bootstrap-ols: docker is required but not found in PATH" >&2
    exit 3
fi

if ! command -v curl >/dev/null 2>&1; then
    echo "bootstrap-ols: curl is required for readiness polling but not found in PATH" >&2
    exit 3
fi

# Kill any prior container of the same name (idempotent).
docker rm -f "$container" >/dev/null 2>&1 || true

echo "bootstrap-ols: starting $image on http://127.0.0.1:$port"
docker run -d --rm --name "$container" \
    -v "$app_dir:/usr/local/lsws/Example/html:ro" \
    -p "127.0.0.1:$port:8088" \
    "$image" >/dev/null

# Poll for readiness. The stock Example vhost serves an HTML welcome page
# at / which does NOT go through our .htaccess rewrite chain (the
# force-HTTPS redirect only fires when the requested URI is a real asset
# we care about), so a 200 response there means the container is up.
for i in $(seq 1 30); do
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/" 2>/dev/null || echo "000")
    if [[ "$code" =~ ^[23] ]]; then
        echo "bootstrap-ols: ready at http://127.0.0.1:$port"
        exit 0
    fi
    sleep 1
done

echo "bootstrap-ols: container did not become ready in 30s" >&2
docker logs "$container" >&2 || true
exit 1

#!/usr/bin/env bash
# Start a Dockerized Apache+PHP instance of Simple-PHP-IPAM for Playwright runs.
#
# Usage:
#   bootstrap-app.sh [driver]
#
# Positional args:
#   driver   Database driver. Only 'sqlite' is supported in v2.5.2. MySQL/Postgres
#            matrix slots land in v2.10.0 / v2.11.0 (see plan for v2.5.2).
#
# Environment overrides:
#   IPAM_TEST_PORT   Host port to bind the container's :443 to (default: 8443).
#   IPAM_TEST_IMAGE  Docker image tag to build (default: ipam-pw-apache:local).
#   IPAM_TEST_NAME   Container name (default: ipam-pw-test).
#
# Side effects:
#   - Overwrites Simple-PHP-IPAM/config.php with a test config (the real config.php
#     is gitignored in dev environments; in CI the checkout is throwaway).
#   - Deletes Simple-PHP-IPAM/data/ipam.sqlite* and reseeds.
#   - Starts a long-running container. Tear it down with teardown-app.sh.
#
# On success: prints the base URL (https://127.0.0.1:<port>) and exits 0.
# On failure: dumps container logs to stderr and exits 1.

set -euo pipefail

driver="${1:-sqlite}"
if [[ "$driver" != "sqlite" ]]; then
    echo "bootstrap-app.sh: driver '$driver' is not supported in v2.5.2 (sqlite only)" >&2
    exit 2
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
app_dir="$repo_root/Simple-PHP-IPAM"

container="${IPAM_TEST_NAME:-ipam-pw-test}"
image="${IPAM_TEST_IMAGE:-ipam-pw-apache:local}"
port="${IPAM_TEST_PORT:-8443}"

if ! command -v docker >/dev/null 2>&1; then
    echo "bootstrap-app.sh: docker is required but not found in PATH" >&2
    exit 3
fi

# 1. Back up any existing config.php and install the test fixture. The fixture
#    in testing/playwright/fixtures/test-config.php carries every default key
#    the app expects (housekeeping, update_check, login_protection, etc.) so
#    no "undefined array key" warnings fire and mangle response headers.
#    demo_mode is flipped on/off via sed as a simple two-step surgery.
echo "bootstrap-app: installing test config"
mkdir -p "$app_dir/data"
if [[ -f "$app_dir/config.php" && ! -f "$app_dir/config.php.prebootstrap-backup" ]]; then
    cp "$app_dir/config.php" "$app_dir/config.php.prebootstrap-backup"
fi
cp "$script_dir/fixtures/test-config.php" "$app_dir/config.php"
rm -f "$app_dir/data/ipam.sqlite" "$app_dir/data/ipam.sqlite-wal" "$app_dir/data/ipam.sqlite-shm"

# Flip demo_mode on for seeding. sed with two distinct markers would be safer,
# but the file ships with exactly one `'enabled' => false` line inside the
# demo_mode block so this single replace is unambiguous.
set_demo_mode() {
    local enabled="$1"
    local other; other=$([[ "$enabled" == "true" ]] && echo "false" || echo "true")
    # Use a perl one-liner so we can match the full demo_mode=>[ block.
    perl -i -0pe "s/('demo_mode'\s*=>\s*\[\s*'enabled'\s*=>\s*)${other}/\${1}${enabled}/" "$app_dir/config.php"
}

echo "bootstrap-app: enabling demo_mode for seeding"
set_demo_mode "true"

# 2. Build the container image (cached on re-runs; fast once warm).
echo "bootstrap-app: building $image"
docker build --quiet -t "$image" -f "$script_dir/Dockerfile.apache" "$script_dir" >/dev/null

# 3. Run migrate + demo seed inside a throwaway container so there is no host
#    PHP version dependency. Uses the same image the long-running container uses.
echo "bootstrap-app: running migrate.php and demo_seed.php"
# Seed inside a throwaway container of the same image so there is no host
# PHP dependency. The seed container's root user creates the SQLite file
# with UID 0 ownership in the bind mount; the long-running container runs
# Apache as www-data (UID 33) and must also write the file (SQLite WAL).
# Relax permissions on the data dir so www-data can open it. We do this
# inside the throwaway container (guaranteed to have chmod) rather than
# on the host (which on macOS Docker Desktop is a no-op due to UID
# translation and on CI is only reachable if run as root).
docker run --rm \
    -v "$app_dir:/var/www/html" \
    -w /var/www/html \
    "$image" \
    bash -c 'php migrate.php && php demo_seed.php && chmod -R a+rwX data' \
    >/tmp/ipam-pw-seed.log 2>&1 || {
        echo "bootstrap-app: seeding failed, log follows:" >&2
        cat /tmp/ipam-pw-seed.log >&2
        exit 1
    }

# 4. Flip demo_mode off so the suite can exercise normal admin flows.
echo "bootstrap-app: disabling demo_mode for runtime"
set_demo_mode "false"

# 5. Kill any prior container of the same name.
docker rm -f "$container" >/dev/null 2>&1 || true

# 6. Launch the long-running Apache container.
echo "bootstrap-app: starting container $container on https://127.0.0.1:$port"
docker run -d --rm --name "$container" \
    -v "$app_dir:/var/www/html" \
    -p "127.0.0.1:$port:443" \
    "$image" >/dev/null

# 7. Poll for readiness. status.php returns {"status":"ok"} and does not require auth.
for i in $(seq 1 30); do
    if curl -ksSf "https://127.0.0.1:$port/status.php" >/dev/null 2>&1; then
        echo "bootstrap-app: ready at https://127.0.0.1:$port"
        exit 0
    fi
    sleep 1
done

echo "bootstrap-app: container did not become ready in 30s" >&2
docker logs "$container" >&2 || true
exit 1

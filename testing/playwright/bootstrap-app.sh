#!/usr/bin/env bash
# Start a Dockerized Apache+PHP instance of Simple-PHP-IPAM for Playwright runs.
#
# Usage:
#   bootstrap-app.sh [driver]
#
# Positional args:
#   driver   Database driver: 'sqlite' (default) or 'mysql' (v2.10.0 #433).
#            Postgres slot lands in v2.11.0 (#388). Unknown values exit 2.
#
# Environment overrides:
#   IPAM_TEST_PORT        Host port to bind the container's :443 to (default: 8443).
#   IPAM_TEST_IMAGE       Docker image tag to build (default: ipam-pw-apache:local).
#   IPAM_TEST_NAME        Apache container name (default: ipam-pw-test).
#   IPAM_TEST_NETWORK     Docker network name for the mysql driver (default:
#                         ipam-pw-net). Unused on sqlite.
#   IPAM_TEST_MYSQL_NAME  MySQL service container name (default: ipam-pw-mysql).
#
# Side effects:
#   - Overwrites Simple-PHP-IPAM/config.php with a test config (the real config.php
#     is gitignored in dev environments; in CI the checkout is throwaway).
#   - On sqlite: deletes Simple-PHP-IPAM/data/ipam.sqlite* and reseeds.
#   - On mysql: creates a docker network, starts a fresh mysql:8.0 container,
#     creates the ipam_pw database from scratch, and reseeds.
#   - Starts a long-running Apache container. Tear it down with teardown-app.sh.
#
# On success: prints the base URL (https://127.0.0.1:<port>) and exits 0.
# On failure: dumps container logs to stderr and exits 1.

set -euo pipefail

driver="${1:-sqlite}"
case "$driver" in
    sqlite|mysql) ;;
    *)
        echo "bootstrap-app.sh: unsupported driver '$driver' (expected sqlite or mysql)" >&2
        exit 2
        ;;
esac

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
app_dir="$repo_root/Simple-PHP-IPAM"

container="${IPAM_TEST_NAME:-ipam-pw-test}"
image="${IPAM_TEST_IMAGE:-ipam-pw-apache:local}"
port="${IPAM_TEST_PORT:-8443}"
network="${IPAM_TEST_NETWORK:-ipam-pw-net}"
mysql_name="${IPAM_TEST_MYSQL_NAME:-ipam-pw-mysql}"

if ! command -v docker >/dev/null 2>&1; then
    echo "bootstrap-app.sh: docker is required but not found in PATH" >&2
    exit 3
fi

# 1. Back up any existing config.php and install the test fixture matching
#    the requested driver. The sqlite and mysql fixtures carry every default
#    key the app expects so no "undefined array key" warnings fire and mangle
#    response headers. demo_mode is flipped on/off via sed as a simple
#    two-step surgery.
echo "bootstrap-app: installing test config (driver=$driver)"
mkdir -p "$app_dir/data"
if [[ -f "$app_dir/config.php" && ! -f "$app_dir/config.php.prebootstrap-backup" ]]; then
    cp "$app_dir/config.php" "$app_dir/config.php.prebootstrap-backup"
fi
case "$driver" in
    sqlite)
        cp "$script_dir/fixtures/test-config.php" "$app_dir/config.php"
        rm -f "$app_dir/data/ipam.sqlite" "$app_dir/data/ipam.sqlite-wal" "$app_dir/data/ipam.sqlite-shm"
        ;;
    mysql)
        cp "$script_dir/fixtures/test-config-mysql.php" "$app_dir/config.php"
        ;;
esac

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

# 3. For mysql, spin up a docker network + MySQL 8.0 service container and
#    wait for it to accept connections before seeding.
if [[ "$driver" == "mysql" ]]; then
    echo "bootstrap-app: creating docker network $network"
    docker network create "$network" >/dev/null 2>&1 || true

    echo "bootstrap-app: starting MySQL 8.0 service container $mysql_name"
    docker rm -f "$mysql_name" >/dev/null 2>&1 || true
    docker run -d --rm --name "$mysql_name" \
        --network "$network" \
        -e MYSQL_ROOT_PASSWORD=testpw \
        -e MYSQL_DATABASE=ipam_pw \
        mysql:8.0 >/dev/null

    echo "bootstrap-app: waiting for MySQL ready (up to 90s)"
    for i in $(seq 1 45); do
        if docker exec "$mysql_name" mysqladmin ping -h 127.0.0.1 -uroot -ptestpw --silent >/dev/null 2>&1; then
            echo "bootstrap-app: MySQL ready"
            break
        fi
        if [[ "$i" -eq 45 ]]; then
            echo "bootstrap-app: MySQL did not become ready in 90s" >&2
            docker logs "$mysql_name" >&2 || true
            exit 1
        fi
        sleep 2
    done
fi

# 4. Run migrate + demo seed inside a throwaway container so there is no host
#    PHP version dependency. Uses the same image the long-running container uses.
#    For mysql, the throwaway container joins the docker network so it can
#    resolve the MySQL hostname.
echo "bootstrap-app: running migrate.php and demo_seed.php"
seed_docker_args=(-v "$app_dir:/var/www/html" -w /var/www/html)
if [[ "$driver" == "mysql" ]]; then
    seed_docker_args+=(--network "$network")
fi
docker run --rm "${seed_docker_args[@]}" "$image" \
    bash -c 'php migrate.php && php demo_seed.php && chmod -R a+rwX data' \
    >/tmp/ipam-pw-seed.log 2>&1 || {
        echo "bootstrap-app: seeding failed, log follows:" >&2
        cat /tmp/ipam-pw-seed.log >&2
        if [[ "$driver" == "mysql" ]]; then
            echo "bootstrap-app: MySQL container log:" >&2
            docker logs "$mysql_name" >&2 || true
        fi
        exit 1
    }

# 5. Flip demo_mode off so the suite can exercise normal admin flows.
echo "bootstrap-app: disabling demo_mode for runtime"
set_demo_mode "false"

# 6. Kill any prior container of the same name.
docker rm -f "$container" >/dev/null 2>&1 || true

# 7. Launch the long-running Apache container. On mysql the container joins
#    the docker network so PHP can reach the MySQL service by hostname.
echo "bootstrap-app: starting container $container on https://127.0.0.1:$port"
run_docker_args=(-d --rm --name "$container"
    -v "$app_dir:/var/www/html"
    -p "127.0.0.1:$port:443")
if [[ "$driver" == "mysql" ]]; then
    run_docker_args+=(--network "$network")
fi
docker run "${run_docker_args[@]}" "$image" >/dev/null

# 8. Poll for readiness. status.php returns {"status":"ok"} and does not require auth.
for i in $(seq 1 30); do
    if curl -ksSf "https://127.0.0.1:$port/status.php" >/dev/null 2>&1; then
        echo "bootstrap-app: ready at https://127.0.0.1:$port (driver=$driver)"
        exit 0
    fi
    sleep 1
done

echo "bootstrap-app: container did not become ready in 30s" >&2
docker logs "$container" >&2 || true
exit 1

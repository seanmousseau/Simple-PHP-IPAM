#!/usr/bin/env bash
# Start a Dockerized Apache+PHP instance of Simple-PHP-IPAM for Playwright runs.
#
# Usage:
#   bootstrap-app.sh [driver]
#
# Positional args:
#   driver   Database driver: 'sqlite' (default), 'mysql' (v2.10.0 #433), or
#            'pgsql' (v2.11.0 #388). Unknown values exit 2.
#
# Environment overrides:
#   IPAM_TEST_PORT         Host port to bind the container's :443 to (default: 8443).
#   IPAM_TEST_IMAGE        Docker image tag to build (default: ipam-pw-apache:local).
#   IPAM_TEST_NAME         Apache container name (default: ipam-pw-test).
#   IPAM_TEST_NETWORK      Docker network name for the mysql/pgsql drivers
#                          (default: ipam-pw-net). Unused on sqlite.
#   IPAM_TEST_MYSQL_NAME   MySQL service container name (default: ipam-pw-mysql).
#   IPAM_TEST_PGSQL_NAME   Postgres service container name (default: ipam-pw-pgsql).
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
    sqlite|mysql|mariadb|pgsql) ;;
    *)
        echo "bootstrap-app.sh: unsupported driver '$driver' (expected sqlite, mysql, mariadb, or pgsql)" >&2
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
mariadb_name="${IPAM_TEST_MARIADB_NAME:-ipam-pw-mariadb}"
pgsql_name="${IPAM_TEST_PGSQL_NAME:-ipam-pw-pgsql}"
# MailHog opt-in (#458): set IPAM_TEST_MAILHOG=1 to start a mailhog container.
# The MailHog SMTP port (1025) is only reachable from within the docker network.
# The HTTP API port is exposed on the host at IPAM_TEST_MAILHOG_WEB_PORT (default 8026).
mailhog_enabled="${IPAM_TEST_MAILHOG:-0}"
mailhog_name="${IPAM_TEST_MAILHOG_NAME:-ipam-pw-mailhog}"
mailhog_web_port="${IPAM_TEST_MAILHOG_WEB_PORT:-8026}"

# MinIO sidecar (#789): always-on S3-compatible object store for the backup
# integration spec. Reachable from PHP at http://minio:9000 over the docker
# network. Credentials and bucket name are hardcoded — fixture-only, never
# touches real cloud infra.
minio_name="${IPAM_TEST_MINIO_NAME:-ipam-pw-minio}"
minio_mc_name="${IPAM_TEST_MINIO_MC_NAME:-ipam-pw-minio-mc}"
minio_root_user="${IPAM_TEST_MINIO_USER:-testkey}"
minio_root_pass="${IPAM_TEST_MINIO_PASS:-testsecret123}"
minio_bucket="${IPAM_TEST_MINIO_BUCKET:-ipam-backups}"

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

# Write IPAM_DRIVER to .env so the Playwright config picks it up automatically
# and IS_SQLITE / IS_MYSQL fixture flags resolve correctly even when the local
# gate command does not export IPAM_DRIVER. The .env file is loaded by
# playwright.config.ts on startup. teardown-app.sh removes it.
if grep -q "^IPAM_DRIVER=" "${script_dir}/.env" 2>/dev/null; then
    perl -i -pe "s/^IPAM_DRIVER=.*/IPAM_DRIVER=${driver}/" "${script_dir}/.env"
else
    printf 'IPAM_DRIVER=%s\n' "$driver" >> "${script_dir}/.env"
fi
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
    mariadb)
        cp "$script_dir/fixtures/test-config-mariadb.php" "$app_dir/config.php"
        ;;
    pgsql)
        cp "$script_dir/fixtures/test-config-pgsql.php" "$app_dir/config.php"
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

# 2. Build the container image. Skip when the tagged image already exists — CI
#    pre-builds it with docker buildx + GHA layer cache before invoking this
#    script, so checking here avoids a redundant uncached docker build.
if docker image inspect "$image" >/dev/null 2>&1; then
    echo "bootstrap-app: image $image already present, skipping build"
else
    echo "bootstrap-app: building $image"
    docker build --quiet -t "$image" -f "$script_dir/Dockerfile.apache" "$script_dir" >/dev/null
fi

# 3. Always create the docker network — MinIO is always-on (#789) and joins it
#    along with mysql/pgsql/mailhog when those are active. Idempotent.
echo "bootstrap-app: creating docker network $network"
docker network create "$network" >/dev/null 2>&1 || true

if [[ "$driver" == "mysql" ]]; then
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

if [[ "$driver" == "mariadb" ]]; then
    echo "bootstrap-app: starting MariaDB 10.11 service container $mariadb_name"
    docker rm -f "$mariadb_name" >/dev/null 2>&1 || true
    docker run -d --rm --name "$mariadb_name" \
        --network "$network" \
        --network-alias ipam-pw-mariadb \
        -e MARIADB_ROOT_PASSWORD=testpw \
        -e MARIADB_DATABASE=ipam_pw \
        mariadb:10.11 >/dev/null

    echo "bootstrap-app: waiting for MariaDB ready (up to 90s)"
    for i in $(seq 1 45); do
        if docker exec "$mariadb_name" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
            echo "bootstrap-app: MariaDB ready"
            break
        fi
        if [[ "$i" -eq 45 ]]; then
            echo "bootstrap-app: MariaDB did not become ready in 90s" >&2
            docker logs "$mariadb_name" >&2 || true
            exit 1
        fi
        sleep 2
    done
fi

if [[ "$driver" == "pgsql" ]]; then
    echo "bootstrap-app: starting Postgres 14 service container $pgsql_name"
    docker rm -f "$pgsql_name" >/dev/null 2>&1 || true
    docker run -d --rm --name "$pgsql_name" \
        --network "$network" \
        --network-alias ipam-pw-pgsql \
        -e POSTGRES_PASSWORD=testpw \
        -e POSTGRES_USER=ipam \
        -e POSTGRES_DB=ipam_pw \
        postgres:14-alpine >/dev/null

    echo "bootstrap-app: waiting for Postgres ready (up to 60s)"
    for i in $(seq 1 30); do
        if docker exec "$pgsql_name" pg_isready -U ipam -d ipam_pw >/dev/null 2>&1; then
            echo "bootstrap-app: Postgres ready"
            break
        fi
        if [[ "$i" -eq 30 ]]; then
            echo "bootstrap-app: Postgres did not become ready in 60s" >&2
            docker logs "$pgsql_name" >&2 || true
            exit 1
        fi
        sleep 2
    done
fi

# 3b. MailHog SMTP trap (opt-in via IPAM_TEST_MAILHOG=1).
if [[ "$mailhog_enabled" == "1" ]]; then
    echo "bootstrap-app: starting MailHog SMTP trap $mailhog_name"
    docker rm -f "$mailhog_name" >/dev/null 2>&1 || true
    docker run -d --rm --name "$mailhog_name" \
        --network "$network" \
        --network-alias mailhog \
        -p "127.0.0.1:${mailhog_web_port}:8025" \
        mailhog/mailhog:latest >/dev/null
    echo "bootstrap-app: MailHog web UI available at http://127.0.0.1:${mailhog_web_port}"
fi

# 3c. MinIO S3-compatible object store (#789, always-on).
# Reachable from the IPAM container at http://minio:9000. Credentials are
# fixture-only (testkey / testsecret123). The integration spec exercises a
# round-trip backup against this. Bucket is created via `minio/mc` after
# the server reports healthy.
echo "bootstrap-app: starting MinIO $minio_name"
docker rm -f "$minio_name" >/dev/null 2>&1 || true
docker run -d --rm --name "$minio_name" \
    --network "$network" \
    --network-alias minio \
    -e "MINIO_ROOT_USER=$minio_root_user" \
    -e "MINIO_ROOT_PASSWORD=$minio_root_pass" \
    minio/minio:latest server /data >/dev/null

echo "bootstrap-app: waiting for MinIO ready (up to 60s)"
for i in $(seq 1 30); do
    # /minio/health/live returns 200 once the server has finished boot.
    if docker run --rm --network "$network" curlimages/curl:latest \
        -fsS "http://minio:9000/minio/health/live" >/dev/null 2>&1; then
        echo "bootstrap-app: MinIO ready"
        break
    fi
    if [[ "$i" -eq 30 ]]; then
        echo "bootstrap-app: MinIO did not become ready in 60s" >&2
        docker logs "$minio_name" >&2 || true
        exit 1
    fi
    sleep 2
done

echo "bootstrap-app: creating MinIO bucket $minio_bucket via mc"
docker rm -f "$minio_mc_name" >/dev/null 2>&1 || true
docker run --rm --name "$minio_mc_name" \
    --network "$network" \
    --entrypoint /bin/sh \
    minio/mc:latest \
    -c "mc alias set local http://minio:9000 $minio_root_user $minio_root_pass >/dev/null && mc mb -p local/$minio_bucket >/dev/null && echo bootstrap-app: MinIO bucket $minio_bucket ready" \
    || {
        echo "bootstrap-app: MinIO bucket creation failed" >&2
        docker logs "$minio_name" >&2 || true
        exit 1
    }

# 4. Run migrate + demo seed inside a throwaway container so there is no host
#    PHP version dependency. Uses the same image the long-running container uses.
#    For mysql, the throwaway container joins the docker network so it can
#    resolve the MySQL hostname.
echo "bootstrap-app: running migrate.php and demo_seed.php"
seed_docker_args=(-v "$app_dir:/var/www/html" -w /var/www/html)
seed_docker_args+=(--network "$network")
# File-level config mount for the seed container — mirrors the same guard used
# for the long-running container (see below). OneDrive (or other cloud-sync
# tools) can revert config.php on the host between the `cp` above and the
# docker run, so we pass the fixture directly rather than relying on the
# directory-level mount to see the host write.
case "$driver" in
    mysql)    _seed_cfg="$script_dir/fixtures/test-config-mysql.php" ;;
    mariadb)  _seed_cfg="$script_dir/fixtures/test-config-mariadb.php" ;;
    pgsql)    _seed_cfg="$script_dir/fixtures/test-config-pgsql.php" ;;
    *)        _seed_cfg="$script_dir/fixtures/test-config.php" ;;
esac
seed_docker_args+=(-v "${_seed_cfg}:/var/www/html/config.php:ro")
unset _seed_cfg
# Mount vendor/ for optional runtime dependencies (e.g. PHPMailer for SMTP).
# Only added when vendor/ exists on the host — absent in the default playwright
# matrix which skips composer install. The alerts-smtp CI job installs it first.
if [[ -d "$repo_root/vendor" ]]; then
    seed_docker_args+=(-v "$repo_root/vendor:/var/www/vendor:ro")
fi
seed_docker_args+=(
    -v "$script_dir/fixtures/seed-backup-destinations.php:/tmp/seed-backup-destinations.php:ro"
    -e "IPAM_TEST_MINIO_USER=$minio_root_user"
    -e "IPAM_TEST_MINIO_PASS=$minio_root_pass"
    -e "IPAM_TEST_MINIO_BUCKET=$minio_bucket"
)
docker run --rm "${seed_docker_args[@]}" -e SEED_2FA_TEST_USER=1 -e SEED_EMAIL_OTP_TEST_USER=1 -e SEED_PASSKEY_TEST_USER=1 -e DEMO_SEED_FORCE=1 "$image" \
    bash -c 'php migrate.php && php demo_seed.php && php /tmp/seed-backup-destinations.php && chmod -R a+rwX data' \
    >/tmp/ipam-pw-seed.log 2>&1 || {
        echo "bootstrap-app: seeding failed, log follows:" >&2
        cat /tmp/ipam-pw-seed.log >&2
        if [[ "$driver" == "mysql" ]]; then
            echo "bootstrap-app: MySQL container log:" >&2
            docker logs "$mysql_name" >&2 || true
        elif [[ "$driver" == "mariadb" ]]; then
            echo "bootstrap-app: MariaDB container log:" >&2
            docker logs "$mariadb_name" >&2 || true
        elif [[ "$driver" == "pgsql" ]]; then
            echo "bootstrap-app: Postgres container log:" >&2
            docker logs "$pgsql_name" >&2 || true
        fi
        exit 1
    }

# Write .env so Playwright auto-loads SEED_2FA_TEST_USER — the seeding step above
# always seeds the 2FA test user (SEED_2FA_TEST_USER=1 is hardcoded on line 228),
# but that flag is a Docker env var scoped to the seed container. Playwright reads
# its own .env file from the config directory, so writing it here ensures the
# is2FaSeeded() guard in totp.spec.ts returns true without any extra CLI wrangling.
# Preserve any other .env keys that may exist alongside this flag.
if grep -q "^SEED_2FA_TEST_USER=" "${script_dir}/.env" 2>/dev/null; then
    perl -i -pe 's/^SEED_2FA_TEST_USER=.*/SEED_2FA_TEST_USER=1/' "${script_dir}/.env"
else
    echo "SEED_2FA_TEST_USER=1" >> "${script_dir}/.env"
fi
if grep -q "^SEED_EMAIL_OTP_TEST_USER=" "${script_dir}/.env" 2>/dev/null; then
    perl -i -pe 's/^SEED_EMAIL_OTP_TEST_USER=.*/SEED_EMAIL_OTP_TEST_USER=1/' "${script_dir}/.env"
else
    echo "SEED_EMAIL_OTP_TEST_USER=1" >> "${script_dir}/.env"
fi
if grep -q "^SEED_PASSKEY_TEST_USER=" "${script_dir}/.env" 2>/dev/null; then
    perl -i -pe 's/^SEED_PASSKEY_TEST_USER=.*/SEED_PASSKEY_TEST_USER=1/' "${script_dir}/.env"
else
    echo "SEED_PASSKEY_TEST_USER=1" >> "${script_dir}/.env"
fi
# Propagate MailHog flag so isMailhogEnabled() in the spec can gate SMTP tests.
if [[ "$mailhog_enabled" == "1" ]]; then
    if grep -q "^IPAM_TEST_MAILHOG=" "${script_dir}/.env" 2>/dev/null; then
        perl -i -pe 's/^IPAM_TEST_MAILHOG=.*/IPAM_TEST_MAILHOG=1/' "${script_dir}/.env"
    else
        echo "IPAM_TEST_MAILHOG=1" >> "${script_dir}/.env"
    fi
fi

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
# Mount the test config fixture directly over config.php so that the running
# container always sees the correct test credentials (app_secret, app_name, etc.)
# regardless of whether a cloud-sync tool (OneDrive, Dropbox) reverts the
# host-side config.php back to the committed default. This is a file-level
# bind mount that takes precedence over the directory-level mount above.
# We need to select the right fixture for the driver (mysql/mariadb/pgsql have
# their own test-config files that point at the correct DSN).
case "$driver" in
    mysql)    _test_cfg="$script_dir/fixtures/test-config-mysql.php" ;;
    mariadb)  _test_cfg="$script_dir/fixtures/test-config-mariadb.php" ;;
    pgsql)    _test_cfg="$script_dir/fixtures/test-config-pgsql.php" ;;
    *)        _test_cfg="$script_dir/fixtures/test-config.php" ;;
esac
run_docker_args+=(-v "${_test_cfg}:/var/www/html/config.php:ro")
unset _test_cfg
run_docker_args+=(--network "$network")
if [[ -d "$repo_root/vendor" ]]; then
    run_docker_args+=(-v "$repo_root/vendor:/var/www/vendor:ro")
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

#!/usr/bin/env bash
# Stop and remove the containerized IPAM instance started by bootstrap-app.sh,
# and restore any pre-bootstrap config.php that was saved during setup.
# Idempotent — safe to run when nothing is running.
#
# Extended in v2.10.0 #433 to tear down the MySQL service container,
# in v2.11.0 #388 for Postgres, and v2.12.0 #534 for MariaDB. The docker
# network removal is idempotent and only succeeds when no containers are
# attached — both drivers' service containers are gone by then.

set -euo pipefail

container="${IPAM_TEST_NAME:-ipam-pw-test}"
mysql_name="${IPAM_TEST_MYSQL_NAME:-ipam-pw-mysql}"
mariadb_name="${IPAM_TEST_MARIADB_NAME:-ipam-pw-mariadb}"
pgsql_name="${IPAM_TEST_PGSQL_NAME:-ipam-pw-pgsql}"
mailhog_name="${IPAM_TEST_MAILHOG_NAME:-ipam-pw-mailhog}"
minio_name="${IPAM_TEST_MINIO_NAME:-ipam-pw-minio}"
minio_mc_name="${IPAM_TEST_MINIO_MC_NAME:-ipam-pw-minio-mc}"
network="${IPAM_TEST_NETWORK:-ipam-pw-net}"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
app_dir="$(cd "$script_dir/../.." && pwd)/Simple-PHP-IPAM"

if command -v docker >/dev/null 2>&1; then
    docker rm -f "$container" >/dev/null 2>&1 || true
    docker rm -f "$mysql_name" >/dev/null 2>&1 || true
    docker rm -f "$mariadb_name" >/dev/null 2>&1 || true
    docker rm -f "$pgsql_name" >/dev/null 2>&1 || true
    docker rm -f "$mailhog_name" >/dev/null 2>&1 || true
    docker rm -f "$minio_name" >/dev/null 2>&1 || true
    docker rm -f "$minio_mc_name" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
fi

if [[ -f "$app_dir/config.php.prebootstrap-backup" ]]; then
    mv "$app_dir/config.php.prebootstrap-backup" "$app_dir/config.php"
    echo "teardown-app: restored original config.php"
fi

# Remove the .env file written by bootstrap-app.sh so it doesn't linger between runs
rm -f "${script_dir}/.env"

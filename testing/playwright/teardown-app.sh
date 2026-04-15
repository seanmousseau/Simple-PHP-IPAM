#!/usr/bin/env bash
# Stop and remove the containerized IPAM instance started by bootstrap-app.sh,
# and restore any pre-bootstrap config.php that was saved during setup.
# Idempotent — safe to run when nothing is running.
#
# Extended in v2.10.0 #433 to also tear down the MySQL service container
# and docker network when the mysql driver matrix slot was used.

set -euo pipefail

container="${IPAM_TEST_NAME:-ipam-pw-test}"
mysql_name="${IPAM_TEST_MYSQL_NAME:-ipam-pw-mysql}"
network="${IPAM_TEST_NETWORK:-ipam-pw-net}"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
app_dir="$(cd "$script_dir/../.." && pwd)/Simple-PHP-IPAM"

if command -v docker >/dev/null 2>&1; then
    docker rm -f "$container" >/dev/null 2>&1 || true
    docker rm -f "$mysql_name" >/dev/null 2>&1 || true
    # Network removal is idempotent and only succeeds when no containers are
    # attached. Both of the above are gone by this point.
    docker network rm "$network" >/dev/null 2>&1 || true
fi

if [[ -f "$app_dir/config.php.prebootstrap-backup" ]]; then
    mv "$app_dir/config.php.prebootstrap-backup" "$app_dir/config.php"
    echo "teardown-app: restored original config.php"
fi

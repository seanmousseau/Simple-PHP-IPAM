#!/usr/bin/env bash
# Stop and remove the containerized IPAM instance started by bootstrap-app.sh,
# and restore any pre-bootstrap config.php that was saved during setup.
# Idempotent — safe to run when nothing is running.

set -euo pipefail

container="${IPAM_TEST_NAME:-ipam-pw-test}"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
app_dir="$(cd "$script_dir/../.." && pwd)/Simple-PHP-IPAM"

if command -v docker >/dev/null 2>&1; then
    docker rm -f "$container" >/dev/null 2>&1 || true
fi

if [[ -f "$app_dir/config.php.prebootstrap-backup" ]]; then
    mv "$app_dir/config.php.prebootstrap-backup" "$app_dir/config.php"
    echo "teardown-app: restored original config.php"
fi

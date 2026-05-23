#!/usr/bin/env bash
# Regenerate VR (visual-regression) snapshot baselines for both platforms.
#
# Playwright tags snapshots with the OS that captured them (`*-darwin.png`,
# `*-linux.png`). CI runs on Linux runners and reads `*-linux.png`; macOS
# devs read `*-darwin.png`. A visual change must regenerate BOTH or CI
# will fail with stale linux baselines.
#
# This script runs `--update-snapshots` twice:
#   1. On the macOS host (writes `*-darwin.png`)
#   2. Inside `mcr.microsoft.com/playwright:vX.Y.Z-noble` joined to the
#      `ipam-pw-net` Docker network (writes `*-linux.png`)
#
# Prerequisites:
#   - `bash testing/playwright/bootstrap-app.sh sqlite` is currently up
#     (this script does NOT bootstrap / teardown — keeps state for inspection)
#   - Local `npx playwright install chromium` has been done at least once
#
# Args: forwarded to `playwright test`. Defaults to running all VR projects.
# Examples:
#   bash testing/playwright/update-vr-baselines.sh
#   bash testing/playwright/update-vr-baselines.sh --project=vr-768 --project=vr-375
#
# Footguns: the container image MUST match the `@playwright/test` version
# pinned in `testing/playwright/package.json` (the runtime version-check is
# strict — a v1.59.1 container against a v1.60.0 install exits at startup
# with zero useful output). The image tag is derived from package.json
# below; bump it whenever you bump `@playwright/test`.

set -euo pipefail

cd "$(dirname "$0")"

PW_VERSION="$(node -p "require('./package.json').devDependencies['@playwright/test'].replace(/^\^/, '')")"
PW_IMAGE="mcr.microsoft.com/playwright:v${PW_VERSION}-noble"

# Default to all VR projects when no args given.
if [ "$#" -eq 0 ]; then
  ARGS=(
    --project=vr-1440 --project=vr-1024 --project=vr-768 --project=vr-375
    --project=vr-dashboard-1440 --project=vr-dashboard-1024
    --project=vr-dashboard-768 --project=vr-dashboard-375
  )
else
  ARGS=("$@")
fi

if ! docker network inspect ipam-pw-net >/dev/null 2>&1; then
  echo "update-vr-baselines: docker network ipam-pw-net not found." >&2
  echo "Run 'bash testing/playwright/bootstrap-app.sh sqlite' first." >&2
  exit 1
fi

if ! docker ps --format '{{.Names}}' | grep -q '^ipam-pw-test$'; then
  echo "update-vr-baselines: container ipam-pw-test not running." >&2
  echo "Run 'bash testing/playwright/bootstrap-app.sh sqlite' first." >&2
  exit 1
fi

echo "==> darwin baselines (host)"
IPAM_BASE_URL=https://127.0.0.1:8443 \
  IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
  npx playwright test --update-snapshots --reporter=line "${ARGS[@]}"

echo "==> linux baselines (${PW_IMAGE})"
docker run --rm \
  --network ipam-pw-net \
  -v "$(pwd):/work" \
  -w /work \
  -e IPAM_BASE_URL=https://ipam-pw-test \
  -e IPAM_ADMIN_USER=demo \
  -e IPAM_ADMIN_PASS=demo \
  "${PW_IMAGE}" \
  npx playwright test --update-snapshots --reporter=line "${ARGS[@]}"

echo "==> done. Review and commit the updated *.png snapshots."

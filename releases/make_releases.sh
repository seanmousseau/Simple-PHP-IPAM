#!/usr/bin/env bash
set -euo pipefail

#
# make_release.sh - Build a GitHub release artifact (tar.gz) + SHA256SUMS
#
# Features:
#  - Nests everything under a top-level directory: <ASSET_PREFIX>-<VERSION>/
#  - Sanitizes permissions in a staging copy (does NOT modify your source folder)
#  - Sanitizes ownership recorded in the tarball using (when supported):
#       --numeric-owner --owner=0 --group=0
#  - Detects macOS and prefers GNU tar (gtar) automatically
#  - Outputs artifacts into a directory named after the tarball (without .tar.gz)
#       OUT_DIR/<ASSET_PREFIX>-<VERSION>/
#       ├── <ASSET_PREFIX>-<VERSION>.tar.gz
#       └── SHA256SUMS
#
# Usage:
#   ./make_release.sh /path/to/release-folder 0.1
#
# Output directory:
#   <OUT_DIR>/<ASSET_PREFIX>-<VERSION>/
#

ASSET_PREFIX="${ASSET_PREFIX:-ipam}"
OUT_DIR="${OUT_DIR:-.}"
STRICT_VERSION_MATCH="${STRICT_VERSION_MATCH:-true}"

# Default ownership metadata in tarball (CONFIRMED): root:root numeric
TAR_OWNER_UID="${TAR_OWNER_UID:-0}"
TAR_GROUP_GID="${TAR_GROUP_GID:-0}"

usage() {
  cat >&2 <<USAGE
Usage:
  $0 /path/to/release-folder VERSION

Env vars:
  ASSET_PREFIX=$ASSET_PREFIX
  OUT_DIR=$OUT_DIR
  STRICT_VERSION_MATCH=$STRICT_VERSION_MATCH   (true/false)
  TAR_OWNER_UID=$TAR_OWNER_UID                (numeric UID stored in tar)
  TAR_GROUP_GID=$TAR_GROUP_GID                (numeric GID stored in tar)

Notes:
  - On macOS, this script prefers GNU tar (gtar). Install with:
      brew install gnu-tar
USAGE
}

RELEASE_DIR="${1:-}"
VERSION="${2:-}"

[[ -n "$RELEASE_DIR" && -n "$VERSION" ]] || { usage; exit 2; }
RELEASE_DIR="$(cd "$RELEASE_DIR" && pwd)" || { echo "Bad release dir" >&2; exit 2; }

# Repo root is the parent of $RELEASE_DIR (which is .../Simple-PHP-IPAM/).
# composer.json + composer.lock live at the repo root.
REPO_ROOT="$(dirname "$RELEASE_DIR")"

need() { command -v "$1" >/dev/null 2>&1 || { echo "Missing dependency: $1" >&2; exit 2; }; }

need rsync
need sha256sum
need find
need chmod
need awk
need sed
need head
need mkdir
need mv
need composer

# --- tar selection (macOS prefers gtar) ---
OS="$(uname -s)"
TAR_BIN="tar"

if [[ "$OS" == "Darwin" ]]; then
  if command -v gtar >/dev/null 2>&1; then
    TAR_BIN="gtar"
  else
    echo "WARNING: macOS detected but gtar not found. Using BSD tar; ownership sanitization flags may be unavailable." >&2
    TAR_BIN="tar"
  fi
fi

need "$TAR_BIN"

TARBALL="${ASSET_PREFIX}-${VERSION}.tar.gz"
SUMS="SHA256SUMS"
TOPDIR="${ASSET_PREFIX}-${VERSION}"

# Output artifacts into OUT_DIR/<ASSET_PREFIX>-<VERSION>/
ARTIFACT_DIR="$OUT_DIR/${ASSET_PREFIX}-${VERSION}"
mkdir -p "$ARTIFACT_DIR"

TMP="$(mktemp -d)"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

STAGE="$TMP/stage"
PAYLOAD="$STAGE/$TOPDIR"

mkdir -p "$PAYLOAD"

# Copy release tree into staging payload. Exclude vendor/ from the source
# rsync — we install production deps into the payload separately below so
# the developer's local vendor/ (which has dev deps) is never bundled.
#
# Exclude /config.php (and its various bootstrap/upgrade backup forms) — the
# developer's working config.php contains a real app_secret (and possibly a
# real db DSN). Shipping it in the public release tarball would (a) leak the
# build machine's app_secret on GitHub and (b) clobber the installer's
# existing config.php on every deploy path that doesn't carefully preserve
# it. upgrade.sh already excludes /config.php from its own rsync; the
# release builder shipping a config.php at all is the upstream defect.
# See v3.36.0 demo regression (decrypt of login_protection.secret_key) and
# v3.36.1 release notes. config.php.example continues to ship so fresh
# installs and the upgrade.sh "missing config.php" safety net still work.
rsync -a \
  --exclude '/config.php' \
  --exclude '/config.php.bak-*' \
  --exclude '/config.php.prebootstrap-backup' \
  --exclude '*.sqlite' --exclude '*.db' \
  --exclude 'data/ipam.sqlite' --exclude 'data/ipam.sqlite-wal' --exclude 'data/ipam.sqlite-shm' \
  --exclude 'data/*.bak' --exclude 'data/ipam.sqlite.pre-import-*' \
  --exclude 'data/demo_last_reset.txt' \
  --exclude 'data/cron.lock' --exclude 'data/backups/' \
  --exclude 'data/.db_initialized' \
  --exclude 'data/tmp/*' \
  --exclude 'data/sessions/' \
  --exclude 'testing/scripts/' \
  --exclude '/vendor/' \
  --exclude '.git/' --exclude '.github/' --exclude '.DS_Store' \
  "$RELEASE_DIR/" "$PAYLOAD/"

# --- Composer production install (#416) ---
# Build vendor/ in a scratch directory using composer.json + composer.lock
# from the repo root so the bundle gets a clean prod-only autoloader without
# touching the developer's repo-root vendor/ (which has dev deps).
if [[ -f "$REPO_ROOT/composer.json" ]]; then
  COMPOSER_TMP="$TMP/composer-prod"
  mkdir -p "$COMPOSER_TMP"
  cp "$REPO_ROOT/composer.json" "$COMPOSER_TMP/composer.json"
  if [[ -f "$REPO_ROOT/composer.lock" ]]; then
    cp "$REPO_ROOT/composer.lock" "$COMPOSER_TMP/composer.lock"
  fi
  (
    cd "$COMPOSER_TMP"
    COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-dev \
      --optimize-autoloader \
      --no-interaction \
      --no-progress \
      --no-scripts \
      --prefer-dist
  )
  if [[ -d "$COMPOSER_TMP/vendor" ]]; then
    mkdir -p "$PAYLOAD/vendor"
    rsync -a "$COMPOSER_TMP/vendor/" "$PAYLOAD/vendor/"
    # Belt-and-suspenders: deny direct HTTP access to bundled library source.
    # Uses a RewriteRule-based deny primarily so OpenLiteSpeed honours it —
    # OLS ignores `Require all denied` / `Order allow,deny` but processes
    # mod_rewrite rules. On Apache both the RewriteRule and the authz_core
    # fallback apply; the rewrite hits first and returns 403. v2.11.0 #500
    # closed the OLS gap by switching from authz-only to rewrite-first.
    cat > "$PAYLOAD/vendor/.htaccess" <<'HTACCESS'
# Deny all direct web access to bundled Composer libraries.
# The application autoloads them via vendor/autoload.php; nothing here
# should be reachable as a URL.

<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^.*$ - [F,L]
</IfModule>

# Apache fallback when mod_rewrite is not loaded.
<IfModule !mod_rewrite.c>
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</IfModule>
HTACCESS
    chmod 0644 "$PAYLOAD/vendor/.htaccess"
  fi
fi

# --- Permission sanitization ---
find "$PAYLOAD" -type d -exec chmod 0755 {} \;

find "$PAYLOAD" -type f -name '*.php' -exec chmod 0644 {} \;
find "$PAYLOAD" -type f -name '*.sql' -exec chmod 0644 {} \;
find "$PAYLOAD" -type f -name '*.txt' -exec chmod 0644 {} \;
find "$PAYLOAD" -type f -name '.htaccess' -exec chmod 0644 {} \;

find "$PAYLOAD" -type f -name '*.sh' -exec chmod 0755 {} \; 2>/dev/null || true

if [[ -d "$PAYLOAD/data" ]]; then
  chmod 0700 "$PAYLOAD/data" || true
  [[ -f "$PAYLOAD/data/.htaccess" ]] && chmod 0644 "$PAYLOAD/data/.htaccess" || true
fi

# --- Version sanity check ---
if [[ -f "$PAYLOAD/version.php" ]]; then
  DETECTED="$(sed -n "s/^[[:space:]]*const[[:space:]]\+IPAM_VERSION[[:space:]]*=[[:space:]]*['\"]\([^'\"]\+\)['\"][[:space:]]*;[[:space:]]*$/\1/p" "$PAYLOAD/version.php" | head -n 1 || true)"
  if [[ -n "$DETECTED" && "$DETECTED" != "$VERSION" ]]; then
    msg="version.php has IPAM_VERSION='$DETECTED' but you requested VERSION='$VERSION'"
    if [[ "$STRICT_VERSION_MATCH" == "true" ]]; then
      echo "ERROR: $msg" >&2
      exit 3
    else
      echo "WARNING: $msg" >&2
    fi
  fi
else
  echo "WARNING: version.php not found in release folder" >&2
fi

# --- Create tar.gz with ownership sanitization when supported ---
TAR_OPTS=(--numeric-owner --owner="$TAR_OWNER_UID" --group="$TAR_GROUP_GID")

if ! "$TAR_BIN" --help 2>/dev/null | grep -q -- '--owner'; then
  echo "WARNING: $TAR_BIN does not support --owner/--group; creating tarball without ownership sanitization." >&2
  TAR_OPTS=()
fi

(
  cd "$STAGE"
  "$TAR_BIN" "${TAR_OPTS[@]}" -czf "$TMP/$TARBALL" "$TOPDIR"
)

# --- Create checksum file ---
SHA="$(sha256sum "$TMP/$TARBALL" | awk '{print $1}')"
printf "%s  %s\n" "$SHA" "$TARBALL" > "$TMP/$SUMS"

# Move artifacts into the artifact directory
mv -f "$TMP/$TARBALL" "$ARTIFACT_DIR/$TARBALL"
mv -f "$TMP/$SUMS" "$ARTIFACT_DIR/$SUMS"

echo "Created artifacts in:"
echo "  $ARTIFACT_DIR"
echo
echo "Files:"
echo "  $ARTIFACT_DIR/$TARBALL"
echo "  $ARTIFACT_DIR/$SUMS"
echo
echo "Tar tool used:"
echo "  $TAR_BIN"
echo
echo "Top-level directory inside tarball:"
echo "  $TOPDIR/"
echo
echo "Ownership metadata in tarball (when supported):"
echo "  --numeric-owner --owner=$TAR_OWNER_UID --group=$TAR_GROUP_GID"
echo
echo "SHA256:"
cat "$ARTIFACT_DIR/$SUMS"

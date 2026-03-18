#!/usr/bin/env bash
set -euo pipefail

log() { printf '%s\n' "$*" >&2; }
die() { log "ERROR: $*"; exit 1; }

usage() {
  cat >&2 <<'USAGE'
Usage:
  ./upgrade.sh [--yes] [--force] [--force-downgrade] /path/to/current/install

Options:
  --yes              Non-interactive: assume "yes" to prompts
  --force            Allow reinstalling same version or upgrading when version checks fail
  --force-downgrade  Allow downgrades (DANGEROUS; may break DB)

Environment variables:
  CLEANUP_ARTIFACTS=1                 Remove common upgrade artifacts from target webroot after success
  REMOVE_UPGRADE_SH_FROM_TARGET=1     Also delete upgrade.sh from the target webroot after success (default on)
USAGE
}

YES=0
FORCE=0
FORCE_DOWNGRADE=0

CLEANUP_ARTIFACTS="${CLEANUP_ARTIFACTS:-1}"
REMOVE_UPGRADE_SH_FROM_TARGET="${REMOVE_UPGRADE_SH_FROM_TARGET:-1}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --yes) YES=1; shift ;;
    --force) FORCE=1; shift ;;
    --force-downgrade) FORCE_DOWNGRADE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    --) shift; break ;;
    -*)
      usage
      die "Unknown option: $1"
      ;;
    *)
      break
      ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NEW_DIR="$SCRIPT_DIR"
TARGET_DIR="${1:-}"

[[ -n "$TARGET_DIR" ]] || die "Missing target install path."
TARGET_DIR="$(cd "$TARGET_DIR" && pwd)" || die "Cannot access target dir: $TARGET_DIR"

[[ -f "$TARGET_DIR/init.php" ]] || die "Target does not look like an install (missing init.php): $TARGET_DIR"
[[ -f "$NEW_DIR/init.php" ]] || die "New bundle dir does not look correct (missing init.php): $NEW_DIR"

need_cmd() { command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"; }
need_cmd rsync
need_cmd tar
need_cmd stat
need_cmd find
need_cmd chmod
need_cmd sort
need_cmd sed
need_cmd head
need_cmd rm

CHOWN_BIN="$(command -v chown || true)"
PHP_BIN="$(command -v php || true)"

timestamp="$(date +%Y%m%d-%H%M%S)"

extract_php_const_version() {
  local file="$1"
  [[ -f "$file" ]] || return 1
  sed -n "s/^[[:space:]]*const[[:space:]]\+IPAM_VERSION[[:space:]]*=[[:space:]]*['\"]\([^'\"]\+\)['\"][[:space:]]*;[[:space:]]*$/\1/p" "$file" | head -n 1
}

vercmp() {
  local a="$1"
  local b="$2"
  if [[ "$a" == "$b" ]]; then echo 0; return 0; fi
  local first
  first="$(printf "%s\n%s\n" "$a" "$b" | sort -V | head -n1)"
  if [[ "$first" == "$a" ]]; then echo -1; else echo 1; fi
}

confirm() {
  local prompt="$1"
  if [[ "$YES" -eq 1 ]]; then log "$prompt --yes set; proceeding."; return 0; fi
  read -r -p "$prompt [y/N]: " ans
  case "${ans,,}" in y|yes) return 0 ;; *) return 1 ;; esac
}

NEW_VER="$(extract_php_const_version "$NEW_DIR/version.php" || true)"
OLD_VER="$(extract_php_const_version "$TARGET_DIR/version.php" || true)"

if [[ -z "$NEW_VER" ]]; then
  [[ "$FORCE" -eq 1 ]] || die "New bundle version.php missing/unparsable. Use --force to override."
fi

log "Detected versions:"
log "  New bundle: ${NEW_VER:-unknown}"
log "  Target:     ${OLD_VER:-unknown}"

if [[ -z "$OLD_VER" && "$FORCE" -ne 1 ]]; then
  confirm "No current version found in target. Proceed anyway?" || die "Aborted."
elif [[ -n "$OLD_VER" && -n "$NEW_VER" ]]; then
  cmp="$(vercmp "$NEW_VER" "$OLD_VER")"

  if [[ "$cmp" -eq 0 && "$FORCE" -ne 1 ]]; then
    die "Target is already version $OLD_VER and new bundle is $NEW_VER. Refusing. Use --force to reinstall the same version."
  fi

  if [[ "$cmp" -lt 0 && "$FORCE_DOWNGRADE" -ne 1 ]]; then
    die "Refusing downgrade attempt ($OLD_VER -> $NEW_VER). Use --force-downgrade to override (not recommended)."
  fi
fi

OWNER="$(stat -c '%U' "$TARGET_DIR" 2>/dev/null || stat -f '%Su' "$TARGET_DIR")"
GROUP="$(stat -c '%G' "$TARGET_DIR" 2>/dev/null || stat -f '%Sg' "$TARGET_DIR")"

case "$TARGET_DIR" in /|/root|/home|/home/*) die "Refusing dangerous target dir: $TARGET_DIR" ;; esac

PARENT_DIR="$(dirname "$TARGET_DIR")"
BASE_NAME="$(basename "$TARGET_DIR")"
BACKUP_DIR="$PARENT_DIR/${BASE_NAME}.backup.$timestamp"

log "Backup:  $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"

rsync -a --delete --exclude 'data/ipam.sqlite' "$TARGET_DIR/" "$BACKUP_DIR/"

mkdir -p "$BACKUP_DIR/data"
for f in ipam.sqlite ipam.sqlite-wal ipam.sqlite-shm; do
  [[ -f "$TARGET_DIR/data/$f" ]] && cp -a "$TARGET_DIR/data/$f" "$BACKUP_DIR/data/$f" || true
done

mkdir -p "$TARGET_DIR/data"

rsync -a --delete \
  --exclude 'config.php' \
  --exclude 'data/' \
  --exclude '*.sqlite' --exclude '*.db' \
  "$NEW_DIR/" "$TARGET_DIR/"

if [[ ! -f "$TARGET_DIR/config.php" && -f "$NEW_DIR/config.php" ]]; then
  cp -a "$NEW_DIR/config.php" "$TARGET_DIR/config.php"
fi
if [[ ! -f "$TARGET_DIR/data/.htaccess" && -f "$NEW_DIR/data/.htaccess" ]]; then
  cp -a "$NEW_DIR/data/.htaccess" "$TARGET_DIR/data/.htaccess"
fi

find "$TARGET_DIR" -type f -name '*.php' -exec chmod 0644 {} \;
find "$TARGET_DIR" -type f -name '*.sql' -exec chmod 0644 {} \;
find "$TARGET_DIR" -type f -name '.htaccess' -exec chmod 0644 {} \;
find "$TARGET_DIR" -type f -name '*.sh' -exec chmod 0755 {} \; 2>/dev/null || true
find "$TARGET_DIR" -type d -exec chmod 0755 {} \;

chmod 0700 "$TARGET_DIR/data" || true
for f in ipam.sqlite ipam.sqlite-wal ipam.sqlite-shm; do
  [[ -f "$TARGET_DIR/data/$f" ]] && chmod 0600 "$TARGET_DIR/data/$f" || true
done

if [[ -n "$CHOWN_BIN" ]]; then
  chown -R "$OWNER:$GROUP" "$TARGET_DIR" 2>/dev/null || true
fi

if [[ -n "$PHP_BIN" && -f "$TARGET_DIR/migrate.php" ]]; then
  ( cd "$TARGET_DIR" && "$PHP_BIN" migrate.php ) || {
    rsync -a --delete "$BACKUP_DIR/" "$TARGET_DIR/"
    exit 10
  }
fi

if [[ "$CLEANUP_ARTIFACTS" == "1" ]]; then
  rm -f -- \
    "$TARGET_DIR/SHA256SUMS" \
    "$TARGET_DIR/"*.tar.gz \
    "$TARGET_DIR/"*.tgz \
    "$TARGET_DIR/"*.zip \
    "$TARGET_DIR/make_release.sh" \
    "$TARGET_DIR/unbundle.sh" \
    "$TARGET_DIR/bundle.sh" \
    "$TARGET_DIR/ipam-bundle.txt" \
    "$TARGET_DIR/"*.bundle.txt \
    2>/dev/null || true

  if [[ "$REMOVE_UPGRADE_SH_FROM_TARGET" == "1" ]]; then
    rm -f -- "$TARGET_DIR/upgrade.sh" 2>/dev/null || true
  fi
fi

log "Upgrade completed successfully."
log "Backup is at: $BACKUP_DIR"

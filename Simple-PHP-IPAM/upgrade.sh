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

# ---- PHP dependency checks ----
# Migrations require PHP CLI with pdo_sqlite. Check early so we fail before
# touching any files, not after rsync has already modified the target.

if [[ -z "$PHP_BIN" ]]; then
  log "WARNING: php not found in PATH. Database migrations will be skipped."
  log "         Run 'php migrate.php' manually after upgrade."
else
  log "PHP:     $("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo 'unknown')"

  # Check PHP version >= 8.2
  php_version_ok="$("$PHP_BIN" -r 'echo version_compare(PHP_VERSION, "8.2.0", ">=") ? "1" : "0";' 2>/dev/null || echo '0')"
  if [[ "$php_version_ok" != "1" ]]; then
    die "PHP 8.2+ is required. Found: $("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo 'unknown')"
  fi

  # Check required PHP extensions (pdo is always needed; driver-specific checked later)
  if ! "$PHP_BIN" -m 2>/dev/null | grep -qi "^pdo$"; then
    die "Missing required PHP extension: pdo"
  fi
fi

# ---- Utility functions ----

timestamp="$(date +%Y%m%d-%H%M%S)"

extract_php_const_version() {
  local file="$1"
  [[ -f "$file" ]] || return 1
  local ver
  ver="$(sed -n "s/^[[:space:]]*const[[:space:]]\+IPAM_VERSION[[:space:]]*=[[:space:]]*['\"]\([^'\"]\+\)['\"][[:space:]]*;[[:space:]]*$/\1/p" "$file" | head -n 1)"
  if [[ -z "$ver" ]]; then
    ver="$(sed -n "s/^[[:space:]]*define(['\"]IPAM_VERSION['\"][[:space:]]*,[[:space:]]*['\"]\([^'\"]\+\)['\"])[[:space:]]*;[[:space:]]*$/\1/p" "$file" | head -n 1)"
  fi
  printf '%s' "$ver"
}

vercmp() {
  local a="$1" b="$2"
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

# ---- Disk space check ----
# Estimate: need at least 2x the target size (backup + new files)
if command -v df >/dev/null 2>&1 && command -v du >/dev/null 2>&1; then
  target_kb="$(du -sk "$TARGET_DIR" 2>/dev/null | awk '{print $1}')"
  parent_avail_kb="$(df -k "$(dirname "$TARGET_DIR")" 2>/dev/null | awk 'NR==2{print $4}')"
  if [[ -n "$target_kb" && -n "$parent_avail_kb" ]]; then
    needed_kb=$((target_kb * 3))
    if [[ "$parent_avail_kb" -lt "$needed_kb" ]]; then
      log "WARNING: Low disk space. Available: $((parent_avail_kb/1024))MB, estimated need: $((needed_kb/1024))MB"
      confirm "Continue anyway?" || die "Aborted due to low disk space."
    fi
  fi
fi

# ---- Target directory checks ----
if [[ ! -w "$TARGET_DIR" ]]; then
  die "Target directory is not writable: $TARGET_DIR"
fi
if [[ ! -w "$(dirname "$TARGET_DIR")" ]]; then
  die "Parent directory is not writable (needed for backup): $(dirname "$TARGET_DIR")"
fi

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

# Block bare system directories but allow subdirs under /home (e.g. /home/user/public_html/ipam)
case "$TARGET_DIR" in
  /|/root|/home) die "Refusing dangerous target dir: $TARGET_DIR" ;;
esac
# Block home directories themselves (e.g. /home/user) but not their subdirs
if [[ "$TARGET_DIR" =~ ^/home/[^/]+$ ]]; then
  die "Refusing dangerous target dir (home directory): $TARGET_DIR"
fi

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

# Excludes are anchored to the install root with a leading slash: a bare
# 'config.php' would also match lib/config.php (and any other nested
# config.php), which would drop the new lib/config.php module and break
# init.php. Only the root-level config.php (DB credentials) and data/ dir
# must be preserved from the existing install.
rsync -a --delete \
  --exclude '/config.php' \
  --exclude '/data/' \
  --exclude '*.sqlite' --exclude '*.db' \
  "$NEW_DIR/" "$TARGET_DIR/"

if [[ ! -f "$TARGET_DIR/config.php" && -f "$NEW_DIR/config.php" ]]; then
  cp -a "$NEW_DIR/config.php" "$TARGET_DIR/config.php"
fi
# Always update data/.htaccess so security improvements are applied on upgrade
if [[ -f "$NEW_DIR/data/.htaccess" ]]; then
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
  echo "Running database migrations..."
  ( cd "$TARGET_DIR" && "$PHP_BIN" migrate.php ) || {
    echo "Migration failed. Restoring from backup..."
    rsync -a --delete "$BACKUP_DIR/" "$TARGET_DIR/"
    exit 10
  }
  echo "Migrations complete."

  # v3.0.0: offer optional driver migration
  if [[ -f "$TARGET_DIR/migrate_db.php" && -t 0 ]]; then
    current_driver=$("$PHP_BIN" -r "echo (require '$TARGET_DIR/config.php')['db_driver'] ?? 'sqlite';" 2>/dev/null || echo "sqlite")
    echo ""
    echo "Current database driver: $current_driver"
    echo "Would you like to migrate to a different engine?"
    echo "  [s] Stay on $current_driver (default)"
    echo "  [m] Migrate to MySQL"
    echo "  [p] Migrate to PostgreSQL"
    read -r -p "Choice [s/m/p]: " driver_choice </dev/tty 2>/dev/null || driver_choice="s"
    driver_choice="${driver_choice:-s}"

    if [[ "$driver_choice" == "m" || "$driver_choice" == "p" ]]; then
      target_driver=$([[ "$driver_choice" == "m" ]] && echo "mysql" || echo "pgsql")
      echo ""
      read -r -p "Target DSN (e.g. mysql:host=127.0.0.1;dbname=ipam): " target_dsn </dev/tty
      read -r -p "Target username: " target_user </dev/tty
      read -r -s -p "Target password: " target_pass </dev/tty
      echo ""

      if [[ -z "$target_dsn" ]]; then
        echo "No DSN provided. Skipping driver migration."
      else
        src_dsn=""
        if [[ "$current_driver" == "sqlite" ]]; then
          src_dsn="sqlite:$TARGET_DIR/data/ipam.sqlite"
        else
          src_dsn=$("$PHP_BIN" -r "echo (require '$TARGET_DIR/config.php')['db_dsn'] ?? '';" 2>/dev/null)
        fi

        echo "Running migrate_db.php..."
        migrate_ok=0
        "$PHP_BIN" "$TARGET_DIR/migrate_db.php" \
          --from="$current_driver" --from-dsn="$src_dsn" \
          --to="$target_driver" --to-dsn="$target_dsn" \
          --to-user="$target_user" --to-pass="$target_pass" \
          --force && migrate_ok=1

        if [[ "$migrate_ok" -eq 1 ]]; then
          export IPAM_NEW_DRIVER="$target_driver"
          export IPAM_NEW_DSN="$target_dsn"
          export IPAM_NEW_USER="$target_user"
          export IPAM_NEW_PASS="$target_pass"
          "$PHP_BIN" -r '
            $path = "'$TARGET_DIR'/config.php";
            $cfg = require $path;
            $cfg["db_driver"] = getenv("IPAM_NEW_DRIVER");
            $cfg["db_dsn"]    = getenv("IPAM_NEW_DSN");
            $cfg["db_user"]   = getenv("IPAM_NEW_USER");
            $cfg["db_pass"]   = getenv("IPAM_NEW_PASS");
            unset($cfg["db_path"]);
            $out = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($cfg, true) . ";\n";
            file_put_contents($path, $out, LOCK_EX);
          ' 2>/dev/null && echo "config.php updated to $target_driver driver." || echo "Warning: could not update config.php automatically. Edit it manually."
          unset IPAM_NEW_DRIVER IPAM_NEW_DSN IPAM_NEW_USER IPAM_NEW_PASS
        else
          echo "Driver migration failed. Your original database is unchanged."
          echo "You can retry manually: php $TARGET_DIR/migrate_db.php --help"
        fi
      fi
    fi
  fi
fi

if [[ "$CLEANUP_ARTIFACTS" == "1" ]]; then
  # Remove assets superseded in v1.15.0: SVG logo replaced by WebP+PNG
  rm -f -- "$TARGET_DIR/assets/logo_rectangle.svg" 2>/dev/null || true

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

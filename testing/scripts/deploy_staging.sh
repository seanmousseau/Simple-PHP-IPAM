#!/usr/bin/env bash
set -euo pipefail

#
# deploy_staging.sh — Deploy a staging variant of Simple-PHP-IPAM to the dev server
#
# Usage:
#   deploy_staging.sh --name=<slug> [--driver=sqlite|mysql|pgsql] [--fresh] [--yes]
#
# Examples:
#   ./deploy_staging.sh --name=test1                          # SQLite staging at /claude/test1/
#   ./deploy_staging.sh --name=mysql-qa --driver=mysql        # MySQL staging
#   ./deploy_staging.sh --name=pg-test --driver=pgsql --fresh # Fresh PostgreSQL staging
#   ./deploy_staging.sh --name=test1 --yes                    # Skip confirmation prompt
#
# Environment variables (with defaults):
#   SSH_HOST   — SSH target           (default: root@192.168.80.15)
#   DOCROOT    — Remote document root (default: /opt/container_data/dev.seanmousseau.com/html/claude)
#   CONTAINER  — Docker container     (default: dev_seanmousseau_com-apache-php-1)
#   DRIVER     — DB driver            (default: sqlite)
#
# For MySQL/PostgreSQL drivers, source ~/.claude/dev-secrets.env first.
# The script substitutes __DB_DSN__, __DB_USER__, __DB_PASS__ tokens in the
# remote config.php using STAGING_DB_DSN, STAGING_DB_USER, STAGING_DB_PASS
# environment variables.
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
APP_DIR="$REPO_DIR/Simple-PHP-IPAM"
CONFIGS_DIR="$REPO_DIR/testing/samples/staging-configs"

# Defaults
SSH_HOST="${SSH_HOST:-root@192.168.80.15}"
DOCROOT="${DOCROOT:-/opt/container_data/dev.seanmousseau.com/html/claude}"
CONTAINER="${CONTAINER:-dev_seanmousseau_com-apache-php-1}"
DRIVER="${DRIVER:-sqlite}"

# Color output (same pattern as test_api.sh)
if [[ -t 1 ]]; then
    G='\033[0;32m'; R='\033[0;31m'; Y='\033[0;33m'; C='\033[0;36m'; N='\033[0m'
else
    G=''; R=''; Y=''; C=''; N=''
fi

log()  { echo -e "${C}[DEPLOY]${N} $*"; }
pass() { echo -e "  ${G}✓${N} $1"; }
fail() { echo -e "  ${R}✗${N} $1"; }
warn() { echo -e "  ${Y}!${N} $1"; }

usage() {
    cat <<EOF
Usage: deploy_staging.sh --name=<slug> [--driver=sqlite|mysql|pgsql] [--fresh] [--yes]

Deploy a staging variant of Simple-PHP-IPAM to the dev server.

Options:
  --name=SLUG     Required. Subdirectory name under /claude/ (lowercase, digits, hyphens, underscores)
  --driver=DRIVER DB driver: sqlite (default), mysql, or pgsql
  --fresh         Remove existing data directory before deploying
  --yes           Skip confirmation prompt
  --help          Show this help message

Environment variables:
  SSH_HOST    SSH target           (default: root@192.168.80.15)
  DOCROOT     Remote document root (default: /opt/container_data/dev.seanmousseau.com/html/claude)
  CONTAINER   Docker container     (default: dev_seanmousseau_com-apache-php-1)

For MySQL/PostgreSQL, set these before running:
  STAGING_DB_DSN   e.g. mysql:host=localhost;dbname=ipam_staging
  STAGING_DB_USER  e.g. ipam
  STAGING_DB_PASS  e.g. secretpassword
EOF
    exit "${1:-0}"
}

# ---- Parse arguments ----

SLUG=""
FRESH=0
YES=0

for arg in "$@"; do
    case "$arg" in
        --name=*)    SLUG="${arg#--name=}" ;;
        --driver=*)  DRIVER="${arg#--driver=}" ;;
        --fresh)     FRESH=1 ;;
        --yes)       YES=1 ;;
        --help|-h)   usage 0 ;;
        *)           fail "Unknown argument: $arg"; usage 1 ;;
    esac
done

# ---- Validate inputs ----

if [[ -z "$SLUG" ]]; then
    fail "Missing required --name=<slug>"
    usage 1
fi

# Slug format: lowercase alphanumeric, hyphens, underscores
if ! [[ "$SLUG" =~ ^[a-z0-9_-]+$ ]]; then
    fail "Invalid slug '$SLUG' — must match ^[a-z0-9_-]+\$"
    exit 1
fi

# Production guard: refuse to deploy over the live IPAM instance
if [[ "$SLUG" == "ipam" ]]; then
    fail "Refusing to deploy to --name=ipam (production guard)"
    exit 1
fi

# Validate driver
case "$DRIVER" in
    sqlite|mysql|pgsql) ;;
    *) fail "Invalid driver '$DRIVER' — must be sqlite, mysql, or pgsql"; exit 1 ;;
esac

# Verify config template exists
TEMPLATE="$CONFIGS_DIR/$DRIVER.config.php"
if [[ ! -f "$TEMPLATE" ]]; then
    fail "Config template not found: $TEMPLATE"
    exit 1
fi

# Verify app directory exists
if [[ ! -d "$APP_DIR" ]]; then
    fail "App directory not found: $APP_DIR"
    exit 1
fi

# ---- Compute paths ----

TARGET="$DOCROOT/$SLUG"
# Container-internal path (DOCROOT maps to /var/www/html/claude inside the container)
CONTAINER_PATH="/var/www/html/claude/$SLUG"

# ---- Print plan and confirm ----

echo ""
log "Staging deployment plan"
echo ""
echo "  Slug:        $SLUG"
echo "  Driver:      $DRIVER"
echo "  Fresh:       $([ $FRESH -eq 1 ] && echo 'yes (data/ will be removed)' || echo 'no')"
echo "  SSH host:    $SSH_HOST"
echo "  Target:      $TARGET"
echo "  Container:   $CONTAINER"
echo "  Template:    $TEMPLATE"
echo ""

if [[ $YES -eq 0 ]]; then
    read -rp "Proceed? [y/N] " confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        warn "Aborted by user"
        exit 0
    fi
fi

# ---- Step 1: Verify SSH connectivity ----

log "Verifying SSH connectivity..."
if ssh -o ConnectTimeout=5 "$SSH_HOST" true 2>/dev/null; then
    pass "SSH connection to $SSH_HOST"
else
    fail "Cannot connect to $SSH_HOST"
    exit 1
fi

# ---- Step 2: Rsync application files ----

log "Syncing application files..."
rsync -az --delete \
    --exclude='data/' \
    --exclude='config.php' \
    --exclude='.htaccess' \
    "$APP_DIR/" "$SSH_HOST:$TARGET/"
pass "Rsync complete"

# ---- Step 3: Fresh install (remove data dir if --fresh) ----

if [[ $FRESH -eq 1 ]]; then
    log "Removing existing data directory (--fresh)..."
    ssh "$SSH_HOST" "rm -rf $TARGET/data"
    pass "Data directory removed"
fi

# ---- Step 4: Copy config template ----

log "Deploying $DRIVER config template..."
scp -q "$TEMPLATE" "$SSH_HOST:$TARGET/config.php"
pass "Config template deployed"

# ---- Step 5: Substitute DB credentials for mysql/pgsql ----

if [[ "$DRIVER" == "mysql" || "$DRIVER" == "pgsql" ]]; then
    log "Substituting database credentials in remote config.php..."

    # Source secrets if available
    SECRETS_FILE="${HOME}/.claude/dev-secrets.env"
    if [[ -f "$SECRETS_FILE" ]]; then
        set -a
        # shellcheck disable=SC1090
        source "$SECRETS_FILE"
        set +a
    fi

    DB_DSN="${STAGING_DB_DSN:-}"
    DB_USER="${STAGING_DB_USER:-}"
    DB_PASS="${STAGING_DB_PASS:-}"

    if [[ -z "$DB_DSN" || -z "$DB_USER" || -z "$DB_PASS" ]]; then
        warn "STAGING_DB_DSN, STAGING_DB_USER, or STAGING_DB_PASS not set"
        warn "Tokens left as placeholders in config.php — edit manually on the server"
    else
        ssh "$SSH_HOST" "sed -i \
            -e 's|__DB_DSN__|${DB_DSN}|g' \
            -e 's|__DB_USER__|${DB_USER}|g' \
            -e 's|__DB_PASS__|${DB_PASS}|g' \
            $TARGET/config.php"
        pass "Database credentials substituted"
    fi
fi

# ---- Step 6: Fix ownership ----

log "Setting file ownership..."
ssh "$SSH_HOST" "chown -R www-data:www-data $TARGET"
pass "Ownership set to www-data"

# ---- Step 7: Run migrations ----

log "Running migrations..."
MIGRATE_OUTPUT=$(ssh "$SSH_HOST" "docker exec $CONTAINER php $CONTAINER_PATH/migrate.php" 2>&1) || true
if [[ -n "$MIGRATE_OUTPUT" ]]; then
    echo "  $MIGRATE_OUTPUT"
fi
pass "Migrations complete"

# ---- Done ----

STAGING_URL="https://dev-direct.seanmousseau.com/claude/$SLUG/"
echo ""
log "Staging deployment complete!"
echo ""
echo -e "  ${G}URL:${N}  $STAGING_URL"
echo ""
echo "  Smoke test:"
echo "    curl -ksSo /dev/null -w '%{http_code}' '$STAGING_URL/status.php'"
echo ""

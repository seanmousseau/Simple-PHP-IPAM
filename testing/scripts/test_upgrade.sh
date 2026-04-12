#!/usr/bin/env bash
# test_upgrade.sh — Upgrade-path integration test for Simple PHP IPAM.
#
# Tests that importing a pre-v2.0.0 SQL dump and loading the app causes
# apply_migrations() to run cleanly and all v2.x pages to return HTTP 200.
#
# Usage:
#   bash testing/scripts/test_upgrade.sh [BASE_URL]
#
# Defaults to the dev server if BASE_URL is omitted.
# Requires ~/.claude/dev-secrets.env to be sourced for credentials.
#
# Example (dev server):
#   source ~/.claude/dev-secrets.env
#   bash testing/scripts/test_upgrade.sh \
#       https://dev-direct.seanmousseau.com:8343/claude/ipam
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
SNAPSHOT="$REPO_DIR/testing/samples/upgrade-snapshots/pre-v2.0.0.sql"

BASE_URL="${1:-https://dev-direct.seanmousseau.com:8343/claude/ipam}"
BASE_URL="${BASE_URL%/}"  # strip trailing slash

BASIC_AUTH="${BASIC_AUTH:-}"
ADMIN_USER="${IPAM_ADMIN_USER:-admin}"
ADMIN_PASS="${IPAM_ADMIN_PASS:-admin}"

# ── Helpers ───────────────────────────────────────────────────────────────────

PASS=0; FAIL=0; ERRORS=""

if [[ -t 1 ]]; then
    G='\033[0;32m'; R='\033[0;31m'; Y='\033[0;33m'; C='\033[0;36m'; N='\033[0m'
else
    G=''; R=''; Y=''; C=''; N=''
fi

pass() { PASS=$((PASS+1)); echo -e "  ${G}✓${N} $1"; }
fail() { FAIL=$((FAIL+1)); ERRORS+="  ✗ $1\n"; echo -e "  ${R}✗${N} $1"; }
log()  { echo -e "${C}[UPGRADE]${N} $*"; }

curl_get() {
    local url="$1"; shift
    if [[ -n "$BASIC_AUTH" ]]; then
        curl -sk --max-time 30 -u "$BASIC_AUTH" "$url" "$@"
    else
        curl -sk --max-time 30 "$url" "$@"
    fi
}

curl_post() {
    local url="$1"; shift
    if [[ -n "$BASIC_AUTH" ]]; then
        curl -sk --max-time 60 -u "$BASIC_AUTH" -X POST "$url" "$@"
    else
        curl -sk --max-time 60 -X POST "$url" "$@"
    fi
}

# Log in and return cookies
login_and_get_cookies() {
    local cookie_jar="$1"
    local csrf_page
    csrf_page=$(curl_get "$BASE_URL/login.php" -c "$cookie_jar")
    local csrf
    csrf=$(echo "$csrf_page" | grep -oP 'name="csrf" value="\K[^"]+' || echo "")
    if [[ -z "$csrf" ]]; then
        echo "" ; return
    fi
    curl_post "$BASE_URL/login.php" \
        -c "$cookie_jar" -b "$cookie_jar" \
        -d "username=${ADMIN_USER}&password=${ADMIN_PASS}&csrf=${csrf}" \
        -L -o /dev/null -s
    echo "$csrf"
}

# ── Pre-flight checks ─────────────────────────────────────────────────────────

log "Upgrade-path test: $BASE_URL"

if [[ ! -f "$SNAPSHOT" ]]; then
    echo -e "${Y}⚠ Snapshot file not found: $SNAPSHOT${N}"
    echo "  Generate it by exporting a v1.19.0-era database via db_tools.php"
    echo "  and saving it to testing/samples/upgrade-snapshots/pre-v2.0.0.sql"
    echo ""
    echo "  Falling back to using upgrade.spec.ts inline SQL for this test."
    echo "  Run the Playwright test instead:"
    echo "    IPAM_BASE_URL=$BASE_URL npx playwright test upgrade.spec.ts"
    exit 0
fi

# ── Step 1: Save current DB ───────────────────────────────────────────────────

log "Step 1: Export current DB (to restore after test)"
COOKIE_JAR=$(mktemp)
ORIG_SQL=$(mktemp --suffix=.sql)  # defined before trap so cleanup never references undefined var
trap 'rm -f "$COOKIE_JAR" "$ORIG_SQL" 2>/dev/null' EXIT

CSRF=$(login_and_get_cookies "$COOKIE_JAR")
if [[ -z "$CSRF" ]]; then
    fail "Could not log in to get CSRF token — check IPAM_ADMIN_USER/PASS"
    exit 1
fi
pass "Logged in as $ADMIN_USER"
curl_post "$BASE_URL/db_tools.php" \
    -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -d "action=export&csrf=$CSRF" \
    -o "$ORIG_SQL"

ORIG_SIZE=$(wc -c < "$ORIG_SQL")
if [[ "$ORIG_SIZE" -lt 100 ]]; then
    fail "DB export too small ($ORIG_SIZE bytes) — check credentials and URL"
    exit 1
fi
pass "Current DB exported ($ORIG_SIZE bytes)"

# ── Step 2: Import pre-v2 snapshot ───────────────────────────────────────────

log "Step 2: Import pre-v2.0.0 snapshot"
SNAPSHOT_SIZE=$(wc -c < "$SNAPSHOT")
log "  Snapshot: $SNAPSHOT ($SNAPSHOT_SIZE bytes)"

# Re-fetch CSRF (invalidated by previous POST)
CSRF_PAGE=$(curl_get "$BASE_URL/db_tools.php" -c "$COOKIE_JAR" -b "$COOKIE_JAR")
CSRF=$(echo "$CSRF_PAGE" | grep -oP 'name="csrf" value="\K[^"]+' || echo "")

IMPORT_RESP=$(curl_post "$BASE_URL/db_tools.php" \
    -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -F "action=import" \
    -F "confirmed=1" \
    -F "csrf=$CSRF" \
    -F "sql_file=@$SNAPSHOT;type=application/sql")

if echo "$IMPORT_RESP" | grep -qi "import successful"; then
    pass "Pre-v2 snapshot imported"
else
    fail "Snapshot import failed — response: $(echo "$IMPORT_RESP" | head -5)"
    # Restore immediately; fetch a fresh CSRF because the import POST invalidated the old one.
    CSRF_RECOVER=$(curl_get "$BASE_URL/db_tools.php" -c "$COOKIE_JAR" -b "$COOKIE_JAR" | \
                   grep -oP 'name="csrf" value="\K[^"]+' || echo "")
    curl_post "$BASE_URL/db_tools.php" \
        -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
        -F "action=import" -F "confirmed=1" -F "csrf=$CSRF_RECOVER" \
        -F "sql_file=@$ORIG_SQL;type=application/sql" > /dev/null 2>&1 || true
    exit 1
fi

# ── Step 3: Trigger migrations ────────────────────────────────────────────────

log "Step 3: Load login page to trigger apply_migrations()"
# After importing the old schema, re-login is needed (DB was replaced).
# Also, loading any init.php-backed page triggers the migration.
STATUS=$(curl_get "$BASE_URL/login.php" -o /dev/null -w "%{http_code}")
if [[ "$STATUS" == "200" ]]; then
    pass "login.php returns 200 after migration trigger"
else
    fail "login.php returned $STATUS (expected 200)"
fi

# ── Step 4: Log in with the pre-v2 snapshot's admin user ─────────────────────

log "Step 4: Log in with snapshot's admin credentials"
COOKIE_JAR2=$(mktemp)
trap 'rm -f "$COOKIE_JAR" "$COOKIE_JAR2" "$ORIG_SQL" 2>/dev/null' EXIT

CSRF_PAGE2=$(curl_get "$BASE_URL/login.php" -c "$COOKIE_JAR2")
CSRF2=$(echo "$CSRF_PAGE2" | grep -oP 'name="csrf" value="\K[^"]+' || echo "")

LOGIN_RESP=$(curl_post "$BASE_URL/login.php" \
    -c "$COOKIE_JAR2" -b "$COOKIE_JAR2" \
    -d "username=upgrade-test-admin&password=admin&csrf=$CSRF2" \
    -L -w "%{http_code}" -o /dev/null)

# If login succeeds it redirects to dashboard (200 after redirect)
if [[ "$LOGIN_RESP" == "200" ]]; then
    pass "Logged in as upgrade-test-admin"
else
    # Snapshot may not have this user — try the main admin.
    # Re-fetch CSRF: the first login POST invalidated $CSRF2.
    CSRF_PAGE2B=$(curl_get "$BASE_URL/login.php" -c "$COOKIE_JAR2" -b "$COOKIE_JAR2")
    CSRF2=$(echo "$CSRF_PAGE2B" | grep -oP 'name="csrf" value="\K[^"]+' || echo "")
    LOGIN_RESP2=$(curl_post "$BASE_URL/login.php" \
        -c "$COOKIE_JAR2" -b "$COOKIE_JAR2" \
        -d "username=${ADMIN_USER}&password=${ADMIN_PASS}&csrf=$CSRF2" \
        -L -w "%{http_code}" -o /dev/null)
    if [[ "$LOGIN_RESP2" == "200" ]]; then
        pass "Logged in as $ADMIN_USER (snapshot admin)"
    else
        fail "Could not log in after migration (status $LOGIN_RESP / $LOGIN_RESP2)"
    fi
fi

# ── Step 5: Verify v2.x pages are accessible ─────────────────────────────────

log "Step 5: Verify v2.x pages load (migrations ran)"

check_page() {
    local path="$1" desc="$2"
    local code
    code=$(curl_get "$BASE_URL/$path" -c "$COOKIE_JAR2" -b "$COOKIE_JAR2" \
           -o /dev/null -w "%{http_code}")
    if [[ "$code" == "200" ]]; then
        pass "$desc returns $code"
    else
        fail "$desc returned $code (expected 200)"
    fi
}

check_page "vlans.php"    "VLANs page    (vlans table added)"
check_page "vrfs.php"     "VRFs page     (vrfs table added)"
check_page "contacts.php" "Contacts page (contacts table added)"
check_page "tags.php"     "Tags page     (tags table added)"
check_page "sites.php"    "Sites page    (parent_id column added)"
check_page "users.php"    "Users page    (theme column added)"
check_page "subnets.php"  "Subnets page  (vrf_id column added)"
check_page "audit.php"    "Audit page"
check_page "dashboard.php" "Dashboard"

# Verify no PHP error visible on subnets page
SUBNET_BODY=$(curl_get "$BASE_URL/subnets.php" -c "$COOKIE_JAR2" -b "$COOKIE_JAR2")
if echo "$SUBNET_BODY" | grep -qi "fatal error\|no such column\|no such table"; then
    fail "subnets.php shows PHP/SQL error after migration"
else
    pass "subnets.php body is clean (no PHP/SQL errors)"
fi

# ── Step 6: Restore original DB ───────────────────────────────────────────────

log "Step 6: Restore original DB"
CSRF_PAGE3=$(curl_get "$BASE_URL/db_tools.php" -c "$COOKIE_JAR2" -b "$COOKIE_JAR2")
CSRF3=$(echo "$CSRF_PAGE3" | grep -oP 'name="csrf" value="\K[^"]+' || echo "")

RESTORE_RESP=$(curl_post "$BASE_URL/db_tools.php" \
    -c "$COOKIE_JAR2" -b "$COOKIE_JAR2" \
    -F "action=import" \
    -F "confirmed=1" \
    -F "csrf=$CSRF3" \
    -F "sql_file=@$ORIG_SQL;type=application/sql")

if echo "$RESTORE_RESP" | grep -qi "import successful"; then
    pass "Original DB restored"
else
    fail "DB restore failed — manual restore required from $ORIG_SQL"
fi

# ── Summary ───────────────────────────────────────────────────────────────────

echo ""
echo "$(printf '=%.0s' {1..50})"
printf "Upgrade-path tests: ${G}%d passed${N}" "$PASS"
if [[ $FAIL -gt 0 ]]; then
    printf ", ${R}%d failed${N}" "$FAIL"
fi
echo ""
if [[ -n "$ERRORS" ]]; then
    echo -e "${R}Failures:${N}"
    echo -e "$ERRORS"
fi
echo "$(printf '=%.0s' {1..50})"

[[ $FAIL -eq 0 ]]

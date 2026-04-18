#!/usr/bin/env bash
set -euo pipefail

#
# test_api.sh — API integration tests for Simple-PHP-IPAM
#
# Usage:
#   ./test_api.sh [BASE_URL]
#
# If BASE_URL is omitted, creates a temp API key in SQLite and uses PHP built-in server.
#
# Examples:
#   ./test_api.sh                                                              # auto-start local server
#   ./test_api.sh https://ipam.example.com                                     # test remote instance
#   API_KEY=abc123 ./test_api.sh https://ipam.example.com                      # with explicit key
#   BASIC_AUTH=user:pass ./test_api.sh https://ipam.example.com                 # behind HTTP Basic Auth
#
# For the dev server, source ~/.claude/dev-secrets.env first:
#   source ~/.claude/dev-secrets.env
#   SSH_HOST=root@192.168.80.15 \
#   SSH_DB_PATH=/opt/container_data/dev.seanmousseau.com/html/claude/ipam/data/ipam.sqlite \
#   BASIC_AUTH="$IPAM_BASIC_USER:$IPAM_BASIC_PASS" AUTH_MODE=query \
#   ./test_api.sh https://dev-direct.seanmousseau.com:8343/claude/ipam
#
# For the containerized Playwright harness (#451), bootstrap the image first and
# point at it via DOCKER_CONTAINER:
#   bash testing/playwright/bootstrap-app.sh sqlite
#   DOCKER_CONTAINER=ipam-pw-test ./test_api.sh https://127.0.0.1:8443
#   bash testing/playwright/teardown-app.sh
#
# Auto-API-key creation accepts THREE backends, in priority order:
#   1. DOCKER_CONTAINER  — runs sqlite3/php inside the named container via `docker exec`
#   2. SSH_HOST + SSH_DB_PATH — runs php on a remote host via ssh (dev-direct)
#   3. local PHP CLI — when BASE_URL is omitted (auto-started built-in server)
#
# Pass an explicit API_KEY=... to bypass auto-creation entirely.
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
APP_DIR="$REPO_DIR/Simple-PHP-IPAM"
DB_PATH="$APP_DIR/data/ipam.sqlite"

# Disable proxy for local testing
unset http_proxy https_proxy HTTP_PROXY HTTPS_PROXY GLOBAL_AGENT_HTTP_PROXY GLOBAL_AGENT_HTTPS_PROXY 2>/dev/null || true
export no_proxy='*'

PASS=0; FAIL=0; SKIP=0; ERRORS=""

if [[ -t 1 ]]; then
    G='\033[0;32m'; R='\033[0;31m'; Y='\033[0;33m'; C='\033[0;36m'; N='\033[0m'
else
    G=''; R=''; Y=''; C=''; N=''
fi

log()  { echo -e "${C}[TEST]${N} $*"; }
pass() { PASS=$((PASS+1)); echo -e "  ${G}✓${N} $1"; }
fail() { FAIL=$((FAIL+1)); ERRORS+="  ✗ $1\n"; echo -e "  ${R}✗${N} $1"; }
skip() { SKIP=$((SKIP+1)); echo -e "  ${Y}⊘${N} $1"; }

# ---- Setup ----

BASE_URL="${1:-}"
# Set when user passed an explicit URL (remote server — different SQLite than local $DB_PATH)
IS_REMOTE=0
[[ -n "$BASE_URL" ]] && IS_REMOTE=1

# SSH access to remote server's SQLite (required for auto key creation in remote mode)
SSH_HOST="${SSH_HOST:-}"       # e.g. root@192.168.80.15
SSH_DB_PATH="${SSH_DB_PATH:-}" # e.g. /opt/container_data/.../data/ipam.sqlite

# Containerized harness access (#451). When DOCKER_CONTAINER is set, the script
# runs sqlite manipulation via `docker exec` instead of ssh. The container name
# matches testing/playwright/bootstrap-app.sh (default: ipam-pw-test, overridable
# via IPAM_TEST_NAME). DOCKER_DB_PATH defaults to the inside-container path that
# bootstrap-app.sh's volume mount produces.
DOCKER_CONTAINER="${DOCKER_CONTAINER:-}"
DOCKER_DB_PATH="${DOCKER_DB_PATH:-/var/www/html/data/ipam.sqlite}"

# Helper: run PHP to manipulate the correct DB. Backend priority:
#   docker exec  > ssh remote  > local php
db_php() {
    local code="$1"
    if [[ -n "$DOCKER_CONTAINER" ]]; then
        docker exec -i "$DOCKER_CONTAINER" php -r "$code"
    elif [[ "$IS_REMOTE" -eq 1 && -n "$SSH_HOST" && -n "$SSH_DB_PATH" ]]; then
        ssh -o BatchMode=yes "$SSH_HOST" "php -r $(printf '%q' "$code")"
    elif [[ "$IS_REMOTE" -eq 0 ]]; then
        php -r "$code"
    fi
}

PHP_PID=""

# Resolve the right SQLite path for the chosen backend. DOCKER_DB_PATH is the
# inside-container path; SSH_DB_PATH is the remote-server path; DB_PATH is local.
if [[ -n "$DOCKER_CONTAINER" ]]; then
    EFFECTIVE_DB_PATH="$DOCKER_DB_PATH"
elif [[ -n "$SSH_DB_PATH" ]]; then
    EFFECTIVE_DB_PATH="$SSH_DB_PATH"
else
    EFFECTIVE_DB_PATH="$DB_PATH"
fi

# When running inside the containerized harness (DOCKER_CONTAINER set), route
# all api_keys DDL/DML through the app's own ipam_db($config) so the script
# works against whichever driver the container is configured for — sqlite,
# mysql, or pgsql. v2.11.0 #388. The local-disk and SSH paths stay
# sqlite-only (they always were, and adding driver detection on those paths
# would complicate test scenarios that don't need it).
container_api_key_php() {
    local code="$1"
    # Prefix the supplied snippet with the config + lib bootstrap so
    # $config and $db (= ipam_db($config)) are in scope.
    cat <<PHP
\$config = require '/var/www/html/config.php';
require_once '/var/www/html/lib.php';
\$db = ipam_db(\$config);
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$code
PHP
}

cleanup() {
    [[ -n "$PHP_PID" ]] && kill "$PHP_PID" 2>/dev/null || true
    if [[ -n "$DOCKER_CONTAINER" ]]; then
        db_php "$(container_api_key_php "\$db->exec(\"DELETE FROM api_keys WHERE name IN ('test-runner','test-readonly')\");")" 2>/dev/null || true
    else
        db_php "\$db = new PDO('sqlite:$EFFECTIVE_DB_PATH'); \$db->exec(\"DELETE FROM api_keys WHERE name IN ('test-runner','test-readonly')\");" 2>/dev/null || true
    fi
}
trap cleanup EXIT

if [[ "$IS_REMOTE" -eq 0 ]]; then
    PORT=$(( RANDOM % 1000 + 9000 ))
    log "Starting PHP built-in server on port $PORT..."
    php -S "127.0.0.1:$PORT" -t "$APP_DIR" >/dev/null 2>&1 &
    PHP_PID=$!
    sleep 1
    if ! kill -0 "$PHP_PID" 2>/dev/null; then
        echo "ERROR: Failed to start PHP server" >&2; exit 1
    fi
    BASE_URL="http://127.0.0.1:$PORT"
    log "Server at $BASE_URL (PID $PHP_PID)"
fi

API="$BASE_URL/api.php"

# ---- API Key ----
if [[ -z "${API_KEY:-}" ]]; then
    if [[ "$IS_REMOTE" -eq 1 && -z "$DOCKER_CONTAINER" && ( -z "$SSH_HOST" || -z "$SSH_DB_PATH" ) ]]; then
        echo "ERROR: Set API_KEY, or set DOCKER_CONTAINER, or set SSH_HOST + SSH_DB_PATH to auto-create keys." >&2
        echo "  API_KEY=your-key ./test_api.sh $BASE_URL" >&2
        echo "  DOCKER_CONTAINER=ipam-pw-test ./test_api.sh $BASE_URL" >&2
        echo "  SSH_HOST=root@host SSH_DB_PATH=/path/to/ipam.sqlite ./test_api.sh $BASE_URL" >&2
        exit 1
    fi

    local_db="$EFFECTIVE_DB_PATH"
    # Local-PHP backend needs the file present on disk. Docker/SSH backends
    # access the file inside the remote/container so the host-side check is
    # not meaningful.
    [[ "$IS_REMOTE" -eq 0 && -z "$DOCKER_CONTAINER" ]] && { [[ -f "$DB_PATH" ]] || { echo "ERROR: No database at $DB_PATH" >&2; exit 1; }; }

    API_KEY="test-key-$(date +%s)-$$"
    READONLY_KEY="test-readonly-$(date +%s)-$$"

    if [[ -n "$DOCKER_CONTAINER" ]]; then
        # Containerized harness path — engine-agnostic via ipam_db($config).
        # Clean up stale keys first, then insert the two fresh ones in a
        # single php invocation.
        db_php "$(container_api_key_php "
            \$db->exec(\"DELETE FROM api_keys WHERE name IN ('test-runner','test-readonly')\");
            \$h1 = hash('sha256', '$API_KEY');
            \$db->prepare(\"INSERT INTO api_keys (name, key_hash, is_active, created_by) VALUES ('test-runner', :h, 1, 'test_api.sh')\")->execute([':h' => \$h1]);
            \$h2 = hash('sha256', '$READONLY_KEY');
            \$db->prepare(\"INSERT INTO api_keys (name, description, is_readonly, key_hash, is_active, created_by) VALUES ('test-readonly', 'test suite readonly key', 1, :h, 1, 'test_api.sh')\")->execute([':h' => \$h2]);
        ")"
    else
        # Local-disk SQLite or SSH-remote SQLite path. Unchanged from pre-v2.11.0.
        db_php "\$db = new PDO('sqlite:$local_db'); \$db->exec(\"DELETE FROM api_keys WHERE name IN ('test-runner','test-readonly')\");" 2>/dev/null || true
        db_php "
            \$db = new PDO('sqlite:$local_db');
            \$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            \$h = hash('sha256', '$API_KEY');
            \$db->prepare(\"INSERT INTO api_keys (name, key_hash, is_active, created_by) VALUES ('test-runner', :h, 1, 'test_api.sh')\")->execute([':h' => \$h]);
        "
        db_php "
            \$db = new PDO('sqlite:$local_db');
            \$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            \$h = hash('sha256', '$READONLY_KEY');
            \$db->prepare(\"INSERT INTO api_keys (name, description, is_readonly, key_hash, is_active, created_by) VALUES ('test-readonly', 'test suite readonly key', 1, :h, 1, 'test_api.sh')\")->execute([':h' => \$h]);
        "
    fi
    log "Created temp API keys (runner + readonly)"
fi

# ---- Helpers ----

HTTP_CODE=""; BODY=""

# Optional HTTP Basic Auth for servers behind a gateway (e.g. BASIC_AUTH=user:pass)
BASIC_AUTH="${BASIC_AUTH:-}"

# Skip TLS cert verification. Required for the containerized harness (#451)
# which serves a self-signed cert. Defaults to "on" when DOCKER_CONTAINER is
# set, opt-in elsewhere via CURL_INSECURE=1.
CURL_INSECURE="${CURL_INSECURE:-}"
[[ -z "$CURL_INSECURE" && -n "$DOCKER_CONTAINER" ]] && CURL_INSECURE=1

# Optional extra header for every request. Added in v2.10.0 (#384) so the
# MySQL CI job can inject X-Forwarded-Proto: https against an HTTP-only
# `php -S` backend whose config has proxy_trust=true. Value is a single
# "Name: value" pair; leave unset in normal usage.
CURL_EXTRA_HEADER="${CURL_EXTRA_HEADER:-}"

# call METHOD URL [JSON_BODY]
call() {
    local method="$1" url="$2" body="${3:-}"
    local tmp; tmp=$(mktemp)
    local args=(-s --noproxy '*' -o "$tmp" -w '%{http_code}' -X "$method"
                -H "Content-Type: application/json")
    [[ -n "$CURL_INSECURE" ]] && args+=(-k)
    [[ -n "$CURL_EXTRA_HEADER" ]] && args+=(-H "$CURL_EXTRA_HEADER")
    [[ -n "$BASIC_AUTH" ]] && args+=(-u "$BASIC_AUTH")
    args+=(-H "Authorization: Bearer $API_KEY")
    [[ -n "$body" ]] && args+=(-d "$body")
    args+=("$url")
    HTTP_CODE=$(curl "${args[@]}")
    BODY=$(cat "$tmp")
    rm -f "$tmp"
}

# Convenience: call_api METHOD RESOURCE_AND_PARAMS [JSON_BODY]
# e.g. call_api GET "subnets&page=1&limit=2"
call_api() {
    call "$1" "${API}?resource=$2" "${3:-}"
}

jq_val() {
    python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('$1',''))" <<< "$BODY" 2>/dev/null || echo ""
}
jq_len() {
    python3 -c "import sys,json; d=json.load(sys.stdin); v=d.get('$1',d.get('data',[])); print(len(v) if isinstance(v,list) else 0)" <<< "$BODY" 2>/dev/null || echo "0"
}

assert_http() {
    local expected="$1" label="$2"
    [[ "$HTTP_CODE" == "$expected" ]] && pass "$label (HTTP $HTTP_CODE)" || fail "$label — expected $expected, got $HTTP_CODE. Body: ${BODY:0:200}"
}

# Call API with the read-only key instead of the main key
call_api_ro() {
    local saved_key="$API_KEY"
    API_KEY="${READONLY_KEY:-}"
    call_api "$@"
    API_KEY="$saved_key"
}

# ---- Pre-test cleanup via API ----
# Remove stale test data from previous runs that may not have cleaned up.
# Uses the API so it works for both local and remote testing.
cleanup_stale_data() {
    log "Cleaning up stale test data..."
    # Find and delete test subnets (203.0.113.x)
    call_api GET "subnets&limit=1000"
    if [[ "$HTTP_CODE" == "200" ]]; then
        local stale_ids
        stale_ids=$(python3 -c "
import sys, json
d = json.load(sys.stdin)
for s in d.get('subnets', []):
    if s['cidr'].startswith('203.0.113.'):
        print(s['id'])
" <<< "$BODY" 2>/dev/null || true)
        for sid in $stale_ids; do
            # Delete addresses in the subnet first
            call_api GET "addresses&subnet_id=$sid&limit=1000"
            local addr_ids
            addr_ids=$(python3 -c "
import sys, json
d = json.load(sys.stdin)
for a in d.get('addresses', []):
    print(a['id'])
" <<< "$BODY" 2>/dev/null || true)
            for aid in $addr_ids; do
                call_api DELETE "addresses&id=$aid"
            done
            call_api DELETE "subnets&id=$sid"
        done
        [[ -n "$stale_ids" ]] && log "  Removed stale test subnets" || true
    fi

    # Find and delete test sites (API-Test-Site-*)
    call_api GET sites
    if [[ "$HTTP_CODE" == "200" ]]; then
        local stale_site_ids
        stale_site_ids=$(python3 -c "
import sys, json
d = json.load(sys.stdin)
for s in d.get('sites', []):
    if s['name'].startswith('API-Test-Site-'):
        print(s['id'])
" <<< "$BODY" 2>/dev/null || true)
        for sid in $stale_site_ids; do
            call_api DELETE "sites&id=$sid"
        done
        [[ -n "$stale_site_ids" ]] && log "  Removed stale test sites" || true
    fi
}

# ====================================================================
log "=== Authentication ==="
# ====================================================================

_ba_args=()
[[ -n "$CURL_INSECURE" ]] && _ba_args+=(-k)
[[ -n "$CURL_EXTRA_HEADER" ]] && _ba_args+=(-H "$CURL_EXTRA_HEADER")
[[ -n "${BASIC_AUTH:-}" ]] && _ba_args+=(-u "$BASIC_AUTH")
HTTP_CODE=$(curl -s --noproxy '*' "${_ba_args[@]+"${_ba_args[@]}"}" -o /dev/null -w '%{http_code}' "${API}?resource=subnets")
[[ "$HTTP_CODE" == "401" ]] && pass "No auth → 401" || fail "No auth → expected 401, got $HTTP_CODE"

HTTP_CODE=$(curl -s --noproxy '*' "${_ba_args[@]+"${_ba_args[@]}"}" -o /dev/null -w '%{http_code}' -H "Authorization: Bearer bad-key" "${API}?resource=subnets")
[[ "$HTTP_CODE" == "401" ]] && pass "Bad key → 401" || fail "Bad key → expected 401, got $HTTP_CODE"

call_api GET subnets
if [[ "$HTTP_CODE" == "401" && "$AUTH_MODE" == "header" ]]; then
    log "Header auth returned 401 — proxy may strip Authorization. Switching to AUTH_MODE=query"
    AUTH_MODE="query"
    call_api GET subnets
fi
assert_http 200 "Valid key → 200"

call_api GET nonexistent
assert_http 404 "Unknown resource → 404"

# Now that auth is established, clean up stale data from prior runs
cleanup_stale_data

# ====================================================================
log "=== Sites CRUD ==="
# ====================================================================

TEST_SITE_NAME="API-Test-Site-$$-$(date +%s)"
call_api POST sites "{\"name\":\"$TEST_SITE_NAME\",\"description\":\"Created by test_api.sh\"}"
assert_http 201 "Create site"
SITE_ID=$(jq_val id)
[[ -n "$SITE_ID" && "$SITE_ID" != "None" && "$SITE_ID" != "" ]] && pass "Site ID: $SITE_ID" || fail "No site ID returned"

call_api GET sites
assert_http 200 "List sites"
SITE_COUNT=$(jq_len sites)
[[ "$SITE_COUNT" -gt 0 ]] && pass "Sites: $SITE_COUNT entries" || fail "Sites list empty"

if [[ -n "${SITE_ID:-}" && "$SITE_ID" != "None" ]]; then
    call_api PUT "sites&id=$SITE_ID" '{"description":"Updated by test_api.sh"}'
    assert_http 200 "Update site"

    # Duplicate site name on update
    call_api PUT "sites&id=$SITE_ID" "{\"name\":\"$TEST_SITE_NAME\"}"
    assert_http 200 "Update site same name (no conflict)"
    # Create a second site, then try to rename it to the first site's name
    call_api POST sites '{"name":"API-Conflict-Site","description":"conflict test"}'
    CONFLICT_SITE_ID=$(jq_val id)
    if [[ -n "$CONFLICT_SITE_ID" && "$CONFLICT_SITE_ID" != "None" ]]; then
        call_api PUT "sites&id=$CONFLICT_SITE_ID" "{\"name\":\"$TEST_SITE_NAME\"}"
        assert_http 409 "Duplicate site name on update → 409"
        call_api DELETE "sites&id=$CONFLICT_SITE_ID"
    fi
fi

# ====================================================================
log "=== Subnets CRUD ==="
# ====================================================================

# Use a unique test CIDR unlikely to exist in the DB
TEST_CIDR="203.0.113.0/24"   # RFC 5737 TEST-NET-3
TEST_IP="203.0.113.10"
TEST_CHILD_CIDR="203.0.113.128/28"

call_api POST subnets "{\"cidr\":\"$TEST_CIDR\",\"description\":\"API test subnet\",\"site_id\":${SITE_ID:-null}}"
assert_http 201 "Create subnet"
SUBNET_ID=$(jq_val id)
[[ -n "$SUBNET_ID" && "$SUBNET_ID" != "None" && "$SUBNET_ID" != "" ]] && pass "Subnet ID: $SUBNET_ID" || fail "No subnet ID returned"

# Check for overlap warnings
WARNINGS=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('warnings',[])))" <<< "$BODY" 2>/dev/null || echo "0")
[[ "$WARNINGS" -gt 0 ]] && pass "Overlap warnings returned ($WARNINGS)" || pass "No overlap warnings (expected for unique CIDR)"

call_api POST subnets "{\"cidr\":\"$TEST_CIDR\",\"description\":\"duplicate\"}"
assert_http 409 "Duplicate subnet → 409"

call_api GET subnets
assert_http 200 "List subnets"
SUBNET_TOTAL=$(jq_val total)
[[ -n "$SUBNET_TOTAL" && "$SUBNET_TOTAL" != "None" && "$SUBNET_TOTAL" != "" ]] && pass "Subnets total: $SUBNET_TOTAL" || fail "No total in subnets response"

# Overlapping child subnet
call_api POST subnets "{\"cidr\":\"$TEST_CHILD_CIDR\",\"description\":\"overlap test\"}"
if [[ "$HTTP_CODE" == "201" || "$HTTP_CODE" == "200" ]]; then
    OVL_WARNINGS=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('warnings',[])))" <<< "$BODY" 2>/dev/null || echo "0")
    [[ "$OVL_WARNINGS" -gt 0 ]] && pass "Overlap subnet created with $OVL_WARNINGS warning(s)" || pass "Overlap subnet created (HTTP $HTTP_CODE)"
    OVL_ID=$(jq_val id)
    call_api DELETE "subnets&id=$OVL_ID"
else
    fail "Overlap subnet create — expected 200/201, got $HTTP_CODE"
fi

# ====================================================================
log "=== Subnet Filters ==="
# ====================================================================

# Create a second test subnet (IPv4 with VLAN) for filter tests
TEST_VLAN=999
VLAN_SUBNET_CIDR="203.0.113.192/28"
call_api POST subnets "{\"cidr\":\"$VLAN_SUBNET_CIDR\",\"description\":\"API test vlan subnet\",\"vlan_id\":$TEST_VLAN}"
if [[ "$HTTP_CODE" == "201" || "$HTTP_CODE" == "200" ]]; then
    VLAN_SUBNET_ID=$(jq_val id)
    pass "Created VLAN test subnet ($VLAN_SUBNET_CIDR vlan=$TEST_VLAN)"
else
    fail "Create VLAN subnet — expected 201, got $HTTP_CODE"
    VLAN_SUBNET_ID=""
fi

# ip_version filter
call_api GET "subnets&ip_version=4"
assert_http 200 "Filter subnets by ip_version=4"
V4_COUNT=$(python3 -c "import sys,json; d=json.load(sys.stdin); bad=[s for s in d.get('subnets',[]) if s.get('ip_version')!=4]; print(len(bad))" <<< "$BODY" 2>/dev/null || echo "1")
[[ "$V4_COUNT" -eq 0 ]] && pass "ip_version=4 filter: all results are IPv4" || fail "ip_version=4 filter: $V4_COUNT non-IPv4 results"

call_api GET "subnets&ip_version=6"
assert_http 200 "Filter subnets by ip_version=6"

call_api GET "subnets&ip_version=5"
assert_http 400 "Invalid ip_version=5 → 400"

# vlan_id filter
if [[ -n "${VLAN_SUBNET_ID:-}" && "$VLAN_SUBNET_ID" != "None" && "$VLAN_SUBNET_ID" != "" ]]; then
    call_api GET "subnets&vlan_id=$TEST_VLAN"
    assert_http 200 "Filter subnets by vlan_id=$TEST_VLAN"
    VLAN_MATCH=$(python3 -c "import sys,json; d=json.load(sys.stdin); ids=[s['id'] for s in d.get('subnets',[]) if s.get('vlan_id')==$TEST_VLAN]; print(len(ids))" <<< "$BODY" 2>/dev/null || echo "0")
    [[ "$VLAN_MATCH" -ge 1 ]] && pass "vlan_id filter: found $VLAN_MATCH matching subnet(s)" || fail "vlan_id filter: no matching subnets"

    call_api DELETE "subnets&id=$VLAN_SUBNET_ID"
    [[ "$HTTP_CODE" == "204" ]] && pass "Deleted VLAN test subnet" || fail "Delete VLAN subnet — got $HTTP_CODE"
fi

call_api GET "subnets&vlan_id=9999"
assert_http 400 "Invalid vlan_id=9999 → 400"

# site_id filter on subnets
if [[ -n "${SITE_ID:-}" && "$SITE_ID" != "None" ]]; then
    call_api GET "subnets&site_id=$SITE_ID"
    assert_http 200 "Filter subnets by site_id"
fi

# address counts (?counts=1)
call_api GET "subnets&counts=1&page=1&limit=5"
assert_http 200 "Subnets with ?counts=1"
HAS_COUNTS=$(python3 -c "import sys,json; d=json.load(sys.stdin); subs=d.get('subnets',[]); ok=all('address_counts' in s for s in subs) if subs else True; print('yes' if ok else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
[[ "$HAS_COUNTS" == "yes" ]] && pass "Subnet ?counts=1: address_counts present" || fail "Subnet ?counts=1: address_counts missing"

# ====================================================================
log "=== Addresses CRUD ==="
# ====================================================================

if [[ -n "${SUBNET_ID:-}" && "$SUBNET_ID" != "None" ]]; then
    call_api POST addresses "{\"subnet_id\":$SUBNET_ID,\"ip\":\"$TEST_IP\",\"hostname\":\"api-test-host\",\"owner\":\"tester\",\"status\":\"used\",\"mac\":\"AA:BB:CC:DD:EE:FF\",\"expires_at\":\"2099-12-31\"}"
    assert_http 201 "Create address"
    ADDR_ID=$(jq_val id)
    [[ -n "$ADDR_ID" && "$ADDR_ID" != "None" && "$ADDR_ID" != "" ]] && pass "Address ID: $ADDR_ID" || fail "No address ID returned"

    call_api POST addresses "{\"subnet_id\":$SUBNET_ID,\"ip\":\"$TEST_IP\",\"hostname\":\"dup\",\"status\":\"used\"}"
    assert_http 409 "Duplicate address → 409"

    call_api GET "addresses&subnet_id=$SUBNET_ID"
    assert_http 200 "List addresses by subnet"
    ADDR_COUNT=$(jq_len addresses)
    [[ "$ADDR_COUNT" -gt 0 ]] && pass "Addresses: $ADDR_COUNT in subnet" || fail "Address list empty"
    HAS_MAC=$(python3 -c "import sys,json; d=json.load(sys.stdin); a=d.get('addresses',[{}])[0]; print('yes' if 'mac' in a else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
    [[ "$HAS_MAC" == "yes" ]] && pass "Address response includes mac field" || fail "Address response missing mac field"
    HAS_EXP=$(python3 -c "import sys,json; d=json.load(sys.stdin); a=d.get('addresses',[{}])[0]; print('yes' if 'expires_at' in a else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
    [[ "$HAS_EXP" == "yes" ]] && pass "Address response includes expires_at field" || fail "Address response missing expires_at field"

    if [[ -n "${ADDR_ID:-}" && "$ADDR_ID" != "None" ]]; then
        call_api PUT "addresses&id=$ADDR_ID" '{"hostname":"api-test-updated","status":"reserved","mac":"11:22:33:44:55:66","expires_at":"2099-06-30"}'
        assert_http 200 "Update address (with mac/expires_at)"
    fi
else
    skip "Addresses CRUD — no subnet ID"
fi

# ====================================================================
log "=== Read-only Endpoints ==="
# ====================================================================

if [[ -n "${ADDR_ID:-}" && "$ADDR_ID" != "None" ]]; then
    call_api GET "history&address_id=$ADDR_ID"
    assert_http 200 "Address history"
fi

call_api GET "search&q=api-test"
assert_http 200 "Search endpoint"
SEARCH_N=$(jq_len results)
[[ "$SEARCH_N" -gt 0 ]] && pass "Search: $SEARCH_N results" || skip "Search: 0 results"

call_api GET "audit&limit=5"
assert_http 200 "Audit log"
AUDIT_N=$(jq_len entries)
[[ "$AUDIT_N" -gt 0 ]] && pass "Audit: $AUDIT_N entries" || fail "Audit log empty"

call_api GET "audit&action=subnet.create&limit=5"
assert_http 200 "Audit log with action filter"

# site_id filter on addresses
if [[ -n "${SITE_ID:-}" && "$SITE_ID" != "None" ]]; then
    call_api GET "addresses&site_id=$SITE_ID"
    assert_http 200 "Filter addresses by site_id"
fi

# unassigned endpoint
if [[ -n "${SUBNET_ID:-}" && "$SUBNET_ID" != "None" ]]; then
    call_api GET "unassigned&subnet_id=$SUBNET_ID"
    assert_http 200 "Unassigned IPs for subnet"
    HAS_LIST=$(python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if 'unassigned' in d else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
    [[ "$HAS_LIST" == "yes" ]] && pass "Unassigned: response has unassigned array" || fail "Unassigned: missing unassigned field"
fi

call_api GET "unassigned"
assert_http 400 "Unassigned missing subnet_id → 400"

# ?expired=1 filter — create an address with a past expiry, verify filter returns it
if [[ -n "${SUBNET_ID:-}" && "$SUBNET_ID" != "None" ]]; then
    call_api POST addresses "{\"subnet_id\":$SUBNET_ID,\"ip\":\"203.0.113.11\",\"hostname\":\"api-test-expired\",\"status\":\"used\",\"expires_at\":\"2020-01-01\"}"
    if [[ "$HTTP_CODE" == "201" ]]; then
        EXPIRED_ADDR_ID=$(jq_val id)
        call_api GET "addresses&subnet_id=$SUBNET_ID&expired=1"
        assert_http 200 "Expired addresses filter (?expired=1)"
        EXP_COUNT=$(jq_len addresses)
        [[ "$EXP_COUNT" -ge 1 ]] && pass "Expired filter: $EXP_COUNT expired address(es)" || fail "Expired filter: no expired addresses found"
        call_api DELETE "addresses&id=$EXPIRED_ADDR_ID"
        [[ "$HTTP_CODE" == "204" ]] && pass "Deleted expired test address" || fail "Delete expired test address — got $HTTP_CODE"
    else
        skip "Expired filter test — could not create test address (HTTP $HTTP_CODE)"
    fi
fi

# ====================================================================
log "=== Pagination ==="
# ====================================================================

call_api GET "subnets&page=1&limit=2"
assert_http 200 "Subnets pagination"
PAGE_N=$(jq_len subnets)
[[ "$PAGE_N" -le 2 ]] && pass "Pagination: $PAGE_N items (limit 2)" || fail "Pagination not respected: $PAGE_N items"

# ====================================================================
log "=== Error Handling ==="
# ====================================================================

call_api POST subnets '{"cidr":"not-a-cidr"}'
assert_http 400 "Invalid CIDR → 400"

call_api POST addresses '{"subnet_id":99999}'
assert_http 400 "Missing IP → 400"

call_api PUT "subnets&id=999999" '{"description":"ghost"}'
assert_http 404 "Update non-existent → 404"

call_api DELETE "addresses&id=999999"
assert_http 404 "Delete non-existent → 404"

call_api POST subnets 'not json'
assert_http 400 "Invalid JSON → 400"

call "PATCH" "${API}?resource=subnets" ""
assert_http 405 "PATCH → 405"

# ====================================================================
log "=== Query Param Auth Removed (v3.0.0) ==="
# ====================================================================

QP_CODE=$(curl -s --noproxy '*' "${_ba_args[@]+"${_ba_args[@]}"}" -o /dev/null -w '%{http_code}' "${API}?resource=subnets&api_key=$API_KEY" 2>/dev/null)
[[ "$QP_CODE" == "401" ]] && pass "Query param ?api_key= rejected with 401" || fail "Expected 401 for query param auth, got $QP_CODE"

# ====================================================================
log "=== Read-only Key Enforcement ==="
# ====================================================================

if [[ -n "${READONLY_KEY:-}" ]]; then
    call_api_ro GET subnets
    assert_http 200 "Readonly key: GET subnets allowed"

    call_api_ro GET addresses
    assert_http 200 "Readonly key: GET addresses allowed"

    call_api_ro GET sites
    assert_http 200 "Readonly key: GET sites allowed"

    call_api_ro POST subnets '{"cidr":"203.0.113.0/28","description":"should be blocked"}'
    assert_http 403 "Readonly key: POST subnets → 403"

    call_api_ro POST addresses '{"subnet_id":1,"ip":"10.0.0.1","status":"used"}'
    assert_http 403 "Readonly key: POST addresses → 403"

    call_api_ro DELETE "subnets&id=1"
    assert_http 403 "Readonly key: DELETE subnets → 403"

    call_api_ro POST sites '{"name":"should-fail"}'
    assert_http 403 "Readonly key: POST sites → 403"
else
    skip "Read-only key tests — READONLY_KEY not set (remote mode)"
fi

# ====================================================================
log "=== IPv6 Unassigned ==="
# ====================================================================

call_api POST subnets '{"cidr":"2001:db8::/120","description":"API test IPv6 subnet"}'
if [[ "$HTTP_CODE" == "201" || "$HTTP_CODE" == "200" ]]; then
    IPV6_SUBNET_ID=$(jq_val id)
    pass "Created IPv6 test subnet (2001:db8::/120, id=$IPV6_SUBNET_ID)"

    call_api GET "unassigned&subnet_id=$IPV6_SUBNET_ID"
    assert_http 200 "Unassigned IPs for IPv6 subnet"
    HAS_V6_LIST=$(python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if 'unassigned' in d else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
    [[ "$HAS_V6_LIST" == "yes" ]] && pass "IPv6 unassigned: response has unassigned array" || fail "IPv6 unassigned: missing unassigned field"
    V6_UNASSIGNED_COUNT=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('unassigned',[])))" <<< "$BODY" 2>/dev/null || echo "0")
    [[ "$V6_UNASSIGNED_COUNT" -ge 1 ]] && pass "IPv6 unassigned: $V6_UNASSIGNED_COUNT address(es) listed" || fail "IPv6 unassigned: expected at least 1 address, got $V6_UNASSIGNED_COUNT"

    call_api DELETE "subnets&id=$IPV6_SUBNET_ID"
    [[ "$HTTP_CODE" == "204" ]] && pass "Deleted IPv6 test subnet" || fail "Delete IPv6 subnet — got $HTTP_CODE"
else
    skip "IPv6 unassigned test — could not create IPv6 subnet (HTTP $HTTP_CODE)"
fi

# ====================================================================
log "=== Bulk Write ==="
# ====================================================================

BULK_CIDR1="203.0.113.208/30"
BULK_CIDR2="203.0.113.212/30"

# Pre-cleanup in case of prior runs
call_api GET "subnets&limit=1000" 2>/dev/null || true
for bc in "$BULK_CIDR1" "$BULK_CIDR2" "203.0.113.216/30"; do
    stale_id=$(python3 -c "import sys,json; d=json.load(sys.stdin); r=[s['id'] for s in d.get('subnets',[]) if s['cidr']=='$bc']; print(r[0] if r else '')" <<< "$BODY" 2>/dev/null || echo "")
    [[ -n "$stale_id" ]] && call_api DELETE "subnets&id=$stale_id" 2>/dev/null || true
done

# Bulk create subnets — all success → 201
call_api POST "subnets&bulk=1" "[{\"cidr\":\"$BULK_CIDR1\",\"description\":\"bulk test 1\"},{\"cidr\":\"$BULK_CIDR2\",\"description\":\"bulk test 2\"}]"
assert_http 201 "Bulk create subnets → 201"
BULK_CREATED=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('created',0))" <<< "$BODY" 2>/dev/null || echo "0")
[[ "$BULK_CREATED" -eq 2 ]] && pass "Bulk create: $BULK_CREATED subnets created" || fail "Bulk create: expected 2, got $BULK_CREATED"
BULK_SUBNET_IDS=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(' '.join(str(r['id']) for r in d.get('results',[]) if r.get('success')))" <<< "$BODY" 2>/dev/null || echo "")

# Bulk partial success — one duplicate, one new → 207
call_api POST "subnets&bulk=1" "[{\"cidr\":\"$BULK_CIDR1\",\"description\":\"dup\"},{\"cidr\":\"203.0.113.216/30\",\"description\":\"new\"}]"
[[ "$HTTP_CODE" == "207" ]] && pass "Bulk partial success → 207" || fail "Bulk partial — expected 207, got $HTTP_CODE"
EXTRA_BULK_ID=$(python3 -c "import sys,json; d=json.load(sys.stdin); ids=[str(r['id']) for r in d.get('results',[]) if r.get('success')]; print(ids[0] if ids else '')" <<< "$BODY" 2>/dev/null || echo "")
[[ -n "$EXTRA_BULK_ID" ]] && call_api DELETE "subnets&id=$EXTRA_BULK_ID"

# Empty array → 400
call_api POST "subnets&bulk=1" "[]"
assert_http 400 "Bulk create empty array → 400"

# Non-array → 400
call_api POST "subnets&bulk=1" '{"cidr":"203.0.113.0/24"}'
assert_http 400 "Bulk create non-array body → 400"

# Bulk create addresses
if [[ -n "${SUBNET_ID:-}" && "$SUBNET_ID" != "None" ]]; then
    call_api POST "addresses&bulk=1" "[{\"subnet_id\":$SUBNET_ID,\"ip\":\"203.0.113.20\",\"hostname\":\"bulk-1\",\"status\":\"used\"},{\"subnet_id\":$SUBNET_ID,\"ip\":\"203.0.113.21\",\"hostname\":\"bulk-2\",\"status\":\"free\"}]"
    assert_http 201 "Bulk create addresses → 201"
    BULK_ADDR_CREATED=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('created',0))" <<< "$BODY" 2>/dev/null || echo "0")
    [[ "$BULK_ADDR_CREATED" -eq 2 ]] && pass "Bulk create: $BULK_ADDR_CREATED addresses created" || fail "Bulk create: expected 2, got $BULK_ADDR_CREATED"
    BULK_ADDR_IDS=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(' '.join(str(r['id']) for r in d.get('results',[]) if r.get('success')))" <<< "$BODY" 2>/dev/null || echo "")
    for aid in $BULK_ADDR_IDS; do
        call_api DELETE "addresses&id=$aid"
    done
fi

# Readonly key: bulk POST must be forbidden
if [[ -n "${READONLY_KEY:-}" ]]; then
    call_api_ro POST "subnets&bulk=1" "[{\"cidr\":\"203.0.113.228/30\"}]"
    assert_http 403 "Readonly key: bulk POST subnets → 403"
fi

# Cleanup bulk subnets
for bid in $BULK_SUBNET_IDS; do
    call_api DELETE "subnets&id=$bid"
done

# ====================================================================
log "=== VLANs Resource ==="
# ====================================================================

call_api GET "vlans"
assert_http 200 "GET vlans → 200"
HAS_VLANS=$(python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if 'vlans' in d else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
[[ "$HAS_VLANS" == "yes" ]] && pass "VLANs response has vlans array" || fail "VLANs response missing vlans key"

# Create a VLAN for subsequent tests
call_api POST "vlans" '{"vlan_id":299,"name":"api-test-vlan","description":"API test VLAN"}'
if [[ "$HTTP_CODE" == "201" ]]; then
    API_VLAN_FK=$(jq_val id)
    API_VLAN_ID=299
    pass "Create VLAN → 201 (id=$API_VLAN_FK)"

    # GET by id
    call_api GET "vlans&id=$API_VLAN_FK"
    assert_http 200 "GET vlan by id"
    GOT_NAME=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('vlan',{}).get('name',''))" <<< "$BODY" 2>/dev/null || echo "")
    [[ "$GOT_NAME" == "api-test-vlan" ]] && pass "GET vlan by id: name matches" || fail "GET vlan by id: expected 'api-test-vlan', got '$GOT_NAME'"

    # Subnet response includes vlan_name when vlan_fk is set
    if [[ -n "${SUBNET_ID:-}" && "$SUBNET_ID" != "None" ]]; then
        call_api PUT "subnets&id=$SUBNET_ID" "{\"vlan_fk\":$API_VLAN_FK}"
        [[ "$HTTP_CODE" == "200" ]] && pass "Attach VLAN to subnet via vlan_fk" || skip "Attach VLAN to subnet — got $HTTP_CODE"

        call_api GET "subnets&id=$SUBNET_ID"
        assert_http 200 "GET subnet after VLAN attach"
        HAS_VLAN_NAME=$(python3 -c "import sys,json; d=json.load(sys.stdin); s=d.get('subnet',d.get('subnets',[{}])[0] if 'subnets' in d else {}); print('yes' if 'vlan_name' in s else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
        [[ "$HAS_VLAN_NAME" == "yes" ]] && pass "Subnet response includes vlan_name field" || fail "Subnet response missing vlan_name field"
    fi

    # Duplicate VLAN ID → 409
    call_api POST "vlans" '{"vlan_id":299,"name":"api-test-vlan-dup"}'
    assert_http 409 "Duplicate VLAN vlan_id → 409"

    # Cleanup
    call_api DELETE "vlans&id=$API_VLAN_FK"
    [[ "$HTTP_CODE" == "204" ]] && pass "Delete VLAN → 204" || fail "Delete VLAN — got $HTTP_CODE"
else
    skip "VLAN create tests — could not create test VLAN (HTTP $HTTP_CODE)"
    API_VLAN_FK=""
fi

# Out-of-range vlan_id → 400
call_api POST "vlans" '{"vlan_id":5000,"name":"bad-vlan"}'
assert_http 400 "VLAN id out of range (5000) → 400"

# GET non-existent VLAN → 404
call_api GET "vlans&id=999999"
assert_http 404 "GET non-existent VLAN → 404"

# ====================================================================
log "=== Tags Filter ==="
# ====================================================================

# Create a tag for filter tests
call_api POST "tags" '{"name":"api-test-tag","colour":"#aabbcc"}'
if [[ "$HTTP_CODE" == "201" ]]; then
    API_TAG_ID=$(jq_val id)
    pass "Create tag → 201 (id=$API_TAG_ID)"

    # Attach tag to test subnet and address
    if [[ -n "${SUBNET_ID:-}" && "$SUBNET_ID" != "None" ]]; then
        call_api PUT "subnets&id=$SUBNET_ID" "{\"tag_ids\":[$API_TAG_ID]}"
        [[ "$HTTP_CODE" == "200" ]] && pass "Attach tag to subnet" || skip "Attach tag to subnet — got $HTTP_CODE"

        # Verify tags array in subnet list response
        call_api GET "subnets&id=$SUBNET_ID"
        assert_http 200 "GET subnet after tag attach"
        HAS_TAGS=$(python3 -c "import sys,json; d=json.load(sys.stdin); s=d.get('subnet',d.get('subnets',[{}])[0] if 'subnets' in d else {}); print('yes' if 'tags' in s else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
        [[ "$HAS_TAGS" == "yes" ]] && pass "Subnet response includes tags array" || fail "Subnet response missing tags field"

        # ?tag= filter on subnets
        call_api GET "subnets&tag=api-test-tag"
        assert_http 200 "Filter subnets by tag name"
        TAG_MATCH=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('subnets',[])))" <<< "$BODY" 2>/dev/null || echo "0")
        [[ "$TAG_MATCH" -ge 1 ]] && pass "?tag= filter on subnets: $TAG_MATCH match(es)" || fail "?tag= filter: no subnets returned"
    fi

    if [[ -n "${ADDR_ID:-}" && "$ADDR_ID" != "None" ]]; then
        call_api PUT "addresses&id=$ADDR_ID" "{\"tag_ids\":[$API_TAG_ID]}"
        [[ "$HTTP_CODE" == "200" ]] && pass "Attach tag to address" || skip "Attach tag to address — got $HTTP_CODE"

        # ?tag= filter on addresses
        call_api GET "addresses&tag=api-test-tag"
        assert_http 200 "Filter addresses by tag name"
        ADDR_TAG_MATCH=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('addresses',[])))" <<< "$BODY" 2>/dev/null || echo "0")
        [[ "$ADDR_TAG_MATCH" -ge 1 ]] && pass "?tag= filter on addresses: $ADDR_TAG_MATCH match(es)" || fail "?tag= filter on addresses: no results"
    fi

    # Cleanup tag
    call_api DELETE "tags&id=$API_TAG_ID"
    [[ "$HTTP_CODE" == "204" ]] && pass "Delete tag → 204" || fail "Delete tag — got $HTTP_CODE"
else
    skip "Tag filter tests — could not create test tag (HTTP $HTTP_CODE)"
    API_TAG_ID=""
fi

# ====================================================================
log "=== Site Hierarchy ==="
# ====================================================================

# Create a parent region
call_api POST "sites" '{"name":"api-test-region","description":"API test parent region"}'
if [[ "$HTTP_CODE" == "201" ]]; then
    API_PARENT_SITE_ID=$(jq_val id)
    pass "Create parent region site → 201 (id=$API_PARENT_SITE_ID)"

    # Verify site response includes parent_id field
    call_api GET "sites&id=$API_PARENT_SITE_ID"
    assert_http 200 "GET site by id"
    HAS_PARENT_ID=$(python3 -c "import sys,json; d=json.load(sys.stdin); s=d.get('site',d.get('sites',[{}])[0] if 'sites' in d else {}); print('yes' if 'parent_id' in s else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
    [[ "$HAS_PARENT_ID" == "yes" ]] && pass "Site response includes parent_id field" || fail "Site response missing parent_id field"

    # Create a child site under the parent
    call_api POST "sites" "{\"name\":\"api-test-child-site\",\"description\":\"API test child\",\"parent_id\":$API_PARENT_SITE_ID}"
    if [[ "$HTTP_CODE" == "201" ]]; then
        API_CHILD_SITE_ID=$(jq_val id)
        pass "Create child site → 201 (id=$API_CHILD_SITE_ID)"

        # ?parent_id= filter on sites
        call_api GET "sites&parent_id=$API_PARENT_SITE_ID"
        assert_http 200 "Filter sites by parent_id"
        CHILD_COUNT=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('sites',[])))" <<< "$BODY" 2>/dev/null || echo "0")
        [[ "$CHILD_COUNT" -ge 1 ]] && pass "?parent_id= filter: $CHILD_COUNT child site(s)" || fail "?parent_id= filter: no sites returned"

        # Cleanup child
        call_api DELETE "sites&id=$API_CHILD_SITE_ID"
        [[ "$HTTP_CODE" == "204" ]] && pass "Delete child site → 204" || fail "Delete child site — got $HTTP_CODE"
    else
        skip "Child site tests — could not create child site (HTTP $HTTP_CODE)"
    fi

    # Cleanup parent
    call_api DELETE "sites&id=$API_PARENT_SITE_ID"
    [[ "$HTTP_CODE" == "204" ]] && pass "Delete parent region → 204" || fail "Delete parent region — got $HTTP_CODE"
else
    skip "Site hierarchy tests — could not create parent region (HTTP $HTTP_CODE)"
fi

# ====================================================================
log "=== VRFs ==="
# ====================================================================

call_api GET vrfs
assert_http 200 "GET vrfs → 200"
VRFS_ARRAY=$(python3 -c "import sys,json; print(type(json.load(sys.stdin).get('vrfs',[])).__name__)" <<< "$BODY" 2>/dev/null || echo "")
[[ "$VRFS_ARRAY" == "list" ]] && pass "vrfs array is a list" || fail "vrfs response missing 'vrfs' array"

# Create a VRF
call_api POST vrfs '{"name":"API-Test-VRF","description":"API test","rd":"65000:1"}'
assert_http 201 "Create VRF → 201"
API_VRF_ID=$(python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" <<< "$BODY" 2>/dev/null || echo "")
[[ -n "$API_VRF_ID" && "$API_VRF_ID" != "None" ]] && pass "Create VRF returns id=$API_VRF_ID" || fail "Create VRF — no id in response"

# Get VRF
call_api GET "vrfs&id=$API_VRF_ID"
assert_http 200 "GET single VRF → 200"
VRF_NAME=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('vrf',d).get('name',''))" <<< "$BODY" 2>/dev/null || echo "")
[[ "$VRF_NAME" == "API-Test-VRF" ]] && pass "VRF name matches" || fail "VRF name mismatch: $VRF_NAME"

# Update VRF
call_api PUT "vrfs&id=$API_VRF_ID" '{"name":"API-Test-VRF-UPDATED","description":"updated","rd":"65000:2"}'
assert_http 200 "Update VRF → 200"

# VRF overlap: same CIDR in different VRFs should succeed
call_api POST subnets '{"cidr":"10.200.0.0/24","description":"VRF overlap test 1"}'
assert_http 201 "Create subnet (global VRF) → 201"
OVERLAP_SUBNET1=$(python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" <<< "$BODY" 2>/dev/null || echo "")

call_api POST subnets "{\"cidr\":\"10.200.0.0/24\",\"description\":\"VRF overlap test 2\",\"vrf_id\":$API_VRF_ID}"
assert_http 201 "Create same CIDR in different VRF → 201 (overlap allowed)"
OVERLAP_SUBNET2=$(python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" <<< "$BODY" 2>/dev/null || echo "")

# Subnet response includes vrf_id and vrf_name
call_api GET "subnets&id=$OVERLAP_SUBNET2"
VRF_NAME_IN_SUBNET=$(python3 -c "import sys,json; d=json.load(sys.stdin); s=d.get('subnet',{}); print(s.get('vrf_name','MISSING'))" <<< "$BODY" 2>/dev/null || echo "")
[[ "$VRF_NAME_IN_SUBNET" == "API-Test-VRF-UPDATED" ]] && pass "Subnet response includes vrf_name" || fail "Subnet vrf_name mismatch: $VRF_NAME_IN_SUBNET"

# vrf_id filter on subnets
call_api GET "subnets&vrf_id=$API_VRF_ID"
assert_http 200 "GET subnets with ?vrf_id= → 200"
VRF_FILTER_COUNT=$(python3 -c "import sys,json; print(len(json.load(sys.stdin).get('subnets',[])))" <<< "$BODY" 2>/dev/null || echo "0")
[[ "$VRF_FILTER_COUNT" -ge 1 ]] && pass "?vrf_id= filter: $VRF_FILTER_COUNT subnet(s)" || fail "?vrf_id= filter returned no subnets"

# Cleanup overlap test subnets
[[ -n "$OVERLAP_SUBNET2" && "$OVERLAP_SUBNET2" != "None" ]] && { call_api DELETE "subnets&id=$OVERLAP_SUBNET2"; assert_http 204 "Delete VRF-scoped subnet → 204"; }
[[ -n "$OVERLAP_SUBNET1" && "$OVERLAP_SUBNET1" != "None" ]] && { call_api DELETE "subnets&id=$OVERLAP_SUBNET1"; assert_http 204 "Delete global-VRF subnet → 204"; }

# Delete VRF
[[ -n "$API_VRF_ID" && "$API_VRF_ID" != "None" ]] && {
    call_api DELETE "vrfs&id=$API_VRF_ID"
    assert_http 204 "Delete VRF → 204"
}

# ====================================================================
log "=== Contacts ==="
# ====================================================================

call_api GET contacts
assert_http 200 "GET contacts → 200"
CONTACTS_ARRAY=$(python3 -c "import sys,json; print(type(json.load(sys.stdin).get('contacts',[])).__name__)" <<< "$BODY" 2>/dev/null || echo "")
[[ "$CONTACTS_ARRAY" == "list" ]] && pass "contacts array is a list" || fail "contacts response missing 'contacts' array"

# Create a contact
call_api POST contacts '{"name":"API Test Contact","email":"api@example.com","phone":"555-1234","org":"Test Org","note":"API test"}'
assert_http 201 "Create contact → 201"
API_CONTACT_ID=$(python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" <<< "$BODY" 2>/dev/null || echo "")
[[ -n "$API_CONTACT_ID" && "$API_CONTACT_ID" != "None" ]] && pass "Create contact returns id=$API_CONTACT_ID" || fail "Create contact — no id in response"

# Search contacts with ?q=
call_api GET "contacts&q=API+Test"
assert_http 200 "GET contacts with ?q= → 200"
CONTACT_SEARCH_COUNT=$(python3 -c "import sys,json; print(len(json.load(sys.stdin).get('contacts',[])))" <<< "$BODY" 2>/dev/null || echo "0")
[[ "$CONTACT_SEARCH_COUNT" -ge 1 ]] && pass "?q= contact search: $CONTACT_SEARCH_COUNT result(s)" || fail "?q= contact search returned no results"

# Update contact
call_api PUT "contacts&id=$API_CONTACT_ID" '{"name":"API Test Contact UPDATED","email":"updated@example.com","phone":"555-5678","org":"Updated Org","note":"updated"}'
assert_http 200 "Update contact → 200"

# Verify owner_contact_id in address response after linking
# Create a subnet + address to test contact_id filter
call_api POST subnets '{"cidr":"10.201.0.0/24","description":"contact test subnet"}'
assert_http 201 "Create contact-test subnet → 201"
CONTACT_SUBNET_ID=$(python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" <<< "$BODY" 2>/dev/null || echo "")

if [[ -n "$CONTACT_SUBNET_ID" && "$CONTACT_SUBNET_ID" != "None" ]]; then
    call_api POST addresses "{\"subnet_id\":$CONTACT_SUBNET_ID,\"ip\":\"10.201.0.10\",\"hostname\":\"contact-test\",\"owner_contact_id\":$API_CONTACT_ID}"
    assert_http 201 "Create address with owner_contact_id → 201"
    CONTACT_ADDR_ID=$(python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" <<< "$BODY" 2>/dev/null || echo "")

    # Verify owner_contact_name in address response
    if [[ -n "$CONTACT_ADDR_ID" && "$CONTACT_ADDR_ID" != "None" ]]; then
        call_api GET "addresses&subnet_id=$CONTACT_SUBNET_ID"
        CONTACT_NAME_IN_ADDR=$(python3 -c "
import sys, json
d = json.load(sys.stdin)
for a in d.get('addresses', []):
    if str(a.get('id','')) == '${CONTACT_ADDR_ID}':
        print(a.get('owner_contact_name','MISSING'))
        break
else:
    print('NOT_FOUND')
" <<< "$BODY" 2>/dev/null || echo "MISSING")
        [[ "$CONTACT_NAME_IN_ADDR" == "API Test Contact UPDATED" ]] && pass "Address response includes owner_contact_name" || fail "owner_contact_name mismatch: $CONTACT_NAME_IN_ADDR"

        # contact_id filter on addresses
        call_api GET "addresses&contact_id=$API_CONTACT_ID"
        assert_http 200 "GET addresses with ?contact_id= → 200"
        CONTACT_ADDR_COUNT=$(python3 -c "import sys,json; print(len(json.load(sys.stdin).get('addresses',[])))" <<< "$BODY" 2>/dev/null || echo "0")
        [[ "$CONTACT_ADDR_COUNT" -ge 1 ]] && pass "?contact_id= filter: $CONTACT_ADDR_COUNT address(es)" || fail "?contact_id= filter returned no addresses"

        call_api DELETE "addresses&id=$CONTACT_ADDR_ID"
        assert_http 204 "Delete contact-test address → 204"
    fi
    call_api DELETE "subnets&id=$CONTACT_SUBNET_ID"
    assert_http 204 "Delete contact-test subnet → 204"
fi

# Delete contact
[[ -n "$API_CONTACT_ID" && "$API_CONTACT_ID" != "None" ]] && {
    call_api DELETE "contacts&id=$API_CONTACT_ID"
    assert_http 204 "Delete contact → 204"
}

# ====================================================================
log "=== Scan API (v2.3.0) ==="
# ====================================================================

# Use the test subnet created in Subnets CRUD section (SUBNET_ID)
if [[ -n "${SUBNET_ID:-}" && "$SUBNET_ID" != "None" ]]; then
    # GET scan_results — expect 200 with empty array (no scans run)
    call_api GET "scan_results&subnet_id=$SUBNET_ID"
    assert_http 200 "GET scan_results (empty)"
    SCAN_IS_ARR=$(python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if isinstance(d,list) else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
    [[ "$SCAN_IS_ARR" == "yes" ]] && pass "scan_results is array" || fail "scan_results expected array"

    # GET scan_history — expect 200 with empty array
    call_api GET "scan_history&subnet_id=$SUBNET_ID"
    assert_http 200 "GET scan_history (empty)"
    HIST_IS_ARR=$(python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if isinstance(d,list) else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
    [[ "$HIST_IS_ARR" == "yes" ]] && pass "scan_history is array" || fail "scan_history expected array"

    # GET scan_schedules — expect 200 with array (may be empty)
    call_api GET "scan_schedules"
    assert_http 200 "GET scan_schedules list"
    SCHED_IS_ARR=$(python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if isinstance(d,list) else 'no')" <<< "$BODY" 2>/dev/null || echo "no")
    [[ "$SCHED_IS_ARR" == "yes" ]] && pass "scan_schedules is array" || fail "scan_schedules expected array"

    # GET scan_schedules?subnet_id=N — no schedule yet, expect 200 with null
    call_api GET "scan_schedules&subnet_id=$SUBNET_ID"
    assert_http 200 "GET scan_schedules by subnet_id (none)"
    SCHED_VAL=$(python3 -c "import sys,json; d=json.load(sys.stdin); print('null' if d is None else 'present')" <<< "$BODY" 2>/dev/null || echo "null")
    [[ "$SCHED_VAL" == "null" ]] && pass "scan_schedule absent before creation" || skip "scan_schedule already exists for test subnet"

    # POST scan_schedules — create schedule
    call_api POST "scan_schedules" "{\"subnet_id\":$SUBNET_ID,\"method\":\"icmp\",\"interval_minutes\":60,\"is_active\":1}"
    if [[ "$HTTP_CODE" == "200" || "$HTTP_CODE" == "201" ]]; then
        pass "POST scan_schedules create (HTTP $HTTP_CODE)"
    else
        fail "POST scan_schedules create — expected 200/201, got $HTTP_CODE. Body: ${BODY:0:200}"
    fi

    # GET scan_schedules?subnet_id=N — should now return the schedule
    call_api GET "scan_schedules&subnet_id=$SUBNET_ID"
    assert_http 200 "GET scan_schedules by subnet_id (after create)"
    SCHED_METHOD=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('method','') if d else '')" <<< "$BODY" 2>/dev/null || echo "")
    [[ "$SCHED_METHOD" == "icmp" ]] && pass "scan_schedule method=icmp" || fail "scan_schedule method expected icmp, got: $SCHED_METHOD"

    # scan_schedules shows up in list
    call_api GET "scan_schedules"
    SCHED_COUNT=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d) if isinstance(d,list) else 0)" <<< "$BODY" 2>/dev/null || echo "0")
    [[ "$SCHED_COUNT" -ge 1 ]] && pass "scan_schedules list has ≥1 entry" || fail "scan_schedules list empty after create"

    # scan_run on large subnet should return 400 (cap enforced) or 404 for unknown
    call_api POST "scan_run&subnet_id=999999"
    [[ "$HTTP_CODE" == "400" || "$HTTP_CODE" == "404" || "$HTTP_CODE" == "403" ]] \
        && pass "scan_run rejects non-existent subnet (HTTP $HTTP_CODE)" \
        || fail "scan_run unexpected status $HTTP_CODE for non-existent subnet"

    # DELETE scan_schedules — remove schedule
    call_api DELETE "scan_schedules&subnet_id=$SUBNET_ID"
    assert_http 204 "DELETE scan_schedule"

    # GET scan_schedules?subnet_id=N — should now return null again
    call_api GET "scan_schedules&subnet_id=$SUBNET_ID"
    assert_http 200 "GET scan_schedules after delete"
    SCHED_GONE=$(python3 -c "import sys,json; d=json.load(sys.stdin); print('null' if d is None else 'present')" <<< "$BODY" 2>/dev/null || echo "present")
    [[ "$SCHED_GONE" == "null" ]] && pass "scan_schedule absent after delete" || fail "scan_schedule still present after delete"
else
    skip "Scan API tests — no test subnet available (SUBNET_ID not set)"
fi

# ====================================================================
log "=== Devices API ==="
# ====================================================================

# POST device — unique name avoids collisions on repeated runs
_DEV_TS=$(date +%s)
call_api POST "devices" "{\"name\":\"api-test-router-$_DEV_TS\",\"type\":\"router\",\"vendor\":\"Cisco\",\"model\":\"ISR4321\",\"serial\":\"TST001\",\"note\":\"\"}"
assert_http 201 "POST device"
DEV_ID=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('device',{}).get('id',''))" <<< "$BODY" 2>/dev/null || echo "")
[[ -n "$DEV_ID" && "$DEV_ID" != "None" ]] && pass "POST device: id=$DEV_ID" || fail "POST device: no id in response"

if [[ -n "${DEV_ID:-}" && "$DEV_ID" != "None" ]]; then
    # GET single device
    call_api GET "devices&id=$DEV_ID"
    assert_http 200 "GET device by id"
    DEV_NAME=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('device',{}).get('name',''))" <<< "$BODY" 2>/dev/null || echo "")
    [[ "$DEV_NAME" == "api-test-router-$_DEV_TS" ]] && pass "GET device: name correct" || fail "GET device: name='$DEV_NAME'"

    # GET list with type filter
    call_api GET "devices&type=router"
    assert_http 200 "GET devices?type=router"

    # GET list with search
    call_api GET "devices&q=api-test"
    assert_http 200 "GET devices?q=api-test"

    # PUT device
    call_api PUT "devices&id=$DEV_ID" '{"model":"ISR4351"}'
    assert_http 200 "PUT device"

    # POST device interface
    call_api POST "device_interfaces" "{\"device_id\":$DEV_ID,\"name\":\"GigabitEthernet0/0\",\"description\":\"uplink\"}"
    assert_http 201 "POST device_interface"
    IFACE_ID=$(python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('interface',{}).get('id',''))" <<< "$BODY" 2>/dev/null || echo "")
    [[ -n "$IFACE_ID" && "$IFACE_ID" != "None" ]] && pass "POST device_interface: id=$IFACE_ID" || fail "POST device_interface: no id"

    if [[ -n "${IFACE_ID:-}" && "$IFACE_ID" != "None" ]]; then
        # GET interfaces for device
        call_api GET "device_interfaces&device_id=$DEV_ID"
        assert_http 200 "GET device_interfaces by device_id"

        # GET single interface
        call_api GET "device_interfaces&id=$IFACE_ID"
        assert_http 200 "GET device_interface by id"

        # PUT interface
        call_api PUT "device_interfaces&id=$IFACE_ID" '{"description":"updated uplink"}'
        assert_http 200 "PUT device_interface"

        # DELETE interface
        call_api DELETE "device_interfaces&id=$IFACE_ID"
        assert_http 204 "DELETE device_interface"
    fi

    # DELETE device
    call_api DELETE "devices&id=$DEV_ID"
    assert_http 204 "DELETE device"

    # Confirm gone
    call_api GET "devices&id=$DEV_ID"
    assert_http 404 "GET deleted device returns 404"
else
    skip "Device sub-tests — no DEV_ID"
fi

# ====================================================================
log "=== OpenAPI Spec ==="
# ====================================================================

# GET spec (no auth required)
SPEC_HTTP=$(curl -s --noproxy '*' "${_ba_args[@]+"${_ba_args[@]}"}" -k -o /tmp/_ipam_spec.yaml -w '%{http_code}' "$BASE_URL/api.php?resource=spec")
[[ "$SPEC_HTTP" == "200" ]] && pass "GET ?resource=spec HTTP 200" || fail "GET ?resource=spec HTTP $SPEC_HTTP"
SPEC_CT=$(curl -s --noproxy '*' "${_ba_args[@]+"${_ba_args[@]}"}" -k -I "$BASE_URL/api.php?resource=spec" 2>/dev/null | grep -i '^content-type' | head -1 || echo "")
[[ "$SPEC_CT" == *"application/yaml"* ]] && pass "spec Content-Type: application/yaml" || fail "spec Content-Type unexpected: $SPEC_CT"
SPEC_OPENAPI=$(python3 -c "import sys; data=open('/tmp/_ipam_spec.yaml').read(); print('ok' if 'openapi: ' in data else 'missing')" 2>/dev/null || echo "missing")
[[ "$SPEC_OPENAPI" == "ok" ]] && pass "spec body contains 'openapi:' key" || fail "spec body missing 'openapi:' key"

# Confirm X-IPAM-API-Version header on a normal endpoint
VER_HEADER=$(curl -s --noproxy '*' "${_ba_args[@]+"${_ba_args[@]}"}" -k -I -H "Authorization: Bearer $API_KEY" "$BASE_URL/api.php?resource=subnets" 2>/dev/null | grep -i 'x-ipam-api-version' | head -1 || echo "")
[[ "$VER_HEADER" == *"1"* ]] && pass "X-IPAM-API-Version: 1 header present" || fail "X-IPAM-API-Version header missing or wrong: $VER_HEADER"

# ====================================================================
log "=== Health Check ==="
# ====================================================================

STATUS_HTTP=$(curl -s --noproxy '*' "${_ba_args[@]+"${_ba_args[@]}"}" -o /tmp/_ipam_status.json -w '%{http_code}' "$BASE_URL/status.php")
if [[ "$STATUS_HTTP" == "200" ]]; then
    STATUS_VAL=$(python3 -c "import json; d=json.load(open('/tmp/_ipam_status.json')); print(d.get('status',''))" 2>/dev/null || echo "")
    [[ "$STATUS_VAL" == "ok" ]] && pass "status.php: {\"status\":\"ok\"}" || fail "status.php: unexpected body (status=$STATUS_VAL)"
else
    skip "status.php: HTTP $STATUS_HTTP (may be behind extra auth layer)"
fi

# ====================================================================
log "=== Cleanup ==="
# ====================================================================

if [[ -n "${ADDR_ID:-}" && "$ADDR_ID" != "None" ]]; then
    call_api DELETE "addresses&id=$ADDR_ID"
    assert_http 204 "Delete address"
fi

if [[ -n "${SUBNET_ID:-}" && "$SUBNET_ID" != "None" ]]; then
    call_api DELETE "subnets&id=$SUBNET_ID"
    assert_http 204 "Delete subnet"
fi

if [[ -n "${SITE_ID:-}" && "$SITE_ID" != "None" ]]; then
    call_api DELETE "sites&id=$SITE_ID"
    assert_http 204 "Delete site"
fi

# ====================================================================
echo ""
echo -e "${C}════════════════════════════════════${N}"
echo -e "  ${G}PASS: $PASS${N}  ${R}FAIL: $FAIL${N}  ${Y}SKIP: $SKIP${N}"
echo -e "${C}════════════════════════════════════${N}"

if [[ $FAIL -gt 0 ]]; then
    echo -e "\n${R}Failed:${N}"
    echo -e "$ERRORS"
    exit 1
fi
echo -e "\n${G}All tests passed!${N}"

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
#   ./test_api.sh                                          # auto-start local server
#   ./test_api.sh https://ipam.example.com                 # test remote instance
#   API_KEY=abc123 ./test_api.sh https://ipam.example.com  # with explicit key
#   AUTH_MODE=query API_KEY=abc123 ./test_api.sh https://ipam.example.com  # query param auth (proxy strips headers)
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
PHP_PID=""
cleanup() {
    [[ -n "$PHP_PID" ]] && kill "$PHP_PID" 2>/dev/null || true
    if [[ -z "${1:-}" && -f "$DB_PATH" ]]; then
        php -r "\$db = new PDO('sqlite:$DB_PATH'); \$db->exec(\"DELETE FROM api_keys WHERE name = 'test-runner'\");" 2>/dev/null || true
    fi
}
trap cleanup EXIT

if [[ -z "$BASE_URL" ]]; then
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
    if [[ -n "$BASE_URL" && ! -f "$DB_PATH" ]]; then
        echo "ERROR: API_KEY is required for remote testing." >&2
        echo "Usage: API_KEY=your-key ./test_api.sh $BASE_URL" >&2
        exit 1
    fi
    [[ -f "$DB_PATH" ]] || { echo "ERROR: No database at $DB_PATH" >&2; exit 1; }
    # Clean up any stale test data from previous runs
    php -r "
        \$db = new PDO('sqlite:$DB_PATH');
        \$db->exec(\"DELETE FROM api_keys WHERE name = 'test-runner'\");
        \$db->exec(\"DELETE FROM addresses WHERE hostname LIKE 'api-test-%'\");
        \$db->exec(\"DELETE FROM subnets WHERE description LIKE 'API test%' OR description LIKE 'overlap%' OR description = 'duplicate'\");
        \$db->exec(\"DELETE FROM sites WHERE name LIKE 'API-Test-Site-%'\");
    " 2>/dev/null || true

    API_KEY="test-key-$(date +%s)-$$"
    php -r "
        \$db = new PDO('sqlite:$DB_PATH');
        \$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        \$h = hash('sha256', '$API_KEY');
        \$db->prepare(\"INSERT INTO api_keys (name, key_hash, is_active, created_by) VALUES ('test-runner', :h, 1, 'test_api.sh')\")->execute([':h' => \$h]);
    "
    log "Created temp API key"
fi

# ---- Helpers ----

HTTP_CODE=""; BODY=""

# Auth mode: header (default) or query param (for proxies that strip Authorization)
AUTH_MODE="${AUTH_MODE:-header}"

# Optional HTTP Basic Auth for servers behind a gateway (e.g. BASIC_AUTH=user:pass)
BASIC_AUTH="${BASIC_AUTH:-}"

# call METHOD URL [JSON_BODY]
call() {
    local method="$1" url="$2" body="${3:-}"
    local tmp; tmp=$(mktemp)
    local args=(-s --noproxy '*' -o "$tmp" -w '%{http_code}' -X "$method"
                -H "Content-Type: application/json")
    [[ -n "$BASIC_AUTH" ]] && args+=(-u "$BASIC_AUTH")
    if [[ "$AUTH_MODE" == "query" ]]; then
        # Append api_key as query parameter (for proxies that strip Authorization header)
        [[ "$url" == *"?"* ]] && url="${url}&api_key=$API_KEY" || url="${url}?api_key=$API_KEY"
    else
        args+=(-H "Authorization: Bearer $API_KEY")
    fi
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

_ba_args=(); [[ -n "$BASIC_AUTH" ]] && _ba_args=(-u "$BASIC_AUTH")
HTTP_CODE=$(curl -s --noproxy '*' "${_ba_args[@]}" -o /dev/null -w '%{http_code}' "${API}?resource=subnets")
[[ "$HTTP_CODE" == "401" ]] && pass "No auth → 401" || fail "No auth → expected 401, got $HTTP_CODE"

HTTP_CODE=$(curl -s --noproxy '*' "${_ba_args[@]}" -o /dev/null -w '%{http_code}' -H "Authorization: Bearer bad-key" "${API}?resource=subnets")
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
log "=== Addresses CRUD ==="
# ====================================================================

if [[ -n "${SUBNET_ID:-}" && "$SUBNET_ID" != "None" ]]; then
    call_api POST addresses "{\"subnet_id\":$SUBNET_ID,\"ip\":\"$TEST_IP\",\"hostname\":\"api-test-host\",\"owner\":\"tester\",\"status\":\"used\"}"
    assert_http 201 "Create address"
    ADDR_ID=$(jq_val id)
    [[ -n "$ADDR_ID" && "$ADDR_ID" != "None" && "$ADDR_ID" != "" ]] && pass "Address ID: $ADDR_ID" || fail "No address ID returned"

    call_api POST addresses "{\"subnet_id\":$SUBNET_ID,\"ip\":\"$TEST_IP\",\"hostname\":\"dup\",\"status\":\"used\"}"
    assert_http 409 "Duplicate address → 409"

    call_api GET "addresses&subnet_id=$SUBNET_ID"
    assert_http 200 "List addresses by subnet"
    ADDR_COUNT=$(jq_len addresses)
    [[ "$ADDR_COUNT" -gt 0 ]] && pass "Addresses: $ADDR_COUNT in subnet" || fail "Address list empty"

    if [[ -n "${ADDR_ID:-}" && "$ADDR_ID" != "None" ]]; then
        call_api PUT "addresses&id=$ADDR_ID" '{"hostname":"api-test-updated","status":"reserved"}'
        assert_http 200 "Update address"
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
log "=== Deprecation Warning ==="
# ====================================================================

DEP_HEADER=$(curl -s --noproxy '*' "${_ba_args[@]}" -D - -o /dev/null "${API}?resource=subnets&api_key=$API_KEY" 2>/dev/null | grep -i 'deprecation' || echo "")
[[ -n "$DEP_HEADER" ]] && pass "Query param API key sends Deprecation header" || skip "Deprecation header not found (may need header-only auth)"

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

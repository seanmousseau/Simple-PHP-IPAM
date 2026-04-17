#!/usr/bin/env bash
set -euo pipefail

#
# test_migrate_db.sh — integration test for migrate_db.php
#
# Tests SQLite → SQLite round-trip by default. Pass engine names to test
# other direction pairs (requires MySQL/Postgres containers in CI).
#
# Usage:
#   ./test_migrate_db.sh                          # sqlite round-trip only
#   ./test_migrate_db.sh sqlite mysql              # sqlite→mysql→sqlite
#   ./test_migrate_db.sh all                       # all 6 direction pairs
#
# Environment variables for MySQL/Postgres:
#   MYSQL_DSN, MYSQL_USER, MYSQL_PASS
#   PGSQL_DSN, PGSQL_USER, PGSQL_PASS

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
APP_DIR="$REPO_ROOT/Simple-PHP-IPAM"
FIXTURE="$REPO_ROOT/testing/samples/large-db-sample/ipam-large-test.sqlite"
DIFF_TOOL="$REPO_ROOT/tests/tools/db_diff.php"
MIGRATE="$APP_DIR/migrate_db.php"

PASS=0; FAIL=0; SKIP=0

pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
skip() { SKIP=$((SKIP+1)); echo "  SKIP: $1"; }

cleanup_files=()
cleanup() {
    for f in "${cleanup_files[@]}"; do
        rm -f "$f" 2>/dev/null || true
    done
}
trap cleanup EXIT

test_pair() {
    local from_driver="$1" to_driver="$2"
    echo ""
    echo "=== ${from_driver} → ${to_driver} ==="

    local src_dsn="" src_user="" src_pass=""
    local dst_dsn="" dst_user="" dst_pass=""

    if [[ "$from_driver" == "sqlite" ]]; then
        local src_copy; src_copy=$(mktemp /tmp/ipam-src-XXXXXX.sqlite)
        cleanup_files+=("$src_copy")
        cp "$FIXTURE" "$src_copy"
        src_dsn="sqlite:$src_copy"
    elif [[ "$from_driver" == "mysql" ]]; then
        src_dsn="${MYSQL_DSN:-}"; src_user="${MYSQL_USER:-}"; src_pass="${MYSQL_PASS:-}"
        [[ -z "$src_dsn" ]] && { skip "${from_driver}→${to_driver}: MYSQL_DSN not set"; return; }
    elif [[ "$from_driver" == "pgsql" ]]; then
        src_dsn="${PGSQL_DSN:-}"; src_user="${PGSQL_USER:-}"; src_pass="${PGSQL_PASS:-}"
        [[ -z "$src_dsn" ]] && { skip "${from_driver}→${to_driver}: PGSQL_DSN not set"; return; }
    fi

    if [[ "$to_driver" == "sqlite" ]]; then
        local dst_file; dst_file=$(mktemp /tmp/ipam-dst-XXXXXX.sqlite)
        cleanup_files+=("$dst_file")
        dst_dsn="sqlite:$dst_file"
        # Provision empty schema
        php "$APP_DIR/migrate.php" 2>/dev/null || true
        sqlite3 "$dst_file" < "$APP_DIR/schema.sql"
    elif [[ "$to_driver" == "mysql" ]]; then
        dst_dsn="${MYSQL_DSN:-}"; dst_user="${MYSQL_USER:-}"; dst_pass="${MYSQL_PASS:-}"
        [[ -z "$dst_dsn" ]] && { skip "${from_driver}→${to_driver}: MYSQL_DSN not set"; return; }
    elif [[ "$to_driver" == "pgsql" ]]; then
        dst_dsn="${PGSQL_DSN:-}"; dst_user="${PGSQL_USER:-}"; dst_pass="${PGSQL_PASS:-}"
        [[ -z "$dst_dsn" ]] && { skip "${from_driver}→${to_driver}: PGSQL_DSN not set"; return; }
    fi

    local args=(php "$MIGRATE"
        "--from=$from_driver" "--from-dsn=$src_dsn"
        "--to=$to_driver" "--to-dsn=$dst_dsn"
        "--force" "--batch-size=500")
    [[ -n "$src_user" ]] && args+=("--from-user=$src_user")
    [[ -n "$src_pass" ]] && args+=("--from-pass=$src_pass")
    [[ -n "$dst_user" ]] && args+=("--to-user=$dst_user")
    [[ -n "$dst_pass" ]] && args+=("--to-pass=$dst_pass")

    if ! "${args[@]}" > /tmp/migrate_out.txt 2>&1; then
        fail "${from_driver}→${to_driver}: migrate_db.php failed"
        tail -5 /tmp/migrate_out.txt
        return
    fi

    if grep -q "MISMATCH" /tmp/migrate_out.txt; then
        fail "${from_driver}→${to_driver}: row count mismatch in migrate output"
        grep "MISMATCH" /tmp/migrate_out.txt
        return
    fi

    pass "${from_driver}→${to_driver}: migration succeeded"

    # Diff source vs target
    local diff_args=(php "$DIFF_TOOL" "$src_dsn")
    [[ -n "$src_user" ]] && diff_args+=("$src_user" "$src_pass")
    diff_args+=("$dst_dsn")
    [[ -n "$dst_user" ]] && diff_args+=("$dst_user" "$dst_pass")

    if "${diff_args[@]}" > /tmp/diff_out.txt 2>&1; then
        pass "${from_driver}→${to_driver}: db_diff verified"
    else
        fail "${from_driver}→${to_driver}: db_diff found differences"
        grep "DIFF" /tmp/diff_out.txt
    fi
}

echo "migrate_db.php integration test"
echo "Fixture: $FIXTURE"

pairs=()
if [[ "${1:-sqlite}" == "all" ]]; then
    pairs=("sqlite:mysql" "sqlite:pgsql" "mysql:sqlite" "mysql:pgsql" "pgsql:sqlite" "pgsql:mysql")
elif [[ $# -eq 2 ]]; then
    pairs=("$1:$2")
else
    # Default: sqlite round-trip
    pairs=("sqlite:sqlite")
fi

for pair in "${pairs[@]}"; do
    IFS=: read -r from to <<< "$pair"
    test_pair "$from" "$to"
done

echo ""
echo "Results: ${PASS} pass, ${FAIL} fail, ${SKIP} skip"
exit $([[ $FAIL -eq 0 ]] && echo 0 || echo 1)

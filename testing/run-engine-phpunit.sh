#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# testing/run-engine-phpunit.sh
#
# Run `vendor/bin/phpunit` against MySQL and PostgreSQL — the engines the
# plain `vendor/bin/phpunit` static-gate step does NOT cover (it runs against
# SQLite only). This is what catches engine-specific phpunit failures BEFORE
# they show up on a PR's CI — e.g. the `schema_migrations` pre-seed count
# asserted by `MysqlSmokeTest` / `PgsqlSmokeTest::testSchemaMigrationsPreseeded`,
# which a SQLite-only run can't see (SQLite has no schema-file pre-seed).
#
# It spins up throwaway `mysql:8.0` + `postgres:14-alpine` containers (the same
# images `.github/workflows/php-qa.yml` uses), publishes each on a localhost
# port, points phpunit at it via `IPAM_MYSQL_DSN` / `IPAM_PGSQL_DSN`, runs the
# suite, and tears the container down. The smoke tests drop+recreate their own
# database (`ipam_localqa`), so the containers carry no important state.
#
# Requires: Docker, and the host PHP built with `pdo_mysql` / `pdo_pgsql`.
# If a driver extension is missing the corresponding engine is skipped with a
# loud warning (you're then trusting CI for that engine) — it is NOT a hard
# failure, because not every dev box has both drivers. A check that actually
# RAN and FAILED is a hard failure.
#
# Usage: bash testing/run-engine-phpunit.sh
# Part of the Local gate — see docs/internal/test-suites.md § "Local gate".
# ---------------------------------------------------------------------------
set -uo pipefail
cd "$(dirname "$0")/.."

if ! command -v docker >/dev/null 2>&1; then
  echo "run-engine-phpunit.sh: docker not found — cannot run the MySQL/PgSQL phpunit gate." >&2
  exit 1
fi
if [[ ! -x vendor/bin/phpunit ]]; then
  echo "run-engine-phpunit.sh: vendor/bin/phpunit missing — run 'composer install' first." >&2
  exit 1
fi

ran_any=0
fail=0

cleanup() {
  docker rm -f ipam-localqa-mysql ipam-localqa-pgsql >/dev/null 2>&1 || true
}
trap cleanup EXIT

# --- MySQL -----------------------------------------------------------------
if php --ri pdo_mysql >/dev/null 2>&1; then
  echo "== spinning up mysql:8.0 (ipam-localqa-mysql, 127.0.0.1:13306) =="
  docker rm -f ipam-localqa-mysql >/dev/null 2>&1 || true
  docker run -d --rm --name ipam-localqa-mysql \
    -p 127.0.0.1:13306:3306 \
    -e MYSQL_ROOT_PASSWORD=rootpw -e MYSQL_DATABASE=ipam_localqa \
    mysql:8.0 >/dev/null
  echo -n "   waiting for mysql"
  for _ in $(seq 1 40); do
    if docker exec ipam-localqa-mysql mysqladmin ping -h127.0.0.1 -uroot -prootpw --silent >/dev/null 2>&1; then
      echo " — ready"; break
    fi
    echo -n "."; sleep 2
  done
  echo "== phpunit (MySQL) =="
  ran_any=1
  if ! IPAM_MYSQL_DSN='mysql:host=127.0.0.1;port=13306;dbname=ipam_localqa;charset=utf8mb4' \
       IPAM_MYSQL_USER=root IPAM_MYSQL_PASS=rootpw \
       vendor/bin/phpunit; then
    echo "!! phpunit FAILED against MySQL" >&2
    fail=1
  fi
  docker rm -f ipam-localqa-mysql >/dev/null 2>&1 || true
else
  echo "!! pdo_mysql not loaded in this PHP — SKIPPING the MySQL phpunit gate."
  echo "!! CI WILL still run it. Install pdo_mysql (or trust CI) — do not treat green here as MySQL coverage."
fi

# --- PostgreSQL ------------------------------------------------------------
if php --ri pdo_pgsql >/dev/null 2>&1; then
  echo "== spinning up postgres:14-alpine (ipam-localqa-pgsql, 127.0.0.1:15432) =="
  docker rm -f ipam-localqa-pgsql >/dev/null 2>&1 || true
  docker run -d --rm --name ipam-localqa-pgsql \
    -p 127.0.0.1:15432:5432 \
    -e POSTGRES_PASSWORD=rootpw -e POSTGRES_USER=postgres -e POSTGRES_DB=ipam_localqa \
    postgres:14-alpine >/dev/null
  echo -n "   waiting for postgres"
  for _ in $(seq 1 40); do
    if docker exec ipam-localqa-pgsql pg_isready -U postgres -d ipam_localqa >/dev/null 2>&1; then
      echo " — ready"; break
    fi
    echo -n "."; sleep 2
  done
  echo "== phpunit (PostgreSQL) =="
  ran_any=1
  if ! IPAM_PGSQL_DSN='pgsql:host=127.0.0.1;port=15432;dbname=ipam_localqa' \
       IPAM_PGSQL_USER=postgres IPAM_PGSQL_PASS=rootpw \
       vendor/bin/phpunit; then
    echo "!! phpunit FAILED against PostgreSQL" >&2
    fail=1
  fi
  docker rm -f ipam-localqa-pgsql >/dev/null 2>&1 || true
else
  echo "!! pdo_pgsql not loaded in this PHP — SKIPPING the PostgreSQL phpunit gate."
  echo "!! CI WILL still run it. Install pdo_pgsql (or trust CI) — do not treat green here as PostgreSQL coverage."
fi

# --- Verdict ---------------------------------------------------------------
echo
if [[ $fail -ne 0 ]]; then
  echo "run-engine-phpunit.sh: FAILED — at least one engine's phpunit run failed. Fix before opening a PR." >&2
  exit 1
fi
if [[ $ran_any -eq 0 ]]; then
  echo "run-engine-phpunit.sh: WARNING — neither pdo_mysql nor pdo_pgsql is available; nothing was actually checked." >&2
  echo "run-engine-phpunit.sh: this is a dev-environment limitation, not a pass. CI will still gate MySQL/PgSQL." >&2
  exit 0
fi
echo "run-engine-phpunit.sh: OK — phpunit green on the engine(s) available locally."

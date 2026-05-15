#!/usr/bin/env bash
# v3.29.0 #1106 — Run PHPUnit against a running ipam-pw-{mysql,pgsql}
# container without needing to remember DSN env vars.
#
# Usage:
#   testing/scripts/phpunit-against-driver.sh mysql
#   testing/scripts/phpunit-against-driver.sh pgsql
#   testing/scripts/phpunit-against-driver.sh mysql tests/MysqlSmokeTest.php
set -euo pipefail

driver="${1:-}"; shift || true
case "$driver" in
  mysql)  container=ipam-pw-mysql;  dsn='mysql:host=127.0.0.1;port=3306;dbname=ipam_pw;charset=utf8mb4';;
  pgsql)  container=ipam-pw-pgsql;  dsn='pgsql:host=127.0.0.1;port=5432;dbname=ipam_pw';;
  *) echo "usage: $0 {mysql|pgsql} [phpunit args...]" >&2; exit 2;;
esac

if ! docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
  echo "container $container not running. Run: bash testing/bootstrap-app.sh $driver" >&2
  exit 1
fi

case "$driver" in
  mysql)
    IPAM_MYSQL_DSN="$dsn" IPAM_MYSQL_USER=ipam IPAM_MYSQL_PASS=ipam_password \
      vendor/bin/phpunit "$@"
    ;;
  pgsql)
    IPAM_PGSQL_DSN="$dsn" IPAM_PGSQL_USER=ipam IPAM_PGSQL_PASS=ipam_password \
      vendor/bin/phpunit "$@"
    ;;
esac

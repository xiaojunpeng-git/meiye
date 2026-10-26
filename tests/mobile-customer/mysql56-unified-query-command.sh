#!/usr/bin/env bash
set -euo pipefail

TEST_ROOT="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$TEST_ROOT/../.." && pwd)"
UPGRADE="$REPO/后端代码/database/upgrades/2026-07-30-手机端客户统一查询命令"
MYSQL_IMAGE="docker.m.daocloud.io/library/mysql:5.6.51"
CONTAINER="mobile-customer-uq-my56-$$"

cleanup() { docker rm -f "$CONTAINER" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker run --rm -d --name "$CONTAINER" -e MYSQL_ROOT_PASSWORD=local-mobile-customer-uq-test "$MYSQL_IMAGE" >/dev/null
for attempt in $(seq 1 60); do
  if docker exec "$CONTAINER" mysql -uroot -plocal-mobile-customer-uq-test -e 'SELECT 1' >/dev/null 2>&1; then break; fi
  if [ "$attempt" = 60 ]; then echo 'FAIL MYSQL56_NOT_READY' >&2; exit 1; fi
  sleep 1
done
mysql() { docker exec -i "$CONTAINER" mysql -uroot -plocal-mobile-customer-uq-test "$@" mobile_customer_uq_test; }
docker exec -i "$CONTAINER" mysql -uroot -plocal-mobile-customer-uq-test <<'SQL'
CREATE DATABASE mobile_customer_uq_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE mobile_customer_uq_test;
CREATE TABLE eb_unified_query_preference (id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB;
CREATE TABLE eb_unified_query_export_task (id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB;
SQL
mysql < "$UPGRADE/01-升级前检查.sql" > "$TEST_ROOT/.mysql56-mobile-customer-uq-precheck.out"
grep -q $'precheck_ok\n1' "$TEST_ROOT/.mysql56-mobile-customer-uq-precheck.out"
mysql < "$UPGRADE/02-正式升级.sql" > "$TEST_ROOT/.mysql56-mobile-customer-uq-apply.out"
mysql < "$UPGRADE/03-升级后验证.sql" > "$TEST_ROOT/.mysql56-mobile-customer-uq-postcheck.out"
grep -q $'table_count\tcolumn_count\tindex_count\tbad_row_count\tverification_failure_count' "$TEST_ROOT/.mysql56-mobile-customer-uq-postcheck.out"
grep -q $'1\t13\t3\t0\t0' "$TEST_ROOT/.mysql56-mobile-customer-uq-postcheck.out"
echo 'MYSQL56_MOBILE_CUSTOMER_UNIFIED_QUERY_COMMAND=PASS'

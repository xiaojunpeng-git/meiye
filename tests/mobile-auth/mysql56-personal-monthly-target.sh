#!/usr/bin/env bash
set -euo pipefail

TEST_ROOT="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$TEST_ROOT/../.." && pwd)"
UPGRADE="$REPO/后端代码/database/upgrades/2026-07-30-手机端个人月度目标V3"
MYSQL_IMAGE="docker.m.daocloud.io/library/mysql:5.6.51"
CONTAINER="mobile-personal-target-my56-$$"

cleanup() { docker rm -f "$CONTAINER" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker run --rm -d --name "$CONTAINER" -e MYSQL_ROOT_PASSWORD=local-mobile-target-test "$MYSQL_IMAGE" >/dev/null
for attempt in $(seq 1 60); do
  if docker exec "$CONTAINER" mysql -uroot -plocal-mobile-target-test -e 'SELECT 1' >/dev/null 2>&1; then break; fi
  if [ "$attempt" = 60 ]; then echo 'FAIL MYSQL56_NOT_READY' >&2; exit 1; fi
  sleep 1
done
mysql() { docker exec -i "$CONTAINER" mysql -uroot -plocal-mobile-target-test "$@" mobile_personal_target_test; }
docker exec -i "$CONTAINER" mysql -uroot -plocal-mobile-target-test <<'SQL'
CREATE DATABASE mobile_personal_target_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mobile_personal_target_test;
CREATE TABLE eb_database_upgrade_log (upgrade_key varchar(128) NOT NULL, PRIMARY KEY (upgrade_key)) ENGINE=InnoDB;
SQL
mysql < "$UPGRADE/01-升级前检查.sql" > "$TEST_ROOT/.mysql56-target-precheck.out"
grep -q $'target_table_conflicts\n0' "$TEST_ROOT/.mysql56-target-precheck.out"
mysql < "$UPGRADE/02-正式升级.sql" > "$TEST_ROOT/.mysql56-target-apply.out"
mysql < "$UPGRADE/03-升级后验证.sql" > "$TEST_ROOT/.mysql56-target-postcheck.out"
grep -q $'target_table_count\n4' "$TEST_ROOT/.mysql56-target-postcheck.out"
grep -q $'upgrade_logged\n1' "$TEST_ROOT/.mysql56-target-postcheck.out"
indexes="$(mysql -N -e "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='eb_mobile_personal_monthly_target' AND index_name='uk_employee_staff_store_month';")"
if [ "$indexes" != '4' ]; then echo "FAIL TARGET_UNIQUE_INDEX_COLUMNS=${indexes}" >&2; exit 1; fi
echo 'MYSQL56_PERSONAL_MONTHLY_TARGET=PASS'

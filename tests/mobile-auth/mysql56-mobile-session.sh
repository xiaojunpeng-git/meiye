#!/usr/bin/env bash
set -euo pipefail

TEST_ROOT="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$TEST_ROOT/../.." && pwd)"
CANONICAL="$REPO/后端代码/database/upgrades/2026-07-29-手机认证Canonical手机号身份"
UPGRADE="$REPO/后端代码/database/upgrades/2026-07-30-手机认证与商家会话"
MYSQL_IMAGE="docker.m.daocloud.io/library/mysql:5.6.51"
CONTAINER="mobile-auth-session-my56-$$"

cleanup() { docker rm -f "$CONTAINER" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker run --rm -d --name "$CONTAINER" -e MYSQL_ROOT_PASSWORD=local-mobile-auth-test "$MYSQL_IMAGE" >/dev/null
for attempt in $(seq 1 60); do
  if docker exec "$CONTAINER" mysql -uroot -plocal-mobile-auth-test -e 'SELECT 1' >/dev/null 2>&1; then break; fi
  if [ "$attempt" = 60 ]; then echo 'FAIL MYSQL56_NOT_READY' >&2; exit 1; fi
  sleep 1
done
mysql() { docker exec -i "$CONTAINER" mysql -uroot -plocal-mobile-auth-test "$@" mobile_auth_session_test; }
docker exec -i "$CONTAINER" mysql -uroot -plocal-mobile-auth-test <<'SQL'
CREATE DATABASE mobile_auth_session_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mobile_auth_session_test;
CREATE TABLE eb_database_upgrade_log (upgrade_key varchar(128) NOT NULL, PRIMARY KEY (upgrade_key)) ENGINE=InnoDB;
CREATE TABLE eb_user (uid bigint unsigned NOT NULL, phone varchar(32) NOT NULL DEFAULT '', is_del tinyint unsigned NOT NULL DEFAULT 0, PRIMARY KEY (uid)) ENGINE=InnoDB;
CREATE TABLE eb_employee (id bigint unsigned NOT NULL, phone varchar(32) NOT NULL DEFAULT '', status tinyint unsigned NOT NULL DEFAULT 1, is_del tinyint unsigned NOT NULL DEFAULT 0, auth_version bigint unsigned NOT NULL DEFAULT 1, PRIMARY KEY (id)) ENGINE=InnoDB;
INSERT INTO eb_database_upgrade_log (upgrade_key) VALUES
('20260719-009-employee-org-leader'),('20260722-027-employee-org-staff-i1'),('20260722-028-employee-auth-center-i2'),('20260722-029-employee-auth-i2-schema-strict'),('20260722-031-job-position-data-scope-i2'),('20260723-033-org-auth-version-merchant'),('20260723-035-hq-store-role-template'),('20260724-001-org-identity-final'),('20260727-001-cashier-v3-command-idem'),('20260728-003-cashier-v3-event-outbox'),('20260728-002-cashier-v3-member-consistency');
INSERT INTO eb_user (uid,phone,is_del) VALUES (1,'13800138000',0);
INSERT INTO eb_employee (id,phone,status,is_del,auth_version) VALUES (101,'13900139000',1,0,3);
SQL
mysql < "$CANONICAL/01-升级前检查.sql" > "$TEST_ROOT/.mysql56-session-canonical-precheck.out"
grep -q PRECHECK_OK "$TEST_ROOT/.mysql56-session-canonical-precheck.out"
mysql < "$CANONICAL/02-正式升级.sql" > "$TEST_ROOT/.mysql56-session-canonical-apply.out"
# The prior migration is marked by the release runner after its post-check. This
# isolated session test reproduces that already-completed prerequisite state.
docker exec -i "$CONTAINER" mysql -uroot -plocal-mobile-auth-test mobile_auth_session_test -e "INSERT INTO eb_database_upgrade_log (upgrade_key) VALUES ('20260729-013-mobile-auth-canonical-identity');"
mysql < "$UPGRADE/01-升级前检查.sql" > "$TEST_ROOT/.mysql56-session-precheck.out"
grep -q PRECHECK_OK "$TEST_ROOT/.mysql56-session-precheck.out"
mysql < "$UPGRADE/02-正式升级.sql" > "$TEST_ROOT/.mysql56-session-apply.out"
mysql < "$UPGRADE/03-升级后验证.sql" > "$TEST_ROOT/.mysql56-session-postcheck.out"
grep -q POSTCHECK_OK "$TEST_ROOT/.mysql56-session-postcheck.out"
state="$(mysql -N -e "SELECT CONCAT(phone_binding_version,':',auth_version,':',merchant_session_epoch) FROM eb_employee_mobile_auth_state WHERE employee_id=101;")"
binding="$(mysql -N -e "SELECT CONCAT(employee_id,':',state) FROM eb_employee_phone_binding WHERE phone_digest=SHA2('13900139000',256);")"
if [ "$state" != '1:3:1' ] || [ "$binding" != '101:BOUND' ]; then
  echo "FAIL MOBILE_SESSION_SEED state=${state} binding=${binding}" >&2
  exit 1
fi
echo 'MYSQL56_MOBILE_SESSION=PASS'

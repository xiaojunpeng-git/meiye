#!/usr/bin/env bash
set -euo pipefail

TEST_ROOT="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$TEST_ROOT/../.." && pwd)"
UPGRADE="$REPO/后端代码/database/upgrades/2026-07-29-手机认证Canonical手机号身份"
MYSQL_IMAGE="docker.m.daocloud.io/library/mysql:5.6.51"
CONTAINER="mobile-auth-canonical-my56-$$"

cleanup() {
  docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker run --rm -d --name "$CONTAINER" \
  -e MYSQL_ROOT_PASSWORD=local-mobile-auth-test \
  "$MYSQL_IMAGE" >/dev/null

for attempt in $(seq 1 60); do
  if docker exec "$CONTAINER" mysql -uroot -plocal-mobile-auth-test -e 'SELECT 1' >/dev/null 2>&1; then
    break
  fi
  if [ "$attempt" = 60 ]; then
    echo 'FAIL MYSQL56_NOT_READY' >&2
    exit 1
  fi
  sleep 1
done

mysql() {
  docker exec -i "$CONTAINER" mysql -uroot -plocal-mobile-auth-test "$@" mobile_auth_test
}

docker exec -i "$CONTAINER" mysql -uroot -plocal-mobile-auth-test <<'SQL'
CREATE DATABASE mobile_auth_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mobile_auth_test;
CREATE TABLE eb_database_upgrade_log (upgrade_key varchar(128) NOT NULL, PRIMARY KEY (upgrade_key)) ENGINE=InnoDB;
CREATE TABLE eb_user (
  uid bigint(20) unsigned NOT NULL,
  phone varchar(32) NOT NULL DEFAULT '',
  is_del tinyint(3) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (uid)
) ENGINE=InnoDB;
INSERT INTO eb_database_upgrade_log (upgrade_key) VALUES
('20260719-009-employee-org-leader'),
('20260722-027-employee-org-staff-i1'),
('20260722-028-employee-auth-center-i2'),
('20260722-029-employee-auth-i2-schema-strict'),
('20260722-031-job-position-data-scope-i2'),
('20260723-033-org-auth-version-merchant'),
('20260723-035-hq-store-role-template'),
('20260724-001-org-identity-final'),
('20260727-001-cashier-v3-command-idem'),
('20260728-003-cashier-v3-event-outbox'),
('20260728-002-cashier-v3-member-consistency');
INSERT INTO eb_user (uid,phone,is_del) VALUES
(1,'13800138000',0),
(2,'13900139000',1),
(3,'13700137000',0),
(4,'13700137000',1),
(5,'not-a-mobile',0),
(6,'',0);
SQL

mysql < "$UPGRADE/01-升级前检查.sql" > "$TEST_ROOT/.mysql56-precheck.out"
grep -q 'PRECHECK_OK' "$TEST_ROOT/.mysql56-precheck.out"
mysql < "$UPGRADE/02-正式升级.sql" > "$TEST_ROOT/.mysql56-apply.out"
grep -q 'APPLY_OK' "$TEST_ROOT/.mysql56-apply.out"
mysql -e "SELECT id,state,IFNULL(bound_uid,0) AS bound_uid,identity_version,HEX(phone_digest) AS phone_digest_hex FROM eb_user_phone_identity ORDER BY id;" > "$TEST_ROOT/.mysql56-identities.out"
mysql < "$UPGRADE/03-升级后验证.sql" > "$TEST_ROOT/.mysql56-postcheck.out"
grep -q 'POSTCHECK_OK' "$TEST_ROOT/.mysql56-postcheck.out"

identity_rows="$(mysql -N -e "SELECT GROUP_CONCAT(CONCAT(state,':',IFNULL(bound_uid,0)) ORDER BY FIELD(state,'BOUND','LEGACY_CONFLICT','RELEASED') SEPARATOR ',') FROM eb_user_phone_identity;")"
if [ "$identity_rows" != 'BOUND:1,LEGACY_CONFLICT:0,RELEASED:0' ]; then
  echo "FAIL IDENTITY_ROWS ${identity_rows}" >&2
  exit 1
fi

user_rows="$(mysql -N -e "SELECT GROUP_CONCAT(CONCAT(uid,':',phone,':',is_del) ORDER BY uid SEPARATOR ',') FROM eb_user;")"
if [ "$user_rows" != '1:13800138000:0,2:13900139000:1,3:13700137000:0,4:13700137000:1,5:not-a-mobile:0,6::0' ]; then
  echo "FAIL USER_ROWS_CHANGED ${user_rows}" >&2
  exit 1
fi

echo 'MYSQL56_CANONICAL_IDENTITY=PASS'

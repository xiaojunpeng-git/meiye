#!/usr/bin/env bash
# Isolated MySQL 5.6 proof for repeat bookkeeping-method checkout drafts.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
UPGRADE_DIR="$ROOT_DIR/后端代码/database/upgrades/2026-08-04-收银V3同方式多笔收款"
FIXTURE="$ROOT_DIR/tests/cashier-v3/sql/repeat-payment-method-migration-fixture.sql"
MYSQL_IMAGE="${REPEAT_PAYMENT_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
CONTAINER="cashier-v3-repeat-payment-migration-$$"
DB="cashier_v3_repeat_payment_test"

cleanup() {
  docker rm -fv "$CONTAINER" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

mysql_file() {
  docker exec -i "$CONTAINER" mysql -uroot --database="$DB" < "$1"
}

docker run -d --name "$CONTAINER" --platform linux/amd64 \
  --cpus 1 --memory 1g -e MYSQL_ALLOW_EMPTY_PASSWORD=yes "$MYSQL_IMAGE" \
  --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci \
  --sql-mode=STRICT_ALL_TABLES >/dev/null

for _ in $(seq 1 90); do
  docker exec "$CONTAINER" mysqladmin ping -uroot --silent >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$CONTAINER" mysqladmin ping -uroot --silent >/dev/null
test "$(docker exec "$CONTAINER" mysql -uroot -Nse 'SELECT VERSION()')" = '5.6.51'

docker exec -i "$CONTAINER" mysql -uroot < "$FIXTURE"

set +e
docker exec "$CONTAINER" mysql -uroot --database="$DB" -e "
  INSERT INTO eb_cashier_v3_checkout_payment_draft
    (payment_draft_id,request_id,payment_authority_key,payment_method)
  VALUES ('CKP-1','CKR-1','payment:alipay:1','alipay'),
         ('CKP-2','CKR-1','payment:alipay:2','alipay');" >/dev/null 2>&1
before_rc=$?
set -e
test "$before_rc" -ne 0
docker exec "$CONTAINER" mysql -uroot --database="$DB" -e 'DELETE FROM eb_cashier_v3_checkout_payment_draft;'

mysql_file "$UPGRADE_DIR/01-升级前检查.sql" | grep -q PRECHECK_OK
mysql_file "$UPGRADE_DIR/02-正式升级.sql" | grep -q APPLY_OK
mysql_file "$UPGRADE_DIR/03-升级后验证.sql" | grep -q POSTCHECK_OK

docker exec "$CONTAINER" mysql -uroot --database="$DB" -e "
  INSERT INTO eb_cashier_v3_checkout_payment_draft
    (payment_draft_id,request_id,payment_authority_key,payment_method)
  VALUES ('CKP-1','CKR-1','payment:alipay:1','alipay'),
         ('CKP-2','CKR-1','payment:alipay:2','alipay');"

set +e
docker exec "$CONTAINER" mysql -uroot --database="$DB" -e "
  INSERT INTO eb_cashier_v3_checkout_payment_draft
    (payment_draft_id,request_id,payment_authority_key,payment_method)
  VALUES ('CKP-3','CKR-1','payment:alipay:2','alipay');" >/dev/null 2>&1
duplicate_authority_rc=$?
set -e
test "$duplicate_authority_rc" -ne 0

test "$(docker exec "$CONTAINER" mysql -uroot --database="$DB" -Nse \
  "SELECT COUNT(*) FROM eb_cashier_v3_checkout_payment_draft WHERE request_id='CKR-1' AND payment_method='alipay';")" = '2'

echo 'REPEAT_PAYMENT_METHOD_MIGRATION_MYSQL56=PASS'

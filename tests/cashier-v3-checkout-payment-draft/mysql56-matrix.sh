#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_DIR="$ROOT_DIR/tests/cashier-v3-checkout-payment-draft"
CHECKOUT_MIGRATION="$ROOT_DIR/后端代码/database/upgrades/2026-07-29-收银V3结账请求与收款明细"
SOURCE_MIGRATION="$ROOT_DIR/后端代码/database/upgrades/2026-07-29-收银V3结账请求来源权威"
SKU_MIGRATION="$ROOT_DIR/后端代码/database/upgrades/2026-07-30-收银V3销售SKU冻结链路"
SERVICE_TAG_MIGRATION="$ROOT_DIR/后端代码/database/upgrades/2026-08-03-收银V3结账草稿服务标签快照"
REPEAT_PAYMENT_MIGRATION="$ROOT_DIR/后端代码/database/upgrades/2026-08-04-收银V3同方式多笔收款"
MYSQL_IMAGE="${PAYMENT_DRAFT_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
PHP_IMAGE="${PAYMENT_DRAFT_PHP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"
NETWORK="payment-draft-net-$$"
MYSQL_CONTAINER="payment-draft-mysql56-$$"
PHP_CONTAINER="payment-draft-php74-$$"
TMP_ROOT="$TEST_DIR/_tmp"
mkdir -p "$TMP_ROOT"
WORK_DIR="$(mktemp -d "$TMP_ROOT/mysql56.XXXXXX")"
TEMP_ENV="$WORK_DIR/payment-draft.env"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$MYSQL_CONTAINER" >&2 || true
  fi
  docker rm -fv "$PHP_CONTAINER" "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  rm -rf "$WORK_DIR"
  rmdir "$TMP_ROOT" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

printf '%s\n' \
  'APP_DEBUG = true' \
  "HOSTNAME = $MYSQL_CONTAINER" \
  'DATABASE = payment_draft_runtime' \
  'USERNAME = root' \
  'PASSWORD = ' \
  'HOSTPORT = 3306' \
  'DRIVER = file' \
  'CACHE_DRIVER = file' > "$TEMP_ENV"

docker network create "$NETWORK" >/dev/null
docker run -d --name "$MYSQL_CONTAINER" --network "$NETWORK" --platform linux/amd64 \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$MYSQL_IMAGE" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  docker logs "$MYSQL_CONTAINER" 2>&1 | grep 'MySQL init process done' >/dev/null && break
  sleep 1
done
docker logs "$MYSQL_CONTAINER" 2>&1 | grep 'MySQL init process done' >/dev/null
for _ in $(seq 1 90); do
  docker exec "$MYSQL_CONTAINER" mysqladmin ping -uroot --silent >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$MYSQL_CONTAINER" mysqladmin ping -uroot --silent >/dev/null

MYSQL_VERSION="$(docker exec "$MYSQL_CONTAINER" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$MYSQL_VERSION" == '5.6.51' ]] || {
  echo "MYSQL_VERSION_MISMATCH=$MYSQL_VERSION" >&2
  exit 1
}
echo "MYSQL_VERSION=$MYSQL_VERSION"

mysql_server() {
  docker exec "$MYSQL_CONTAINER" mysql -uroot "$@"
}
mysql_file() {
  local db="$1" file="$2"
  docker exec -i "$MYSQL_CONTAINER" mysql -uroot --database="$db" < "$file"
}

mysql_server -e 'CREATE DATABASE `payment_draft_runtime` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;'
mysql_file payment_draft_runtime "$TEST_DIR/sql/base-schema.sql" >/dev/null
mysql_file payment_draft_runtime "$CHECKOUT_MIGRATION/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file payment_draft_runtime "$SOURCE_MIGRATION/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file payment_draft_runtime "$SKU_MIGRATION/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file payment_draft_runtime "$SERVICE_TAG_MIGRATION/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file payment_draft_runtime "$REPEAT_PAYMENT_MIGRATION/02-正式升级.sql" | grep -q 'APPLY_OK'

docker run --rm --name "$PHP_CONTAINER" --network "$NETWORK" --platform linux/amd64 \
  -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE=payment_draft_runtime \
  -e DB_USERNAME=root -e DB_PASSWORD= \
  -e HOSTNAME="$MYSQL_CONTAINER" -e HOSTPORT=3306 -e DATABASE=payment_draft_runtime \
  -e USERNAME=root -e PASSWORD= -e CACHE_DRIVER=file -e DRIVER=file \
  -e PAYMENT_DRAFT_BACKEND_ROOT=/var/www/html \
  -v "$ROOT_DIR/后端代码:/source:ro" \
  -v "$ROOT_DIR/tests:/tests:ro" \
  -v "$WORK_DIR:/test-env:ro" \
  --tmpfs /var/www/html:rw,size=384m \
  --entrypoint sh "$PHP_IMAGE" -lc '
    set -e
    tar -C /source \
      --exclude="./.env" --exclude="./.env.*" \
      --exclude="./runtime" --exclude="./public" \
      -cf - . | tar -C /var/www/html -xf -
    mkdir -p /var/www/html/runtime /var/www/html/public/uploads
    test ! -f /var/www/html/.env
    cp /test-env/payment-draft.env /var/www/html/.env
    test -s /var/www/html/.env
    grep -q "DATABASE = payment_draft_runtime" /var/www/html/.env
    php -l /var/www/html/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php
    php -l /var/www/html/app/services/cashier/v3/settlement/CashierV3CheckoutPaymentDraftServices.php
    php -l /tests/cashier-v3-checkout-payment-draft/php/contract.php
    php -l /tests/cashier-v3-checkout-payment-draft/php/mysql-integration.php
    CONTRACT_OUT="$(php /tests/cashier-v3-checkout-payment-draft/php/contract.php 2>&1)"
    printf "%s\n" "$CONTRACT_OUT"
    printf "%s\n" "$CONTRACT_OUT" | grep -Eq "RESULT: [1-9][0-9]* passed, 0 failed"
    MYSQL_OUT="$(php /tests/cashier-v3-checkout-payment-draft/php/mysql-integration.php 2>&1)"
    printf "%s\n" "$MYSQL_OUT"
    printf "%s\n" "$MYSQL_OUT" | grep -Eq "PAYMENT_DRAFT_MYSQL passed=[1-9][0-9]* failed=0"
  '

mysql_file payment_draft_runtime "$REPEAT_PAYMENT_MIGRATION/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
mysql_file payment_draft_runtime "$SERVICE_TAG_MIGRATION/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'

echo 'PAYMENT_DRAFT_MYSQL56_MATRIX=PASS'

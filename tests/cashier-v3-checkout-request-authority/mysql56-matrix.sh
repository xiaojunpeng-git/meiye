#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_DIR="$ROOT_DIR/tests/cashier-v3-checkout-request-authority"
BASE_MIGRATION="$ROOT_DIR/后端代码/database/upgrades/2026-07-29-收银V3结账请求与收款明细"
SOURCE_MIGRATION="$ROOT_DIR/后端代码/database/upgrades/2026-07-29-收银V3结账请求来源权威"
MYSQL_IMAGE="${CHECKOUT_REQUEST_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
PHP_IMAGE="${CHECKOUT_REQUEST_PHP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"
NETWORK="checkout-request-authority-net-$$"
MYSQL_CONTAINER="checkout-request-authority-mysql56-$$"
PHP_CONTAINER="checkout-request-authority-php74-$$"
# Colima resolves the macOS real path, while /tmp is a host symlink. Keep
# transient coordination files on the real private temp path for bind mounts.
WORK_DIR="$(mktemp -d /private/tmp/checkout-request-authority.XXXXXX)"
COORD_DIR="$WORK_DIR/coord"
FAILURE_LOG="$WORK_DIR/expected-failure.log"
mkdir -p "$COORD_DIR"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$MYSQL_CONTAINER" >&2 || true
    [[ -f "$FAILURE_LOG" ]] && sed -n '1,240p' "$FAILURE_LOG" >&2 || true
  fi
  docker rm -fv "$PHP_CONTAINER" "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

(cd "$SOURCE_MIGRATION" && shasum -a 256 -c SHA256SUMS.txt)

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
mysql_sql() {
  local db="$1" sql="$2"
  docker exec "$MYSQL_CONTAINER" mysql -uroot --database="$db" -e "$sql"
}
init_db() {
  local db="$1"
  mysql_server -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$ROOT_DIR/后端代码/database/upgrades/0000-升级登记表初始化.sql" >/dev/null
  mysql_file "$db" "$BASE_MIGRATION/02-正式升级.sql" >/dev/null
}
expect_file_failure() {
  local db="$1" file="$2" marker="$3"
  if mysql_file "$db" "$file" >"$FAILURE_LOG" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  echo "$marker=PASS"
}

runtime_db='checkout_request_runtime'
init_db "$runtime_db"
mysql_file "$runtime_db" "$SOURCE_MIGRATION/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$runtime_db" "$SOURCE_MIGRATION/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$runtime_db" "$SOURCE_MIGRATION/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'CHECKOUT_SOURCE_MYSQL56_FRESH=PASS'
expect_file_failure "$runtime_db" "$SOURCE_MIGRATION/01-升级前检查.sql" \
  'CHECKOUT_SOURCE_EXISTING_TABLE_PRECHECK_BLOCKED'

partial_db='checkout_request_partial'
init_db "$partial_db"
mysql_file "$partial_db" "$SOURCE_MIGRATION/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" 'ALTER TABLE eb_cashier_v3_checkout_source_reference DROP INDEX uk_request_source' >/dev/null
expect_file_failure "$partial_db" "$SOURCE_MIGRATION/05-部分创表恢复.sql" \
  'CHECKOUT_SOURCE_PARTIAL_DDL_BLOCKED'

bad_scope_db='checkout_request_bad_scope'
init_db "$bad_scope_db"
mysql_file "$bad_scope_db" "$SOURCE_MIGRATION/02-正式升级.sql" >/dev/null
mysql_sql "$bad_scope_db" "
  INSERT INTO eb_cashier_v3_checkout_request
    (request_id,tenant_id,store_id,request_version,business_date)
  VALUES ('CKR-0000000000000000000000000000000000000001','0',7,1,'2026-07-29');
  INSERT INTO eb_cashier_v3_checkout_source_reference
    (request_id,tenant_id,store_id,bound_request_version,source_kind,source_id,
     source_version,source_role,source_fingerprint,add_time,update_time)
  VALUES ('CKR-0000000000000000000000000000000000000001','0',8,1,
    'room','12',1,'service_room',REPEAT('a',64),1,1);
" >/dev/null
expect_file_failure "$bad_scope_db" "$SOURCE_MIGRATION/03-升级后验证.sql" \
  'CHECKOUT_SOURCE_CROSS_STORE_POSTCHECK_BLOCKED'

docker run --rm --name "$PHP_CONTAINER" --network "$NETWORK" --platform linux/amd64 \
  -e DB_HOST="$MYSQL_CONTAINER" \
  -e DB_PORT=3306 \
  -e DB_DATABASE="$runtime_db" \
  -e DB_USERNAME=root \
  -e DB_PASSWORD= \
  -e CHECKOUT_REQUEST_CONCURRENCY_DIR=/coord \
  -e CHECKOUT_REQUEST_BACKEND_ROOT=/var/www/html \
  -e CHECKOUT_SOURCE_MIGRATION_DIR=/var/www/html/database/upgrades/2026-07-29-收银V3结账请求来源权威 \
  -v "$ROOT_DIR/后端代码:/var/www/html:ro" \
  --tmpfs /var/www/html/runtime:rw,noexec,nosuid,size=64m \
  -v "$ROOT_DIR/tests:/tests:ro" \
  -v "$COORD_DIR:/coord" \
  --entrypoint sh "$PHP_IMAGE" -lc '
    set -e
    find /var/www/html/app/services/cashier/v3/settlement \
      /tests/cashier-v3-checkout-request-authority/php \
      -type f -name "*.php" -exec php -l {} \;
    php /tests/cashier-v3-checkout-request-authority/php/source-set-contract.php
    php /tests/cashier-v3-checkout-request-authority/php/migration-contract.php
    php /tests/cashier-v3-checkout-request-authority/php/mysql-integration.php
    php /tests/cashier-v3-checkout-request-authority/php/mysql-concurrency.php
  '

echo 'CHECKOUT_REQUEST_AUTHORITY_MYSQL56_MATRIX=PASS'

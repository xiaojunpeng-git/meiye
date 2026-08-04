#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_DIR="$ROOT_DIR/tests/cashier-v3-checkout-submission-execution"
BASE_SCHEMA="$ROOT_DIR/tests/cashier-v3-sale-only-submission/sql/base-schema.sql"
UPGRADE_ROOT="$ROOT_DIR/后端代码/database/upgrades"
MYSQL_IMAGE="${MIXED_CHECKOUT_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
PHP_DOCKERFILE="$ROOT_DIR/tests/cashier-v3/docker/php74-runtime.Dockerfile"
PHP_IMAGE="${MIXED_CHECKOUT_PHP_IMAGE:-}"
PHP_PLATFORM="${MIXED_CHECKOUT_PHP_PLATFORM:-}"
NETWORK="mixed-checkout-net-$$"
MYSQL_CONTAINER="mixed-checkout-mysql56-$$"
PHP_CONTAINER="mixed-checkout-php74-$$"
TMP_BASE="${MIXED_CHECKOUT_TMP_DIR:-$(dirname "$ROOT_DIR")}"
if [[ ! -d "$TMP_BASE" ]]; then
  TMP_BASE="${TMPDIR:-/tmp}"
fi
WORK_DIR="$(mktemp -d "$TMP_BASE/mixed-checkout.XXXXXX")"
TEMP_ENV="$WORK_DIR/mixed-checkout.env"
DB_NAME="mixed_checkout_runtime"

MIGRATIONS=(
  '2026-07-27-收银V3命令与幂等底座'
  '2026-07-28-收银V3权益购物车草稿'
  '2026-07-28-收银V3统一事件与Outbox'
  '2026-07-29-收银V3销售购物车权威行'
  '2026-07-29-收银V3结账请求与收款明细'
  '2026-07-29-收银V3结账请求来源权威'
  '2026-07-29-收银V3结账资源预锁计划'
  '2026-07-29-收银V3正式销售订单权威'
  '2026-07-29-收银V3正式收款权威'
  '2026-07-29-收银V3统一结账事实底座'
  '2026-07-29-员工人员类型权威源'
  '2026-07-29-C2权益完成依赖提供者'
  '2026-07-29-C3服务单权益占用权威源'
  '2026-07-29-收银V3项目业绩规则权威'
  '2026-07-29-库存耗材批次完成合同'
  '2026-07-29-库存统一查询批次事实'
  '2026-07-29-收银V3权益完成权威写入'
)

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$MYSQL_CONTAINER" >&2 || true
  fi
  docker rm -fv "$PHP_CONTAINER" "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

if [[ -z "$PHP_PLATFORM" ]]; then
  DOCKER_ARCH="$(docker version --format '{{.Server.Arch}}')"
  case "$DOCKER_ARCH" in
    amd64|x86_64) PHP_PLATFORM='linux/amd64' ;;
    arm64|aarch64) PHP_PLATFORM='linux/arm64' ;;
    *) echo "MIXED_CHECKOUT_UNSUPPORTED_DOCKER_ARCH=$DOCKER_ARCH" >&2; exit 1 ;;
  esac
fi

if [[ -z "$PHP_IMAGE" ]]; then
  PHP_IMAGE_SHA="$(shasum -a 256 "$PHP_DOCKERFILE" | awk '{print substr($1,1,12)}')"
  PHP_IMAGE="c1a-cashier-v3-php74:${PHP_IMAGE_SHA}-${PHP_PLATFORM#linux/}"
  if ! docker image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
    docker build --platform "$PHP_PLATFORM" \
      -f "$PHP_DOCKERFILE" \
      -t "$PHP_IMAGE" "$ROOT_DIR/tests/cashier-v3/docker"
  fi
fi

docker run --rm --platform "$PHP_PLATFORM" --entrypoint php "$PHP_IMAGE" -r '
  $required = ["bcmath", "pdo_mysql"];
  $missing = [];
  foreach ($required as $extension) {
    if (!extension_loaded($extension)) {
      $missing[] = $extension;
    }
  }
  echo "PHP_VERSION=" . PHP_VERSION . "\n";
  echo "MIXED_CHECKOUT_PHP_MISSING_EXTENSIONS=" . implode(",", $missing) . "\n";
  exit($missing ? 1 : 0);
'

printf '%s\n' \
  'APP_DEBUG = true' \
  "HOSTNAME = $MYSQL_CONTAINER" \
  "DATABASE = $DB_NAME" \
  'USERNAME = root' \
  'PASSWORD = ' \
  'HOSTPORT = 3306' \
  'DRIVER = file' \
  'CACHE_DRIVER = file' \
  'CASHIER_V3_CHECKOUT_NAMESPACE_SECRET = mixed-checkout-local-secret-20260729' \
  > "$TEMP_ENV"

docker network create "$NETWORK" >/dev/null
docker run -d --name "$MYSQL_CONTAINER" --network "$NETWORK" --platform linux/amd64 \
  --cpus 1 --memory 768m \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$MYSQL_IMAGE" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --sql-mode=STRICT_ALL_TABLES \
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
  local database="$1" file="$2"
  docker exec -i "$MYSQL_CONTAINER" mysql -uroot --database="$database" < "$file"
}

mysql_server -e "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql_file "$DB_NAME" "$UPGRADE_ROOT/0000-升级登记表初始化.sql" >/dev/null
mysql_file "$DB_NAME" "$BASE_SCHEMA" >/dev/null
mysql_file "$DB_NAME" "$TEST_DIR/sql/entitlement-authority-fixture.sql" >/dev/null
for migration in "${MIGRATIONS[@]}"; do
  mysql_file "$DB_NAME" "$UPGRADE_ROOT/$migration/02-正式升级.sql" >/dev/null
done
mysql_file "$DB_NAME" "$UPGRADE_ROOT/2026-07-30-收银V3销售SKU冻结链路/01-升级前检查.sql" | grep -F PRECHECK_OK >/dev/null
mysql_file "$DB_NAME" "$UPGRADE_ROOT/2026-07-30-收银V3销售SKU冻结链路/02-正式升级.sql" | grep -F APPLY_OK >/dev/null
mysql_file "$DB_NAME" "$UPGRADE_ROOT/2026-07-30-收银V3销售SKU冻结链路/03-升级后验证.sql" | grep -F POSTCHECK_OK >/dev/null

docker run --rm --name "$PHP_CONTAINER" --network "$NETWORK" --platform "$PHP_PLATFORM" \
  --cpus 1 --memory 512m \
  -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE="$DB_NAME" \
  -e DB_USERNAME=root -e DB_PASSWORD= \
  -e HOSTNAME="$MYSQL_CONTAINER" -e HOSTPORT=3306 -e DATABASE="$DB_NAME" \
  -e USERNAME=root -e PASSWORD= -e CACHE_DRIVER=file -e DRIVER=file \
  -e CASHIER_V3_CHECKOUT_NAMESPACE_SECRET=mixed-checkout-local-secret-20260729 \
  -v "$ROOT_DIR/后端代码:/source:ro" \
  -v "$ROOT_DIR/tests:/tests:ro" \
  -v "$WORK_DIR:/test-env:ro" \
  --tmpfs /var/www/html:rw,size=512m \
  --entrypoint sh "$PHP_IMAGE" -lc '
    set -e
    test "$(php -r "echo PHP_MAJOR_VERSION . \".\" . PHP_MINOR_VERSION;")" = "7.4"
    tar -C /source --exclude="./.env" --exclude="./.env.*" \
      --exclude="./runtime" --exclude="./public" -cf - . | tar -C /var/www/html -xf -
    mkdir -p /var/www/html/runtime /var/www/html/public/uploads
    test ! -f /var/www/html/.env
    cp /test-env/mixed-checkout.env /var/www/html/.env
    grep -q "DATABASE = mixed_checkout_runtime" /var/www/html/.env
    php -l /tests/cashier-v3-checkout-submission-execution/php/mysql-integration.php
    set +e
    OUT="$(php /tests/cashier-v3-checkout-submission-execution/php/mysql-integration.php 2>&1)"
    PHP_RC=$?
    set -e
    printf "%s\n" "$OUT"
    test "$PHP_RC" -eq 0
    printf "%s\n" "$OUT" | grep -Eq "MIXED_CHECKOUT_MYSQL passed=[1-9][0-9]* failed=0"
  '

echo 'MIXED_CHECKOUT_MYSQL56=PASS'

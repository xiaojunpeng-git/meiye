#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_DIR="$ROOT/tests/cashier-v3-sale-inventory"
UPGRADES="$ROOT/后端代码/database/upgrades"
MYSQL_IMAGE="${CASHIER_V3_SALE_INVENTORY_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
PHP_DOCKERFILE="$ROOT/tests/cashier-v3/docker/php74-runtime.Dockerfile"
NETWORK="sale-inventory-net-$$"
MYSQL="sale-inventory-mysql56-$$"
PHP_CONTAINER="sale-inventory-php74-$$"
DB="sale_inventory"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$MYSQL" >&2 || true
  fi
  docker rm -fv "$PHP_CONTAINER" "$MYSQL" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

PHP_PLATFORM="${CASHIER_V3_SALE_INVENTORY_PHP_PLATFORM:-}"
if [[ -z "$PHP_PLATFORM" ]]; then
  case "$(docker version --format '{{.Server.Arch}}')" in
    amd64|x86_64) PHP_PLATFORM='linux/amd64' ;;
    arm64|aarch64) PHP_PLATFORM='linux/arm64' ;;
    *) echo 'SALE_INVENTORY_UNSUPPORTED_DOCKER_ARCH' >&2; exit 1 ;;
  esac
fi
PHP_IMAGE="c1a-cashier-v3-php74:$(shasum -a 256 "$PHP_DOCKERFILE" | awk '{print substr($1,1,12)}')-${PHP_PLATFORM#linux/}"
if ! docker image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
  docker build --platform "$PHP_PLATFORM" -f "$PHP_DOCKERFILE" -t "$PHP_IMAGE" "$ROOT/tests/cashier-v3/docker"
fi

docker network create "$NETWORK" >/dev/null
docker run -d --name "$MYSQL" --network "$NETWORK" --platform linux/amd64 --cpus 1 --memory 768m \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes "$MYSQL_IMAGE" \
  --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci \
  --sql-mode=STRICT_ALL_TABLES --innodb-large-prefix=0 --innodb-file-format=Antelope >/dev/null
for _ in $(seq 1 90); do
  docker exec "$MYSQL" mysqladmin ping -uroot --silent >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$MYSQL" mysqladmin ping -uroot --silent >/dev/null
[[ "$(docker exec "$MYSQL" mysql -uroot -Nse 'SELECT VERSION()')" == '5.6.51' ]]

mysql_file() {
  docker exec -i "$MYSQL" mysql -uroot --database="$DB" < "$1"
}

mysql_file_for_db() {
  local database="$1"
  local script="$2"
  docker exec -i "$MYSQL" mysql -uroot --database="$database" < "$script"
}

prepare_receipt_schema_db() {
  local database="$1"
  docker exec "$MYSQL" mysql -uroot -e "CREATE DATABASE \`$database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file_for_db "$database" "$UPGRADES/0000-升级登记表初始化.sql" >/dev/null
  mysql_file_for_db "$database" "$TEST_DIR/sql/base-schema.sql" >/dev/null
  mysql_file_for_db "$database" "$UPGRADES/2026-07-29-库存耗材批次完成合同/02-正式升级.sql" >/dev/null
  mysql_file_for_db "$database" "$UPGRADES/2026-07-29-库存统一查询批次事实/02-正式升级.sql" >/dev/null
  mysql_file_for_db "$database" "$UPGRADES/2026-07-30-收银V3销售SKU冻结链路/01-升级前检查.sql" | grep -F PRECHECK_OK >/dev/null
  mysql_file_for_db "$database" "$UPGRADES/2026-07-30-收银V3销售SKU冻结链路/02-正式升级.sql" | grep -F APPLY_OK >/dev/null
  mysql_file_for_db "$database" "$UPGRADES/2026-07-30-收银V3销售SKU冻结链路/03-升级后验证.sql" | grep -F POSTCHECK_OK >/dev/null
}

assert_receipt_precheck_fails() {
  local database="$1"
  local label="$2"
  local output=''
  if output="$(mysql_file_for_db "$database" "$UPGRADES/2026-07-30-收银V3正式销售库存批次扣减/01-升级前检查.sql" 2>&1)"; then
    echo "SALE_INVENTORY_MIGRATION_EXPECTED_PRECHECK_FAILURE=$label" >&2
    exit 1
  fi
  printf '%s\n' "$output" | grep -F PRECHECK_FAILED >/dev/null
}

assert_receipt_postcheck_fails() {
  local database="$1"
  local label="$2"
  local output=''
  if output="$(mysql_file_for_db "$database" "$UPGRADES/2026-07-30-收银V3正式销售库存批次扣减/03-升级后验证.sql" 2>&1)"; then
    echo "SALE_INVENTORY_MIGRATION_EXPECTED_POSTCHECK_FAILURE=$label" >&2
    exit 1
  fi
  printf '%s\n' "$output" | grep -F POSTCHECK_FAILED >/dev/null
}

docker exec "$MYSQL" mysql -uroot -e "CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql_file "$UPGRADES/0000-升级登记表初始化.sql" >/dev/null
mysql_file "$TEST_DIR/sql/base-schema.sql" >/dev/null
mysql_file "$UPGRADES/2026-07-29-库存耗材批次完成合同/02-正式升级.sql" >/dev/null
mysql_file "$UPGRADES/2026-07-29-库存统一查询批次事实/02-正式升级.sql" >/dev/null
mysql_file "$UPGRADES/2026-07-30-收银V3销售SKU冻结链路/01-升级前检查.sql" | grep -F PRECHECK_OK >/dev/null
mysql_file "$UPGRADES/2026-07-30-收银V3销售SKU冻结链路/02-正式升级.sql" | grep -F APPLY_OK >/dev/null
mysql_file "$UPGRADES/2026-07-30-收银V3销售SKU冻结链路/03-升级后验证.sql" | grep -F POSTCHECK_OK >/dev/null
mysql_file "$UPGRADES/2026-07-30-收银V3正式销售库存批次扣减/01-升级前检查.sql" | grep -F PRECHECK_OK >/dev/null
mysql_file "$UPGRADES/2026-07-30-收银V3正式销售库存批次扣减/02-正式升级.sql" | grep -F APPLY_OK >/dev/null
mysql_file "$UPGRADES/2026-07-30-收银V3正式销售库存批次扣减/03-升级后验证.sql" | grep -F POSTCHECK_OK >/dev/null
mysql_file "$UPGRADES/2026-07-30-收银V3正式销售库存批次扣减/01-升级前检查.sql" | grep -F PRECHECK_OK >/dev/null
mysql_file "$UPGRADES/2026-07-30-收银V3正式销售库存批次扣减/02-正式升级.sql" | grep -F APPLY_OK >/dev/null
mysql_file "$UPGRADES/2026-07-30-收银V3正式销售库存批次扣减/03-升级后验证.sql" | grep -F POSTCHECK_OK >/dev/null

MISSING_DB="${DB}_missing"
prepare_receipt_schema_db "$MISSING_DB"
mysql_file_for_db "$MISSING_DB" "$UPGRADES/2026-07-30-收银V3正式销售库存批次扣减/02-正式升级.sql" >/dev/null
docker exec "$MYSQL" mysql -uroot --database="$MISSING_DB" -e "ALTER TABLE eb_cashier_v3_sale_inventory_receipt DROP COLUMN result_snapshot;" >/dev/null
assert_receipt_precheck_fails "$MISSING_DB" 'missing_column'
assert_receipt_postcheck_fails "$MISSING_DB" 'missing_column'

WRONG_INDEX_DB="${DB}_wrong_index"
prepare_receipt_schema_db "$WRONG_INDEX_DB"
mysql_file_for_db "$WRONG_INDEX_DB" "$UPGRADES/2026-07-30-收银V3正式销售库存批次扣减/02-正式升级.sql" >/dev/null
docker exec "$MYSQL" mysql -uroot --database="$WRONG_INDEX_DB" -e "ALTER TABLE eb_cashier_v3_sale_inventory_receipt DROP INDEX uk_tenant_receipt, ADD UNIQUE KEY uk_tenant_receipt (receipt_id,tenant_id);" >/dev/null
assert_receipt_precheck_fails "$WRONG_INDEX_DB" 'wrong_unique_index_order'
assert_receipt_postcheck_fails "$WRONG_INDEX_DB" 'wrong_unique_index_order'

docker exec "$MYSQL" mysql -uroot --database="$DB" -e "
  INSERT INTO eb_inventory_location
    (id,tenant_id,organization_id,organization_path,organization_name_snapshot,location_type,
     owner_id,location_code,location_name,store_id,store_name_snapshot,is_default,location_status,version,created_at,updated_at)
  VALUES (71,'tenant-1','org-1','/org-1/store-7','测试组织','STORE',7,'STORE-7','测试门店仓',7,'测试门店',1,'ACTIVE',1,1785369600,1785369600);
  INSERT INTO eb_inventory_stock
    (id,tenant_id,organization_id,organization_path,location_id,store_id,consumable_product_id,sku_id,product_unique,
     stock_status,stock_unit,quantity_scale,available_quantity_units,estimated_unit_cost_cents,version,created_at,updated_at)
  VALUES
    (701,'tenant-1','org-1','/org-1/store-7',71,7,501,601,'SKU-501','GOOD','件',0,5,0,1,1785369600,1785369600),
    (702,'tenant-1','org-1','/org-1/store-7',71,7,502,602,'SKU-502','GOOD','件',0,1,0,1,1785369600,1785369600),
    (703,'tenant-1','org-1','/org-1/store-7',71,7,503,603,'SKU-503','GOOD','件',0,0,0,1,1785369600,1785369600);
  INSERT INTO eb_inventory_batch
    (id,stock_id,batch_no,manufactured_date,expire_date,received_at,available_quantity_units,unit_cost_cents,cost_allocated_quantity_units,batch_status,version,created_at,updated_at)
  VALUES
    (801,701,'SALE-BATCH-OLD','2026-01-01','2026-10-01',1780000000,1,100,0,'ACTIVE',1,1785369600,1785369600),
    (802,701,'SALE-BATCH-NEW','2026-01-01','2026-12-01',1781000000,4,300,0,'ACTIVE',1,1785369600,1785369600),
    (803,702,'SALE-BATCH-SHORT','2026-01-01','2026-10-01',1780000000,1,120,0,'ACTIVE',1,1785369600,1785369600);
" >/dev/null

docker run --rm --name "$PHP_CONTAINER" --network "$NETWORK" --platform "$PHP_PLATFORM" --cpus 1 --memory 512m \
  -e DB_HOST="$MYSQL" -e DB_PORT=3306 -e DB_DATABASE="$DB" -e DB_USERNAME=root -e DB_PASSWORD= \
  -v "$ROOT/后端代码:/source:ro" -v "$ROOT/tests:/tests:ro" \
  --tmpfs /var/www/html:rw,size=512m --entrypoint sh "$PHP_IMAGE" -lc '
    set -e
    tar -C /source --exclude="./.env" --exclude="./.env.*" --exclude="./runtime" --exclude="./public" -cf - . | tar -C /var/www/html -xf -
    mkdir -p /var/www/html/runtime /var/www/html/public/uploads
    printf "%s\\n" \
      "APP_DEBUG = true" \
      "HOSTNAME = ${DB_HOST}" \
      "DATABASE = ${DB_DATABASE}" \
      "USERNAME = ${DB_USERNAME}" \
      "PASSWORD = ${DB_PASSWORD}" \
      "HOSTPORT = ${DB_PORT}" \
      "DRIVER = file" \
      "CACHE_DRIVER = file" > /var/www/html/.env
    php -l /tests/cashier-v3-sale-inventory/php/mysql-integration.php
    php /tests/cashier-v3-sale-inventory/php/mysql-integration.php
  '

echo 'SALE_INVENTORY_MYSQL56=PASS'

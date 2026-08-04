#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
package_dir="$repo_dir/后端代码/database/upgrades/2026-07-29-收银V3销售购物车权威行"
container_name="c2-sale-cart-mysql56-$$"
network_name="c2-sale-cart-net-$$"
mysql_image="docker.m.daocloud.io/library/mysql:5.6.51"
php_image="mohe-app:local"
failure_log="$(mktemp /tmp/c2-sale-cart-matrix.XXXXXX)"
lock_a_log="$(mktemp /tmp/c2-sale-cart-lock-a.XXXXXX)"
lock_b_log="$(mktemp /tmp/c2-sale-cart-lock-b.XXXXXX)"
temp_env="$(mktemp "$repo_dir/tests/cashier-v3/.c2-sale-cart-env.XXXXXX")"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$container_name" >&2 || true
    sed -n '1,200p' "$failure_log" >&2 || true
    sed -n '1,120p' "$lock_a_log" >&2 || true
    sed -n '1,120p' "$lock_b_log" >&2 || true
  fi
  docker rm -f "$container_name" >/dev/null 2>&1 || true
  docker network rm "$network_name" >/dev/null 2>&1 || true
  rm -f "$failure_log" "$lock_a_log" "$lock_b_log" "$temp_env"
}
trap cleanup EXIT INT TERM

docker network create "$network_name" >/dev/null
docker run -d --name "$container_name" --platform linux/amd64 \
  --network "$network_name" \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$mysql_image" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci >/dev/null

for _ in $(seq 1 90); do
  if docker logs "$container_name" 2>&1 | grep 'MySQL init process done' >/dev/null; then
    break
  fi
  sleep 1
done
docker logs "$container_name" 2>&1 | grep 'MySQL init process done' >/dev/null
for _ in $(seq 1 90); do
  if docker exec "$container_name" mysqladmin ping -uroot --silent >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
docker exec "$container_name" mysqladmin ping -uroot --silent >/dev/null

mysql_version="$(docker exec "$container_name" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$mysql_version" == "5.6.51" ]] || {
  echo "MYSQL_VERSION_MISMATCH=$mysql_version" >&2
  exit 1
}
echo "MYSQL_VERSION=$mysql_version"
echo "MYSQL_IMAGE=$mysql_image"

mysql_sql() {
  local db="$1" sql="$2"
  docker exec "$container_name" mysql -uroot --database="$db" -e "$sql"
}

mysql_value() {
  local db="$1" sql="$2"
  docker exec "$container_name" mysql -uroot --database="$db" -Nse "$sql"
}

mysql_file() {
  local db="$1" file="$2"
  docker exec -i "$container_name" mysql -uroot --database="$db" < "$file"
}

init_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e \
    "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$repo_dir/后端代码/database/upgrades/0000-升级登记表初始化.sql" >/dev/null
  mysql_sql "$db" "
    CREATE TABLE eb_cashier_v3_workspace_line (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      line_role varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
      holder_id bigint(20) unsigned NOT NULL DEFAULT 0,
      source_detail_id bigint(20) unsigned NOT NULL DEFAULT 0,
      project_id bigint(20) unsigned NOT NULL DEFAULT 0,
      detail_version bigint(20) unsigned NOT NULL DEFAULT 1,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    CREATE TABLE eb_store_product (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      pid bigint(20) unsigned NOT NULL DEFAULT 0,
      type tinyint NOT NULL DEFAULT 1,
      relation_id bigint(20) unsigned NOT NULL DEFAULT 0,
      product_type tinyint NOT NULL DEFAULT 0,
      store_name varchar(128) NOT NULL DEFAULT '',
      cate_id varchar(255) NOT NULL DEFAULT '',
      keyword varchar(255) NOT NULL DEFAULT '',
      unit_name varchar(32) NOT NULL DEFAULT '',
      sort int NOT NULL DEFAULT 0,
      is_show tinyint NOT NULL DEFAULT 1,
      is_del tinyint NOT NULL DEFAULT 0,
      is_verify tinyint NOT NULL DEFAULT 1,
      is_inventory tinyint NOT NULL DEFAULT 0,
      allow_negative_stock tinyint NOT NULL DEFAULT 1,
      card_num int NOT NULL DEFAULT 0,
      card_num_type tinyint NOT NULL DEFAULT 0,
      card_rule_type varchar(24) NOT NULL DEFAULT '',
      card_rule_version int unsigned NOT NULL DEFAULT 0,
      card_choice_limit int unsigned NOT NULL DEFAULT 0,
      card_shared_times int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    CREATE TABLE eb_store_product_attr_value (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      product_id bigint(20) unsigned NOT NULL DEFAULT 0,
      product_type tinyint NOT NULL DEFAULT 0,
      \`unique\` varchar(20) NOT NULL DEFAULT '',
      suk varchar(128) NOT NULL DEFAULT '',
      price decimal(12,2) unsigned NOT NULL DEFAULT 0,
      ot_price decimal(12,2) unsigned NOT NULL DEFAULT 0,
      stock decimal(18,4) NOT NULL DEFAULT 0,
      code varchar(50) NOT NULL DEFAULT '',
      bar_code varchar(50) NOT NULL DEFAULT '',
      is_show tinyint NOT NULL DEFAULT 1,
      type tinyint NOT NULL DEFAULT 0,
      write_times int NOT NULL DEFAULT 0,
      write_valid tinyint NOT NULL DEFAULT 1,
      write_days int NOT NULL DEFAULT 0,
      write_start int NOT NULL DEFAULT 0,
      write_end int NOT NULL DEFAULT 0,
      PRIMARY KEY (id), KEY idx_unique_suk (\`unique\`,suk), KEY idx_product_suk (product_id,suk)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    CREATE TABLE eb_store_product_category (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      cate_name varchar(128) NOT NULL DEFAULT '',
      type tinyint NOT NULL DEFAULT 0,
      relation_id bigint(20) unsigned NOT NULL DEFAULT 0,
      is_show tinyint NOT NULL DEFAULT 1,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    CREATE TABLE eb_store_card_related (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      card_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
      product_id bigint(20) unsigned NOT NULL DEFAULT 0,
      product_type tinyint NOT NULL DEFAULT 0,
      product_attr_unique varchar(20) NOT NULL DEFAULT '',
      cost decimal(12,2) unsigned NOT NULL DEFAULT 0,
      price decimal(12,2) unsigned NOT NULL DEFAULT 0,
      write_times int NOT NULL DEFAULT 0,
      writeoff_amount decimal(12,2) unsigned NOT NULL DEFAULT 0,
      status tinyint NOT NULL DEFAULT 1,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  " >/dev/null
}

drop_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e "DROP DATABASE IF EXISTS \`$db\`;"
}

expect_fail_file() {
  local db="$1" file="$2" marker="$3"
  if mysql_file "$db" "$file" >"$failure_log" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  : > "$failure_log"
  echo "$marker=PASS"
}

register_upgrade() {
  local db="$1"
  mysql_sql "$db" "
    INSERT INTO eb_database_upgrade_log
      (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
    VALUES
      ('20260729-008-cashier-v3-sale-cart-authority','local matrix','tests/cashier-v3/c2-sale-cart-sql-matrix.sh','','local',NOW(),'Codex','local MySQL 5.6.51 matrix');
  " >/dev/null
}

fresh_db="c2_sale_cart_fresh"
init_db "$fresh_db"
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/03-升级后验证.sql" >/dev/null
mysql_sql "$fresh_db" "
  INSERT INTO eb_cashier_v3_workspace_line
    (line_role,holder_id,source_detail_id,project_id,detail_version)
  VALUES ('entitlement_service',9,10,11,1);
  INSERT INTO eb_cashier_v3_workspace_line
    (line_role,holder_id,source_detail_id,project_id,detail_version,
     catalog_product_id,catalog_sku_id,catalog_product_type,unit_price_cents,
     original_unit_price_cents,authority_fingerprint,authority_snapshot_json)
  VALUES ('sale',0,0,101,2,101,1001,6,12850,13800,REPEAT('a',64),'{}');
" >/dev/null
mysql_file "$fresh_db" "$package_dir/03-升级后验证.sql" >/dev/null
fresh_shape="$(mysql_value "$fresh_db" "SELECT CONCAT((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_workspace_line' AND COLUMN_NAME IN ('catalog_product_id','catalog_sku_id','catalog_product_type','unit_price_cents','original_unit_price_cents','authority_fingerprint','authority_snapshot_json')),':',(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_workspace_line' AND INDEX_NAME='idx_catalog_source'),':',(SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND SEQ_IN_INDEX=1 AND COLUMN_NAME='card_product_id'),':',(SELECT COUNT(*) FROM eb_cashier_v3_workspace_line))")"
[[ "$fresh_shape" == "7:4:1:2" ]] || {
  echo "FRESH_SHAPE_MISMATCH=$fresh_shape" >&2
  exit 1
}
entitlement_defaults="$(mysql_value "$fresh_db" "SELECT CONCAT(catalog_product_id,':',catalog_sku_id,':',unit_price_cents,':',original_unit_price_cents,':',authority_fingerprint,':',IF(authority_snapshot_json IS NULL,'NULL','VALUE')) FROM eb_cashier_v3_workspace_line WHERE line_role='entitlement_service'")"
[[ "$entitlement_defaults" == "0:0:0:0::NULL" ]] || {
  echo "ENTITLEMENT_DEFAULTS_CHANGED=$entitlement_defaults" >&2
  exit 1
}
echo "SALE_MIGRATION_FRESH_REPLAY=PASS"

mysql_sql "$fresh_db" "
  INSERT INTO eb_store_product
    (id,type,relation_id,product_type,store_name)
  VALUES (2,1,7,6,'component-2'),(10,1,7,6,'component-10'),(100,1,7,5,'card-a'),(200,1,7,5,'card-b');
  INSERT INTO eb_store_product_attr_value
    (id,product_id,product_type,\`unique\`,suk,price,ot_price,write_valid,write_days)
  VALUES (2,2,6,'sku-2','default',10,10,1,0),(10,10,6,'sku-10','default',20,20,1,0),
         (1000,100,5,'sku-1000','default',100,100,2,365),
         (2000,200,5,'sku-2000','default',200,200,2,180);
  INSERT INTO eb_store_card_related
    (id,card_product_id,product_id,product_type,product_attr_unique,price,write_times,status)
  VALUES (1,100,2,6,'sku-2',10,1,1),(2,100,10,6,'sku-10',20,1,1),
         (3,200,10,6,'sku-10',20,1,1),(4,200,2,6,'sku-2',10,1,1);
" >/dev/null
validity_shape="$(mysql_value "$fresh_db" "SELECT GROUP_CONCAT(CONCAT(id,':',write_valid,':',write_days) ORDER BY id SEPARATOR ',') FROM eb_store_product_attr_value WHERE id IN (1000,2000)")"
[[ "$validity_shape" == "1000:2:365,2000:2:180" ]] || {
  echo "WRITE_DAYS_SHAPE_MISMATCH=$validity_shape" >&2
  exit 1
}
echo "SALE_WRITE_DAYS_MYSQL56=PASS"

docker run --rm --entrypoint php \
  --network "$network_name" \
  -e DB_HOST="$container_name" -e DB_PORT=3306 -e DB_DATABASE="$fresh_db" \
  -e DB_USERNAME=root -e DB_PASSWORD= \
  -e CACHE_DRIVER=file -e REDIS_HOSTNAME=127.0.0.1 \
  -v "$repo_dir/后端代码:/var/www/html:ro" \
  -v "$repo_dir/tests/cashier-v3:/tests:ro" \
  -v "$temp_env:/var/www/html/.env:ro" \
  --tmpfs /var/www/html/runtime:rw,size=64m \
  -w /var/www/html \
  "$php_image" /tests/php/c2-sale-catalog-adapter-integration.php
echo "SALE_THINKPHP_ADAPTER_MYSQL56=PASS"

docker exec "$container_name" mysql -uroot --database="$fresh_db" -e "
  SET innodb_lock_wait_timeout=5; START TRANSACTION;
  SELECT id FROM eb_store_product WHERE id=100 FOR UPDATE;
  SELECT 'catalog_card_definition:100' AS lock_trace;
  SELECT id FROM eb_store_card_related WHERE card_product_id=100 ORDER BY id ASC;
  SELECT id FROM eb_store_product WHERE id=2 FOR UPDATE;
  SELECT 'catalog_product:2' AS lock_trace;
  DO SLEEP(0.4);
  SELECT id FROM eb_store_product WHERE id=10 FOR UPDATE;
  SELECT 'catalog_product:10' AS lock_trace;
  SELECT id FROM eb_store_product WHERE id=100 FOR UPDATE;
  SELECT 'catalog_product:100' AS lock_trace;
  SELECT id FROM eb_store_product_attr_value WHERE id=2 FOR UPDATE;
  SELECT 'catalog_sku:2' AS lock_trace;
  SELECT id FROM eb_store_product_attr_value WHERE id=10 FOR UPDATE;
  SELECT 'catalog_sku:10' AS lock_trace;
  SELECT id FROM eb_store_product_attr_value WHERE id=1000 FOR UPDATE;
  SELECT 'catalog_sku:1000' AS lock_trace;
  COMMIT;
" >"$lock_a_log" 2>&1 &
lock_a_pid=$!
sleep 0.1
docker exec "$container_name" mysql -uroot --database="$fresh_db" -e "
  SET innodb_lock_wait_timeout=5; START TRANSACTION;
  SELECT id FROM eb_store_product WHERE id=200 FOR UPDATE;
  SELECT 'catalog_card_definition:200' AS lock_trace;
  SELECT id FROM eb_store_card_related WHERE card_product_id=200 ORDER BY id ASC;
  SELECT id FROM eb_store_product WHERE id=2 FOR UPDATE;
  SELECT 'catalog_product:2' AS lock_trace;
  SELECT id FROM eb_store_product WHERE id=10 FOR UPDATE;
  SELECT 'catalog_product:10' AS lock_trace;
  SELECT id FROM eb_store_product WHERE id=200 FOR UPDATE;
  SELECT 'catalog_product:200' AS lock_trace;
  SELECT id FROM eb_store_product_attr_value WHERE id=2 FOR UPDATE;
  SELECT 'catalog_sku:2' AS lock_trace;
  SELECT id FROM eb_store_product_attr_value WHERE id=10 FOR UPDATE;
  SELECT 'catalog_sku:10' AS lock_trace;
  SELECT id FROM eb_store_product_attr_value WHERE id=2000 FOR UPDATE;
  SELECT 'catalog_sku:2000' AS lock_trace;
  COMMIT;
" >"$lock_b_log" 2>&1 &
lock_b_pid=$!
wait "$lock_a_pid"
wait "$lock_b_pid"
lock_a_trace="$(grep -E '^catalog_(card_definition|product|sku):' "$lock_a_log" | paste -sd ',' -)"
lock_b_trace="$(grep -E '^catalog_(card_definition|product|sku):' "$lock_b_log" | paste -sd ',' -)"
expected_a_trace="catalog_card_definition:100,catalog_product:2,catalog_product:10,catalog_product:100,catalog_sku:2,catalog_sku:10,catalog_sku:1000"
expected_b_trace="catalog_card_definition:200,catalog_product:2,catalog_product:10,catalog_product:200,catalog_sku:2,catalog_sku:10,catalog_sku:2000"
[[ "$lock_a_trace" == "$expected_a_trace" ]] || {
  echo "SALE_LOCK_A_TRACE_MISMATCH=$lock_a_trace" >&2
  exit 1
}
[[ "$lock_b_trace" == "$expected_b_trace" ]] || {
  echo "SALE_LOCK_B_TRACE_MISMATCH=$lock_b_trace" >&2
  exit 1
}
echo "SALE_LOCK_ORDER_MYSQL56=PASS"
mysql_sql "$fresh_db" "
  INSERT INTO eb_store_product_attr_value
    (id,product_id,product_type,\`unique\`,suk,price,ot_price)
  VALUES (11,10,6,'sku-10','duplicate',20,20);
" >/dev/null
duplicate_sku_count="$(mysql_value "$fresh_db" "SELECT COUNT(*) FROM eb_store_product_attr_value WHERE product_id=10 AND \`unique\`='sku-10'")"
[[ "$duplicate_sku_count" == "2" ]] || {
  echo "NON_UNIQUE_SKU_SHAPE_MISMATCH=$duplicate_sku_count" >&2
  exit 1
}
echo "SALE_NON_UNIQUE_SKU_SCHEMA=PASS"
drop_db "$fresh_db"

partial_db="c2_sale_cart_partial"
init_db "$partial_db"
mysql_sql "$partial_db" "
  ALTER TABLE eb_cashier_v3_workspace_line
    ADD COLUMN catalog_product_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER project_id,
    ADD COLUMN catalog_sku_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER catalog_product_id;
" >/dev/null
mysql_file "$partial_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$partial_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$partial_db" "$package_dir/03-升级后验证.sql" >/dev/null
echo "SALE_PARTIAL_DDL_RESUME=PASS"
drop_db "$partial_db"

wrong_column_db="c2_sale_cart_wrong_column"
init_db "$wrong_column_db"
mysql_file "$wrong_column_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_column_db" "ALTER TABLE eb_cashier_v3_workspace_line MODIFY unit_price_cents decimal(12,2) unsigned NOT NULL DEFAULT 0" >/dev/null
expect_fail_file "$wrong_column_db" "$package_dir/01-升级前检查.sql" "SALE_WRONG_COLUMN_PRECHECK_REJECTED"
expect_fail_file "$wrong_column_db" "$package_dir/03-升级后验证.sql" "SALE_WRONG_COLUMN_POSTCHECK_REJECTED"
drop_db "$wrong_column_db"

wrong_index_db="c2_sale_cart_wrong_index"
init_db "$wrong_index_db"
mysql_file "$wrong_index_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_index_db" "ALTER TABLE eb_cashier_v3_workspace_line DROP INDEX idx_catalog_source, ADD KEY idx_catalog_source(catalog_sku_id,catalog_product_id,line_role,id)" >/dev/null
expect_fail_file "$wrong_index_db" "$package_dir/01-升级前检查.sql" "SALE_WRONG_INDEX_PRECHECK_REJECTED"
expect_fail_file "$wrong_index_db" "$package_dir/03-升级后验证.sql" "SALE_WRONG_INDEX_POSTCHECK_REJECTED"
drop_db "$wrong_index_db"

wrong_relation_index_db="c2_sale_cart_wrong_relation_index"
init_db "$wrong_relation_index_db"
mysql_sql "$wrong_relation_index_db" "ALTER TABLE eb_store_card_related ADD KEY idx_c2_card_definition(product_id,id)" >/dev/null
expect_fail_file "$wrong_relation_index_db" "$package_dir/01-升级前检查.sql" "SALE_WRONG_RELATION_INDEX_PRECHECK_REJECTED"
drop_db "$wrong_relation_index_db"

invalid_row_db="c2_sale_cart_invalid_row"
init_db "$invalid_row_db"
mysql_file "$invalid_row_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$invalid_row_db" "
  INSERT INTO eb_cashier_v3_workspace_line
    (line_role,holder_id,source_detail_id,project_id,detail_version,
     catalog_product_id,catalog_sku_id,catalog_product_type,unit_price_cents,
     original_unit_price_cents,authority_fingerprint,authority_snapshot_json)
  VALUES ('sale',9,0,0,1,101,1001,0,100,100,'bad','{}');
" >/dev/null
expect_fail_file "$invalid_row_db" "$package_dir/03-升级后验证.sql" "SALE_INVALID_ROW_POSTCHECK_REJECTED"
drop_db "$invalid_row_db"

legacy_db="c2_sale_cart_legacy_column"
init_db "$legacy_db"
mysql_sql "$legacy_db" "ALTER TABLE eb_store_card_related DROP COLUMN write_times" >/dev/null
expect_fail_file "$legacy_db" "$package_dir/01-升级前检查.sql" "SALE_LEGACY_COLUMN_PRECHECK_REJECTED"
drop_db "$legacy_db"

used_key_db="c2_sale_cart_used_key"
init_db "$used_key_db"
mysql_file "$used_key_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$used_key_db" "$package_dir/03-升级后验证.sql" >/dev/null
register_upgrade "$used_key_db"
expect_fail_file "$used_key_db" "$package_dir/01-升级前检查.sql" "SALE_USED_UPGRADE_KEY_REJECTED"
mysql_file "$used_key_db" "$package_dir/03-升级后验证.sql" >/dev/null
drop_db "$used_key_db"

echo "C2_SALE_CART_MYSQL56_MATRIX=PASS"
printf 'GATE_PASS=%s\n' \
  C2-SALE-SQL-01 C2-SALE-SQL-02 C2-SALE-SQL-03 C2-SALE-SQL-04 \
  C2-SALE-SQL-05 C2-SALE-SQL-06 C2-SALE-SQL-07

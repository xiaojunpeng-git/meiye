#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
upgrade_dir="$repo_dir/后端代码/database/upgrades/2026-07-29-库存耗材批次完成合同"
query_upgrade_dir="$repo_dir/后端代码/database/upgrades/2026-07-29-库存统一查询批次事实"
warehouse_upgrade_dir="$repo_dir/后端代码/database/upgrades/2026-07-31-库存V3仓库创建命令"
init_sql="$repo_dir/后端代码/database/upgrades/0000-升级登记表初始化.sql"
container="inventory-completion-mysql56-$$"
image="docker.m.daocloud.io/library/mysql:5.6.51"
failure_log="$(mktemp /tmp/inventory-completion-mysql56.XXXXXX)"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$container" >&2 || true
    sed -n '1,180p' "$failure_log" >&2 || true
  fi
  docker rm -f "$container" >/dev/null 2>&1 || true
  rm -f "$failure_log"
}
trap cleanup EXIT INT TERM

docker run -d --name "$container" --platform linux/amd64 --cpus 1 --memory 768m \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$image" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --sql-mode=STRICT_ALL_TABLES \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  if docker logs "$container" 2>&1 | grep 'MySQL init process done' >/dev/null; then
    break
  fi
  sleep 1
done
docker logs "$container" 2>&1 | grep 'MySQL init process done' >/dev/null
for _ in $(seq 1 90); do
  if docker exec "$container" mysqladmin ping -uroot --silent >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
docker exec "$container" mysqladmin ping -uroot --silent >/dev/null
mysql_version="$(docker exec "$container" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$mysql_version" == "5.6.51" ]]
echo "MYSQL_VERSION=$mysql_version"

mysql_file() {
  local db="$1" file="$2"
  docker exec -i "$container" mysql -uroot --default-character-set=utf8mb4 --database="$db" < "$file"
}

mysql_sql() {
  local db="$1" sql="$2"
  docker exec "$container" mysql -uroot --default-character-set=utf8mb4 --database="$db" -e "$sql"
}

mysql_value() {
  local db="$1" sql="$2"
  docker exec "$container" mysql -uroot --default-character-set=utf8mb4 --database="$db" -Nse "$sql"
}

db="inventory_completion"
docker exec "$container" mysql -uroot -e \
  "CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql_file "$db" "$init_sql" >/dev/null
mysql_file "$db" "$upgrade_dir/01-升级前检查.sql" >/dev/null
mysql_file "$db" "$upgrade_dir/02-正式升级.sql" >/dev/null
mysql_file "$db" "$upgrade_dir/02-正式升级.sql" >/dev/null
mysql_file "$db" "$upgrade_dir/01-升级前检查.sql" >/dev/null
mysql_file "$db" "$upgrade_dir/03-升级后验证.sql" >/dev/null
echo "FRESH_REPLAY=PASS"

partial_db="inventory_completion_partial"
docker exec "$container" mysql -uroot -e \
  "CREATE DATABASE \`$partial_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql_file "$partial_db" "$init_sql" >/dev/null
mysql_sql "$partial_db" "CREATE TABLE eb_inventory_stock(id bigint unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB" >/dev/null
if mysql_file "$partial_db" "$upgrade_dir/01-升级前检查.sql" >"$failure_log" 2>&1; then
  echo "PARTIAL_PRECHECK_DID_NOT_FAIL" >&2
  exit 1
fi
: > "$failure_log"
echo "PARTIAL_PRECHECK=PASS"

mysql_sql "$db" "
  INSERT INTO eb_inventory_shortage_policy
    (tenant_id,policy_scope,project_id,policy_value,version,updated_by,created_at,updated_at)
  VALUES
    ('tenant-1','MERCHANT',0,'deny_shortage',2,99,1000,1000),
    ('tenant-1','PROJECT',401,'inherit',3,99,1000,1000),
    ('tenant-1','PROJECT',402,'allow_shortage',4,99,1000,1000),
    ('tenant-1','PROJECT',403,'deny_shortage',5,99,1000,1000);

  INSERT INTO eb_inventory_stock
    (id,tenant_id,organization_id,organization_path,store_id,consumable_product_id,sku_id,
     product_unique,stock_status,stock_unit,quantity_scale,available_quantity_units,
     estimated_unit_cost_cents,version,created_at,updated_at)
  VALUES
    (501,'tenant-1','org-1','/org-1/store-7',7,601,701,'sku-701','GOOD','piece',0,10,50,1,1000,1000);

  INSERT INTO eb_inventory_batch
    (id,stock_id,batch_no,manufactured_date,expire_date,received_at,available_quantity_units,
     unit_cost_cents,cost_allocated_quantity_units,batch_status,version,created_at,updated_at)
  VALUES
    (801,501,'BATCH-REAL-001','2026-01-01','2027-01-01',1000,10,100,0,'ACTIVE',1,1000,1000);
" >/dev/null

policy_matrix="$(mysql_value "$db" "
  SELECT GROUP_CONCAT(CONCAT(p.project_id,':',
    IF(p.policy_value='inherit',m.policy_value,p.policy_value)) ORDER BY p.project_id SEPARATOR ',')
  FROM eb_inventory_shortage_policy p
  JOIN eb_inventory_shortage_policy m
    ON m.tenant_id=p.tenant_id AND m.policy_scope='MERCHANT' AND m.project_id=0
  WHERE p.tenant_id='tenant-1' AND p.policy_scope='PROJECT';
")"
[[ "$policy_matrix" == "401:deny_shortage,402:allow_shortage,403:deny_shortage" ]]
echo "POLICY_MATRIX=PASS"

mysql_sql "$db" "
  START TRANSACTION;
  SELECT id FROM eb_inventory_stock WHERE id=501 FOR UPDATE;
  SELECT id FROM eb_inventory_batch WHERE id=801 FOR UPDATE;
  SET @required:=11;
  SET @available:=(SELECT available_quantity_units FROM eb_inventory_stock WHERE id=501);
  UPDATE eb_inventory_stock SET available_quantity_units=0 WHERE id=501 AND @required<=@available;
  ROLLBACK;
" >/dev/null
[[ "$(mysql_value "$db" "SELECT available_quantity_units FROM eb_inventory_stock WHERE id=501")" == "10" ]]
[[ "$(mysql_value "$db" "SELECT COUNT(*) FROM eb_inventory_consumption_receipt")" == "0" ]]
echo "STRICT_SHORTAGE_ROLLBACK=PASS"

if mysql_sql "$db" "UPDATE eb_inventory_batch SET available_quantity_units=-1 WHERE id=801" >"$failure_log" 2>&1; then
  echo "NEGATIVE_BATCH_ACCEPTED" >&2
  exit 1
fi
: > "$failure_log"
echo "NEGATIVE_BATCH_BLOCKED=PASS"

mysql_sql "$db" "
  START TRANSACTION;
  SELECT id FROM eb_inventory_stock WHERE id=501 FOR UPDATE;
  SELECT id FROM eb_inventory_batch WHERE id=801 FOR UPDATE;
  UPDATE eb_inventory_stock
    SET available_quantity_units=4,version=2,updated_at=1100 WHERE id=501 AND version=1;
  UPDATE eb_inventory_batch
    SET available_quantity_units=4,cost_allocated_quantity_units=6,version=2,updated_at=1100
    WHERE id=801 AND version=1 AND available_quantity_units=10;
  INSERT INTO eb_inventory_shortage_cost_cursor
    (tenant_id,store_id,stock_id,recipe_id,estimated_unit_cost_cents,allocated_quantity_units,version,created_at,updated_at)
  VALUES ('tenant-1',7,501,901,50,2,1,1100,1100);
  INSERT INTO eb_inventory_consumption_receipt
    (id,receipt_key,idempotency_key,request_fingerprint,contract_version,operation_type,
     tenant_id,organization_id,organization_path,organization_name_snapshot,store_id,store_name_snapshot,
     operator_id,source_type,source_id,source_detail_id,project_id,project_name_snapshot,
     policy_value,policy_version,recipe_id,recipe_version,recipe_formula_hash,
     actual_cost_cents,estimated_shortage_cost_cents,cost_complete_at_settlement,result_snapshot,
     business_date,occurred_at,settled_at,recorded_at)
  VALUES
    (1001,'receipt-1','idem-1',REPEAT('a',64),'inventory-entitlement-completion-provider-v1','CONSUME',
     'tenant-1','org-1','/org-1/store-7','Org 1',7,'Store 7',99,'writeoff','source-1','detail-1',402,'Project 402',
     'allow_shortage',8589934596,901,6,REPEAT('b',64),600,100,0,'{}','2026-07-29',1100,1100,1100);
  INSERT INTO eb_inventory_batch_consumption_fact
    (id,fact_key,receipt_id,direction,tenant_id,organization_path,store_id,source_type,source_id,source_detail_id,
     line_id,project_id,project_name_snapshot,recipe_id,recipe_version,recipe_formula_hash,policy_value,policy_version,
     consumable_product_id,consumable_name_snapshot,sku_id,sku_name_snapshot,stock_id,batch_id,batch_no_snapshot,
     quantity_scale,quantity_units,unit_cost_cents,actual_cost_cents,cost_cursor_before,cost_cursor_after,
     batch_version_before,batch_version_after,business_date,occurred_at,settled_at,recorded_at)
  VALUES
    (1101,'batch-fact-1',1001,1,'tenant-1','/org-1/store-7',7,'writeoff','source-1','detail-1',
     'line-1',402,'Project 402',901,6,REPEAT('b',64),'allow_shortage',8589934596,
     601,'Material 601',701,'SKU 701',501,801,'BATCH-REAL-001',0,6,100,600,0,6,1,2,
     '2026-07-29',1100,1100,1100);
  INSERT INTO eb_inventory_shortage_fact
    (id,fact_key,receipt_id,direction,tenant_id,organization_path,store_id,source_type,source_id,source_detail_id,
     line_id,project_id,project_name_snapshot,recipe_id,recipe_version,recipe_formula_hash,consumable_product_id,consumable_name_snapshot,
     sku_id,sku_name_snapshot,stock_id,quantity_scale,shortage_quantity_units,estimated_unit_cost_cents,
     estimated_cost_cents,cost_cursor_before,cost_cursor_after,policy_value,policy_version,
     business_date,occurred_at,settled_at,recorded_at)
  VALUES
    (1201,'shortage-fact-1',1001,1,'tenant-1','/org-1/store-7',7,'writeoff','source-1','detail-1',
     'line-1',402,'Project 402',901,6,REPEAT('b',64),601,'Material 601',701,'SKU 701',501,0,2,50,100,0,2,
     'allow_shortage',8589934596,'2026-07-29',1100,1100,1100);
  COMMIT;
" >/dev/null

[[ "$(mysql_value "$db" "SELECT CONCAT(available_quantity_units,':',version) FROM eb_inventory_stock WHERE id=501")" == "4:2" ]]
[[ "$(mysql_value "$db" "SELECT CONCAT(available_quantity_units,':',cost_allocated_quantity_units,':',version) FROM eb_inventory_batch WHERE id=801")" == "4:6:2" ]]
[[ "$(mysql_value "$db" "SELECT COUNT(*) FROM eb_inventory_batch")" == "1" ]]
[[ "$(mysql_value "$db" "SELECT CONCAT(shortage_quantity_units,':',estimated_cost_cents) FROM eb_inventory_shortage_fact WHERE id=1201")" == "2:100" ]]
echo "ALLOW_SHORTAGE_REAL_BATCH_ONLY=PASS"

if mysql_sql "$db" "
  INSERT INTO eb_inventory_consumption_receipt
    (receipt_key,idempotency_key,request_fingerprint,contract_version,tenant_id,result_snapshot,business_date)
  VALUES ('receipt-1','idem-other',REPEAT('c',64),'inventory-entitlement-completion-provider-v1','tenant-1','{}','2026-07-29')
" >"$failure_log" 2>&1; then
  echo "RECEIPT_IDEMPOTENCY_UNIQUE_MISSING" >&2
  exit 1
fi
: > "$failure_log"
echo "IDEMPOTENCY_UNIQUE=PASS"

docker exec "$container" mysql -uroot --database="$db" -e "
  START TRANSACTION;
  SELECT id FROM eb_inventory_stock WHERE id=501 FOR UPDATE;
  SELECT id FROM eb_inventory_batch WHERE id=801 FOR UPDATE;
  DO SLEEP(2);
  UPDATE eb_inventory_stock SET available_quantity_units=available_quantity_units-1,version=version+1 WHERE id=501 AND available_quantity_units>=1;
  UPDATE eb_inventory_batch SET available_quantity_units=available_quantity_units-1,cost_allocated_quantity_units=cost_allocated_quantity_units+1,version=version+1 WHERE id=801 AND available_quantity_units>=1;
  COMMIT;
" >/dev/null &
worker_a=$!
sleep 0.3
docker exec "$container" mysql -uroot --database="$db" -e "
  START TRANSACTION;
  SELECT id FROM eb_inventory_stock WHERE id=501 FOR UPDATE;
  SELECT id FROM eb_inventory_batch WHERE id=801 FOR UPDATE;
  UPDATE eb_inventory_stock SET available_quantity_units=available_quantity_units-1,version=version+1 WHERE id=501 AND available_quantity_units>=1;
  UPDATE eb_inventory_batch SET available_quantity_units=available_quantity_units-1,cost_allocated_quantity_units=cost_allocated_quantity_units+1,version=version+1 WHERE id=801 AND available_quantity_units>=1;
  COMMIT;
" >/dev/null &
worker_b=$!
wait "$worker_a"
wait "$worker_b"
[[ "$(mysql_value "$db" "SELECT CONCAT(available_quantity_units,':',version) FROM eb_inventory_stock WHERE id=501")" == "2:4" ]]
[[ "$(mysql_value "$db" "SELECT CONCAT(available_quantity_units,':',cost_allocated_quantity_units,':',version) FROM eb_inventory_batch WHERE id=801")" == "2:8:4" ]]
echo "TWO_CONNECTION_LOCK_ORDER=PASS"

mysql_sql "$db" "
  INSERT INTO eb_inventory_shortage_cost_adjustment
    (id,adjustment_key,shortage_fact_id,direction,tenant_id,store_id,adjustment_version,
     allocated_quantity_units,actual_unit_cost_cents,actual_cost_cents,source_type,source_id,request_fingerprint,occurred_at,recorded_at)
  VALUES (1301,'adjust-1',1201,1,'tenant-1',7,1,2,60,120,'receipt','receipt-1',REPEAT('e',64),1200,1200);

  START TRANSACTION;
  SELECT id FROM eb_inventory_stock WHERE id=501 FOR UPDATE;
  SELECT id FROM eb_inventory_batch WHERE id=801 FOR UPDATE;
  UPDATE eb_inventory_stock SET available_quantity_units=available_quantity_units+6,version=version+1 WHERE id=501;
  UPDATE eb_inventory_batch SET available_quantity_units=available_quantity_units+6,version=version+1 WHERE id=801;
  INSERT INTO eb_inventory_consumption_receipt
    (id,receipt_key,idempotency_key,request_fingerprint,contract_version,operation_type,reversal_of_receipt_id,
     tenant_id,organization_id,organization_path,organization_name_snapshot,store_id,store_name_snapshot,
     operator_id,source_type,source_id,source_detail_id,actual_cost_cents,estimated_shortage_cost_cents,
     cost_complete_at_settlement,result_snapshot,business_date,occurred_at,settled_at,recorded_at)
  VALUES
    (1002,'receipt-reverse-1','idem-reverse-1',REPEAT('d',64),'inventory-entitlement-completion-provider-v1','REVERSAL',1001,
     'tenant-1','org-1','/org-1/store-7','Org 1',7,'Store 7',99,'writeoff_reversal','source-r1','detail-r1',600,100,0,'{}',
     '2026-07-29',1300,1300,1300);
  INSERT INTO eb_inventory_batch_consumption_fact
    (fact_key,receipt_id,reversal_of,direction,tenant_id,organization_path,store_id,source_type,source_id,source_detail_id,
     line_id,project_id,project_name_snapshot,recipe_id,recipe_version,recipe_formula_hash,policy_value,policy_version,
     consumable_product_id,consumable_name_snapshot,sku_id,sku_name_snapshot,stock_id,batch_id,batch_no_snapshot,
     quantity_scale,quantity_units,unit_cost_cents,actual_cost_cents,cost_cursor_before,cost_cursor_after,
     batch_version_before,batch_version_after,business_date,occurred_at,settled_at,recorded_at)
  SELECT 'batch-fact-reverse-1',1002,id,-1,tenant_id,organization_path,store_id,'writeoff_reversal','source-r1','detail-r1',
     line_id,project_id,project_name_snapshot,recipe_id,recipe_version,recipe_formula_hash,policy_value,policy_version,
     consumable_product_id,consumable_name_snapshot,sku_id,sku_name_snapshot,stock_id,batch_id,batch_no_snapshot,
     quantity_scale,quantity_units,unit_cost_cents,actual_cost_cents,cost_cursor_before,cost_cursor_after,
     4,5,'2026-07-29',1300,1300,1300
  FROM eb_inventory_batch_consumption_fact WHERE id=1101;
  INSERT INTO eb_inventory_shortage_fact
    (fact_key,receipt_id,reversal_of,direction,tenant_id,organization_path,store_id,source_type,source_id,source_detail_id,
     line_id,project_id,project_name_snapshot,recipe_id,recipe_version,recipe_formula_hash,consumable_product_id,consumable_name_snapshot,
     sku_id,sku_name_snapshot,stock_id,quantity_scale,shortage_quantity_units,estimated_unit_cost_cents,
     estimated_cost_cents,cost_cursor_before,cost_cursor_after,policy_value,policy_version,
     business_date,occurred_at,settled_at,recorded_at)
  SELECT 'shortage-fact-reverse-1',1002,id,-1,tenant_id,organization_path,store_id,'writeoff_reversal','source-r1','detail-r1',
     line_id,project_id,project_name_snapshot,recipe_id,recipe_version,recipe_formula_hash,consumable_product_id,consumable_name_snapshot,
     sku_id,sku_name_snapshot,stock_id,quantity_scale,shortage_quantity_units,estimated_unit_cost_cents,
     estimated_cost_cents,cost_cursor_before,cost_cursor_after,policy_value,policy_version,
     '2026-07-29',1300,1300,1300
  FROM eb_inventory_shortage_fact WHERE id=1201;
  INSERT INTO eb_inventory_shortage_cost_adjustment
    (adjustment_key,shortage_fact_id,reversal_of,direction,tenant_id,store_id,adjustment_version,
     allocated_quantity_units,actual_unit_cost_cents,actual_cost_cents,source_type,source_id,request_fingerprint,occurred_at,recorded_at)
  VALUES ('adjust-reverse-1',1201,1301,-1,'tenant-1',7,1000000001,2,60,120,'inventory_reversal','1002',REPEAT('f',64),1300,1300);
  COMMIT;
" >/dev/null

[[ "$(mysql_value "$db" "SELECT SUM(IF(direction=1,quantity_units,-CAST(quantity_units AS SIGNED))) FROM eb_inventory_batch_consumption_fact WHERE id IN (1101,(SELECT id FROM eb_inventory_batch_consumption_fact WHERE reversal_of=1101))")" == "0" ]]
[[ "$(mysql_value "$db" "SELECT SUM(IF(direction=1,shortage_quantity_units,-CAST(shortage_quantity_units AS SIGNED))) FROM eb_inventory_shortage_fact WHERE id=1201 OR reversal_of=1201")" == "0" ]]
[[ "$(mysql_value "$db" "SELECT SUM(IF(direction=1,actual_cost_cents,-CAST(actual_cost_cents AS SIGNED))) FROM eb_inventory_shortage_cost_adjustment WHERE shortage_fact_id=1201")" == "0" ]]
[[ "$(mysql_value "$db" "SELECT available_quantity_units FROM eb_inventory_batch WHERE id=801")" == "8" ]]
echo "REVERSAL_AND_COST_ADJUSTMENT_FACTS=PASS"

mysql_sql "$db" "
  CREATE TABLE eb_store_product (
    id bigint unsigned NOT NULL, pid bigint unsigned NOT NULL DEFAULT 0,
    type tinyint unsigned NOT NULL DEFAULT 0, relation_id bigint unsigned NOT NULL DEFAULT 0,
    is_del tinyint unsigned NOT NULL DEFAULT 0, store_name varchar(120) NOT NULL DEFAULT '',
    code varchar(64) NOT NULL DEFAULT '', bar_code varchar(64) NOT NULL DEFAULT '',
    salon_stock_enabled tinyint unsigned NOT NULL DEFAULT 0, sort int NOT NULL DEFAULT 0,
    keyword varchar(255) NOT NULL DEFAULT '', is_inventory tinyint unsigned NOT NULL DEFAULT 0, PRIMARY KEY(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  CREATE TABLE eb_store_product_attr_value (
    id bigint unsigned NOT NULL, product_id bigint unsigned NOT NULL DEFAULT 0,
    \`unique\` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    suk varchar(120) NOT NULL DEFAULT '', bar_code varchar(64) NOT NULL DEFAULT '',
    code varchar(64) NOT NULL DEFAULT '', stock_unit varchar(32) NOT NULL DEFAULT '', type tinyint unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY(id), UNIQUE KEY uk_product_unique(product_id,\`unique\`), KEY idx_product_suk(product_id,suk)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  CREATE TABLE eb_store_project_consumable_recipe (
    id bigint unsigned NOT NULL, type tinyint unsigned NOT NULL DEFAULT 0,
    relation_id bigint unsigned NOT NULL DEFAULT 0, project_product_id bigint unsigned NOT NULL DEFAULT 0,
    project_unique varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    status tinyint unsigned NOT NULL DEFAULT 1, version bigint unsigned NOT NULL DEFAULT 1,
    PRIMARY KEY(id), UNIQUE KEY uk_owner_project(type,relation_id,project_product_id,project_unique)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  CREATE TABLE eb_store_project_consumable_recipe_detail (
    id bigint unsigned NOT NULL, recipe_id bigint unsigned NOT NULL DEFAULT 0,
    consumable_product_id bigint unsigned NOT NULL DEFAULT 0,
    consumable_unique varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    qty_per_writeoff decimal(18,4) NOT NULL DEFAULT 0,
    PRIMARY KEY(id), KEY idx_recipe(recipe_id,id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

  INSERT INTO eb_store_product
    (id,pid,type,relation_id,is_del,store_name,is_inventory)
  VALUES
    (100,0,0,0,0,'Platform Project',0),
    (101,100,1,7,0,'Store Project',0),
    (200,0,0,0,0,'Platform Material',1),
    (201,200,1,7,0,'Store Material',1);
  INSERT INTO eb_store_product_attr_value
    (id,product_id,\`unique\`,suk,type)
  VALUES
    (300,100,'PLATFORM-PROJECT-SKU','Project Standard',0),
    (301,101,'STORE-PROJECT-SKU','Project Standard',0),
    (302,200,'PLATFORM-MATERIAL-SKU','Material Standard',0),
    (303,201,'STORE-MATERIAL-SKU','Material Standard',0);
  INSERT INTO eb_store_project_consumable_recipe
    (id,type,relation_id,project_product_id,project_unique,status,version)
  VALUES (901,0,0,100,'PLATFORM-PROJECT-SKU',1,6);
  INSERT INTO eb_store_project_consumable_recipe_detail
    (id,recipe_id,consumable_product_id,consumable_unique,qty_per_writeoff)
  VALUES (902,901,200,'PLATFORM-MATERIAL-SKU',5.0000);
  INSERT INTO eb_inventory_shortage_policy
    (tenant_id,policy_scope,project_id,policy_value,version,updated_by,created_at,updated_at)
  VALUES ('tenant-1','PROJECT',101,'allow_shortage',6,99,1900,1900);
  INSERT INTO eb_inventory_stock
    (id,tenant_id,organization_id,organization_path,store_id,consumable_product_id,sku_id,
     product_unique,stock_status,stock_unit,quantity_scale,available_quantity_units,
     estimated_unit_cost_cents,version,created_at,updated_at)
  VALUES
    (502,'tenant-1','org-1','/org-1/store-7',7,201,303,'STORE-MATERIAL-SKU','GOOD','piece',0,3,50,1,1900,1900);
  INSERT INTO eb_inventory_batch
    (id,stock_id,batch_no,manufactured_date,expire_date,received_at,available_quantity_units,
     unit_cost_cents,cost_allocated_quantity_units,batch_status,version,created_at,updated_at)
  VALUES
    (802,502,'BATCH-PROVIDER-001','2026-01-01','2027-01-01',1900,3,100,0,'ACTIVE',1,1900,1900);
" >/dev/null

mysql_file "$db" "$query_upgrade_dir/01-升级前检查.sql" >/dev/null
mysql_file "$db" "$query_upgrade_dir/02-正式升级.sql" >/dev/null
mysql_file "$db" "$query_upgrade_dir/02-正式升级.sql" >/dev/null
mysql_file "$db" "$query_upgrade_dir/01-升级前检查.sql" >/dev/null
echo "QUERY_FACT_MIGRATION=PASS"

php_image="$(docker inspect --format '{{.Config.Image}}' mohe-app)"
mysql_file "$db" "$repo_dir/tests/inventory/sql/manual-inbound-fixture.sql" >/dev/null
mysql_sql "$db" "
  CREATE TABLE IF NOT EXISTS eb_system_menus (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    pid bigint(20) unsigned NOT NULL DEFAULT 0,
    type tinyint(2) NOT NULL DEFAULT 1,
    icon varchar(64) NOT NULL DEFAULT '',
    menu_name varchar(128) NOT NULL DEFAULT '',
    module varchar(64) NOT NULL DEFAULT '',
    controller varchar(128) NOT NULL DEFAULT '',
    action varchar(128) NOT NULL DEFAULT '',
    api_url varchar(255) NOT NULL DEFAULT '',
    methods varchar(32) NOT NULL DEFAULT '',
    params text,
    sort int(11) NOT NULL DEFAULT 0,
    is_show tinyint(1) NOT NULL DEFAULT 0,
    is_show_path tinyint(1) NOT NULL DEFAULT 0,
    access tinyint(1) NOT NULL DEFAULT 1,
    menu_path varchar(255) NOT NULL DEFAULT '',
    path varchar(255) NOT NULL DEFAULT '',
    auth_type tinyint(1) NOT NULL DEFAULT 1,
    header varchar(64) NOT NULL DEFAULT '',
    is_header tinyint(1) NOT NULL DEFAULT 0,
    unique_auth varchar(128) NOT NULL DEFAULT '',
    is_del tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_unique_auth (unique_auth,is_del,type)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
" >/dev/null
mysql_file "$db" "$warehouse_upgrade_dir/01-升级前检查.sql" >/dev/null
mysql_file "$db" "$warehouse_upgrade_dir/02-正式升级.sql" >/dev/null
mysql_file "$db" "$warehouse_upgrade_dir/02-正式升级.sql" >/dev/null
mysql_file "$db" "$warehouse_upgrade_dir/03-升级后验证.sql" >/dev/null
echo "WAREHOUSE_CREATE_MIGRATION=PASS"
manual_inbound_output="$(docker run --rm --cpus 1 --memory 256m \
  --network "container:$container" \
  --volume "$repo_dir:/workspace:ro" \
  --env CACHE_DRIVER=file \
  --env PHP_CACHE_DRIVER=file \
  --env DB_HOST=127.0.0.1 \
  --env DB_PORT=3306 \
  --env DB_DATABASE="$db" \
  --env DB_USERNAME=root \
  --env DB_PASSWORD= \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/manual-inbound-integration.php 2>&1)" || {
    echo "$manual_inbound_output" >&2
    exit 1
  }
echo "$manual_inbound_output"
echo "$manual_inbound_output" | grep 'INVENTORY_MANUAL_INBOUND_RESULT failed=0' >/dev/null
echo "THINKPHP_MANUAL_INBOUND_INTEGRATION=PASS"

warehouse_command_output="$(docker run --rm --cpus 1 --memory 256m \
  --network "container:$container" \
  --volume "$repo_dir:/workspace:ro" \
  --env CACHE_DRIVER=file \
  --env PHP_CACHE_DRIVER=file \
  --env DB_HOST=127.0.0.1 \
  --env DB_PORT=3306 \
  --env DB_DATABASE="$db" \
  --env DB_USERNAME=root \
  --env DB_PASSWORD= \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/warehouse-command-integration.php 2>&1)" || {
    echo "$warehouse_command_output" >&2
    exit 1
  }
echo "$warehouse_command_output"
echo "$warehouse_command_output" | grep 'INVENTORY_WAREHOUSE_COMMAND_RESULT failed=0' >/dev/null
echo "THINKPHP_WAREHOUSE_COMMAND_INTEGRATION=PASS"

provider_output="$(docker run --rm --cpus 1 --memory 256m \
  --network "container:$container" \
  --volume "$repo_dir:/workspace:ro" \
  --env CACHE_DRIVER=file \
  --env PHP_CACHE_DRIVER=file \
  --env DB_HOST=127.0.0.1 \
  --env DB_PORT=3306 \
  --env DB_DATABASE="$db" \
  --env DB_USERNAME=root \
  --env DB_PASSWORD= \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/provider-integration.php 2>&1)" || {
    echo "$provider_output" >&2
    exit 1
  }
echo "$provider_output"
echo "$provider_output" | grep 'INVENTORY_PROVIDER_INTEGRATION_RESULT passed=' >/dev/null
echo "$provider_output" | grep 'failed=0' >/dev/null
echo "THINKPHP_PROVIDER_INTEGRATION=PASS"

# The v002 verifier is intentionally version-specific and already ran before
# v003 replaced its store-level stock unique key with the location-level key.
mysql_file "$db" "$query_upgrade_dir/03-升级后验证.sql" >/dev/null
[[ "$(mysql_value "$db" "SELECT COUNT(*) FROM eb_inventory_batch_movement_fact WHERE source_type='completion_batch'")" == "2" ]]
echo "COMPLETION_MOVEMENT_RECONCILIATION=PASS"
echo "NONEMPTY_VERIFY=PASS"
echo "INVENTORY_MYSQL56_MATRIX=PASS"

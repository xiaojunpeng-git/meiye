#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
base_upgrade="$repo_dir/后端代码/database/upgrades/2026-07-29-库存耗材批次完成合同"
query_upgrade="$repo_dir/后端代码/database/upgrades/2026-07-29-库存统一查询批次事实"
init_sql="$repo_dir/后端代码/database/upgrades/0000-升级登记表初始化.sql"
container="inventory-batch-query-mysql56-$$"
image="docker.m.daocloud.io/library/mysql:5.6.51"
failure_log="$(mktemp /tmp/inventory-batch-query-mysql56.XXXXXX)"

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
  --tmpfs /var/lib/mysql:rw,size=512m \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$image" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --sql-mode=STRICT_ALL_TABLES \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  if docker logs "$container" 2>&1 | grep 'MySQL init process done' >/dev/null; then break; fi
  sleep 1
done
docker logs "$container" 2>&1 | grep 'MySQL init process done' >/dev/null
for _ in $(seq 1 90); do
  if docker exec "$container" mysqladmin ping -uroot --silent >/dev/null 2>&1; then break; fi
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

db="inventory_batch_query"
docker exec "$container" mysql -uroot -e "CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql_file "$db" "$init_sql" >/dev/null
mysql_file "$db" "$base_upgrade/01-升级前检查.sql" >/dev/null
mysql_file "$db" "$base_upgrade/02-正式升级.sql" >/dev/null

mysql_sql "$db" "
  INSERT INTO eb_inventory_stock
    (id,tenant_id,organization_id,organization_path,store_id,consumable_product_id,sku_id,
     product_unique,stock_status,stock_unit,quantity_scale,available_quantity_units,
     estimated_unit_cost_cents,version,created_at,updated_at)
  VALUES
    (501,'tenant-1','org-1','/org-1/store-7',7,601,701,'sku-701','GOOD','盒',0,10,7700,1,2000,2000),
    (502,'tenant-1','org-1','/org-1/store-8',8,602,702,'sku-702','GOOD','瓶',1,25,108,1,2000,2000);
  INSERT INTO eb_inventory_batch
    (id,stock_id,batch_no,manufactured_date,expire_date,received_at,available_quantity_units,
     unit_cost_cents,cost_allocated_quantity_units,batch_status,version,created_at,updated_at)
  VALUES
    (801,501,'BATCH-001','2026-01-01','2026-07-29',2000,10,7700,0,'ACTIVE',1,2000,2000),
    (802,502,'BATCH-002',NULL,NULL,2000,25,108,0,'ACTIVE',1,2000,2000);
" >/dev/null

mysql_file "$db" "$query_upgrade/01-升级前检查.sql" >/dev/null
mysql_file "$db" "$query_upgrade/02-正式升级.sql" >/dev/null
mysql_file "$db" "$query_upgrade/02-正式升级.sql" >/dev/null
mysql_file "$db" "$query_upgrade/01-升级前检查.sql" >/dev/null
[[ "$(mysql_value "$db" "SELECT GROUP_CONCAT(CONCAT(id,':',data_quality) ORDER BY id) FROM eb_inventory_batch")" == "801:HISTORICAL_UNKNOWN,802:HISTORICAL_UNKNOWN" ]]
[[ "$(mysql_value "$db" "SELECT GROUP_CONCAT(CONCAT(batch_id,':',cost_amount_cents) ORDER BY batch_id) FROM eb_inventory_batch_movement_fact")" == "801:77000,802:270" ]]
echo "OPENING_QUALITY_AND_COST=PASS"

mysql_sql "$db" "
  UPDATE eb_inventory_location SET
    organization_name_snapshot='成都区域',
    location_name=IF(store_id=7,'锦江门店仓','高新门店仓'),
    store_name_snapshot=IF(store_id=7,'锦江门店','高新门店');
  UPDATE eb_inventory_batch SET
    received_business_date='2026-07-20',product_name_snapshot='海藻修护面膜',
    sku_name_snapshot='盒装10片',product_code_snapshot='P601',barcode_snapshot='6901234567890',
    brand_name_snapshot='海蓝',category_name_snapshot='面膜',source_order_no_snapshot='RK-001',
    data_quality='COMPLETE'
  WHERE id=801;
  UPDATE eb_inventory_batch SET
    received_business_date='2026-07-20',product_name_snapshot='胶原精华液',
    sku_name_snapshot='500ml',product_code_snapshot='P602',barcode_snapshot='6901234567814',
    brand_name_snapshot='研肌',category_name_snapshot='精华',source_order_no_snapshot='RK-002',
    data_quality='HISTORICAL_UNKNOWN'
  WHERE id=802;
  INSERT INTO eb_inventory_batch_movement_fact
    (fact_key,tenant_id,organization_id,organization_path,location_id,store_id,stock_id,batch_id,
     direction,quantity_units,unit_cost_cents,cost_amount_cents,fact_status,source_type,source_id,
     source_detail_id,reversal_of,business_date,occurred_at,settled_at,recorded_at)
  SELECT 'movement:801:out','tenant-1','org-1','/org-1/store-7',location_id,7,501,801,
     -1,2,7700,15400,'SETTLED','manual_out','OUT-001','OUT-001-1',0,'2026-07-30',2100,2100,2100
  FROM eb_inventory_stock WHERE id=501;
  UPDATE eb_inventory_stock SET available_quantity_units=8,version=2 WHERE id=501;
  UPDATE eb_inventory_batch SET available_quantity_units=8,version=2 WHERE id=801;
" >/dev/null

mysql_file "$db" "$query_upgrade/03-升级后验证.sql" >/dev/null
echo "FRESH_REPLAY_AND_VERIFY=PASS"

partial_db="inventory_batch_query_partial"
docker exec "$container" mysql -uroot -e "CREATE DATABASE \`$partial_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql_file "$partial_db" "$init_sql" >/dev/null
mysql_file "$partial_db" "$base_upgrade/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" "CREATE TABLE eb_inventory_location(id bigint unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB" >/dev/null
if mysql_file "$partial_db" "$query_upgrade/01-升级前检查.sql" >"$failure_log" 2>&1; then
  echo "PARTIAL_PRECHECK_DID_NOT_FAIL" >&2
  exit 1
fi
: > "$failure_log"
echo "PARTIAL_PRECHECK=PASS"

explain_key="$(mysql_value "$db" "
  EXPLAIN SELECT f.batch_id,SUM(IF(f.direction=1,f.quantity_units,-CAST(f.quantity_units AS SIGNED))) qty
  FROM eb_inventory_batch_movement_fact f
  WHERE f.tenant_id='tenant-1' AND f.location_id=1 AND f.business_date<='2026-07-30' AND f.fact_status='SETTLED'
  GROUP BY f.batch_id;
" | awk 'NR==1 {print $6}')"
[[ "$explain_key" == "idx_scope_cutoff_batch" ]]
echo "CUTOFF_EXPLAIN_INDEX=PASS"

php_image="$(docker inspect --format '{{.Config.Image}}' mohe-app)"
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
  /workspace/tests/inventory/php/batch-query-provider.php 2>&1)" || {
    echo "$provider_output" >&2
    exit 1
  }
echo "$provider_output"
echo "$provider_output" | grep 'INVENTORY_BATCH_QUERY_PROVIDER_RESULT passed=' >/dev/null
echo "$provider_output" | grep 'failed=0' >/dev/null
echo "THINKPHP_BATCH_QUERY_PROVIDER=PASS"
echo "INVENTORY_BATCH_QUERY_MYSQL56=PASS"

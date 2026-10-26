#!/usr/bin/env bash
set -euo pipefail

package_dir="$(cd "$(dirname "$0")" && pwd)"
upgrades_dir="$(cd "$package_dir/.." && pwd)"
container_name="c5o1-sales-mysql56-$$"
mysql_image="docker.m.daocloud.io/library/mysql:5.6.51"
failure_log="$(mktemp /tmp/c5o1-sales-matrix.XXXXXX)"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$container_name" >&2 || true
  fi
  docker rm -f "$container_name" >/dev/null 2>&1 || true
  rm -f "$failure_log"
}
trap cleanup EXIT INT TERM

(cd "$package_dir" && shasum -a 256 -c SHA256SUMS.txt)

docker run -d --name "$container_name" \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$mysql_image" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  if docker logs "$container_name" 2>&1 \
      | grep -F 'MySQL init process done. Ready for start up.' >/dev/null \
      && docker exec "$container_name" mysqladmin ping -uroot --silent >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
docker exec "$container_name" mysqladmin ping -uroot --silent >/dev/null

version="$(docker exec "$container_name" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$version" == "5.6.51" ]] || { echo "VERSION_MISMATCH=$version" >&2; exit 1; }
echo "MYSQL_VERSION=$version"
echo "MYSQL_IMAGE=$mysql_image"

mysql_file() {
  local db="$1" file="$2"
  docker exec -i "$container_name" mysql -uroot --database="$db" < "$file"
}

mysql_sql() {
  local db="$1" sql="$2"
  docker exec "$container_name" mysql -uroot --database="$db" -e "$sql"
}

mysql_tsv() {
  local db="$1" sql="$2"
  docker exec "$container_name" mysql -uroot --batch --skip-column-names --database="$db" -e "$sql"
}

init_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e \
    "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$upgrades_dir/0000-升级登记表初始化.sql" >/dev/null
  mysql_sql "$db" "
    CREATE TABLE eb_store_order (
      id int(11) unsigned NOT NULL,
      order_id varchar(64) NOT NULL DEFAULT '',
      uid int(11) unsigned NOT NULL DEFAULT 0,
      real_name varchar(64) NOT NULL DEFAULT '',
      user_phone varchar(20) NOT NULL DEFAULT '',
      store_id int(11) unsigned NOT NULL DEFAULT 0,
      order_type tinyint(3) unsigned NOT NULL DEFAULT 0,
      is_debt_repay tinyint(1) unsigned NOT NULL DEFAULT 0,
      paid tinyint(1) unsigned NOT NULL DEFAULT 0,
      is_del tinyint(1) unsigned NOT NULL DEFAULT 0,
      is_system_del tinyint(1) unsigned NOT NULL DEFAULT 0,
      pid int(11) NOT NULL DEFAULT 0,
      terminal_action tinyint(3) unsigned NOT NULL DEFAULT 0,
      refund_status tinyint(3) unsigned NOT NULL DEFAULT 0,
      pay_time int(11) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE eb_store_order_cart_info (
      id int(11) unsigned NOT NULL,
      oid int(11) unsigned NOT NULL DEFAULT 0,
      cart_type tinyint(3) unsigned NOT NULL DEFAULT 0,
      is_gift tinyint(1) unsigned NOT NULL DEFAULT 0,
      cart_info longtext NOT NULL,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  " >/dev/null
}

drop_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e "DROP DATABASE IF EXISTS \`$db\`;"
}

expect_fail_file() {
  local db="$1" file="$2" expected_code="$3" marker="$4"
  if mysql_file "$db" "$file" >"$failure_log" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  if ! grep -F "$expected_code" "$failure_log" >/dev/null; then
    echo "EXPECTED_FAILURE_CODE_MISSING=$marker/$expected_code" >&2
    cat "$failure_log" >&2
    exit 1
  fi
  echo "$marker=PASS"
}

seed_volume() {
  local db="$1"
  mysql_sql "$db" "
    CREATE TABLE _c5o1_digits (n tinyint unsigned NOT NULL PRIMARY KEY) ENGINE=Memory;
    INSERT INTO _c5o1_digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9);
    INSERT INTO eb_store_order
      (id,order_id,uid,real_name,user_phone,store_id,order_type,is_debt_repay,
       paid,is_del,is_system_del,pid,terminal_action,refund_status,pay_time)
    SELECT n+1,CONCAT('SO-',n+1),n+1000,CONCAT('会员',n+1),'13800000000',
      IF(MOD(n,2)=0,1,2),0,0,1,IF(MOD(n,17)=0,1,0),0,0,
      IF(MOD(n,211)=0,2,0),IF(MOD(n,97)=0,2,0),1800000000-FLOOR(n/2)
    FROM (
      SELECT a.n + b.n*10 + c.n*100 + d.n*1000 + e.n*10000 AS n
      FROM _c5o1_digits a CROSS JOIN _c5o1_digits b CROSS JOIN _c5o1_digits c
      CROSS JOIN _c5o1_digits d CROSS JOIN _c5o1_digits e
    ) numbers
    WHERE n < 20000;
    INSERT INTO eb_store_order_cart_info (id,oid,cart_type,is_gift,cart_info)
    SELECT id,id,IF(MOD(id,23)=0,3,0),0,
      IF(MOD(id,101)=0,'{\"name\":\"target-keyword\"}','{\"name\":\"normal\"}')
    FROM eb_store_order;
    INSERT INTO eb_store_order_cart_info (id,oid,cart_type,is_gift,cart_info)
    SELECT 30000+id,id,2,1,'{\"name\":\"gift-shell-child\"}'
    FROM eb_store_order WHERE MOD(id,100)=0;
    DROP TABLE _c5o1_digits;
  " >/dev/null
  local order_rows cart_rows
  order_rows="$(mysql_tsv "$db" 'SELECT COUNT(*) FROM eb_store_order')"
  cart_rows="$(mysql_tsv "$db" 'SELECT COUNT(*) FROM eb_store_order_cart_info')"
  [[ "$order_rows" == "20000" && "$cart_rows" == "20200" ]] \
    || { echo "SEED_COUNT_INVALID=$order_rows/$cart_rows" >&2; exit 1; }
  echo "VOLUME_SEED=PASS orders=$order_rows cart_rows=$cart_rows"
}

assert_plan() {
  local label="$1" plan="$2" max_order_rows="$3"
  local order_key order_rows
  order_key="$(awk -F $'\t' '$3=="o" {print $6; exit}' <<<"$plan")"
  order_rows="$(awk -F $'\t' '$3=="o" {print $9; exit}' <<<"$plan")"
  [[ "$order_key" == "idx_c5_sales_order_read" ]] \
    || { echo "${label}_ORDER_KEY=$order_key" >&2; echo "$plan" >&2; exit 1; }
  [[ "$order_rows" =~ ^[0-9]+$ && "$order_rows" -le "$max_order_rows" ]] \
    || { echo "${label}_ORDER_ROWS=$order_rows" >&2; echo "$plan" >&2; exit 1; }
  if grep -q $'\tp\t' <<<"$plan"; then
    awk -F $'\t' '$3=="p" && $6!="idx_c5_sales_cart_read" {exit 1}' <<<"$plan"
  fi
  if grep -q $'\tg\t' <<<"$plan"; then
    awk -F $'\t' '$3=="g" && $6!="idx_c5_sales_cart_read" {exit 1}' <<<"$plan"
  fi
  if grep -q $'\ts\t' <<<"$plan"; then
    awk -F $'\t' '$3=="s" && $6!="idx_c5_sales_cart_read" {exit 1}' <<<"$plan"
  fi
  echo "${label}=PASS key=$order_key rows=$order_rows"
}

fresh_db="c5o1_sales_fresh"
init_db "$fresh_db"
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/03-升级后验证.sql" | grep -F POSTCHECK_OK >/dev/null
seed_volume "$fresh_db"

main_plan="$(mysql_tsv "$fresh_db" "
  EXPLAIN SELECT o.id,o.order_id,o.pay_time
  FROM eb_store_order o
  WHERE o.store_id=1 AND o.order_type=0 AND o.is_debt_repay=0
    AND o.paid=1 AND o.is_system_del=0 AND o.pay_time<=1800000000 AND o.id<=20000
    AND (o.pid>=0 OR o.pid=-2)
    AND EXISTS (
      SELECT 1 FROM eb_store_order_cart_info p
      WHERE p.oid=o.id AND IFNULL(p.cart_type,0) IN (0,3) AND IFNULL(p.is_gift,0)=0
    )
  ORDER BY o.pay_time DESC,o.id DESC LIMIT 101;
")"
assert_plan "MAIN_LIST_EXPLAIN" "$main_plan" 12000

keyset_plan="$(mysql_tsv "$fresh_db" "
  EXPLAIN SELECT o.id,o.pay_time
  FROM eb_store_order o
  WHERE o.store_id=1 AND o.order_type=0 AND o.is_debt_repay=0
    AND o.paid=1 AND o.is_system_del=0 AND o.pay_time<=1800000000 AND o.id<=20000
    AND (o.pay_time<1799999500 OR (o.pay_time=1799999500 AND o.id<1000))
    AND EXISTS (
      SELECT 1 FROM eb_store_order_cart_info p
      WHERE p.oid=o.id AND p.cart_type IN (0,3) AND p.is_gift=0
    )
  ORDER BY o.pay_time DESC,o.id DESC LIMIT 101;
")"
assert_plan "KEYSET_EXPLAIN" "$keyset_plan" 12000

keyword_plan="$(mysql_tsv "$fresh_db" "
  EXPLAIN SELECT o.id,o.pay_time
  FROM eb_store_order o
  WHERE o.store_id=1 AND o.order_type=0 AND o.is_debt_repay=0
    AND o.paid=1 AND o.is_system_del=0
    AND o.pay_time BETWEEN 1799990000 AND 1800000000 AND o.id<=20000
    AND (
      o.order_id LIKE '%target-keyword%'
      OR o.real_name LIKE '%target-keyword%'
      OR o.user_phone LIKE '%target-keyword%'
      OR EXISTS (
        SELECT 1 FROM eb_store_order_cart_info s
        WHERE s.oid=o.id AND s.cart_type IN (0,3) AND s.is_gift=0
          AND s.cart_info LIKE '%target-keyword%'
      )
    )
  ORDER BY o.pay_time DESC,o.id DESC LIMIT 101;
")"
assert_plan "KEYWORD_EXPLAIN" "$keyword_plan" 12000

line_plan="$(mysql_tsv "$fresh_db" "
  EXPLAIN SELECT id,oid,cart_type,is_gift
  FROM eb_store_order_cart_info
  WHERE oid IN (1,2,3,4,5,6,7,8,9,10)
    AND cart_type IN (0,3) AND is_gift=0
  ORDER BY oid ASC,id ASC;
")"
line_key="$(awk -F $'\t' 'NR==1 {print $6}' <<<"$line_plan")"
line_rows="$(awk -F $'\t' 'NR==1 {print $9}' <<<"$line_plan")"
[[ "$line_key" == "idx_c5_sales_cart_read" && "$line_rows" =~ ^[0-9]+$ && "$line_rows" -le 20 ]] \
  || { echo "LINE_FILTER_PLAN_INVALID=$line_key/$line_rows" >&2; echo "$line_plan" >&2; exit 1; }
echo "LINE_FILTER_EXPLAIN=PASS key=$line_key rows=$line_rows"
drop_db "$fresh_db"

partial_db="c5o1_sales_partial"
init_db "$partial_db"
mysql_sql "$partial_db" "ALTER TABLE eb_store_order ADD KEY idx_c5_sales_order_read (store_id,order_type,is_debt_repay,paid,is_system_del,pay_time,id)" >/dev/null
mysql_file "$partial_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$partial_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$partial_db" "$package_dir/03-升级后验证.sql" >/dev/null
echo "PARTIAL_DDL_RECOVERY=PASS"
drop_db "$partial_db"

wrong_index_db="c5o1_sales_wrong_index"
init_db "$wrong_index_db"
mysql_sql "$wrong_index_db" "ALTER TABLE eb_store_order ADD KEY idx_c5_sales_order_read (store_id,id)" >/dev/null
expect_fail_file "$wrong_index_db" "$package_dir/01-升级前检查.sql" \
  "C5O1_ORDER_INDEX_INVALID" "WRONG_NAMED_INDEX_REJECTED"
drop_db "$wrong_index_db"

missing_table_db="c5o1_sales_missing_table"
init_db "$missing_table_db"
mysql_sql "$missing_table_db" "DROP TABLE eb_store_order_cart_info" >/dev/null
expect_fail_file "$missing_table_db" "$package_dir/01-升级前检查.sql" \
  "C5O1_MISSING_AUTHORITY_TABLE" "MISSING_AUTHORITY_TABLE_REJECTED"
drop_db "$missing_table_db"

registered_db="c5o1_sales_registered"
init_db "$registered_db"
mysql_sql "$registered_db" "
  INSERT INTO eb_database_upgrade_log
    (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
  VALUES
    ('20260729-001-c5-sales-order-read-index','matrix','','','local',NOW(),'Codex','registered');
" >/dev/null
expect_fail_file "$registered_db" "$package_dir/01-升级前检查.sql" \
  "C5O1_UPGRADE_KEY_USED" "REGISTERED_KEY_REJECTED"
drop_db "$registered_db"

echo "C5_O1_SALES_MYSQL56_MATRIX=PASS"
echo "GATE_PASS=C5-O1-SQL-01 C5-O1-SQL-02 C5-O1-SQL-03 C5-O1-EXPLAIN-01"

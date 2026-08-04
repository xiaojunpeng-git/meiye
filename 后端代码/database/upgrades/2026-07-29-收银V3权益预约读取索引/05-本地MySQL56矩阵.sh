#!/usr/bin/env bash
set -euo pipefail

package_dir="$(cd "$(dirname "$0")" && pwd)"
upgrades_dir="$(cd "$package_dir/.." && pwd)"
container_name="cashier-v3-reservation-index-mysql56-$$"
mysql_image="docker.m.daocloud.io/library/mysql:5.6.51"
failure_log="$(mktemp /private/tmp/cashier-v3-reservation-index.XXXXXX)"

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
  if docker logs "$container_name" 2>&1 | grep -F 'Ready for start up.' >/dev/null \
    && docker exec "$container_name" mysqladmin ping -uroot --silent >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
docker exec "$container_name" mysqladmin ping -uroot --silent >/dev/null

mysql_file() {
  local db="$1" file="$2"
  docker exec -i "$container_name" mysql -uroot --database="$db" < "$file"
}

mysql_sql() {
  local db="$1" sql="$2"
  docker exec "$container_name" mysql -uroot --database="$db" -e "$sql"
}

init_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$upgrades_dir/0000-升级登记表初始化.sql" >/dev/null
  mysql_sql "$db" "
    CREATE TABLE eb_store_reservation_order (
      id int(11) unsigned NOT NULL,
      cart_info_id int(11) NOT NULL DEFAULT 0,
      status tinyint(3) unsigned NOT NULL DEFAULT 0,
      is_del tinyint(1) unsigned NOT NULL DEFAULT 0,
      is_system_del tinyint(1) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  " >/dev/null
}

expect_fail() {
  local db="$1" marker="$2"
  if mysql_file "$db" "$package_dir/01-升级前检查.sql" >"$failure_log" 2>&1; then
    echo "EXPECTED_PRECHECK_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  grep -F "$marker" "$failure_log" >/dev/null
  echo "$marker=PASS"
}

fresh_db="cashier_v3_reservation_index_fresh"
init_db "$fresh_db"
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql" | grep -F PRECHECK_OK >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" | grep -F APPLY_OK >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" | grep -F APPLY_OK >/dev/null
mysql_file "$fresh_db" "$package_dir/03-升级后验证.sql" | grep -F POSTCHECK_OK >/dev/null
plan="$(docker exec "$container_name" mysql -uroot -N --database="$fresh_db" -e "EXPLAIN SELECT id,cart_info_id FROM eb_store_reservation_order WHERE cart_info_id=7 ORDER BY id ASC FOR UPDATE;")"
awk -F $'\t' '$6=="idx_c2_entitlement_cart_info" { found=1 } END { exit(found ? 0 : 1) }' <<<"$plan"
echo "FRESH_APPLY_AND_EXPLAIN=PASS"

equivalent_db="cashier_v3_reservation_index_equivalent"
init_db "$equivalent_db"
mysql_sql "$equivalent_db" "ALTER TABLE eb_store_reservation_order ADD KEY idx_existing_cart_lock (cart_info_id,id)" >/dev/null
mysql_file "$equivalent_db" "$package_dir/01-升级前检查.sql" | grep -F PRECHECK_OK >/dev/null
mysql_file "$equivalent_db" "$package_dir/02-正式升级.sql" | grep -F APPLY_OK >/dev/null
mysql_file "$equivalent_db" "$package_dir/03-升级后验证.sql" | grep -F POSTCHECK_OK >/dev/null
echo "EQUIVALENT_INDEX_NOOP=PASS"

wrong_named_db="cashier_v3_reservation_index_wrong_named"
init_db "$wrong_named_db"
mysql_sql "$wrong_named_db" "ALTER TABLE eb_store_reservation_order ADD KEY idx_c2_entitlement_cart_info (cart_info_id)" >/dev/null
expect_fail "$wrong_named_db" "STOP_CASHIER_V3_ENTITLEMENT_RESERVATION_INDEX_PRECHECK_FAILED"

echo "MYSQL56_RESERVATION_INDEX_MATRIX=PASS"

#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
upgrade_dir="$repo_dir/后端代码/database/upgrades/2026-07-29-C3服务单权益占用权威源"
generic_line_upgrade_dir="$repo_dir/后端代码/database/upgrades/2026-07-29-C3服务单通用项目明细"
init_sql="$repo_dir/后端代码/database/upgrades/0000-升级登记表初始化.sql"
mysql_image="${C3_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
php_image="${C3_PHP_IMAGE:-$(docker inspect --format '{{.Config.Image}}' mohe-app)}"
mysql_container="c3-service-order-mysql56-$$"
tmp_root="$repo_dir/tests/c3-service-order-authority/_tmp"
mkdir -p "$tmp_root"
work_dir="$(mktemp -d "$tmp_root/mysql56.XXXXXX")"
failure_log="$work_dir/expected-failure.log"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$mysql_container" >&2 || true
    for file in "$work_dir"/*.out "$failure_log"; do
      [[ -f "$file" ]] && { echo "== $file ==" >&2; sed -n '1,240p' "$file" >&2; }
    done
  fi
  docker rm -fv "$mysql_container" >/dev/null 2>&1 || true
  rm -rf "$work_dir"
}
trap cleanup EXIT INT TERM

docker run -d --name "$mysql_container" --platform linux/amd64 --cpus 1 --memory 768m \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$mysql_image" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --sql-mode=STRICT_ALL_TABLES \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  docker logs "$mysql_container" 2>&1 | grep 'MySQL init process done' >/dev/null && break
  sleep 1
done
docker logs "$mysql_container" 2>&1 | grep 'MySQL init process done' >/dev/null
for _ in $(seq 1 90); do
  docker exec "$mysql_container" mysqladmin ping -uroot --silent >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$mysql_container" mysqladmin ping -uroot --silent >/dev/null
mysql_version="$(docker exec "$mysql_container" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$mysql_version" == "5.6.51" ]]
echo "MYSQL_VERSION=$mysql_version"
echo "MYSQL_IMAGE=$mysql_image"
echo "PHP_IMAGE=$php_image"

mysql_file() {
  local db="$1" file="$2"
  docker exec -i "$mysql_container" mysql -uroot --database="$db" < "$file"
}
mysql_sql() {
  local db="$1" sql="$2"
  docker exec "$mysql_container" mysql -uroot --database="$db" -e "$sql"
}
mysql_value() {
  local db="$1" sql="$2"
  docker exec "$mysql_container" mysql -uroot --database="$db" -Nse "$sql"
}
init_db() {
  local db="$1"
  docker exec "$mysql_container" mysql -uroot -e \
    "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$init_sql" >/dev/null
}
expect_failure() {
  local db="$1" file="$2" marker="$3"
  if mysql_file "$db" "$file" >"$failure_log" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  grep -q "$marker" "$failure_log"
  : > "$failure_log"
  echo "$marker=PASS"
}
run_php() {
  local script="$1"
  shift
  docker run --rm --cpus 1 --memory 256m \
    --network "container:$mysql_container" \
    --volume "$repo_dir:/workspace:ro" \
    --env C3_BACKEND_ROOT=/workspace/后端代码 \
    --env CACHE_DRIVER=file --env PHP_CACHE_DRIVER=file \
    --env DB_HOST=127.0.0.1 --env DB_PORT=3306 \
    --env DB_DATABASE=c3_service_order --env DB_USERNAME=root --env DB_PASSWORD= \
    --entrypoint php "$php_image" "$script" "$@"
}

db='c3_service_order'
init_db "$db"
mysql_file "$db" "$upgrade_dir/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$db" "$upgrade_dir/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$db" "$upgrade_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
mysql_file "$db" "$upgrade_dir/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$db" "$upgrade_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo "C3_FRESH_REPLAY=PASS"

# The production occupation provider reads the generic source identity and
# quantity snapshots introduced after the base C3 authority tables.  Install
# that additive dependency before the real repository integration path.
mysql_file "$db" "$generic_line_upgrade_dir/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$db" "$generic_line_upgrade_dir/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$db" "$generic_line_upgrade_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo "C3_GENERIC_SERVICE_LINE_DEPENDENCY=PASS"

partial_db='c3_service_order_partial'
init_db "$partial_db"
mysql_file "$partial_db" "$upgrade_dir/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" 'DROP TABLE eb_cashier_v3_service_order_operation,eb_cashier_v3_service_order_line,eb_cashier_v3_service_order_entitlement_guard' >/dev/null
expect_failure "$partial_db" "$upgrade_dir/01-升级前检查.sql" 'STOP_C3_SERVICE_ORDER_PRECHECK_FAILED'
mysql_file "$partial_db" "$upgrade_dir/05-部分创表恢复.sql" | grep -q 'PARTIAL_DDL_RECOVERY_READY'
mysql_file "$partial_db" "$upgrade_dir/02-正式升级.sql" >/dev/null
mysql_file "$partial_db" "$upgrade_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo "C3_PARTIAL_EMPTY_RECOVERY=PASS"

malformed_db='c3_service_order_malformed'
init_db "$malformed_db"
mysql_sql "$malformed_db" 'CREATE TABLE eb_cashier_v3_service_order (id bigint unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci' >/dev/null
expect_failure "$malformed_db" "$upgrade_dir/05-部分创表恢复.sql" 'STOP_C3_SERVICE_ORDER_PARTIAL_RECOVERY_FAILED'
echo "C3_PARTIAL_MALFORMED_FAIL_CLOSED=PASS"

nonempty_db='c3_service_order_nonempty'
init_db "$nonempty_db"
mysql_file "$nonempty_db" "$upgrade_dir/02-正式升级.sql" >/dev/null
mysql_sql "$nonempty_db" 'DROP TABLE eb_cashier_v3_service_order_operation,eb_cashier_v3_service_order_line,eb_cashier_v3_service_order_entitlement_guard' >/dev/null
mysql_sql "$nonempty_db" "INSERT INTO eb_cashier_v3_service_order (service_order_no,tenant_id,business_store_id,member_id,participant_employee_ids_json,status,version,business_date,occurred_at,recorded_at,created_by_staff_id,created_by_employee_id,created_at,updated_at) VALUES ('PARTIAL','tenant-1',7,0,'[]','OPEN',1,'2026-07-29',1,1,1,1,1,1)" >/dev/null
expect_failure "$nonempty_db" "$upgrade_dir/05-部分创表恢复.sql" 'STOP_C3_SERVICE_ORDER_PARTIAL_RECOVERY_FAILED'
echo "C3_PARTIAL_NONEMPTY_FAIL_CLOSED=PASS"

run_php /workspace/tests/c3-service-order-authority/php/mysql-integration.php | tee "$work_dir/integration.out"
grep -q 'C3_MYSQL_INTEGRATION.*failed=0' "$work_dir/integration.out"

explain_key="$(docker exec "$mysql_container" mysql -uroot --database="$db" -Nse \
  "EXPLAIN SELECT id,service_order_id,status FROM eb_cashier_v3_service_order_line WHERE tenant_id='tenant-1' AND entitlement_source_detail_id=501 ORDER BY service_order_id,id" | awk -F '\t' 'NR==1 {print $6}')"
[[ "$explain_key" == 'idx_tenant_detail_order' ]]
echo "C3_OCCUPATION_EXPLAIN_INDEX=PASS"

scan_out="$work_dir/scan.out"
writer_out="$work_dir/writer.out"
run_php /workspace/tests/c3-service-order-authority/php/mysql-concurrency-worker.php scan >"$scan_out" 2>&1 &
scan_pid=$!
for _ in $(seq 1 80); do
  grep -q 'C3_SCAN_GUARD_LOCKED' "$scan_out" 2>/dev/null && break
  kill -0 "$scan_pid" 2>/dev/null || { cat "$scan_out" >&2; exit 1; }
  sleep 0.1
done
grep -q 'C3_SCAN_GUARD_LOCKED' "$scan_out"
run_php /workspace/tests/c3-service-order-authority/php/mysql-concurrency-worker.php writer >"$writer_out" 2>&1 &
writer_pid=$!
sleep 1
lock_waits="$(mysql_value "$db" 'SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS')"
[[ "$lock_waits" -ge 1 ]]
wait "$scan_pid"
wait "$writer_pid"
grep -q 'C3_SCAN_COMMITTED' "$scan_out"
grep -q 'C3_WRITER_ELAPSED=' "$writer_out"
[[ "$(mysql_value "$db" "SELECT COUNT(*) FROM eb_cashier_v3_service_order_line WHERE tenant_id='tenant-1' AND entitlement_source_detail_id=999 AND status='ACTIVE'")" == '1' ]]
[[ "$(mysql_value "$db" "SELECT current_version FROM eb_cashier_v3_service_order_entitlement_guard WHERE tenant_id='tenant-1' AND entitlement_source_detail_id=999")" == '2' ]]
echo "C3_EMPTY_RANGE_GUARD_CONCURRENCY=PASS"

mysql_file "$db" "$upgrade_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
(cd "$upgrade_dir" && shasum -a 256 -c SHA256SUMS.txt)
echo "C3_MYSQL56_MATRIX=PASS"

#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
test_dir="$repo_dir/tests/cashier-v3-checkout-facts"
migration_dir="$repo_dir/后端代码/database/upgrades/2026-07-29-收银V3统一结账事实底座"
init_sql="$repo_dir/后端代码/database/upgrades/0000-升级登记表初始化.sql"
mysql_image="${CHECKOUT_FACT_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
php_image="${CHECKOUT_FACT_PHP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"
mysql_container="checkout-fact-mysql56-$$"
tmp_root="$test_dir/_tmp"
mkdir -p "$tmp_root"
work_dir="$(mktemp -d "$tmp_root/mysql56.XXXXXX")"
failure_log="$work_dir/expected-failure.log"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$mysql_container" >&2 || true
    find "$work_dir" -type f -maxdepth 1 -print -exec sed -n '1,240p' {} \; >&2 || true
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
[[ "$mysql_version" == '5.6.51' ]]
echo "MYSQL_VERSION=$mysql_version"

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
  mysql_file "$db" "$test_dir/sql/base-schema.sql" >/dev/null
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

db='checkout_facts'
init_db "$db"
mysql_file "$db" "$migration_dir/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$db" "$migration_dir/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$db" "$migration_dir/03-升级后验证.sql" | grep -q 'VERIFY_OK'
mysql_file "$db" "$migration_dir/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$db" "$migration_dir/03-升级后验证.sql" | grep -q 'VERIFY_OK'
echo 'CHECKOUT_FACT_MYSQL56_FRESH_REPLAY=PASS'

partial_db='checkout_facts_partial'
init_db "$partial_db"
mysql_file "$partial_db" "$migration_dir/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" 'DROP TABLE eb_cashier_v3_payment_fact,eb_cashier_v3_balance_fact,eb_cashier_v3_performance_fact' >/dev/null
expect_failure "$partial_db" "$migration_dir/01-升级前检查.sql" 'STOP_CASHIER_V3_CHECKOUT_FACT_PRECHECK_FAILED'
mysql_file "$partial_db" "$migration_dir/05-部分创表恢复.sql" | grep -q 'PARTIAL_CREATE_RECOVERY_READY'
mysql_file "$partial_db" "$migration_dir/02-正式升级.sql" >/dev/null
mysql_file "$partial_db" "$migration_dir/03-升级后验证.sql" | grep -q 'VERIFY_OK'
echo 'CHECKOUT_FACT_PARTIAL_CREATE_RECOVERY=PASS'

malformed_db='checkout_facts_malformed'
init_db "$malformed_db"
mysql_sql "$malformed_db" 'CREATE TABLE eb_cashier_v3_sale_fact (id bigint unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci' >/dev/null
expect_failure "$malformed_db" "$migration_dir/05-部分创表恢复.sql" 'STOP_CHECKOUT_FACT_PARTIAL_HETEROGENEOUS_SCHEMA'
echo 'CHECKOUT_FACT_PARTIAL_MALFORMED_FAIL_CLOSED=PASS'

docker run --rm --cpus 1 --memory 384m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint sh "$php_image" -lc \
  'find /workspace/后端代码/app/services/cashier/v3/fact /workspace/tests/cashier-v3-checkout-facts/php -type f -name "*.php" -exec php -l {} \;'

docker run --rm --cpus 1 --memory 384m \
  --network "container:$mysql_container" \
  --volume "$repo_dir:/workspace:ro" \
  --tmpfs /workspace/后端代码/runtime:rw,noexec,nosuid,size=64m \
  --env CHECKOUT_FACT_BACKEND_ROOT=/workspace/后端代码 \
  --env DB_HOST=127.0.0.1 --env DB_PORT=3306 \
  --env DB_DATABASE="$db" --env DB_USERNAME=root --env DB_PASSWORD= \
  --entrypoint php "$php_image" \
  /workspace/tests/cashier-v3-checkout-facts/php/mysql-integration.php \
  | tee "$work_dir/mysql-integration.out"
grep -q 'CHECKOUT_FACT_MYSQL_INTEGRATION.*failed=0' "$work_dir/mysql-integration.out"

run_php() {
  docker run --rm --cpus 1 --memory 384m \
    --network "container:$mysql_container" \
    --volume "$repo_dir:/workspace:ro" \
    --tmpfs /workspace/后端代码/runtime:rw,noexec,nosuid,size=64m \
    --env CHECKOUT_FACT_BACKEND_ROOT=/workspace/后端代码 \
    --env DB_HOST=127.0.0.1 --env DB_PORT=3306 \
    --env DB_DATABASE="$db" --env DB_USERNAME=root --env DB_PASSWORD= \
    --entrypoint php "$php_image" "$@"
}
run_php /workspace/tests/cashier-v3-checkout-facts/php/mysql-concurrency-worker.php setup \
  >"$work_dir/concurrency-setup.out"
run_php /workspace/tests/cashier-v3-checkout-facts/php/mysql-concurrency-worker.php write \
  >"$work_dir/concurrency-a.out" 2>&1 &
worker_a=$!
run_php /workspace/tests/cashier-v3-checkout-facts/php/mysql-concurrency-worker.php write \
  >"$work_dir/concurrency-b.out" 2>&1 &
worker_b=$!
wait "$worker_a"
wait "$worker_b"
[[ "$(mysql_value "$db" 'SELECT COUNT(*) FROM eb_cashier_v3_sale_fact')" == '1' ]]
[[ "$(mysql_value "$db" 'SELECT COUNT(*) FROM eb_cashier_v3_payment_fact')" == '1' ]]
[[ "$(mysql_value "$db" 'SELECT COUNT(*) FROM eb_cashier_v3_balance_fact')" == '1' ]]
[[ "$(mysql_value "$db" 'SELECT COUNT(*) FROM eb_cashier_v3_performance_fact')" == '5' ]]
grep -q '"businessEffectsWritten":true' "$work_dir/concurrency-a.out" "$work_dir/concurrency-b.out"
grep -q '"businessEffectsWritten":false' "$work_dir/concurrency-a.out" "$work_dir/concurrency-b.out"
echo 'CHECKOUT_FACT_NATURAL_KEY_CONCURRENCY=PASS'

mysql_file "$db" "$migration_dir/03-升级后验证.sql" | grep -q 'VERIFY_OK'
(cd "$migration_dir" && shasum -a 256 -c SHA256SUMS.txt)
echo 'CHECKOUT_FACT_MYSQL56_MATRIX=PASS'

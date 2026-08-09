#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
MIGRATION_DIR="$ROOT_DIR/后端代码/database/upgrades/2026-07-29-C2权益完成依赖提供者"
MYSQL_IMAGE="${C2_PROVIDER_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
PHP_IMAGE="${C2_PROVIDER_PHP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"
NETWORK="c2-provider-net-$$"
MYSQL_CONTAINER="c2-provider-mysql56-$$"
PHP_CONTAINER="c2-provider-php74-$$"
HTML_VOL="c2-provider-html-$$"
FAILURE_LOG="$(mktemp /tmp/c2-provider-mysql56.XXXXXX)"
TMP_ROOT="$ROOT_DIR/tests/c2-entitlement-providers/_tmp"
mkdir -p "$TMP_ROOT"
WORK_DIR="$(mktemp -d "$TMP_ROOT/mysql56.XXXXXX")"
TEMP_ENV="$WORK_DIR/c2-provider.env"
COORD_DIR="$WORK_DIR/coord"
mkdir -p "$COORD_DIR"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$MYSQL_CONTAINER" >&2 || true
    sed -n '1,240p' "$FAILURE_LOG" >&2 || true
    for file in "$WORK_DIR"/*.out; do
      [[ -f "$file" ]] && { echo "== $file ==" >&2; sed -n '1,240p' "$file" >&2; }
    done
  fi
  docker rm -fv "$PHP_CONTAINER" "${PHP_CONTAINER}-a" "${PHP_CONTAINER}-b" "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  docker volume rm -f "$HTML_VOL" >/dev/null 2>&1 || true
  rm -f "$FAILURE_LOG"
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

printf '%s\n' \
  'APP_DEBUG = true' \
  "HOSTNAME = $MYSQL_CONTAINER" \
  'DATABASE = c2_provider_runtime' \
  'USERNAME = root' \
  'PASSWORD = ' \
  'HOSTPORT = 3306' \
  'DRIVER = file' \
  'CACHE_DRIVER = file' > "$TEMP_ENV"

# Build a disposable source volume without ever copying the real .env. The
# test env is then written inside that volume before it is mounted read-only.
docker volume create "$HTML_VOL" >/dev/null
docker run --rm \
  -v "$ROOT_DIR/后端代码:/src:ro" \
  -v "$WORK_DIR:/test-env:ro" \
  -v "$HTML_VOL:/dst" \
  --entrypoint sh "$PHP_IMAGE" -lc '
    set -e
    tar -C /src --exclude="./.env" --exclude="./.env.*" -cf - . | tar -C /dst -xf -
    mkdir -p /dst/runtime
    test ! -f /dst/.env
    cp /test-env/c2-provider.env /dst/.env
    test -s /dst/.env
  '

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
docker logs "$MYSQL_CONTAINER" 2>&1 | grep 'MySQL init process done' >/dev/null
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
echo "MYSQL_IMAGE=$MYSQL_IMAGE"
echo "PHP_IMAGE=$PHP_IMAGE"

mysql_server() {
  docker exec "$MYSQL_CONTAINER" mysql -uroot "$@"
}

mysql_sql() {
  local db="$1" sql="$2"
  docker exec "$MYSQL_CONTAINER" mysql -uroot --database="$db" -e "$sql"
}

mysql_value() {
  local db="$1" sql="$2"
  docker exec "$MYSQL_CONTAINER" mysql -uroot --database="$db" -Nse "$sql"
}

mysql_file() {
  local db="$1" file="$2"
  docker exec -i "$MYSQL_CONTAINER" mysql -uroot --database="$db" < "$file"
}

expect_file_failure() {
  local db="$1" file="$2" marker="$3"
  if mysql_file "$db" "$file" >"$FAILURE_LOG" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  grep -q "$marker" "$FAILURE_LOG" || {
    echo "EXPECTED_FAILURE_MARKER_MISSING=$marker" >&2
    exit 1
  }
  : > "$FAILURE_LOG"
  echo "$marker=PASS"
}

register_upgrade() {
  local db="$1" key="$2" title="$3"
  mysql_sql "$db" "
    INSERT INTO eb_database_upgrade_log
      (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
    VALUES
      ('$key','$title','tests/c2-entitlement-providers/mysql56-matrix.sh','','local',NOW(),'Codex','isolated matrix');
  " >/dev/null
}

init_base() {
  local db="$1"
  mysql_server -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$ROOT_DIR/后端代码/database/upgrades/0000-升级登记表初始化.sql" >/dev/null
}

install_authorities() {
  local db="$1"
  mysql_sql "$db" "
    CREATE TABLE eb_store_order (
      id bigint(20) unsigned NOT NULL,
      store_id bigint(20) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_employee (
      id bigint(20) unsigned NOT NULL,
      name varchar(128) NOT NULL DEFAULT '',
      status tinyint(1) NOT NULL DEFAULT 1,
      is_del tinyint(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_system_store_staff (
      id bigint(20) unsigned NOT NULL,
      employee_id bigint(20) unsigned NOT NULL DEFAULT 0,
      store_id bigint(20) unsigned NOT NULL DEFAULT 0,
      staff_name varchar(128) NOT NULL DEFAULT '',
      status tinyint(1) NOT NULL DEFAULT 1,
      is_del tinyint(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      KEY idx_employee_store (employee_id,store_id,is_del)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_store_reservation_order (
      id bigint(20) unsigned NOT NULL,
      store_id bigint(20) unsigned NOT NULL DEFAULT 0,
      cart_info_id bigint(20) unsigned NOT NULL DEFAULT 0,
      status tinyint(2) NOT NULL DEFAULT 0,
      is_del tinyint(1) NOT NULL DEFAULT 0,
      is_system_del tinyint(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      KEY idx_cart_info_id (cart_info_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  " >/dev/null
}

install_inventory_probe_schema() {
  local db="$1"
  mysql_sql "$db" "
    CREATE TABLE eb_inventory_shortage_policy (
      tenant_id varchar(32) NOT NULL, policy_scope varchar(16) NOT NULL,
      project_id bigint unsigned NOT NULL, policy_value varchar(32) NOT NULL,
      version bigint unsigned NOT NULL, PRIMARY KEY (tenant_id,policy_scope,project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_inventory_stock (
      id bigint unsigned NOT NULL, tenant_id varchar(32) NOT NULL,
      store_id bigint unsigned NOT NULL, available_quantity_units bigint unsigned NOT NULL,
      version bigint unsigned NOT NULL, PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_inventory_batch (
      id bigint unsigned NOT NULL, stock_id bigint unsigned NOT NULL,
      available_quantity_units bigint unsigned NOT NULL, unit_cost_cents bigint unsigned NOT NULL,
      cost_allocated_quantity_units bigint unsigned NOT NULL, version bigint unsigned NOT NULL,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_inventory_shortage_cost_cursor (
      tenant_id varchar(32) NOT NULL, store_id bigint unsigned NOT NULL,
      recipe_id bigint unsigned NOT NULL, stock_id bigint unsigned NOT NULL,
      estimated_unit_cost_cents bigint unsigned NOT NULL,
      allocated_quantity_units bigint unsigned NOT NULL,
      PRIMARY KEY (tenant_id,store_id,recipe_id,stock_id,estimated_unit_cost_cents)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_store_project_consumable_recipe (
      id bigint unsigned NOT NULL, project_id bigint unsigned NOT NULL,
      version bigint unsigned NOT NULL, PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_store_project_consumable_recipe_detail (
      id bigint unsigned NOT NULL, recipe_id bigint unsigned NOT NULL, PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  " >/dev/null
}

install_test_authorities() {
  local db="$1"
  mysql_sql "$db" "
    CREATE TABLE eb_test_staff_type_authority (
      employee_id bigint unsigned NOT NULL,
      type_code varchar(32) NOT NULL,
      version bigint unsigned NOT NULL,
      PRIMARY KEY (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_test_service_occupation (
      id bigint unsigned NOT NULL,
      store_id bigint unsigned NOT NULL,
      cart_info_id bigint unsigned NOT NULL,
      status tinyint NOT NULL,
      version bigint unsigned NOT NULL,
      PRIMARY KEY (id), KEY idx_cart (cart_info_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  " >/dev/null
}

init_db() {
  local db="$1"
  init_base "$db"
  install_authorities "$db"
  register_upgrade "$db" '20260727-001-cashier-v3-command-idem' 'C1 dependency'
}

target_count() {
  local db="$1"
  mysql_value "$db" "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (
      'eb_cashier_v3_entitlement_debt_guard',
      'eb_cashier_v3_entitlement_debt_guard_mutation',
      'eb_cashier_v3_staff_profile_version',
      'eb_cashier_v3_entitlement_occupation_version'
    );
  "
}

# Missing authorities fail before DDL.
missing_db='c2_provider_missing'
init_base "$missing_db"
register_upgrade "$missing_db" '20260727-001-cashier-v3-command-idem' 'C1 dependency'
expect_file_failure "$missing_db" "$MIGRATION_DIR/01-升级前检查.sql" \
  'STOP_C2_ENTITLEMENT_PROVIDER_PRECHECK_FAILED'
[[ "$(target_count "$missing_db")" == '0' ]]
echo 'C2_PROVIDER_PRECHECK_FAIL_CLOSED=PASS'

# Fresh install, exact replay, and verification.
runtime_db='c2_provider_runtime'
init_db "$runtime_db"
install_inventory_probe_schema "$runtime_db"
install_test_authorities "$runtime_db"
mysql_file "$runtime_db" "$MIGRATION_DIR/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$runtime_db" "$MIGRATION_DIR/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$runtime_db" "$MIGRATION_DIR/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$runtime_db" "$MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'C2_PROVIDER_MYSQL56_FRESH_AND_REPLAY=PASS'

# One exact empty table is recoverable without modifying it.
partial_db='c2_provider_partial'
init_db "$partial_db"
mysql_file "$partial_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" 'DROP TABLE eb_cashier_v3_entitlement_debt_guard_mutation,eb_cashier_v3_staff_profile_version,eb_cashier_v3_entitlement_occupation_version;' >/dev/null
mysql_file "$partial_db" "$MIGRATION_DIR/05-部分创表恢复.sql" | grep -q 'PARTIAL_DDL_RECOVERY_READY'
[[ "$(target_count "$partial_db")" == '1' ]]
mysql_file "$partial_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_file "$partial_db" "$MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'C2_PROVIDER_PARTIAL_RECOVERY=PASS'

# A heterogeneous same-name table is never an automatic recovery target.
hetero_db='c2_provider_heterogeneous'
init_db "$hetero_db"
mysql_sql "$hetero_db" 'CREATE TABLE eb_cashier_v3_entitlement_debt_guard (id bigint unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;' >/dev/null
expect_file_failure "$hetero_db" "$MIGRATION_DIR/05-部分创表恢复.sql" \
  'STOP_C2_PROVIDER_PARTIAL_HETEROGENEOUS'
echo 'C2_PROVIDER_PARTIAL_HETEROGENEOUS_REJECT=PASS'

# A non-empty exact partial table is retained and rejected.
nonempty_db='c2_provider_nonempty'
init_db "$nonempty_db"
mysql_file "$nonempty_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_sql "$nonempty_db" 'DROP TABLE eb_cashier_v3_entitlement_debt_guard_mutation,eb_cashier_v3_staff_profile_version,eb_cashier_v3_entitlement_occupation_version;' >/dev/null
mysql_sql "$nonempty_db" "
  INSERT INTO eb_cashier_v3_entitlement_debt_guard
    (tenant_id,origin_order_id,current_version,last_action,created_at,updated_at)
  VALUES ('0',1,1,'',1785286800,1785286800);
" >/dev/null
expect_file_failure "$nonempty_db" "$MIGRATION_DIR/05-部分创表恢复.sql" \
  'STOP_C2_PROVIDER_PARTIAL_NONEMPTY'
echo 'C2_PROVIDER_PARTIAL_NONEMPTY_REJECT=PASS'

# Zero and full target states use normal precheck/postcheck, not recovery.
zero_db='c2_provider_zero'
init_db "$zero_db"
expect_file_failure "$zero_db" "$MIGRATION_DIR/05-部分创表恢复.sql" \
  'STOP_C2_PROVIDER_PARTIAL_ZERO_TABLES'
full_db='c2_provider_full'
init_db "$full_db"
mysql_file "$full_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
expect_file_failure "$full_db" "$MIGRATION_DIR/05-部分创表恢复.sql" \
  'STOP_C2_PROVIDER_PARTIAL_FULL_INSTALL'
echo 'C2_PROVIDER_PARTIAL_BOUNDARIES=PASS'

php_run() {
  local name="$1" script="$2"
  docker run --rm --name "$name" --network "$NETWORK" \
    -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE="$runtime_db" \
    -e DB_USERNAME=root -e DB_PASSWORD= \
    -e HOSTNAME="$MYSQL_CONTAINER" -e HOSTPORT=3306 -e DATABASE="$runtime_db" \
    -e USERNAME=root -e PASSWORD= -e CACHE_DRIVER=file -e DRIVER=file \
    -v "$HTML_VOL:/var/www/html:ro" \
    -v "$ROOT_DIR/tests:/tests:ro" \
    --tmpfs /var/www/html/runtime:rw,size=64m \
    --entrypoint php "$PHP_IMAGE" "$script"
}

php_run "$PHP_CONTAINER" /tests/c2-entitlement-providers/php/provider-integration.php \
  | tee "$WORK_DIR/provider-integration.out"
grep -q 'C2_ENTITLEMENT_PROVIDER_INTEGRATION=PASS' "$WORK_DIR/provider-integration.out"

# Two real PHP processes contend on an initially absent guard. Both must see
# the same row/version; B must wait for A's transaction to release the row.
mysql_sql "$runtime_db" "
  INSERT INTO eb_store_order (id,store_id) VALUES (601,8)
    ON DUPLICATE KEY UPDATE store_id=VALUES(store_id);
  DELETE FROM eb_cashier_v3_entitlement_debt_guard_mutation WHERE origin_order_id=601;
  DELETE FROM eb_cashier_v3_entitlement_debt_guard WHERE origin_order_id=601;
" >/dev/null
rm -f "$COORD_DIR/a.locked"

docker run --rm --name "${PHP_CONTAINER}-a" --network "$NETWORK" \
  -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE="$runtime_db" \
  -e DB_USERNAME=root -e DB_PASSWORD= \
  -e HOSTNAME="$MYSQL_CONTAINER" -e HOSTPORT=3306 -e DATABASE="$runtime_db" \
  -e USERNAME=root -e PASSWORD= -e CACHE_DRIVER=file -e DRIVER=file \
  -e C2P_WORKER_ROLE=A -e C2P_WORKER_HOLD_MS=2000 -e C2P_WORKER_MARKER=/coord/a.locked \
  -v "$HTML_VOL:/var/www/html:ro" \
  -v "$ROOT_DIR/tests:/tests:ro" \
  -v "$COORD_DIR:/coord:rw" \
  --tmpfs /var/www/html/runtime:rw,size=64m \
  --entrypoint php "$PHP_IMAGE" /tests/c2-entitlement-providers/php/debt-guard-worker.php \
  >"$WORK_DIR/worker-a.out" 2>&1 &
worker_a_pid=$!
for _ in $(seq 1 100); do
  [[ -f "$COORD_DIR/a.locked" ]] && break
  sleep 0.05
done
[[ -f "$COORD_DIR/a.locked" ]]

docker run --rm --name "${PHP_CONTAINER}-b" --network "$NETWORK" \
  -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE="$runtime_db" \
  -e DB_USERNAME=root -e DB_PASSWORD= \
  -e HOSTNAME="$MYSQL_CONTAINER" -e HOSTPORT=3306 -e DATABASE="$runtime_db" \
  -e USERNAME=root -e PASSWORD= -e CACHE_DRIVER=file -e DRIVER=file \
  -e C2P_WORKER_ROLE=B -e C2P_WORKER_MIN_ELAPSED_MS=1200 -e C2P_WORKER_MARKER=/coord/a.locked \
  -v "$HTML_VOL:/var/www/html:ro" \
  -v "$ROOT_DIR/tests:/tests:ro" \
  -v "$COORD_DIR:/coord:rw" \
  --tmpfs /var/www/html/runtime:rw,size=64m \
  --entrypoint php "$PHP_IMAGE" /tests/c2-entitlement-providers/php/debt-guard-worker.php \
  >"$WORK_DIR/worker-b.out" 2>&1
wait "$worker_a_pid"
grep -q 'WORKER=A VERSION=1' "$WORK_DIR/worker-a.out"
grep -q 'WORKER=B VERSION=1' "$WORK_DIR/worker-b.out"
[[ "$(mysql_value "$runtime_db" 'SELECT COUNT(*) FROM eb_cashier_v3_entitlement_debt_guard WHERE tenant_id="0" AND origin_order_id=601')" == '1' ]]
echo 'C2_PROVIDER_TWO_CONNECTION_GUARD_CONCURRENCY=PASS'

mysql_file "$runtime_db" "$MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'C2_ENTITLEMENT_PROVIDER_MYSQL56_MATRIX=PASS'

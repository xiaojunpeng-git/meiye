#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
MIGRATION_DIR="$ROOT_DIR/后端代码/database/upgrades/2026-07-29-员工人员类型权威源"
DEFAULT_INTERNAL_MIGRATION_DIR="$ROOT_DIR/后端代码/database/upgrades/2026-08-01-在职门店员工默认内部类型"
MYSQL_IMAGE="${ETA_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
PHP_IMAGE="${ETA_PHP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"
RUN_ID="$$"
NETWORK="employee-type-net-$RUN_ID"
MYSQL_CONTAINER="employee-type-mysql56-$RUN_ID"
PHP_CONTAINER="employee-type-php74-$RUN_ID"
HTML_VOL="employee-type-html-$RUN_ID"
FAILURE_LOG="$(mktemp /tmp/employee-type-mysql56.XXXXXX)"
TMP_ROOT="$ROOT_DIR/tests/employee-type-authority/_tmp"
mkdir -p "$TMP_ROOT"
WORK_DIR="$(mktemp -d "$TMP_ROOT/mysql56.XXXXXX")"
TEMP_ENV="$WORK_DIR/employee-type.env"

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
  'DATABASE = employee_type_runtime' \
  'USERNAME = root' \
  'PASSWORD = ' \
  'HOSTPORT = 3306' \
  'DRIVER = file' \
  'CACHE_DRIVER = file' > "$TEMP_ENV"

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
    cp /test-env/employee-type.env /dst/.env
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
[[ "$MYSQL_VERSION" == '5.6.51' ]]
echo "MYSQL_VERSION=$MYSQL_VERSION"

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

init_db() {
  local db="$1"
  mysql_server -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$ROOT_DIR/后端代码/database/upgrades/0000-升级登记表初始化.sql" >/dev/null
  mysql_file "$db" "$ROOT_DIR/tests/employee-type-authority/sql/base-schema.sql" >/dev/null
}

# Missing dependency fails before any DDL.
missing_db='employee_type_missing'
init_db "$missing_db"
mysql_sql "$missing_db" 'DROP TABLE eb_organization_write_idempotency;' >/dev/null
expect_file_failure "$missing_db" "$MIGRATION_DIR/01-升级前检查.sql" \
  'STOP_EMPLOYEE_TYPE_AUTHORITY_PRECHECK_FAILED'
[[ "$(mysql_value "$missing_db" "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_employee' AND COLUMN_NAME LIKE 'employment_type_%'")" == '0' ]]
echo 'EMPLOYEE_TYPE_PRECHECK_FAIL_CLOSED=PASS'

# Fresh migration leaves every historical employee unclassified, then replays exactly.
runtime_db='employee_type_runtime'
init_db "$runtime_db"
mysql_file "$runtime_db" "$MIGRATION_DIR/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$runtime_db" "$MIGRATION_DIR/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$runtime_db" "$MIGRATION_DIR/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$runtime_db" "$MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
[[ "$(mysql_value "$runtime_db" "SELECT COUNT(*) FROM eb_employee WHERE id IN (1,2,3,4,5,6,7) AND employment_type_code IS NULL AND employment_type_version=0")" == '7' ]]
[[ "$(mysql_value "$runtime_db" "SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='setting-staff-employment-type' AND type=1 AND auth_type=2 AND is_show=0 AND is_del=0")" == '1' ]]
echo 'EMPLOYEE_TYPE_MYSQL56_FRESH_NO_BACKFILL_REPLAY=PASS'

# Exact one-column interrupted DDL is auditable and replayable.
partial_db='employee_type_partial'
init_db "$partial_db"
mysql_sql "$partial_db" "ALTER TABLE eb_employee ADD COLUMN employment_type_code varchar(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '人员类型 internal/partner/outsourced' AFTER status;" >/dev/null
mysql_file "$partial_db" "$MIGRATION_DIR/05-部分升级恢复审计.sql" | grep -q 'PARTIAL_UPGRADE_RECOVERY_READY'
mysql_file "$partial_db" "$MIGRATION_DIR/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$partial_db" "$MIGRATION_DIR/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$partial_db" "$MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'EMPLOYEE_TYPE_PARTIAL_DDL_RECOVERY=PASS'

# Heterogeneous and unsafe partial states are rejected without mutation.
hetero_db='employee_type_heterogeneous'
init_db "$hetero_db"
mysql_sql "$hetero_db" 'ALTER TABLE eb_employee ADD COLUMN employment_type_code varchar(8) NULL DEFAULT NULL;' >/dev/null
expect_file_failure "$hetero_db" "$MIGRATION_DIR/05-部分升级恢复审计.sql" \
  'STOP_EMPLOYEE_TYPE_PARTIAL_HETEROGENEOUS_COLUMNS'

unsafe_db='employee_type_unsafe'
init_db "$unsafe_db"
mysql_sql "$unsafe_db" "ALTER TABLE eb_employee ADD COLUMN employment_type_code varchar(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL; UPDATE eb_employee SET employment_type_code='partner' WHERE id=1;" >/dev/null
expect_file_failure "$unsafe_db" "$MIGRATION_DIR/05-部分升级恢复审计.sql" \
  'STOP_EMPLOYEE_TYPE_PARTIAL_UNSAFE_DATA'
echo 'EMPLOYEE_TYPE_PARTIAL_FAIL_CLOSED=PASS'

# Registered upgrade cannot be replayed as an untracked migration.
registered_db='employee_type_registered'
init_db "$registered_db"
mysql_sql "$registered_db" "INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES ('20260729-005-employee-employment-type-authority','test','','','local',NOW(),'test','test');" >/dev/null
expect_file_failure "$registered_db" "$MIGRATION_DIR/01-升级前检查.sql" \
  'STOP_EMPLOYEE_TYPE_AUTHORITY_PRECHECK_FAILED'
echo 'EMPLOYEE_TYPE_REGISTERED_REPLAY_REJECT=PASS'

# Active staff without any active legacy external flag default to internal exactly once.
default_internal_db='employee_type_default_internal'
init_db "$default_internal_db"
mysql_file "$default_internal_db" "$MIGRATION_DIR/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$default_internal_db" "$MIGRATION_DIR/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_sql "$default_internal_db" "
  INSERT INTO eb_database_upgrade_log
    (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
  VALUES
    ('20260729-005-employee-employment-type-authority','test','','','local',NOW(),'test','test'),
    ('20260801-001-store-staff-cashier-role-eligibility','test','','','local',NOW(),'test','test');
" >/dev/null
mysql_file "$default_internal_db" "$DEFAULT_INTERNAL_MIGRATION_DIR/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$default_internal_db" "$DEFAULT_INTERNAL_MIGRATION_DIR/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$default_internal_db" "$DEFAULT_INTERNAL_MIGRATION_DIR/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$default_internal_db" "$DEFAULT_INTERNAL_MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
[[ "$(mysql_value "$default_internal_db" "SELECT COUNT(*) FROM eb_employee WHERE id=2 AND employment_type_code='internal' AND employment_type_version=1")" == '1' ]]
[[ "$(mysql_value "$default_internal_db" "SELECT COUNT(*) FROM eb_employee WHERE id IN (1,3,4,5,6,7) AND employment_type_code IS NULL AND employment_type_version=0")" == '6' ]]
[[ "$(mysql_value "$default_internal_db" "SELECT COUNT(*) FROM eb_employee_change_log WHERE action='employee_employment_type_default_internal' AND employee_id=2")" == '1' ]]
echo 'EMPLOYEE_TYPE_ACTIVE_STORE_DEFAULT_INTERNAL_REPLAY=PASS'

php_run() {
  local name="$1" script="$2"
  shift 2
  docker run --rm --name "$name" --network "$NETWORK" \
    -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE="$runtime_db" \
    -e DB_USERNAME=root -e DB_PASSWORD= \
    -e HOSTNAME="$MYSQL_CONTAINER" -e HOSTPORT=3306 -e DATABASE="$runtime_db" \
    -e USERNAME=root -e PASSWORD= -e CACHE_DRIVER=file -e DRIVER=file \
    "$@" \
    -v "$HTML_VOL:/var/www/html:ro" \
    -v "$ROOT_DIR/tests:/tests:ro" \
    --tmpfs /var/www/html/runtime:rw,size=64m \
    --entrypoint php "$PHP_IMAGE" "$script"
}

php_run "$PHP_CONTAINER" /tests/employee-type-authority/php/integration.php \
  | tee "$WORK_DIR/integration.out"
grep -q 'EMPLOYEE_TYPE_AUTHORITY_INTEGRATION=PASS' "$WORK_DIR/integration.out"

# Two connections race on one expected version. Exactly one change and one conflict.
php_run "${PHP_CONTAINER}-a" /tests/employee-type-authority/php/concurrency-worker.php \
  -e ETA_TARGET_TYPE=partner \
  -e ETA_REQUEST_TOKEN=22222222-2222-4222-8222-222222222222 \
  >"$WORK_DIR/worker-a.out" 2>&1 &
worker_a_pid=$!
php_run "${PHP_CONTAINER}-b" /tests/employee-type-authority/php/concurrency-worker.php \
  -e ETA_TARGET_TYPE=outsourced \
  -e ETA_REQUEST_TOKEN=33333333-3333-4333-8333-333333333333 \
  >"$WORK_DIR/worker-b.out" 2>&1 &
worker_b_pid=$!
wait "$worker_a_pid"
wait "$worker_b_pid"

success_count="$(grep -h '^RESULT=SUCCESS' "$WORK_DIR/worker-a.out" "$WORK_DIR/worker-b.out" | wc -l | tr -d ' ')"
conflict_count="$(grep -h '^RESULT=CONFLICT' "$WORK_DIR/worker-a.out" "$WORK_DIR/worker-b.out" | wc -l | tr -d ' ')"
[[ "$success_count" == '1' && "$conflict_count" == '1' ]]
[[ "$(mysql_value "$runtime_db" "SELECT employment_type_version FROM eb_employee WHERE id=40")" == '2' ]]
[[ "$(mysql_value "$runtime_db" "SELECT COUNT(*) FROM eb_employee_change_log WHERE employee_id=40 AND action='employee_employment_type_save'")" == '1' ]]
echo 'EMPLOYEE_TYPE_TWO_CONNECTION_CONCURRENCY=PASS'

mysql_file "$runtime_db" "$MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'EMPLOYEE_TYPE_AUTHORITY_MYSQL56_MATRIX=PASS'

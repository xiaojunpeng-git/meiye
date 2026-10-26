#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
MIGRATION_DIR="$ROOT_DIR/后端代码/database/upgrades/2026-07-29-客情任务与记录内核"
DOCUMENT_MIGRATION_DIR="$ROOT_DIR/后端代码/database/upgrades/2026-08-03-客情正式单号与下钻"
C1_MIGRATION_DIR="$ROOT_DIR/后端代码/database/upgrades/2026-07-27-收银V3命令与幂等底座"
MYSQL_IMAGE="${CUSTOMER_CARE_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
PHP_IMAGE="${CUSTOMER_CARE_PHP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"
NETWORK="customer-care-net-$$"
MYSQL_CONTAINER="customer-care-mysql56-$$"
PHP_CONTAINER="customer-care-php74-$$"
FAILURE_LOG="$(mktemp /tmp/customer-care-mysql56.XXXXXX)"
TMP_ROOT="$ROOT_DIR/tests/customer-care/_tmp"
mkdir -p "$TMP_ROOT"
WORK_DIR="$(mktemp -d "$TMP_ROOT/mysql56.XXXXXX")"
TEMP_ENV="$WORK_DIR/customer-care.env"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$MYSQL_CONTAINER" >&2 || true
    sed -n '1,240p' "$FAILURE_LOG" >&2 || true
    for file in "$WORK_DIR"/*.out; do
      [[ -f "$file" ]] && { echo "== $file ==" >&2; sed -n '1,220p' "$file" >&2; }
    done
  fi
  docker rm -fv "$PHP_CONTAINER" "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  rm -f "$FAILURE_LOG"
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

printf '%s\n' \
  'APP_DEBUG = true' \
  "HOSTNAME = $MYSQL_CONTAINER" \
  'DATABASE = care_repository' \
  'USERNAME = root' \
  'PASSWORD = ' \
  'HOSTPORT = 3306' \
  'DRIVER = file' \
  'CACHE_DRIVER = file' > "$TEMP_ENV"

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

register_upgrade() {
  local db="$1" key="$2" title="$3"
  mysql_sql "$db" "
    INSERT INTO eb_database_upgrade_log
      (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
    VALUES
      ('$key','$title','tests/customer-care/sql-matrix.sh','','local',NOW(),'Codex','isolated matrix');
  " >/dev/null
}

install_employee_dependency() {
  local db="$1"
  mysql_sql "$db" "
    CREATE TABLE eb_employee (
      id int(10) unsigned NOT NULL,
      status tinyint(1) NOT NULL DEFAULT 1,
      is_del tinyint(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      KEY idx_employee_status_del (status,is_del)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_system_store_staff (
      id int(10) unsigned NOT NULL,
      employee_id int(10) unsigned DEFAULT NULL,
      store_id int(10) unsigned NOT NULL DEFAULT 0,
      staff_name varchar(64) NOT NULL DEFAULT '',
      status tinyint(1) NOT NULL DEFAULT 1,
      is_del tinyint(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      KEY idx_staff_employee_store (employee_id,store_id,is_del),
      KEY idx_staff_store_employee (store_id,employee_id,is_del)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  " >/dev/null
  register_upgrade "$db" '20260719-009-employee-org-leader' 'employee dependency'
}

init_db_base() {
  local db="$1"
  mysql_server -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$ROOT_DIR/后端代码/database/upgrades/0000-升级登记表初始化.sql" >/dev/null
}

init_db() {
  local db="$1"
  init_db_base "$db"
  mysql_file "$db" "$C1_MIGRATION_DIR/02-正式升级.sql" >/dev/null
  register_upgrade "$db" '20260727-001-cashier-v3-command-idem' 'C1 command receipt dependency'
  install_employee_dependency "$db"
}

register_care_upgrade() {
  register_upgrade "$1" '20260729-001-customer-care-core' 'customer care matrix'
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

assert_target_count() {
  local db="$1" expected="$2" marker="$3"
  local actual
  actual="$(mysql_value "$db" "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME IN (
        'eb_customer_care_task','eb_customer_care_record','eb_customer_care_operation'
      );
  ")"
  [[ "$actual" == "$expected" ]] || {
    echo "$marker expected=$expected actual=$actual" >&2
    exit 1
  }
}

# Missing shared dependencies must fail before any care DDL.
missing_dependency_db='care_missing_dependency'
init_db_base "$missing_dependency_db"
expect_file_failure "$missing_dependency_db" "$MIGRATION_DIR/01-升级前检查.sql" \
  'STOP_CUSTOMER_CARE_PRECHECK_FAILED'
assert_target_count "$missing_dependency_db" 0 'MISSING_DEPENDENCY_CREATED_TARGETS'
echo 'CARE_PRECHECK_DEPENDENCY_FAIL_CLOSED=PASS'

# Registered dependencies with an active staff row that has no active employee mapping must fail.
bad_assignment_db='care_bad_assignment'
init_db "$bad_assignment_db"
mysql_sql "$bad_assignment_db" "
  INSERT INTO eb_system_store_staff
    (id,employee_id,store_id,staff_name,status,is_del)
  VALUES (77,NULL,8,'无员工映射',1,0);
" >/dev/null
expect_file_failure "$bad_assignment_db" "$MIGRATION_DIR/01-升级前检查.sql" \
  'STOP_CUSTOMER_CARE_PRECHECK_FAILED'
echo 'CARE_PRECHECK_ACTIVE_ASSIGNMENT_FAIL_CLOSED=PASS'

# Fresh DDL and exact replay.
fresh_db='care_fresh'
init_db "$fresh_db"
mysql_file "$fresh_db" "$MIGRATION_DIR/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$fresh_db" "$MIGRATION_DIR/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$fresh_db" "$MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
mysql_file "$fresh_db" "$MIGRATION_DIR/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$fresh_db" "$MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'CARE_MYSQL56_FRESH_AND_REPLAY=PASS'

# Exactly one canonical empty table can be retained while 02 creates the two missing tables.
one_table_db='care_partial_one_table'
init_db "$one_table_db"
mysql_file "$one_table_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_sql "$one_table_db" 'DROP TABLE eb_customer_care_operation,eb_customer_care_record;' >/dev/null
mysql_file "$one_table_db" "$MIGRATION_DIR/05-部分创表恢复.sql" | grep -q 'PARTIAL_DDL_RECOVERY_READY'
assert_target_count "$one_table_db" 1 'PARTIAL_ONE_TABLE_CHANGED_BY_VALIDATOR'
mysql_file "$one_table_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_file "$one_table_db" "$MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'CARE_PARTIAL_ONE_TABLE_NONDESTRUCTIVE_RECOVERY=PASS'

# Exactly two canonical empty tables can be retained while 02 creates the missing table.
two_table_db='care_partial_two_tables'
init_db "$two_table_db"
mysql_file "$two_table_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_sql "$two_table_db" 'DROP TABLE eb_customer_care_operation;' >/dev/null
mysql_file "$two_table_db" "$MIGRATION_DIR/05-部分创表恢复.sql" | grep -q 'PARTIAL_DDL_RECOVERY_READY'
assert_target_count "$two_table_db" 2 'PARTIAL_TWO_TABLES_CHANGED_BY_VALIDATOR'
mysql_file "$two_table_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_file "$two_table_db" "$MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'CARE_PARTIAL_TWO_TABLE_NONDESTRUCTIVE_RECOVERY=PASS'

# Zero tables is a normal fresh install, not a partial recovery target.
zero_table_db='care_partial_zero_tables'
init_db "$zero_table_db"
expect_file_failure "$zero_table_db" "$MIGRATION_DIR/05-部分创表恢复.sql" \
  'STOP_PARTIAL_DDL_ZERO_TABLES'
assert_target_count "$zero_table_db" 0 'PARTIAL_ZERO_TABLES_CHANGED'
echo 'CARE_PARTIAL_ZERO_TABLES_REJECT=PASS'

# A full three-table install must go to postcheck and cannot enter recovery.
full_install_db='care_partial_full_install'
init_db "$full_install_db"
mysql_file "$full_install_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
expect_file_failure "$full_install_db" "$MIGRATION_DIR/05-部分创表恢复.sql" \
  'STOP_PARTIAL_DDL_FULL_INSTALL'
assert_target_count "$full_install_db" 3 'PARTIAL_FULL_INSTALL_CHANGED'
echo 'CARE_PARTIAL_FULL_INSTALL_REJECT=PASS'

# A heterogeneous same-name table blocks recovery without changing it.
hetero_db='care_partial_heterogeneous'
init_db "$hetero_db"
mysql_sql "$hetero_db" 'CREATE TABLE eb_customer_care_task (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB;' >/dev/null
expect_file_failure "$hetero_db" "$MIGRATION_DIR/05-部分创表恢复.sql" \
  'STOP_PARTIAL_DDL_HETEROGENEOUS'
assert_target_count "$hetero_db" 1 'PARTIAL_HETERO_CHANGED'
echo 'CARE_PARTIAL_HETEROGENEOUS_REJECT=PASS'

# A non-empty exact partial table is never a recovery target.
nonempty_db='care_partial_nonempty'
init_db "$nonempty_db"
mysql_file "$nonempty_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_sql "$nonempty_db" 'DROP TABLE eb_customer_care_operation,eb_customer_care_record;' >/dev/null
mysql_sql "$nonempty_db" "
  INSERT INTO eb_customer_care_task
    (task_key,create_idempotency_key,create_request_fingerprint,tenant_id,
     organization_id,organization_path,organization_name_snapshot,business_store_id,
     business_store_name_snapshot,member_id,member_name_snapshot,owner_staff_id,
     owner_employee_id,owner_name_snapshot,task_type,source_type,source_id,title,
     planned_at,created_by_staff_id,created_at,updated_at)
  VALUES
    ('CARE-TASK-NONEMPTY','CARE_CREATE_TASK-00000000-0000-4000-8000-000000000001',
     REPEAT('a',64),'TENANT_1','ORG_8','/ROOT/ORG_8/','八号组织',8,'八号门店',
     1,'会员',101,1101,'员工101','FOLLOWUP','MANUAL','SOURCE-1','测试',
     1785286800,101,1785286800,1785286800);
" >/dev/null
expect_file_failure "$nonempty_db" "$MIGRATION_DIR/05-部分创表恢复.sql" \
  'STOP_PARTIAL_DDL_NONEMPTY'
assert_target_count "$nonempty_db" 1 'PARTIAL_NONEMPTY_CHANGED'
echo 'CARE_PARTIAL_NONEMPTY_REJECT=PASS'

# A registered partial upgrade cannot be resumed by the recovery validator.
registered_db='care_partial_registered'
init_db "$registered_db"
mysql_file "$registered_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_sql "$registered_db" 'DROP TABLE eb_customer_care_operation,eb_customer_care_record;' >/dev/null
register_care_upgrade "$registered_db"
expect_file_failure "$registered_db" "$MIGRATION_DIR/05-部分创表恢复.sql" \
  'STOP_PARTIAL_DDL_UPGRADE_REGISTERED'
assert_target_count "$registered_db" 1 'PARTIAL_REGISTERED_CHANGED'
echo 'CARE_PARTIAL_REGISTERED_REJECT=PASS'

# A missing upgrade registry fails before any recovery decision.
missing_log_db='care_partial_missing_log'
init_db "$missing_log_db"
mysql_file "$missing_log_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_sql "$missing_log_db" 'DROP TABLE eb_customer_care_operation,eb_customer_care_record,eb_database_upgrade_log;' >/dev/null
expect_file_failure "$missing_log_db" "$MIGRATION_DIR/05-部分创表恢复.sql" \
  'STOP_PARTIAL_DDL_UPGRADE_LOG_INVALID'
assert_target_count "$missing_log_db" 1 'PARTIAL_MISSING_LOG_CHANGED'
echo 'CARE_PARTIAL_MISSING_LOG_REJECT=PASS'

# Partial recovery also requires the registered shared receipt and employee foundations.
recovery_dependency_db='care_partial_dependency_invalid'
init_db "$recovery_dependency_db"
mysql_file "$recovery_dependency_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_sql "$recovery_dependency_db" 'DROP TABLE eb_customer_care_operation,eb_customer_care_record;' >/dev/null
mysql_sql "$recovery_dependency_db" "DELETE FROM eb_database_upgrade_log WHERE upgrade_key='20260719-009-employee-org-leader';" >/dev/null
expect_file_failure "$recovery_dependency_db" "$MIGRATION_DIR/05-部分创表恢复.sql" \
  'STOP_PARTIAL_DDL_DEPENDENCY_INVALID'
assert_target_count "$recovery_dependency_db" 1 'PARTIAL_DEPENDENCY_INVALID_CHANGED'
echo 'CARE_PARTIAL_DEPENDENCY_REJECT=PASS'

# Real ThinkPHP 7.4 repository, service transactions, rollback/retry and deterministic concurrency.
repository_db='care_repository'
init_db "$repository_db"
mysql_file "$repository_db" "$MIGRATION_DIR/02-正式升级.sql" >/dev/null
mysql_file "$repository_db" "$DOCUMENT_MIGRATION_DIR/02-正式升级.sql" >/dev/null
docker run --rm --name "$PHP_CONTAINER" --network "$NETWORK" \
  -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE="$repository_db" \
  -e DB_USERNAME=root -e DB_PASSWORD= \
  -e HOSTNAME="$MYSQL_CONTAINER" -e HOSTPORT=3306 -e DATABASE="$repository_db" \
  -e USERNAME=root -e PASSWORD= -e CACHE_DRIVER=file -e DRIVER=file \
  -v "$ROOT_DIR/后端代码:/var/www/html:ro" \
  -v "$ROOT_DIR/tests:/tests:ro" \
  -v "$TEMP_ENV:/var/www/html/.env:ro" \
  --tmpfs /var/www/html/runtime:rw,size=64m \
  --entrypoint php \
  "$PHP_IMAGE" /tests/customer-care/php/mysql-repository-integration.php \
  | tee "$WORK_DIR/mysql-repository-integration.out"
docker run --rm --name "$PHP_CONTAINER" --network "$NETWORK" \
  -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE="$repository_db" \
  -e DB_USERNAME=root -e DB_PASSWORD= \
  -e HOSTNAME="$MYSQL_CONTAINER" -e HOSTPORT=3306 -e DATABASE="$repository_db" \
  -e USERNAME=root -e PASSWORD= -e CACHE_DRIVER=file -e DRIVER=file \
  -v "$ROOT_DIR/后端代码:/var/www/html:ro" \
  -v "$ROOT_DIR/tests:/tests:ro" \
  -v "$TEMP_ENV:/var/www/html/.env:ro" \
  --tmpfs /var/www/html/runtime:rw,size=64m \
  --entrypoint php \
  "$PHP_IMAGE" /tests/customer-care/php/mysql-concurrency-integration.php \
  | tee "$WORK_DIR/mysql-concurrency-integration.out"
mysql_file "$repository_db" "$MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
mysql_file "$repository_db" "$DOCUMENT_MIGRATION_DIR/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'CARE_REAL_THINKPHP_REPOSITORY_GATE=PASS'

echo 'CUSTOMER_CARE_MYSQL56_MATRIX=PASS'

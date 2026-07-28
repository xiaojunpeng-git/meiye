#!/usr/bin/env bash
set -euo pipefail

package_dir="$(cd "$(dirname "$0")" && pwd)"
c1_dir="$package_dir/../2026-07-27-收银V3命令与幂等底座"
container_name="event-outbox-mysql56-$$"
mysql_image="docker.m.daocloud.io/library/mysql:5.6.51"
failure_log="$(mktemp /tmp/event-outbox-matrix.XXXXXX)"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$container_name" >&2 || true
  fi
  docker rm -f "$container_name" >/dev/null 2>&1 || true
  rm -f "$failure_log"
}
trap cleanup EXIT INT TERM

docker run -d --name "$container_name" --platform linux/amd64 \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$mysql_image" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null

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

version="$(docker exec "$container_name" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$version" == "5.6.51" ]] || { echo "VERSION_MISMATCH=$version" >&2; exit 1; }
echo "MYSQL_VERSION=$version"
echo "MYSQL_IMAGE=$mysql_image"

mysql_sql() {
  local db="$1" sql="$2"
  docker exec "$container_name" mysql -uroot --database="$db" -e "$sql"
}

mysql_file() {
  local db="$1" file="$2"
  docker exec -i "$container_name" mysql -uroot --database="$db" < "$file"
}

init_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$package_dir/../0000-升级登记表初始化.sql"
}

drop_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e "DROP DATABASE IF EXISTS \`$db\`;"
}

register_upgrade() {
  local db="$1" upgrade_key="$2"
  mysql_sql "$db" "
    INSERT INTO eb_database_upgrade_log
      (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
    VALUES
      ('$upgrade_key','matrix dependency','local matrix','','local',NOW(),'Codex','local MySQL 5.6.51 matrix');
  "
}

install_c1() {
  local db="$1"
  mysql_file "$db" "$c1_dir/01-升级前检查.sql" >/dev/null
  mysql_file "$db" "$c1_dir/02-正式升级.sql" >/dev/null
  mysql_file "$db" "$c1_dir/03-升级后验证.sql" >/dev/null
  register_upgrade "$db" "20260727-001-cashier-v3-command-idem"
}

expect_fail_file() {
  local db="$1" file="$2" marker="$3"
  if mysql_file "$db" "$file" >"$failure_log" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  echo "$marker=PASS"
}

fresh_db="event_outbox_fresh"
init_db "$fresh_db"
install_c1 "$fresh_db"
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql"
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql"
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql"
mysql_file "$fresh_db" "$package_dir/03-升级后验证.sql"
echo "FRESH_AND_REPLAY=PASS"
drop_db "$fresh_db"

missing_dependency_db="event_missing_dependency"
init_db "$missing_dependency_db"
expect_fail_file "$missing_dependency_db" "$package_dir/01-升级前检查.sql" "MISSING_C1_DEPENDENCY_REJECTED"
drop_db "$missing_dependency_db"

unregistered_dependency_db="event_unregistered_dependency"
init_db "$unregistered_dependency_db"
mysql_file "$unregistered_dependency_db" "$c1_dir/02-正式升级.sql" >/dev/null
expect_fail_file "$unregistered_dependency_db" "$package_dir/01-升级前检查.sql" "UNREGISTERED_C1_DEPENDENCY_REJECTED"
drop_db "$unregistered_dependency_db"

wrong_dependency_db="event_wrong_dependency"
init_db "$wrong_dependency_db"
install_c1 "$wrong_dependency_db"
mysql_sql "$wrong_dependency_db" "ALTER TABLE eb_cashier_v3_state_context ADD COLUMN unexpected_dependency_column int NOT NULL DEFAULT 0"
expect_fail_file "$wrong_dependency_db" "$package_dir/01-升级前检查.sql" "WRONG_C1_DEPENDENCY_REJECTED"
drop_db "$wrong_dependency_db"

partial_db="event_outbox_partial"
init_db "$partial_db"
install_c1 "$partial_db"
mysql_file "$partial_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" "DROP TABLE eb_cashier_v3_consumer_once"
expect_fail_file "$partial_db" "$package_dir/01-升级前检查.sql" "PARTIAL_TABLE_REJECTED"
drop_db "$partial_db"

wrong_column_db="event_outbox_wrong_column"
init_db "$wrong_column_db"
install_c1 "$wrong_column_db"
mysql_file "$wrong_column_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_column_db" "ALTER TABLE eb_cashier_v3_business_event MODIFY event_key varchar(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''"
expect_fail_file "$wrong_column_db" "$package_dir/01-升级前检查.sql" "WRONG_COLUMN_PRECHECK_REJECTED"
expect_fail_file "$wrong_column_db" "$package_dir/03-升级后验证.sql" "WRONG_COLUMN_VERIFY_REJECTED"
drop_db "$wrong_column_db"

extra_column_db="event_outbox_extra_column"
init_db "$extra_column_db"
install_c1 "$extra_column_db"
mysql_file "$extra_column_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$extra_column_db" "ALTER TABLE eb_cashier_v3_outbox ADD COLUMN unexpected_column int NOT NULL DEFAULT 0"
expect_fail_file "$extra_column_db" "$package_dir/01-升级前检查.sql" "EXTRA_COLUMN_PRECHECK_REJECTED"
drop_db "$extra_column_db"

wrong_index_db="event_outbox_wrong_index"
init_db "$wrong_index_db"
install_c1 "$wrong_index_db"
mysql_file "$wrong_index_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_index_db" "ALTER TABLE eb_cashier_v3_outbox DROP INDEX idx_consumer_status_lease, ADD KEY idx_consumer_status_lease(status,consumer_code,lease_until,id)"
expect_fail_file "$wrong_index_db" "$package_dir/01-升级前检查.sql" "WRONG_INDEX_PRECHECK_REJECTED"
expect_fail_file "$wrong_index_db" "$package_dir/03-升级后验证.sql" "WRONG_INDEX_VERIFY_REJECTED"
drop_db "$wrong_index_db"

extra_index_db="event_outbox_extra_index"
init_db "$extra_index_db"
install_c1 "$extra_index_db"
mysql_file "$extra_index_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$extra_index_db" "ALTER TABLE eb_cashier_v3_outbox_attempt ADD KEY unexpected_index(attempt_no)"
expect_fail_file "$extra_index_db" "$package_dir/01-升级前检查.sql" "EXTRA_INDEX_PRECHECK_REJECTED"
drop_db "$extra_index_db"

legacy_empty_db="event_outbox_legacy_empty"
init_db "$legacy_empty_db"
install_c1 "$legacy_empty_db"
mysql_sql "$legacy_empty_db" "CREATE TABLE eb_cashier_v3_member_event_outbox(id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB"
mysql_file "$legacy_empty_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$legacy_empty_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$legacy_empty_db" "$package_dir/03-升级后验证.sql" >/dev/null
echo "LEGACY_EMPTY_ALLOWED=PASS"
drop_db "$legacy_empty_db"

legacy_nonempty_db="event_outbox_legacy_nonempty"
init_db "$legacy_nonempty_db"
install_c1 "$legacy_nonempty_db"
mysql_sql "$legacy_nonempty_db" "CREATE TABLE eb_cashier_v3_member_event_outbox(id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB; INSERT INTO eb_cashier_v3_member_event_outbox VALUES(NULL)"
expect_fail_file "$legacy_nonempty_db" "$package_dir/01-升级前检查.sql" "LEGACY_NONEMPTY_PRECHECK_REJECTED"
drop_db "$legacy_nonempty_db"

legacy_race_db="event_outbox_legacy_race"
init_db "$legacy_race_db"
install_c1 "$legacy_race_db"
mysql_sql "$legacy_race_db" "CREATE TABLE eb_cashier_v3_member_event_outbox(id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB"
mysql_file "$legacy_race_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$legacy_race_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$legacy_race_db" "INSERT INTO eb_cashier_v3_member_event_outbox VALUES(NULL)"
expect_fail_file "$legacy_race_db" "$package_dir/03-升级后验证.sql" "LEGACY_POST_PRECHECK_WRITE_REJECTED"
drop_db "$legacy_race_db"

zero_version_db="event_outbox_zero_version"
init_db "$zero_version_db"
install_c1 "$zero_version_db"
mysql_file "$zero_version_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$zero_version_db" "INSERT INTO eb_cashier_v3_business_event(event_no,event_key,business_date,aggregate_version) VALUES('EV-ZERO','KEY-ZERO','2026-07-28',0)"
expect_fail_file "$zero_version_db" "$package_dir/03-升级后验证.sql" "ZERO_AGGREGATE_VERSION_REJECTED"
drop_db "$zero_version_db"

registered_db="event_outbox_registered"
init_db "$registered_db"
install_c1 "$registered_db"
register_upgrade "$registered_db" "20260728-003-cashier-v3-event-outbox"
expect_fail_file "$registered_db" "$package_dir/01-升级前检查.sql" "REGISTERED_KEY_REJECTED"
drop_db "$registered_db"

echo "EVENT_OUTBOX_MYSQL56_MATRIX=PASS"
printf 'GATE_PASS=%s\n' \
  EO-SQL-01 EO-SQL-02 EO-SQL-03 EO-SQL-04 EO-SQL-05 EO-SQL-06 EO-SQL-07

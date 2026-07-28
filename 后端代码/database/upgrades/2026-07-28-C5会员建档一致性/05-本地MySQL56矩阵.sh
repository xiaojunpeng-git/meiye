#!/usr/bin/env bash
set -euo pipefail

package_dir="$(cd "$(dirname "$0")" && pwd)"
c1_dir="$package_dir/../2026-07-27-收银V3命令与幂等底座"
event_dir="$package_dir/../2026-07-28-收银V3统一事件与Outbox"
container_name="c5-member-mysql56-$$"
mysql_image="docker.m.daocloud.io/library/mysql:5.6.51"
failure_log="$(mktemp /tmp/c5-member-matrix.XXXXXX)"

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
  mysql_sql "$db" "
    CREATE TABLE eb_user (
      uid int(10) unsigned NOT NULL AUTO_INCREMENT,
      bar_code varchar(32) NOT NULL DEFAULT '',
      PRIMARY KEY (uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  "
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

install_event() {
  local db="$1"
  mysql_file "$db" "$event_dir/01-升级前检查.sql" >/dev/null
  mysql_file "$db" "$event_dir/02-正式升级.sql" >/dev/null
  mysql_file "$db" "$event_dir/03-升级后验证.sql" >/dev/null
  register_upgrade "$db" "20260728-003-cashier-v3-event-outbox"
}

install_dependencies() {
  local db="$1"
  install_c1 "$db"
  install_event "$db"
}

expect_fail_file() {
  local db="$1" file="$2" marker="$3"
  if mysql_file "$db" "$file" >"$failure_log" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  echo "$marker=PASS"
}

fresh_db="c5_member_fresh"
init_db "$fresh_db"
install_dependencies "$fresh_db"
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql"
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql"
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql"
mysql_file "$fresh_db" "$package_dir/03-升级后验证.sql"
legacy_after_c5="$(mysql_sql "$fresh_db" "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_member_event_outbox'" | tail -n 1)"
[[ "$legacy_after_c5" == "0" ]] || { echo "C5_CREATED_LEGACY_OUTBOX=$legacy_after_c5" >&2; exit 1; }
echo "FRESH_REPLAY_AND_NO_LEGACY=PASS"
drop_db "$fresh_db"

missing_dependency_db="c5_member_missing_dependency"
init_db "$missing_dependency_db"
expect_fail_file "$missing_dependency_db" "$package_dir/01-升级前检查.sql" "MISSING_DEPENDENCIES_REJECTED"
drop_db "$missing_dependency_db"

missing_event_db="c5_member_missing_event"
init_db "$missing_event_db"
install_c1 "$missing_event_db"
expect_fail_file "$missing_event_db" "$package_dir/01-升级前检查.sql" "MISSING_EVENT_DEPENDENCY_REJECTED"
drop_db "$missing_event_db"

wrong_dependency_db="c5_member_wrong_dependency"
init_db "$wrong_dependency_db"
install_dependencies "$wrong_dependency_db"
mysql_sql "$wrong_dependency_db" "ALTER TABLE eb_cashier_v3_outbox ADD COLUMN unexpected_dependency_column int NOT NULL DEFAULT 0"
expect_fail_file "$wrong_dependency_db" "$package_dir/01-升级前检查.sql" "WRONG_DEPENDENCY_STRUCTURE_REJECTED"
drop_db "$wrong_dependency_db"

dependency_race_db="c5_member_dependency_race"
init_db "$dependency_race_db"
install_dependencies "$dependency_race_db"
mysql_file "$dependency_race_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$dependency_race_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$dependency_race_db" "DELETE FROM eb_database_upgrade_log WHERE upgrade_key='20260728-003-cashier-v3-event-outbox'"
expect_fail_file "$dependency_race_db" "$package_dir/03-升级后验证.sql" "POSTCHECK_DEPENDENCY_REMOVAL_REJECTED"
drop_db "$dependency_race_db"

partial_db="c5_member_partial"
init_db "$partial_db"
install_dependencies "$partial_db"
mysql_file "$partial_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" "DROP TABLE eb_member_exclusive_service_change"
expect_fail_file "$partial_db" "$package_dir/01-升级前检查.sql" "PARTIAL_TABLE_REJECTED"
drop_db "$partial_db"

wrong_column_db="c5_member_wrong_column"
init_db "$wrong_column_db"
install_dependencies "$wrong_column_db"
mysql_file "$wrong_column_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_column_db" "ALTER TABLE eb_cashier_v3_member_phone_lock MODIFY phone varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''"
expect_fail_file "$wrong_column_db" "$package_dir/01-升级前检查.sql" "WRONG_COLUMN_PRECHECK_REJECTED"
expect_fail_file "$wrong_column_db" "$package_dir/03-升级后验证.sql" "WRONG_COLUMN_VERIFY_REJECTED"
drop_db "$wrong_column_db"

extra_column_db="c5_member_extra_column"
init_db "$extra_column_db"
install_dependencies "$extra_column_db"
mysql_file "$extra_column_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$extra_column_db" "ALTER TABLE eb_member_exclusive_service ADD COLUMN unexpected_column int NOT NULL DEFAULT 0"
expect_fail_file "$extra_column_db" "$package_dir/01-升级前检查.sql" "EXTRA_COLUMN_PRECHECK_REJECTED"
drop_db "$extra_column_db"

wrong_index_db="c5_member_wrong_index"
init_db "$wrong_index_db"
install_dependencies "$wrong_index_db"
mysql_file "$wrong_index_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_index_db" "ALTER TABLE eb_member_exclusive_service_change DROP INDEX idx_member_time, ADD KEY idx_member_time(member_id,id,occurred_at)"
expect_fail_file "$wrong_index_db" "$package_dir/01-升级前检查.sql" "WRONG_INDEX_PRECHECK_REJECTED"
expect_fail_file "$wrong_index_db" "$package_dir/03-升级后验证.sql" "WRONG_INDEX_VERIFY_REJECTED"
drop_db "$wrong_index_db"

extra_index_db="c5_member_extra_index"
init_db "$extra_index_db"
install_dependencies "$extra_index_db"
mysql_file "$extra_index_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$extra_index_db" "ALTER TABLE eb_member_exclusive_service ADD KEY unexpected_index(operator_id)"
expect_fail_file "$extra_index_db" "$package_dir/01-升级前检查.sql" "EXTRA_INDEX_PRECHECK_REJECTED"
drop_db "$extra_index_db"

legacy_empty_db="c5_member_legacy_empty"
init_db "$legacy_empty_db"
install_dependencies "$legacy_empty_db"
mysql_sql "$legacy_empty_db" "CREATE TABLE eb_cashier_v3_member_event_outbox(id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB"
mysql_file "$legacy_empty_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$legacy_empty_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$legacy_empty_db" "$package_dir/03-升级后验证.sql" >/dev/null
echo "LEGACY_EMPTY_ALLOWED=PASS"
drop_db "$legacy_empty_db"

legacy_nonempty_db="c5_member_legacy_nonempty"
init_db "$legacy_nonempty_db"
install_dependencies "$legacy_nonempty_db"
mysql_sql "$legacy_nonempty_db" "CREATE TABLE eb_cashier_v3_member_event_outbox(id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB; INSERT INTO eb_cashier_v3_member_event_outbox VALUES(NULL)"
expect_fail_file "$legacy_nonempty_db" "$package_dir/01-升级前检查.sql" "LEGACY_NONEMPTY_PRECHECK_REJECTED"
drop_db "$legacy_nonempty_db"

legacy_race_db="c5_member_legacy_race"
init_db "$legacy_race_db"
install_dependencies "$legacy_race_db"
mysql_sql "$legacy_race_db" "CREATE TABLE eb_cashier_v3_member_event_outbox(id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB"
mysql_file "$legacy_race_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$legacy_race_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$legacy_race_db" "INSERT INTO eb_cashier_v3_member_event_outbox VALUES(NULL)"
expect_fail_file "$legacy_race_db" "$package_dir/03-升级后验证.sql" "LEGACY_POST_PRECHECK_WRITE_REJECTED"
drop_db "$legacy_race_db"

wrong_bar_index_db="c5_member_wrong_bar_index"
init_db "$wrong_bar_index_db"
install_dependencies "$wrong_bar_index_db"
mysql_file "$wrong_bar_index_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_bar_index_db" "ALTER TABLE eb_user DROP INDEX idx_bar_code, ADD KEY idx_bar_code(bar_code,uid)"
expect_fail_file "$wrong_bar_index_db" "$package_dir/03-升级后验证.sql" "WRONG_BAR_INDEX_REJECTED"
drop_db "$wrong_bar_index_db"

echo "C5_MEMBER_MYSQL56_MATRIX=PASS"
printf 'GATE_PASS=%s\n' \
  C5-SQL-01 C5-SQL-02 C5-SQL-03 C5-SQL-04 C5-SQL-05 C5-SQL-06

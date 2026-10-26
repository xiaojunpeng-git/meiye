#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
package_dir="$repo_dir/后端代码/database/upgrades/2026-07-28-收银V3权益购物车草稿"
c1_dir="$repo_dir/后端代码/database/upgrades/2026-07-27-收银V3命令与幂等底座"
container_name="c2-entitlement-mysql56-$$"
mysql_image="docker.m.daocloud.io/library/mysql:5.6.51"
failure_log="$(mktemp /tmp/c2-entitlement-matrix.XXXXXX)"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$container_name" >&2 || true
    if [[ -s "$failure_log" ]]; then
      sed -n '1,160p' "$failure_log" >&2 || true
    fi
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

mysql_version="$(docker exec "$container_name" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$mysql_version" == "5.6.51" ]] || {
  echo "MYSQL_VERSION_MISMATCH=$mysql_version" >&2
  exit 1
}
echo "MYSQL_VERSION=$mysql_version"
echo "MYSQL_IMAGE=$mysql_image"

mysql_sql() {
  local db="$1" sql="$2"
  docker exec "$container_name" mysql -uroot --database="$db" -e "$sql"
}

mysql_value() {
  local db="$1" sql="$2"
  docker exec "$container_name" mysql -uroot --database="$db" -Nse "$sql"
}

mysql_file() {
  local db="$1" file="$2"
  docker exec -i "$container_name" mysql -uroot --database="$db" < "$file"
}

install_legacy_authorities() {
  local db="$1"
  mysql_sql "$db" "
    CREATE TABLE eb_user (
      uid bigint unsigned NOT NULL AUTO_INCREMENT,
      nickname varchar(60) NOT NULL DEFAULT '', real_name varchar(25) NOT NULL DEFAULT '',
      phone varchar(18) NOT NULL DEFAULT '', avatar varchar(256) NOT NULL DEFAULT '',
      status tinyint NOT NULL DEFAULT 1, is_del tinyint NOT NULL DEFAULT 0,
      delete_time timestamp NULL DEFAULT NULL,
      PRIMARY KEY (uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    CREATE TABLE eb_user_card_holder (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      uid bigint unsigned NOT NULL DEFAULT 0, oid bigint unsigned NOT NULL DEFAULT 0,
      card_name varchar(128) NOT NULL DEFAULT '', card_no varchar(32) NOT NULL DEFAULT '',
      store_id bigint unsigned NOT NULL DEFAULT 0,
      product_type tinyint NOT NULL DEFAULT 0, write_times int unsigned NOT NULL DEFAULT 0,
      write_surplus_times int unsigned NOT NULL DEFAULT 0,
      write_start int unsigned NOT NULL DEFAULT 0, write_end int unsigned NOT NULL DEFAULT 0,
      is_del tinyint NOT NULL DEFAULT 0,
      PRIMARY KEY (id), KEY idx_uid(uid), KEY idx_oid(oid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    CREATE TABLE eb_store_order (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      uid bigint unsigned NOT NULL DEFAULT 0, store_id bigint unsigned NOT NULL DEFAULT 0,
      paid tinyint NOT NULL DEFAULT 0, is_del tinyint NOT NULL DEFAULT 0,
      is_system_del tinyint NOT NULL DEFAULT 0, is_user_del tinyint NOT NULL DEFAULT 0,
      refund_status tinyint NOT NULL DEFAULT 0, terminal_action tinyint NOT NULL DEFAULT 0,
      card_upgrade_use_oid bigint unsigned NOT NULL DEFAULT 0,
      order_id varchar(100) NOT NULL DEFAULT '', mark varchar(512) NOT NULL DEFAULT '', pay_price decimal(12,2) NOT NULL DEFAULT 0,
      cash_pay_price decimal(12,2) NOT NULL DEFAULT 0, yue_pay_price decimal(12,2) NOT NULL DEFAULT 0,
      debt_amount decimal(12,2) NOT NULL DEFAULT 0, repaid_debt_amount decimal(12,2) NOT NULL DEFAULT 0,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    CREATE TABLE eb_store_order_cart_info (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      oid bigint unsigned NOT NULL DEFAULT 0, cart_id varchar(64) NOT NULL DEFAULT '',
      product_id bigint unsigned NOT NULL DEFAULT 0,
      cart_type tinyint NOT NULL DEFAULT 0, product_type tinyint NOT NULL DEFAULT 0,
      cart_info mediumtext,
      write_times int unsigned NOT NULL DEFAULT 0, write_surplus_times int unsigned NOT NULL DEFAULT 0,
      is_writeoff tinyint NOT NULL DEFAULT 0,
      write_start int unsigned NOT NULL DEFAULT 0, write_end int unsigned NOT NULL DEFAULT 0,
      pay_price decimal(12,2) NOT NULL DEFAULT 0, debt_amount decimal(12,2) NOT NULL DEFAULT 0,
      repaid_debt_amount decimal(12,2) NOT NULL DEFAULT 0, is_gift tinyint NOT NULL DEFAULT 0,
      PRIMARY KEY (id), KEY idx_oid(oid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    CREATE TABLE eb_store_reservation_order (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      cart_info_id bigint unsigned NOT NULL DEFAULT 0,
      status tinyint NOT NULL DEFAULT 0, is_del tinyint NOT NULL DEFAULT 0,
      is_system_del tinyint NOT NULL DEFAULT 0,
      PRIMARY KEY (id), KEY idx_cart_info_id(cart_info_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    CREATE TABLE eb_store_debt (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      order_id bigint unsigned NOT NULL DEFAULT 0, status tinyint NOT NULL DEFAULT 0,
      total_debt decimal(12,2) NOT NULL DEFAULT 0, repaid_debt decimal(12,2) NOT NULL DEFAULT 0,
      PRIMARY KEY (id), KEY idx_order_id(order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  " >/dev/null
}

init_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e \
    "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$repo_dir/后端代码/database/upgrades/0000-升级登记表初始化.sql" >/dev/null
  install_legacy_authorities "$db"
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
      ('$upgrade_key','local SQL matrix','tests/cashier-v3/c2-entitlement-sql-matrix.sh','','local',NOW(),'Codex','local MySQL 5.6.51 matrix');
  " >/dev/null
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
  : > "$failure_log"
  echo "$marker=PASS"
}

# Fresh install, exact replay, precheck replay, and non-empty post-verification.
fresh_db="c2_entitlement_fresh"
init_db "$fresh_db"
install_c1 "$fresh_db"
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/03-升级后验证.sql" >/dev/null

mysql_sql "$fresh_db" "
  INSERT INTO eb_cashier_v3_workspace_draft
    (workspace_id,state_context_id,store_id,operator_id,member_id,customer_mode,draft_status,line_fingerprint,add_time,update_time)
  VALUES
    ('ws:101:202:c2-a1','ctx-c2-a1',101,202,303,'member','active',REPEAT('a',64),UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

  INSERT INTO eb_cashier_v3_workspace_line
    (workspace_id,line_key,line_role,member_id,holder_id,source_detail_id,project_id,quantity,source_version,detail_version,service_object,craftsmen_json,display_snapshot_json,sort_no,add_time,update_time)
  VALUES
    ('ws:101:202:c2-a1','sale-line-1','sale',303,0,0,909,1,1,1,'','[]','{\"name\":\"sale\"}',10,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

  INSERT INTO eb_cashier_v3_workspace_line
    (workspace_id,line_key,line_role,member_id,holder_id,source_detail_id,project_id,quantity,source_version,detail_version,service_object,is_experience,craftsmen_json,display_snapshot_json,sort_no,add_time,update_time)
  VALUES
    ('ws:101:202:c2-a1','entitlement-line-1','entitlement_service',303,404,505,606,2,7,9,'self',1,'[{\"staffId\":707}]','{\"name\":\"service\"}',20,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

  INSERT INTO eb_cashier_v3_entitlement_resource_version
    (resource_kind,resource_id,member_id,source_fingerprint,current_version,last_action,add_time,update_time)
  VALUES
    ('card_holder','404',303,REPEAT('b',64),7,'legacy_sync_init',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

  INSERT INTO eb_cashier_v3_workspace_draft
    (workspace_id,state_context_id,store_id,operator_id,draft_status,line_fingerprint,add_time,update_time)
  VALUES
    ('ws:101:202:c2-guest-default','ctx-c2-guest-default',101,202,'active',REPEAT('e',64),UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
" >/dev/null
mysql_file "$fresh_db" "$package_dir/03-升级后验证.sql" >/dev/null

fresh_counts="$(mysql_value "$fresh_db" "SELECT CONCAT((SELECT COUNT(*) FROM eb_cashier_v3_workspace_draft),':',(SELECT COUNT(*) FROM eb_cashier_v3_workspace_line),':',(SELECT COUNT(*) FROM eb_cashier_v3_entitlement_resource_version))")"
[[ "$fresh_counts" == "2:2:1" ]] || {
  echo "NONEMPTY_VERIFY_CHANGED_ROWS=$fresh_counts" >&2
  exit 1
}
guest_default="$(mysql_value "$fresh_db" "SELECT CONCAT(customer_mode,':',member_id) FROM eb_cashier_v3_workspace_draft WHERE workspace_id='ws:101:202:c2-guest-default'")"
[[ "$guest_default" == "guest:0" ]] || {
  echo "GUEST_DEFAULT_MISMATCH=$guest_default" >&2
  exit 1
}
experience_values="$(mysql_value "$fresh_db" "SELECT GROUP_CONCAT(CONCAT(line_key,':',is_experience) ORDER BY line_key SEPARATOR ',') FROM eb_cashier_v3_workspace_line")"
[[ "$experience_values" == "entitlement-line-1:1,sale-line-1:0" ]] || {
  echo "EXPERIENCE_DEFAULT_OR_VALUE_MISMATCH=$experience_values" >&2
  exit 1
}
draft_version_columns="$(mysql_value "$fresh_db" "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_workspace_draft' AND COLUMN_NAME='current_version'")"
[[ "$draft_version_columns" == "0" ]] || {
  echo "DRAFT_DUPLICATES_CURRENT_VERSION=$draft_version_columns" >&2
  exit 1
}
echo "FRESH_REPEAT_NONEMPTY_VERIFY=PASS"
drop_db "$fresh_db"

# Missing C1 registration/structure is fail-closed.
missing_dependency_db="c2_entitlement_missing_dependency"
init_db "$missing_dependency_db"
expect_fail_file "$missing_dependency_db" "$package_dir/01-升级前检查.sql" "MISSING_C1_DEPENDENCY_REJECTED"
drop_db "$missing_dependency_db"

# A subset of target tables cannot be repaired by CREATE IF NOT EXISTS.
partial_db="c2_entitlement_partial"
init_db "$partial_db"
install_c1 "$partial_db"
mysql_file "$partial_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" "DROP TABLE eb_cashier_v3_workspace_line" >/dev/null
expect_fail_file "$partial_db" "$package_dir/01-升级前检查.sql" "PARTIAL_TABLE_PRECHECK_REJECTED"
expect_fail_file "$partial_db" "$package_dir/03-升级后验证.sql" "PARTIAL_TABLE_VERIFY_REJECTED"
drop_db "$partial_db"

# Same-name heterogeneous engine is rejected.
heterogeneous_db="c2_entitlement_heterogeneous"
init_db "$heterogeneous_db"
install_c1 "$heterogeneous_db"
mysql_file "$heterogeneous_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$heterogeneous_db" "ALTER TABLE eb_cashier_v3_workspace_draft ENGINE=MyISAM" >/dev/null
expect_fail_file "$heterogeneous_db" "$package_dir/01-升级前检查.sql" "HETEROGENEOUS_ENGINE_PRECHECK_REJECTED"
expect_fail_file "$heterogeneous_db" "$package_dir/03-升级后验证.sql" "HETEROGENEOUS_ENGINE_VERIFY_REJECTED"
drop_db "$heterogeneous_db"

# Wrong column type is rejected by both exact gates.
wrong_column_db="c2_entitlement_wrong_column"
init_db "$wrong_column_db"
install_c1 "$wrong_column_db"
mysql_file "$wrong_column_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_column_db" "ALTER TABLE eb_cashier_v3_workspace_line MODIFY quantity bigint(20) unsigned NOT NULL DEFAULT '1'" >/dev/null
expect_fail_file "$wrong_column_db" "$package_dir/01-升级前检查.sql" "WRONG_COLUMN_PRECHECK_REJECTED"
expect_fail_file "$wrong_column_db" "$package_dir/03-升级后验证.sql" "WRONG_COLUMN_VERIFY_REJECTED"
drop_db "$wrong_column_db"

# Both new defaults are exact metadata: guest for a fresh draft and 0 for a normal line.
wrong_default_db="c2_entitlement_wrong_default"
init_db "$wrong_default_db"
install_c1 "$wrong_default_db"
mysql_file "$wrong_default_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_default_db" "ALTER TABLE eb_cashier_v3_workspace_draft MODIFY customer_mode varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''" >/dev/null
expect_fail_file "$wrong_default_db" "$package_dir/01-升级前检查.sql" "WRONG_GUEST_DEFAULT_PRECHECK_REJECTED"
expect_fail_file "$wrong_default_db" "$package_dir/03-升级后验证.sql" "WRONG_GUEST_DEFAULT_VERIFY_REJECTED"
mysql_sql "$wrong_default_db" "ALTER TABLE eb_cashier_v3_workspace_draft MODIFY customer_mode varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'guest'" >/dev/null
mysql_sql "$wrong_default_db" "ALTER TABLE eb_cashier_v3_workspace_line MODIFY is_experience tinyint(3) unsigned NOT NULL DEFAULT '1'" >/dev/null
expect_fail_file "$wrong_default_db" "$package_dir/01-升级前检查.sql" "WRONG_EXPERIENCE_DEFAULT_PRECHECK_REJECTED"
expect_fail_file "$wrong_default_db" "$package_dir/03-升级后验证.sql" "WRONG_EXPERIENCE_DEFAULT_VERIFY_REJECTED"
drop_db "$wrong_default_db"

# Same index name with reordered columns is not accepted.
wrong_index_db="c2_entitlement_wrong_index"
init_db "$wrong_index_db"
install_c1 "$wrong_index_db"
mysql_file "$wrong_index_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_index_db" "ALTER TABLE eb_cashier_v3_workspace_line DROP INDEX idx_member_role, ADD KEY idx_member_role(line_role,member_id)" >/dev/null
expect_fail_file "$wrong_index_db" "$package_dir/01-升级前检查.sql" "WRONG_INDEX_PRECHECK_REJECTED"
expect_fail_file "$wrong_index_db" "$package_dir/03-升级后验证.sql" "WRONG_INDEX_VERIFY_REJECTED"
drop_db "$wrong_index_db"

# Table default collation is an exact part of the contract.
wrong_collation_db="c2_entitlement_wrong_collation"
init_db "$wrong_collation_db"
install_c1 "$wrong_collation_db"
mysql_file "$wrong_collation_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_collation_db" "ALTER TABLE eb_cashier_v3_workspace_line DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >/dev/null
expect_fail_file "$wrong_collation_db" "$package_dir/01-升级前检查.sql" "WRONG_COLLATION_PRECHECK_REJECTED"
expect_fail_file "$wrong_collation_db" "$package_dir/03-升级后验证.sql" "WRONG_COLLATION_VERIFY_REJECTED"
drop_db "$wrong_collation_db"

# Prefix indexes are forbidden even when names and indexed columns look correct.
prefix_index_db="c2_entitlement_prefix_index"
init_db "$prefix_index_db"
install_c1 "$prefix_index_db"
mysql_file "$prefix_index_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$prefix_index_db" "ALTER TABLE eb_cashier_v3_workspace_line DROP INDEX uk_workspace_line, ADD UNIQUE KEY uk_workspace_line(workspace_id(32),line_key(32))" >/dev/null
expect_fail_file "$prefix_index_db" "$package_dir/01-升级前检查.sql" "SUB_PART_PRECHECK_REJECTED"
expect_fail_file "$prefix_index_db" "$package_dir/03-升级后验证.sql" "SUB_PART_VERIFY_REJECTED"
drop_db "$prefix_index_db"

# Positive-version semantics are checked against persisted rows.
zero_version_db="c2_entitlement_zero_version"
init_db "$zero_version_db"
install_c1 "$zero_version_db"
mysql_file "$zero_version_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$zero_version_db" "
  INSERT INTO eb_cashier_v3_entitlement_resource_version
    (resource_kind,resource_id,member_id,source_fingerprint,current_version,last_action,add_time,update_time)
  VALUES ('card_holder','1',3,REPEAT('c',64),0,'invalid-fixture',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
" >/dev/null
expect_fail_file "$zero_version_db" "$package_dir/01-升级前检查.sql" "ZERO_VERSION_PRECHECK_REJECTED"
expect_fail_file "$zero_version_db" "$package_dir/03-升级后验证.sql" "ZERO_VERSION_VERIFY_REJECTED"
drop_db "$zero_version_db"

# Unsupported legacy resource aliases and composite pseudo IDs are rejected.
invalid_resource_db="c2_entitlement_invalid_resource"
init_db "$invalid_resource_db"
install_c1 "$invalid_resource_db"
mysql_file "$invalid_resource_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$invalid_resource_db" "
  INSERT INTO eb_cashier_v3_entitlement_resource_version
    (resource_kind,resource_id,member_id,source_fingerprint,current_version,last_action,add_time,update_time)
  VALUES ('entitlement_instance','holder:1:detail:2',3,REPEAT('d',64),1,'invalid-fixture',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
" >/dev/null
expect_fail_file "$invalid_resource_db" "$package_dir/01-升级前检查.sql" "INVALID_RESOURCE_ALIAS_PRECHECK_REJECTED"
expect_fail_file "$invalid_resource_db" "$package_dir/03-升级后验证.sql" "INVALID_RESOURCE_ALIAS_VERIFY_REJECTED"
drop_db "$invalid_resource_db"

# MySQL 5.6 has no reliable CHECK enforcement; persisted experience flags are gated explicitly.
invalid_experience_db="c2_entitlement_invalid_experience"
init_db "$invalid_experience_db"
install_c1 "$invalid_experience_db"
mysql_file "$invalid_experience_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$invalid_experience_db" "
  INSERT INTO eb_cashier_v3_workspace_draft
    (workspace_id,state_context_id,store_id,operator_id,draft_status,line_fingerprint,add_time,update_time)
  VALUES ('ws:experience-invalid','ctx-experience-invalid',1,2,'active',REPEAT('f',64),UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
  INSERT INTO eb_cashier_v3_workspace_line
    (workspace_id,line_key,line_role,project_id,is_experience,add_time,update_time)
  VALUES ('ws:experience-invalid','sale-line-invalid-experience','sale',9,2,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
" >/dev/null
expect_fail_file "$invalid_experience_db" "$package_dir/01-升级前检查.sql" "INVALID_EXPERIENCE_PRECHECK_REJECTED"
expect_fail_file "$invalid_experience_db" "$package_dir/03-升级后验证.sql" "INVALID_EXPERIENCE_VERIFY_REJECTED"
drop_db "$invalid_experience_db"

# Legacy authority columns are deployment prerequisites and are rechecked by 03.
legacy_column_db="c2_entitlement_legacy_column"
init_db "$legacy_column_db"
install_c1 "$legacy_column_db"
mysql_file "$legacy_column_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$legacy_column_db" "ALTER TABLE eb_store_order DROP COLUMN mark" >/dev/null
expect_fail_file "$legacy_column_db" "$package_dir/01-升级前检查.sql" "LEGACY_COLUMN_PRECHECK_REJECTED"
expect_fail_file "$legacy_column_db" "$package_dir/03-升级后验证.sql" "LEGACY_COLUMN_VERIFY_REJECTED"
drop_db "$legacy_column_db"

# Both the authority primary key and the required access-leading indexes are mandatory.
legacy_primary_db="c2_entitlement_legacy_primary"
init_db "$legacy_primary_db"
install_c1 "$legacy_primary_db"
mysql_file "$legacy_primary_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$legacy_primary_db" "ALTER TABLE eb_user_card_holder DROP PRIMARY KEY, ADD KEY idx_id(id)" >/dev/null
expect_fail_file "$legacy_primary_db" "$package_dir/01-升级前检查.sql" "LEGACY_PRIMARY_PRECHECK_REJECTED"
expect_fail_file "$legacy_primary_db" "$package_dir/03-升级后验证.sql" "LEGACY_PRIMARY_VERIFY_REJECTED"
drop_db "$legacy_primary_db"

legacy_access_db="c2_entitlement_legacy_access"
init_db "$legacy_access_db"
install_c1 "$legacy_access_db"
mysql_file "$legacy_access_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$legacy_access_db" "ALTER TABLE eb_store_debt DROP INDEX idx_order_id" >/dev/null
expect_fail_file "$legacy_access_db" "$package_dir/01-升级前检查.sql" "LEGACY_ACCESS_INDEX_PRECHECK_REJECTED"
expect_fail_file "$legacy_access_db" "$package_dir/03-升级后验证.sql" "LEGACY_ACCESS_INDEX_VERIFY_REJECTED"
drop_db "$legacy_access_db"

# Row locks and transaction semantics require every legacy authority to remain InnoDB.
legacy_engine_db="c2_entitlement_legacy_engine"
init_db "$legacy_engine_db"
install_c1 "$legacy_engine_db"
mysql_file "$legacy_engine_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$legacy_engine_db" "ALTER TABLE eb_user_card_holder ENGINE=MyISAM" >/dev/null
expect_fail_file "$legacy_engine_db" "$package_dir/01-升级前检查.sql" "LEGACY_ENGINE_PRECHECK_REJECTED"
expect_fail_file "$legacy_engine_db" "$package_dir/03-升级后验证.sql" "LEGACY_ENGINE_VERIFY_REJECTED"
drop_db "$legacy_engine_db"

# Once execution is registered, 01 must refuse reuse while 03 remains rerunnable.
used_key_db="c2_entitlement_used_key"
init_db "$used_key_db"
install_c1 "$used_key_db"
mysql_file "$used_key_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$used_key_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$used_key_db" "$package_dir/03-升级后验证.sql" >/dev/null
register_upgrade "$used_key_db" "20260728-005-cashier-v3-entitlement-draft"
expect_fail_file "$used_key_db" "$package_dir/01-升级前检查.sql" "USED_UPGRADE_KEY_REJECTED"
mysql_file "$used_key_db" "$package_dir/03-升级后验证.sql" >/dev/null
echo "POSTCHECK_AFTER_REGISTRATION=PASS"
drop_db "$used_key_db"

echo "C2_ENTITLEMENT_MYSQL56_MATRIX=PASS"
printf 'GATE_PASS=%s\n' \
  C2-A1-SQL-01 C2-A1-SQL-02 C2-A1-SQL-03 C2-A1-SQL-04 \
  C2-A1-SQL-05 C2-A1-SQL-06 C2-A1-SQL-07 C2-A1-SQL-08 \
  C2-A1-SQL-09 C2-A1-SQL-10 C2-A1-SQL-11 C2-A1-SQL-12 \
  C2-A1-SQL-13

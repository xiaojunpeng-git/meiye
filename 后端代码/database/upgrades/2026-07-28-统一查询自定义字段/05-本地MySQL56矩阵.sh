#!/usr/bin/env bash
set -euo pipefail

package_dir="$(cd "$(dirname "$0")" && pwd)"
upgrades_dir="$(cd "$package_dir/.." && pwd)"
c1_dir="$upgrades_dir/2026-07-27-收银V3命令与幂等底座"
container_name="unified-query-mysql56-$$"
mysql_image="docker.m.daocloud.io/library/mysql:5.6.51"
failure_log="$(mktemp /tmp/unified-query-matrix.XXXXXX)"
postcheck_log="$(mktemp /tmp/unified-query-postcheck.XXXXXX)"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$container_name" >&2 || true
  fi
  docker rm -f "$container_name" >/dev/null 2>&1 || true
  rm -f "$failure_log" "$postcheck_log"
}
trap cleanup EXIT INT TERM

(cd "$package_dir" && shasum -a 256 -c SHA256SUMS.txt)

# 本机 arm64 Docker 会为 MySQL 5.6.51 选择兼容启动路径；强制 amd64 在初始化
# 阶段会异常退出。启动后仍严格断言精确 5.6.51 版本，避免兼容证据漂移。
docker run -d --name "$container_name" \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$mysql_image" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  if docker logs "$container_name" 2>&1 \
      | grep -F 'MySQL init process done. Ready for start up.' >/dev/null; then
    if docker exec "$container_name" mysqladmin ping -uroot --silent >/dev/null 2>&1; then
      break
    fi
  fi
  sleep 1
done
docker logs "$container_name" 2>&1 \
  | grep -F 'MySQL init process done. Ready for start up.' >/dev/null
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

init_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e \
    "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$upgrades_dir/0000-升级登记表初始化.sql" >/dev/null
  mysql_file "$db" "$c1_dir/02-正式升级.sql" >/dev/null
  mysql_sql "$db" "
    INSERT INTO eb_database_upgrade_log
      (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
    VALUES
      ('20260727-001-cashier-v3-command-idem','matrix dependency','local matrix','','local',NOW(),'Codex','local MySQL 5.6.51 matrix');
  " >/dev/null
}

drop_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e "DROP DATABASE IF EXISTS $db;"
}

expect_fail_file() {
  local db="$1" file="$2" marker="$3"
  if mysql_file "$db" "$file" >"$failure_log" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  echo "$marker=PASS"
}

seed_valid_metadata() {
  local db="$1"
  mysql_sql "$db" "
    INSERT INTO eb_unified_query_custom_field
      (id,field_key,tenant_id,page_code,name,name_namespace,active_name_key,return_type,
       visibility,scope_type,scope_id,owner_account_id,status,current_version,
       expression_hash,complexity_score,created_by,updated_by,created_at,updated_at)
    VALUES
      (1,'cf_000000000000000000000000','0','member_list','测试金额','account:1',
       SHA2('测试金额',256),'amount','personal','account','1',1,'active',1,
       SHA2('expression',256),3,1,1,1,1);
    INSERT INTO eb_unified_query_custom_field_version
      (custom_field_id,tenant_id,field_key,version,name,return_type,expression,
       expression_hash,referenced_fields,referenced_field_contract,
       required_permissions,complexity_score,
       visibility,scope_type,scope_id,status,created_by,created_at)
    VALUES
      (1,'0','cf_000000000000000000000000',1,'测试金额','amount',
       '{\"type\":\"field\",\"key\":\"account_balance\",\"_return_type\":\"amount\"}',
       SHA2('expression',256),'[\"account_balance\"]',
       '{\"account_balance\":{\"type\":\"amount\",\"permission\":\"\"}}','[]',3,
       'personal','account','1','active',1,1);
    INSERT INTO eb_unified_query_field_alias_set
      (tenant_id,account_id,page_code,current_version,created_at,updated_at)
    VALUES ('0',1,'member_list',1,1,1);
    INSERT INTO eb_unified_query_field_alias
      (tenant_id,account_id,page_code,field_key,alias,alias_name_key,alias_version,created_at,updated_at)
    VALUES ('0',1,'member_list','phone','联系电话',SHA2('联系电话',256),1,1,1);
    INSERT INTO eb_unified_query_preference
      (tenant_id,account_id,page_code,settings,settings_hash,referenced_field_versions,
       current_version,created_at,updated_at)
    VALUES ('0',1,'member_list','{}',SHA2('{}',256),
       '{\"cf_000000000000000000000000\":1}',1,1,1);
    INSERT INTO eb_unified_query_export_task
      (task_no,tenant_id,account_id,operator_id,page_code,export_scope,status,
       query_payload,field_snapshot,alias_snapshot,permission_fingerprint,
       frozen_scope,query_cutoff_date,data_as_of,file_name,created_at,updated_at)
    VALUES ('uqe_matrix','0',1,1,'member_list','query','pending',
       '{}','[]','{}',SHA2('permission',256),'{}','2026-07-28',1,'matrix.xlsx',1,1);
    INSERT INTO eb_unified_query_field_reference
      (tenant_id,consumer_type,consumer_id,custom_field_id,field_key,field_version,
       status,query_cutoff_date,created_at,updated_at)
    VALUES ('0','export_task','uqe_matrix',1,'cf_000000000000000000000000',1,
       'active','2026-07-28',1,1);
  " >/dev/null
}

fresh_db="uq_fresh"
init_db "$fresh_db"
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" >/dev/null
seed_valid_metadata "$fresh_db"
mysql_file "$fresh_db" "$package_dir/03-升级后验证.sql" | tee "$postcheck_log"
grep -q 'POSTCHECK_OK' "$postcheck_log"
grep -q 'idx_page_visibility_owner_status' "$postcheck_log"
grep -q 'uk_alias_field' "$postcheck_log"
grep -q 'idx_field_status' "$postcheck_log"
grep -q 'idx_account_status_created' "$postcheck_log"
grep -q 'idx_status_lease' "$postcheck_log"
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_sql "$fresh_db" "
  UPDATE eb_unified_query_export_task
  SET lease_expires_at=1
  WHERE task_no='uqe_matrix' AND status='pending';
" >/dev/null
expect_fail_file "$fresh_db" "$package_dir/01-升级前检查.sql" "PENDING_LEASE_PRECHECK_REJECTED"
expect_fail_file "$fresh_db" "$package_dir/03-升级后验证.sql" "PENDING_LEASE_POSTCHECK_REJECTED"
if mysql_sql "$fresh_db" "
  INSERT INTO eb_unified_query_custom_field
    (field_key,tenant_id,page_code,name,name_namespace,active_name_key,return_type,
     visibility,scope_type,scope_id,owner_account_id,status,current_version,expression_hash)
  VALUES
    ('cf_111111111111111111111111','0','member_list','重复名','account:1',
     SHA2('测试金额',256),'amount','personal','account','1',1,'active',1,SHA2('x',256));
" >"$failure_log" 2>&1; then
  echo "EXPECTED_FAILURE_MISSING=ACTIVE_NAME_UNIQUE" >&2
  exit 1
fi
echo "FRESH_REPLAY_NONEMPTY_EXPLAIN=PASS"
echo "ACTIVE_NAME_UNIQUE=PASS"
drop_db "$fresh_db"

partial_db="uq_partial"
init_db "$partial_db"
mysql_file "$partial_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" "DROP TABLE eb_unified_query_preference" >/dev/null
expect_fail_file "$partial_db" "$package_dir/01-升级前检查.sql" "PARTIAL_TABLE_REJECTED"
drop_db "$partial_db"

wrong_column_db="uq_wrong_column"
init_db "$wrong_column_db"
mysql_file "$wrong_column_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_column_db" "ALTER TABLE eb_unified_query_custom_field MODIFY name varchar(65) NOT NULL DEFAULT ''" >/dev/null
expect_fail_file "$wrong_column_db" "$package_dir/01-升级前检查.sql" "WRONG_COLUMN_PRECHECK_REJECTED"
expect_fail_file "$wrong_column_db" "$package_dir/03-升级后验证.sql" "WRONG_COLUMN_POSTCHECK_REJECTED"
drop_db "$wrong_column_db"

wrong_index_db="uq_wrong_index"
init_db "$wrong_index_db"
mysql_file "$wrong_index_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_index_db" "ALTER TABLE eb_unified_query_field_reference DROP INDEX idx_field_status, ADD KEY idx_field_status(field_key,tenant_id,status)" >/dev/null
expect_fail_file "$wrong_index_db" "$package_dir/01-升级前检查.sql" "WRONG_INDEX_REJECTED"
drop_db "$wrong_index_db"

wrong_engine_db="uq_wrong_engine"
init_db "$wrong_engine_db"
mysql_file "$wrong_engine_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_engine_db" "ALTER TABLE eb_unified_query_field_alias ENGINE=MyISAM" >/dev/null
expect_fail_file "$wrong_engine_db" "$package_dir/01-升级前检查.sql" "WRONG_ENGINE_REJECTED"
drop_db "$wrong_engine_db"

wrong_collation_db="uq_wrong_collation"
init_db "$wrong_collation_db"
mysql_file "$wrong_collation_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_collation_db" "ALTER TABLE eb_unified_query_preference DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >/dev/null
expect_fail_file "$wrong_collation_db" "$package_dir/01-升级前检查.sql" "WRONG_COLLATION_REJECTED"
drop_db "$wrong_collation_db"

registered_db="uq_registered"
init_db "$registered_db"
mysql_sql "$registered_db" "
  INSERT INTO eb_database_upgrade_log
    (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
  VALUES
    ('20260728-004-unified-query-custom-fields','matrix','','','local',NOW(),'Codex','registered');
" >/dev/null
expect_fail_file "$registered_db" "$package_dir/01-升级前检查.sql" "REGISTERED_KEY_REJECTED"
drop_db "$registered_db"

semantic_db="uq_semantic"
init_db "$semantic_db"
mysql_file "$semantic_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$semantic_db" "
  INSERT INTO eb_unified_query_custom_field
    (field_key,tenant_id,page_code,name,name_namespace,active_name_key,return_type,
     visibility,scope_type,scope_id,status,current_version,expression_hash)
  VALUES
    ('cf_222222222222222222222222','0','member_list','坏版本','account:1',
     SHA2('坏版本',256),'amount','personal','account','1','active',0,SHA2('x',256));
" >/dev/null
expect_fail_file "$semantic_db" "$package_dir/03-升级后验证.sql" "ZERO_VERSION_REJECTED"
drop_db "$semantic_db"

echo "UNIFIED_QUERY_MYSQL56_MATRIX=PASS"
printf 'GATE_PASS=%s\n' \
  UQ-SQL-01 UQ-SQL-02 UQ-SQL-03 UQ-SQL-04 \
  UQ-SQL-05 UQ-SQL-06 UQ-SQL-07 UQ-SQL-08 UQ-SQL-09

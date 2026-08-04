#!/usr/bin/env bash
set -euo pipefail

package_dir="$(cd "$(dirname "$0")" && pwd)"
upgrades_dir="$(cd "$package_dir/.." && pwd)"
container_name="checkout-settlement-mysql56-$$"
mysql_image="docker.m.daocloud.io/library/mysql:5.6.51"
failure_log="$(mktemp /tmp/checkout-settlement-matrix.XXXXXX)"
postcheck_log="$(mktemp /tmp/checkout-settlement-postcheck.XXXXXX)"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$container_name" >&2 || true
  fi
  docker rm -f "$container_name" >/dev/null 2>&1 || true
  rm -f "$failure_log" "$postcheck_log"
}
trap cleanup EXIT INT TERM

if [[ -f "$package_dir/SHA256SUMS.txt" ]]; then
  (cd "$package_dir" && shasum -a 256 -c SHA256SUMS.txt)
fi

docker run -d --name "$container_name" \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$mysql_image" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  if docker logs "$container_name" 2>&1 \
      | grep -F 'MySQL init process done. Ready for start up.' >/dev/null \
      && docker exec "$container_name" mysqladmin ping -uroot --silent >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
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

mysql_scalar() {
  local db="$1" sql="$2"
  docker exec "$container_name" mysql -uroot --batch --skip-column-names --database="$db" -e "$sql"
}

init_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e \
    "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$upgrades_dir/0000-升级登记表初始化.sql" >/dev/null
}

drop_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e "DROP DATABASE IF EXISTS \`$db\`;"
}

expect_fail_file() {
  local db="$1" file="$2" marker="$3"
  if mysql_file "$db" "$file" >"$failure_log" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  echo "$marker=PASS"
}

expect_fail_sql() {
  local db="$1" sql="$2" marker="$3"
  if mysql_sql "$db" "$sql" >"$failure_log" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  echo "$marker=PASS"
}

seed_valid_request() {
  local db="$1"
  mysql_sql "$db" "
    INSERT INTO eb_cashier_v3_checkout_request (
      request_id,tenant_id,organization_id,organization_path,organization_name_snapshot,
      workspace_id,state_context_id,store_id,store_name_snapshot,member_id,member_name_snapshot,
      operator_id,operator_name_snapshot,request_version,request_status,composition,business_date,
      business_timezone,operation_occurred_at,recorded_at,source_document_type,source_document_id,
      source_document_no,sales_amount_cents,receivable_amount_cents,selected_payment_amount_cents,
      cash_performance_amount_cents,authority_snapshot_version,authority_fingerprint,
      aggregate_fingerprint,creation_idempotency_key,last_idempotency_key,
      last_operation_fingerprint,last_operation,add_time,update_time
    ) VALUES (
      'CKR-0000000000000000000000000000000000000001','0','3','/1/3/','matrix org',
      'workspace-1','state-1',7,'matrix store',1001,'matrix member',21,'matrix operator',
      1,'ready_for_submit','sale_only','2026-07-20','Asia/Shanghai',1785258000,1785258002,
      'cashier_workspace','workspace-1','CASHIER-MATRIX-1',9000,9000,9000,9000,1,
      REPEAT('a',64),REPEAT('b',64),'CHECKOUT-00000000-0000-4000-8000-000000000001',
      'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000002',REPEAT('c',64),
      'prepare_submission',1785258000,1785258002
    );
    INSERT INTO eb_cashier_v3_checkout_line_draft (
      line_id,request_id,draft_version,draft_status,tenant_id,store_id,member_id,line_role,authority_key,source_kind,
      source_type,source_id,entitlement_source_detail_id,source_version,project_id,project_version,quantity,original_amount_cents,
      discount_amount_cents,sale_amount_cents,source_name_snapshot,source_code_snapshot,
      project_name_snapshot,
      category_id_snapshot,category_name_snapshot,line_fingerprint,sort_no,add_time,update_time
    ) VALUES (
      'CKL-0000000000000000000000000000000000000001',
      'CKR-0000000000000000000000000000000000000001',1,'draft','0',7,1001,'sale','sale:501',
      'project','project',501,0,9,501,9,1,10000,1000,9000,'matrix project','PROJECT-501','matrix project',
      51,'matrix category',REPEAT('d',64),1,1785258000,1785258002
    );
    INSERT INTO eb_cashier_v3_checkout_payment_draft (
      payment_draft_id,request_id,draft_version,tenant_id,store_id,member_id,operator_id,payment_authority_key,
      payment_method,amount_cents,business_date,business_timezone,operation_occurred_at,recorded_at,
      operator_name_snapshot,source_document_type,source_document_id,source_document_no,
      payment_fingerprint,draft_status,sort_no,add_time,update_time
    ) VALUES (
      'CKP-0000000000000000000000000000000000000001',
      'CKR-0000000000000000000000000000000000000001',1,'0',7,1001,21,'payment:unionpay',
      'unionpay',9000,'2026-07-20','Asia/Shanghai',1785258000,1785258002,'matrix operator',
      'cashier_workspace','workspace-1','CASHIER-MATRIX-1',REPEAT('e',64),'draft',1,
      1785258000,1785258002
    );
  " >/dev/null
}

fresh_db="checkout_settlement_fresh"
init_db "$fresh_db"
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" >/dev/null
seed_valid_request "$fresh_db"
mysql_file "$fresh_db" "$package_dir/03-升级后验证.sql" | tee "$postcheck_log"
grep -F 'POSTCHECK_OK' "$postcheck_log" >/dev/null
grep -F 'idx_tenant_workspace_status' "$postcheck_log" >/dev/null
grep -F 'idx_request_role_sort' "$postcheck_log" >/dev/null
grep -F 'idx_scope_method_date' "$postcheck_log" >/dev/null
echo "FRESH_REPLAY_POSTCHECK_EXPLAIN=PASS"

cas_first="$(mysql_scalar "$fresh_db" "
  UPDATE eb_cashier_v3_checkout_request
  SET request_version=2,update_time=update_time+1
  WHERE request_id='CKR-0000000000000000000000000000000000000001' AND request_version=1;
  SELECT ROW_COUNT();
")"
cas_stale="$(mysql_scalar "$fresh_db" "
  UPDATE eb_cashier_v3_checkout_request
  SET request_version=3,update_time=update_time+1
  WHERE request_id='CKR-0000000000000000000000000000000000000001' AND request_version=1;
  SELECT ROW_COUNT();
")"
[[ "$cas_first" == "1" && "$cas_stale" == "0" ]] \
  || { echo "CAS_CONTRACT_FAILED first=$cas_first stale=$cas_stale" >&2; exit 1; }
echo "REQUEST_VERSION_CAS=PASS"

expect_fail_sql "$fresh_db" "
  INSERT INTO eb_cashier_v3_checkout_request
    (request_id,tenant_id,creation_idempotency_key,business_date)
  VALUES
    ('CKR-0000000000000000000000000000000000000002','0',
     'CHECKOUT-00000000-0000-4000-8000-000000000001','2026-07-20');
" "CREATION_IDEMPOTENCY_UNIQUE"
expect_fail_sql "$fresh_db" "
  INSERT INTO eb_cashier_v3_checkout_payment_draft
    (payment_draft_id,request_id,draft_version,payment_authority_key,payment_method,business_date)
  VALUES
    ('CKP-0000000000000000000000000000000000000002',
     'CKR-0000000000000000000000000000000000000001',1,'payment:unionpay:second',
     'unionpay','2026-07-20');
" "REQUEST_PAYMENT_METHOD_UNIQUE"
drop_db "$fresh_db"

partial_db="checkout_settlement_partial"
init_db "$partial_db"
mysql_file "$partial_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" "DROP TABLE eb_cashier_v3_checkout_payment_draft" >/dev/null
expect_fail_file "$partial_db" "$package_dir/01-升级前检查.sql" "PARTIAL_DDL_SAFE_GATE"
mysql_file "$partial_db" "$package_dir/05-部分创表恢复审计.sql" >"$postcheck_log"
grep -F 'EMPTY_PARTIAL_DDL_REVIEW_REQUIRED_NO_MUTATION' "$postcheck_log" >/dev/null
[[ "$(mysql_scalar "$partial_db" "
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'eb_cashier_v3_checkout_%';
")" == "2" ]] || { echo "RECOVERY_AUDIT_MUTATED_PARTIAL_DDL" >&2; exit 1; }
echo "PARTIAL_DDL_RECOVERY_AUDIT_NO_MUTATION=PASS"
drop_db "$partial_db"

unregistered_full_db="checkout_settlement_unregistered_full"
init_db "$unregistered_full_db"
mysql_file "$unregistered_full_db" "$package_dir/02-正式升级.sql" >/dev/null
expect_fail_file "$unregistered_full_db" "$package_dir/01-升级前检查.sql" "UNREGISTERED_FULL_DDL_SAFE_GATE"
mysql_file "$unregistered_full_db" "$package_dir/05-部分创表恢复审计.sql" >"$postcheck_log"
grep -F 'EMPTY_FULL_UNREGISTERED_DDL_REVIEW_REQUIRED_NO_MUTATION' "$postcheck_log" >/dev/null
drop_db "$unregistered_full_db"

wrong_column_db="checkout_settlement_wrong_column"
init_db "$wrong_column_db"
mysql_file "$wrong_column_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_column_db" \
  "ALTER TABLE eb_cashier_v3_checkout_payment_draft MODIFY amount_cents decimal(18,2) NOT NULL DEFAULT 0" >/dev/null
expect_fail_file "$wrong_column_db" "$package_dir/03-升级后验证.sql" "WRONG_MONEY_COLUMN_REJECTED"
drop_db "$wrong_column_db"

bad_row_db="checkout_settlement_bad_row"
init_db "$bad_row_db"
mysql_file "$bad_row_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$bad_row_db" "
  INSERT INTO eb_cashier_v3_checkout_payment_draft
    (payment_draft_id,request_id,draft_version,tenant_id,store_id,operator_id,payment_authority_key,
     payment_method,amount_cents,business_date,business_timezone,operation_occurred_at,
     recorded_at,operator_name_snapshot,source_document_type,source_document_id,
     source_document_no,payment_fingerprint,draft_status)
  VALUES
    ('CKP-0000000000000000000000000000000000000003',
     'CKR-0000000000000000000000000000000000000003',1,'0',7,21,'payment:cash',
     'cash',100,'2026-07-20','Asia/Shanghai',1,1,'operator','workspace','workspace-3',
     'DRAFT-3',REPEAT('f',64),'draft');
" >/dev/null
expect_fail_file "$bad_row_db" "$package_dir/03-升级后验证.sql" "PAPER_CASH_ROW_REJECTED"
mysql_file "$bad_row_db" "$package_dir/05-部分创表恢复审计.sql" >"$postcheck_log"
grep -F 'RECOVERY_BLOCKED_NO_MUTATION' "$postcheck_log" >/dev/null
echo "NONEMPTY_RECOVERY_AUDIT_BLOCKED=PASS"
drop_db "$bad_row_db"

registered_db="checkout_settlement_registered"
init_db "$registered_db"
mysql_sql "$registered_db" "
  INSERT INTO eb_database_upgrade_log
    (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
  VALUES
    ('20260729-004-cashier-v3-checkout-settlement','matrix','','','local',NOW(),'Codex','registered');
" >/dev/null
expect_fail_file "$registered_db" "$package_dir/01-升级前检查.sql" "REGISTERED_UPGRADE_REJECTED"
drop_db "$registered_db"

missing_registry_db="checkout_settlement_missing_registry"
docker exec "$container_name" mysql -uroot -e \
  "DROP DATABASE IF EXISTS \`$missing_registry_db\`; CREATE DATABASE \`$missing_registry_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
expect_fail_file "$missing_registry_db" "$package_dir/01-升级前检查.sql" "MISSING_UPGRADE_REGISTRY_REJECTED"
drop_db "$missing_registry_db"

echo "CHECKOUT_SETTLEMENT_MYSQL56_MATRIX=PASS"
printf 'GATE_PASS=%s\n' \
  C2-SETTLE-SQL-01 C2-SETTLE-SQL-02 C2-SETTLE-SQL-03 \
  C2-SETTLE-SQL-04 C2-SETTLE-SQL-05 C2-SETTLE-SQL-06

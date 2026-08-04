#!/usr/bin/env bash
set -euo pipefail

package_dir="$(cd "$(dirname "$0")" && pwd)"
upgrades_dir="$(cd "$package_dir/.." && pwd)"
container_name="cashier-v3-card-operation-mysql56-$$"
mysql_image="docker.m.daocloud.io/library/mysql:5.6.51"
failure_log="$(mktemp /tmp/cashier-v3-card-operation.XXXXXX)"
audit_log="$(mktemp /tmp/cashier-v3-card-operation-audit.XXXXXX)"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then docker logs "$container_name" >&2 || true; fi
  docker rm -f "$container_name" >/dev/null 2>&1 || true
  rm -f "$failure_log" "$audit_log"
}
trap cleanup EXIT INT TERM

(cd "$package_dir" && shasum -a 256 -c SHA256SUMS.txt)

docker run -d --name "$container_name" \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$mysql_image" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  if docker logs "$container_name" 2>&1 | grep -F 'Ready for start up.' >/dev/null \
    && docker exec "$container_name" mysqladmin ping -uroot --silent >/dev/null 2>&1; then break; fi
  sleep 1
done
docker exec "$container_name" mysqladmin ping -uroot --silent >/dev/null
version="$(docker exec "$container_name" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$version" == "5.6.51" ]] || { echo "VERSION_MISMATCH=$version" >&2; exit 1; }
echo "MYSQL_VERSION=$version"

mysql_file() { docker exec -i "$container_name" mysql -uroot --database="$1" < "$2"; }
mysql_sql() { docker exec "$container_name" mysql -uroot --database="$1" -e "$2"; }
mysql_scalar() { docker exec "$container_name" mysql -uroot --batch --skip-column-names --database="$1" -e "$2"; }

init_db() {
  local db="$1"
  docker exec "$container_name" mysql -uroot -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$upgrades_dir/0000-升级登记表初始化.sql" >/dev/null
  mysql_sql "$db" "
    CREATE TABLE eb_user_card_holder (
      id bigint(20) unsigned NOT NULL, uid bigint(20) unsigned NOT NULL,
      oid bigint(20) unsigned NOT NULL, store_id bigint(20) unsigned NOT NULL,
      write_start bigint(20) unsigned NOT NULL DEFAULT 0,
      write_end bigint(20) unsigned NOT NULL DEFAULT 0,
      is_del tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_store_order (
      id bigint(20) unsigned NOT NULL, uid bigint(20) unsigned NOT NULL,
      store_id bigint(20) unsigned NOT NULL, paid tinyint(1) NOT NULL DEFAULT 0,
      is_del tinyint(1) NOT NULL DEFAULT 0, is_system_del tinyint(1) NOT NULL DEFAULT 0,
      is_user_del tinyint(1) NOT NULL DEFAULT 0, refund_status tinyint(1) NOT NULL DEFAULT 0,
      terminal_action tinyint(1) NOT NULL DEFAULT 0,
      card_upgrade_use_oid bigint(20) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_store_order_cart_info (
      id bigint(20) unsigned NOT NULL, oid bigint(20) unsigned NOT NULL,
      cart_type tinyint(3) NOT NULL DEFAULT 0, product_type tinyint(3) NOT NULL DEFAULT 0,
      write_times bigint(20) unsigned NOT NULL DEFAULT 0,
      write_surplus_times bigint(20) unsigned NOT NULL DEFAULT 0,
      is_writeoff tinyint(1) NOT NULL DEFAULT 0, pay_price decimal(12,2) NOT NULL DEFAULT 0,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_cashier_v3_entitlement_resource_version (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT, resource_kind varchar(64) NOT NULL,
      resource_id varchar(128) NOT NULL, member_id bigint(20) unsigned NOT NULL DEFAULT 0,
      current_version bigint(20) unsigned NOT NULL DEFAULT 1, PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_cashier_v3_command_receipt (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_cashier_v3_business_event (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    CREATE TABLE eb_cashier_v3_outbox (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;" >/dev/null
}

expect_fail_file() {
  local db="$1" file="$2" marker="$3"
  if mysql_file "$db" "$file" >"$failure_log" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2; exit 1
  fi
  grep -F "$marker" "$failure_log" >/dev/null
  echo "$marker=PASS"
}

fresh_db="cashier_v3_card_operation_fresh"
init_db "$fresh_db"
mysql_file "$fresh_db" "$package_dir/01-升级前检查.sql" | grep -F PRECHECK_OK >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" | grep -F APPLY_OK >/dev/null
mysql_file "$fresh_db" "$package_dir/02-正式升级.sql" | grep -F APPLY_OK >/dev/null
mysql_file "$fresh_db" "$package_dir/03-升级后验证.sql" | grep -F POSTCHECK_OK >/dev/null
echo "FRESH_REPLAY_POSTCHECK=PASS"

state_cas_first="$(mysql_scalar "$fresh_db" "
  INSERT INTO eb_cashier_v3_card_state
    (tenant_id,card_holder_id,origin_order_id,origin_member_id,current_member_id,card_status,current_version,created_at,updated_at)
  VALUES ('0',10,100,1001,1001,'enabled',1,1,1);
  UPDATE eb_cashier_v3_card_state SET current_version=2,updated_at=2
  WHERE tenant_id='0' AND card_holder_id=10 AND current_version=1;
  SELECT ROW_COUNT();")"
state_cas_stale="$(mysql_scalar "$fresh_db" "
  UPDATE eb_cashier_v3_card_state SET current_version=3,updated_at=3
  WHERE tenant_id='0' AND card_holder_id=10 AND current_version=1;
  SELECT ROW_COUNT();")"
[[ "$state_cas_first" == "1" && "$state_cas_stale" == "0" ]] || { echo "STATE_CAS_FAILED" >&2; exit 1; }
echo "STATE_VERSION_CAS=PASS"

if mysql_sql "$fresh_db" "
  INSERT INTO eb_cashier_v3_card_operation
    (operation_id,operation_no,operation_type,operation_status,contract_version,tenant_id,organization_id,store_id,
     source_card_holder_id,source_card_holder_version,origin_order_id,origin_member_id,member_id_before,member_id_after,
     command_idempotency_key,natural_key,immutable_fingerprint,source_snapshot_json,target_snapshot_json,result_snapshot_json,
     operator_id,business_date,business_timezone,occurred_at,settled_at,recorded_at,add_time,update_time)
  VALUES
    ('COP-0000000000000000000000000000000000000000','CO2026073000000000000000','card_disable','succeeded',
     'cashier-v3-card-operation-v1','0','0',1,10,2,100,1001,1001,1001,
     'CARD-OP-IDEMPOTENCY-0001',REPEAT('a',64),REPEAT('b',64),'{}','{}','{}',1,'2026-07-30','Asia/Shanghai',1,1,1,1,1);
  INSERT INTO eb_cashier_v3_card_operation
    (operation_id,operation_no,operation_type,operation_status,contract_version,tenant_id,organization_id,store_id,
     source_card_holder_id,source_card_holder_version,origin_order_id,origin_member_id,member_id_before,member_id_after,
     command_idempotency_key,natural_key,immutable_fingerprint,source_snapshot_json,target_snapshot_json,result_snapshot_json,
     operator_id,business_date,business_timezone,occurred_at,settled_at,recorded_at,add_time,update_time)
  VALUES
    ('COP-0000000000000000000000000000000000000001','CO2026073000000000000001','card_disable','succeeded',
     'cashier-v3-card-operation-v1','0','0',1,10,2,100,1001,1001,1001,
     'CARD-OP-IDEMPOTENCY-0001',REPEAT('c',64),REPEAT('d',64),'{}','{}','{}',1,'2026-07-30','Asia/Shanghai',2,2,2,2,2);" >"$failure_log" 2>&1; then
  echo "IDEMPOTENCY_UNIQUE_MISSING" >&2; exit 1
fi
echo "IDEMPOTENCY_UNIQUE=PASS"

wrong_column_db="cashier_v3_card_operation_wrong_column"
init_db "$wrong_column_db"
mysql_file "$wrong_column_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$wrong_column_db" "ALTER TABLE eb_cashier_v3_card_state MODIFY current_version decimal(18,2) NOT NULL DEFAULT 1" >/dev/null
expect_fail_file "$wrong_column_db" "$package_dir/03-升级后验证.sql" "STOP_CASHIER_V3_CARD_OPERATION_POSTCHECK_FAILED"

partial_db="cashier_v3_card_operation_partial"
init_db "$partial_db"
mysql_file "$partial_db" "$package_dir/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" "DROP TABLE eb_cashier_v3_card_operation_line" >/dev/null
expect_fail_file "$partial_db" "$package_dir/01-升级前检查.sql" "STOP_CASHIER_V3_CARD_OPERATION_PRECHECK_FAILED"
mysql_file "$partial_db" "$package_dir/05-部分创表恢复审计.sql" >"$audit_log"
grep -F 'PARTIAL_DDL_REVIEW_REQUIRED_NO_MUTATION' "$audit_log" >/dev/null
[[ "$(mysql_scalar "$partial_db" "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'eb_cashier_v3_card_%';")" == "2" ]] || {
  echo "RECOVERY_AUDIT_MUTATED_PARTIAL_DDL" >&2; exit 1; }
echo "PARTIAL_DDL_AUDIT_NO_MUTATION=PASS"

unregistered_db="cashier_v3_card_operation_unregistered"
init_db "$unregistered_db"
mysql_file "$unregistered_db" "$package_dir/02-正式升级.sql" >/dev/null
expect_fail_file "$unregistered_db" "$package_dir/01-升级前检查.sql" "STOP_CASHIER_V3_CARD_OPERATION_PRECHECK_FAILED"
mysql_file "$unregistered_db" "$package_dir/05-部分创表恢复审计.sql" >"$audit_log"
grep -F 'FULL_UNREGISTERED_REVIEW_REQUIRED_NO_MUTATION' "$audit_log" >/dev/null
echo "FULL_UNREGISTERED_AUDIT_NO_MUTATION=PASS"

registered_db="cashier_v3_card_operation_registered"
init_db "$registered_db"
mysql_sql "$registered_db" "
  INSERT INTO eb_database_upgrade_log
    (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
  VALUES
    ('20260730-021-cashier-v3-card-operation-authority-v1','matrix','','','local',NOW(),'Codex','registered');" >/dev/null
expect_fail_file "$registered_db" "$package_dir/01-升级前检查.sql" "STOP_CASHIER_V3_CARD_OPERATION_PRECHECK_FAILED"

echo "CARD_OPERATION_MYSQL56_MATRIX=PASS"
printf 'GATE_PASS=%s\n' C2-CARDOP-SQL-01 C2-CARDOP-SQL-02 C2-CARDOP-SQL-03 C2-CARDOP-SQL-04

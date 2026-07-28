#!/usr/bin/env bash
# 部分创表恢复：information_schema 精确判断已存表；只 DROP 已存且为空、结构一致的本升级表。
# 升级键已登记 → 禁止部分恢复。
# 任一已存表非空 / 异构同名表 → 停止，不自动删除。
# 权威：C1-00001-00016
set -euo pipefail

MYSQL_CONTAINER="${MYSQL_CONTAINER:?MYSQL_CONTAINER required}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PWD="${MYSQL_PWD:-localdev123}"
DB="${1:?database name required}"
UPG_DIR="$(cd "$(dirname "$0")" && pwd)"
UPGRADE_KEY="20260727-001-cashier-v3-command-idem"

q() {
  docker exec -i "$MYSQL_CONTAINER" mysql -N -B -u"$MYSQL_USER" -p"$MYSQL_PWD" "$DB" -e "$1"
}

echo "PARTIAL_DDL_RECOVERY_START db=$DB"

# 升级键已登记：禁止部分恢复（完整升级后即使空表也不得 DROP）
has_log=$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_database_upgrade_log';" || echo 0)
if [ "${has_log:-0}" != "0" ]; then
  reg=$(q "SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key='${UPGRADE_KEY}';" || echo 0)
  if [ "${reg:-0}" != "0" ]; then
    echo "STOP_PARTIAL_DDL_UPGRADE_REGISTERED key=$UPGRADE_KEY"
    echo "MANUAL_CHECKLIST:"
    echo "  - 升级键已登记，禁止部分恢复；完整回滚请用 04 流程并确认空表后再 DROP"
    exit 3
  fi
fi

tables=(
  eb_cashier_v3_command_receipt
  eb_cashier_v3_resource_version
  eb_cashier_v3_state_context
)

nonempty=()
empty_exist=()
missing=()
hetero=()

for t in "${tables[@]}"; do
  exists=$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t';")
  if [ "${exists:-0}" = "0" ]; then
    missing+=("$t")
    echo "TABLE_MISSING $t"
    continue
  fi
  # 与 02 的双向 metadata 合同：列顺序、类型、可空、默认值、extra、字符集／排序规则、
  # 引擎／表排序规则以及索引列序和前缀都必须完全一致。只检查 id 会把异构空表误删。
  case "$t" in
    eb_cashier_v3_command_receipt)
      expected_cols='id|bigint(20) unsigned|NO|<NULL>|auto_increment||;idempotency_key|varchar(128)|NO|||ascii|ascii_bin;action|varchar(64)|NO|||ascii|ascii_bin;store_id|int(11) unsigned|NO|0|||;operator_id|int(11) unsigned|NO|0|||;state_context_id|varchar(64)|NO|||ascii|ascii_bin;request_hash|char(64)|NO|||ascii|ascii_bin;contexts_hash|char(64)|NO|||ascii|ascii_bin;contexts_json|mediumtext|YES|<NULL>||utf8mb4|utf8mb4_general_ci;status|tinyint(4)|NO|0|||;result_code|varchar(64)|NO|||ascii|ascii_bin;result_message|varchar(255)|NO|||utf8mb4|utf8mb4_general_ci;result_json|mediumtext|YES|<NULL>||utf8mb4|utf8mb4_general_ci;business_no|varchar(64)|NO|||ascii|ascii_bin;operator_ip|varchar(64)|NO|||ascii|ascii_bin;add_time|int(11) unsigned|NO|0|||;finish_time|int(11) unsigned|NO|0|||'
      expected_idx='PRIMARY|0|BTREE|id|;idx_archive_time|1|BTREE|add_time|;idx_business_no|1|BTREE|business_no|;idx_state_context|1|BTREE|state_context_id,add_time|,;idx_store_action_time|1|BTREE|store_id,action,add_time|,,;uk_idempotency_key|0|BTREE|idempotency_key|'
      ;;
    eb_cashier_v3_resource_version)
      expected_cols='id|bigint(20) unsigned|NO|<NULL>|auto_increment||;scope_type|varchar(16)|NO|||ascii|ascii_bin;scope_id|varchar(32)|NO|||ascii|ascii_bin;resource_kind|varchar(32)|NO|||ascii|ascii_bin;resource_id|varchar(64)|NO|||ascii|ascii_bin;current_version|bigint(20) unsigned|NO|1|||;last_action|varchar(64)|NO|||ascii|ascii_bin;add_time|int(11) unsigned|NO|0|||;update_time|int(11) unsigned|NO|0|||'
      expected_idx='PRIMARY|0|BTREE|id|;idx_scope_kind|1|BTREE|scope_type,scope_id,resource_kind|,,;idx_update_time|1|BTREE|update_time|;uk_scope_resource|0|BTREE|scope_type,scope_id,resource_kind,resource_id|,,,'
      ;;
    eb_cashier_v3_state_context)
      expected_cols='id|bigint(20) unsigned|NO|<NULL>|auto_increment||;state_context_id|varchar(64)|NO|||ascii|ascii_bin;store_id|int(11) unsigned|NO|0|||;operator_id|int(11) unsigned|NO|0|||;client_session_id|varchar(128)|NO|||ascii|ascii_bin;current_revision|bigint(20) unsigned|NO|0|||;add_time|int(11) unsigned|NO|0|||;last_seen_time|int(11) unsigned|NO|0|||'
      expected_idx='PRIMARY|0|BTREE|id|;idx_last_seen|1|BTREE|last_seen_time|;uk_identity|0|BTREE|store_id,operator_id,client_session_id|,,;uk_state_context_id|0|BTREE|state_context_id|'
      ;;
    *)
      hetero+=("$t")
      echo "TABLE_HETEROGENEOUS $t"
      continue ;;
  esac
  actual_cols=$(q "SELECT GROUP_CONCAT(CONCAT(COLUMN_NAME,'|',COLUMN_TYPE,'|',IS_NULLABLE,'|',IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),'|',IFNULL(EXTRA,''),'|',IFNULL(CHARACTER_SET_NAME,''),'|',IFNULL(COLLATION_NAME,'')) ORDER BY ORDINAL_POSITION SEPARATOR ';') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t';")
  table_shape=$(q "SELECT CONCAT(IFNULL(ENGINE,''),'|',IFNULL(TABLE_COLLATION,'')) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t';")
  actual_idx=$(q "SELECT GROUP_CONCAT(CONCAT(INDEX_NAME,'|',NON_UNIQUE,'|',INDEX_TYPE,'|',cols,'|',parts) ORDER BY (INDEX_NAME='PRIMARY') DESC, INDEX_NAME SEPARATOR ';') FROM (SELECT INDEX_NAME,MAX(NON_UNIQUE) AS NON_UNIQUE,MAX(INDEX_TYPE) AS INDEX_TYPE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols,GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS parts FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' GROUP BY INDEX_NAME) AS ix;")
  if [ "$actual_cols" != "$expected_cols" ] || [ "$table_shape" != 'InnoDB|utf8mb4_general_ci' ] || [ "$actual_idx" != "$expected_idx" ]; then
    hetero+=("$t")
    echo "TABLE_HETEROGENEOUS $t"
    continue
  fi
  rows=$(q "SELECT COUNT(*) FROM \`$t\`;")
  echo "TABLE_EXISTS $t rows=$rows"
  if [ "${rows:-0}" != "0" ]; then
    nonempty+=("$t:$rows")
  else
    empty_exist+=("$t")
  fi
done

if [ "${#hetero[@]}" -gt 0 ]; then
  echo "STOP_PARTIAL_DDL_HETEROGENEOUS"
  echo "MANUAL_CHECKLIST:"
  for t in "${hetero[@]}"; do
    echo "  - KEEP $t （异构同名表，禁止自动 DROP）"
  done
  exit 4
fi

if [ "${#nonempty[@]}" -gt 0 ]; then
  echo "STOP_PARTIAL_DDL_NONEMPTY"
  echo "MANUAL_CHECKLIST:"
  for item in "${nonempty[@]}"; do
    echo "  - KEEP $item （非空，禁止自动 DROP；下线 V3 入口并按备份评估）"
  done
  for t in "${empty_exist[@]}"; do
    echo "  - EMPTY_BUT_BLOCKED $t （因存在其它非空表，本轮不自动 DROP）"
  done
  exit 2
fi

for t in "${empty_exist[@]}"; do
  q "DROP TABLE \`$t\`;"
  echo "DROPPED_EMPTY $t"
done

# 回查：目标表必须不存在
for t in "${empty_exist[@]}"; do
  left=$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t';")
  if [ "${left:-0}" != "0" ]; then
    echo "FAIL: table still exists after DROP: $t"
    exit 1
  fi
  echo "VERIFY_DROPPED $t"
done

echo "PARTIAL_DDL_RECOVERY_OK dropped=${#empty_exist[@]} missing=${#missing[@]}"
exit 0

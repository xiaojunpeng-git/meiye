#!/usr/bin/env bash
# C1-A SQL 结构合同矩阵：33 场景 × 独立临时库 × 独立场景输出
set -euo pipefail

TESTS="$(cd "$(dirname "$0")/.." && pwd)"
REPO="$(cd "$TESTS/../.." && pwd)"
UPGRADES="$REPO/后端代码/database/upgrades"
UPG="$UPGRADES/2026-07-27-收银V3命令与幂等底座"
INIT0000="$UPGRADES/0000-升级登记表初始化.sql"
LOG="${C1A_EVIDENCE_DIR:-$TESTS}/sql-matrix-index.txt"
SCENEDIR="${C1A_EVIDENCE_DIR:-$TESTS}/sql-scenes"
MYSQL_IMAGE="docker.m.daocloud.io/library/mysql:5.6.51"

mkdir -p "$SCENEDIR"
: > "$LOG"
# 唯一日志写入者：场景独立文件；本脚本只向 stdout 输出摘要（由 run-all tee 成 SQL-MATRIX.log）
# 禁止本脚本再写 SQL-MATRIX.log，避免与 run-all 双写截断／拼接。

MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PWD="${MYSQL_PWD:-localdev123}"

mysql_exec() {
  local db="$1"; shift
  docker exec -i "$MYSQL_CONTAINER" mysql -h127.0.0.1 -P3306 -u"$MYSQL_USER" -p"$MYSQL_PWD" "$db" "$@"
}

mysql_file() {
  local db="$1" file="$2"
  docker exec -i "$MYSQL_CONTAINER" mysql -h127.0.0.1 -P3306 -u"$MYSQL_USER" -p"$MYSQL_PWD" "$db" < "$file"
}

new_db() { echo "c1a_sql_$(date +%s)_$RANDOM"; }

run_expect_ok() {
  local id="$1" db="$2" file="$3" token="$4"
  local out="$SCENEDIR/${id}.out"
  echo "== $id expect OK ($token) =="
  echo "$id|$out|OK" >> "$LOG"
  set +e
  mysql_file "$db" "$file" > "$out" 2>&1
  local rc=$?
  set -e
  if [ "$rc" != "0" ]; then echo "FAIL $id: expected zero exit"; return 1; fi
  grep -q "$token" "$out" || { echo "FAIL $id: missing token $token in scene output"; return 1; }
  echo "GATE_PASS=$id"
}

run_expect_fail() {
  local id="$1" db="$2" file="$3" token="$4"
  local out="$SCENEDIR/${id}.out"
  echo "== $id expect FAIL ($token) =="
  echo "$id|$out|FAIL" >> "$LOG"
  set +e
  mysql_file "$db" "$file" > "$out" 2>&1
  local rc=$?
  set -e
  if [ "$rc" = "0" ]; then echo "FAIL $id: expected non-zero exit"; return 1; fi
  grep -q "$token" "$out" || { echo "FAIL $id: missing token $token in scene output"; return 1; }
  echo "GATE_PASS=$id"
}

run_recovery_sql_expect_ok() {
  local id="$1" db="$2" token="$3"
  local out="$SCENEDIR/${id}.out"
  echo "== $id expect OK (direct 05.sql: $token) =="
  echo "$id|$out|OK" >> "$LOG"
  set +e
  mysql_file "$db" "$UPG/05-部分创表恢复.sql" > "$out" 2>&1
  local rc=$?
  set -e
  if [ "$rc" != "0" ]; then echo "FAIL $id: expected zero exit"; return 1; fi
  grep -q "$token" "$out" || { echo "FAIL $id: missing token $token in scene output"; return 1; }
  echo "GATE_PASS=$id"
}

run_recovery_sql_expect_fail() {
  local id="$1" db="$2" token="$3"
  local out="$SCENEDIR/${id}.out"
  echo "== $id expect FAIL (direct 05.sql: $token) =="
  echo "$id|$out|FAIL" >> "$LOG"
  set +e
  mysql_file "$db" "$UPG/05-部分创表恢复.sql" > "$out" 2>&1
  local rc=$?
  set -e
  if [ "$rc" = "0" ]; then echo "FAIL $id: expected non-zero exit"; return 1; fi
  grep -q "$token" "$out" || { echo "FAIL $id: missing token $token in scene output"; return 1; }
  echo "GATE_PASS=$id"
}

echo "==== SQL matrix $(date '+%Y-%m-%d %H:%M:%S') ===="
echo "MYSQL_IMAGE=$MYSQL_IMAGE"
echo "MYSQL_CONTAINER=$MYSQL_CONTAINER"

MYSQL_VERSION=$(docker exec "$MYSQL_CONTAINER" mysql -N -B -u"$MYSQL_USER" -p"$MYSQL_PWD" -e "SELECT VERSION();")
echo "MYSQL_VERSION=$MYSQL_VERSION"
case "$MYSQL_VERSION" in
  5.6.51*) ;;
  *) echo "FAIL: SQL matrix requires MySQL 5.6.51, got $MYSQL_VERSION"; exit 1 ;;
esac

# S01 真实 0000 + 全流程
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
run_expect_ok "SQL-12-01" "$DB" "$UPG/01-升级前检查.sql" "PRECHECK_OK"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_file "$DB" "$UPG/02-正式升级.sql"
run_expect_ok "SQL-12-04" "$DB" "$UPG/03-升级后验证.sql" "VERIFY_OK"

# S02 登记表缺失
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
run_expect_fail "SQL-12-02" "$DB" "$UPG/01-升级前检查.sql" "STOP_UPGRADE_LOG_TABLE_MISSING"

# S03 登记表结构错误
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
# 表级合同保持与 0000 一致，只故意缺少升级登记表列，确保命中结构合同分支。
mysql_exec "$DB" -e "CREATE TABLE eb_database_upgrade_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
run_expect_fail "SQL-12-03" "$DB" "$UPG/01-升级前检查.sql" "STOP_UPGRADE_LOG_STRUCTURE_MISMATCH"

# S05 02 重复
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_file "$DB" "$UPG/02-正式升级.sql"
run_expect_ok "SQL-12-05" "$DB" "$UPG/03-升级后验证.sql" "VERIFY_OK"

# S06 只存在一张
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_exec "$DB" -e "CREATE TABLE eb_cashier_v3_command_receipt (id INT PRIMARY KEY);"
run_expect_fail "SQL-12-06" "$DB" "$UPG/01-升级前检查.sql" "STOP_TARGET_TABLE_PARTIAL_EXIST"

# S07 只存在两张
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "DROP TABLE eb_cashier_v3_state_context;"
run_expect_fail "SQL-12-07" "$DB" "$UPG/01-升级前检查.sql" "STOP_TARGET_TABLE_PARTIAL_EXIST"

# S08 关键列类型错误
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "ALTER TABLE eb_cashier_v3_command_receipt MODIFY idempotency_key VARCHAR(64) NOT NULL;"
run_expect_fail "SQL-12-08" "$DB" "$UPG/03-升级后验证.sql" "STOP_VERIFY_FAILED"

# S09 唯一索引列序错误
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "ALTER TABLE eb_cashier_v3_resource_version ADD UNIQUE KEY uk_kind_resource (resource_kind, resource_id);"
run_expect_fail "SQL-12-09" "$DB" "$UPG/01-升级前检查.sql" "STOP_TARGET_TABLE_INCOMPATIBLE"

# S10 普通索引缺失
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "ALTER TABLE eb_cashier_v3_command_receipt DROP INDEX idx_archive_time;"
run_expect_fail "SQL-12-10" "$DB" "$UPG/03-升级后验证.sql" "STOP_VERIFY_FAILED"

# S11 collation 错误（列级）
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "ALTER TABLE eb_cashier_v3_state_context MODIFY state_context_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '';"
run_expect_fail "SQL-12-11" "$DB" "$UPG/03-升级后验证.sql" "STOP_VERIFY_FAILED"

# S12 stateContextId 大小写 — 独立输出精确断言 COUNT=2
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
OUT="$SCENEDIR/SQL-12-12.out"
mysql_exec "$DB" <<SQL > "$OUT" 2>&1
INSERT INTO eb_cashier_v3_state_context (state_context_id,store_id,operator_id,client_session_id,current_revision,add_time,last_seen_time)
VALUES ('CaseCtx',1,1,'SESSION-00000000-0000-4000-8000-000000000001',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
       ('casectx',1,2,'SESSION-00000000-0000-4000-8000-000000000002',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
SELECT COUNT(*) AS case_ctx_count FROM eb_cashier_v3_state_context WHERE state_context_id IN ('CaseCtx','casectx');
SQL
grep -q 'case_ctx_count' "$OUT"
grep -E '[[:space:]]2[[:space:]]*$|^2$' "$OUT" >/dev/null || grep -q $'\t2' "$OUT" || grep -q '2' "$OUT"
# 精确：本场景输出必须含 case_ctx_count 行且值为 2
python3 - <<'PY' "$OUT" || { echo "FAIL SQL-12-12 exact count"; exit 1; }
import sys,re
text=open(sys.argv[1]).read()
assert 'case_ctx_count' in text
assert re.search(r'case_ctx_count[^\n]*\n+2\b', text) or re.search(r'\b2\b', text.split('case_ctx_count',1)[-1][:80])
print('SQL-12-12 exact ok')
PY
echo "GATE_PASS=SQL-12-12"

# S13 idempotencyKey 大小写
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
OUT="$SCENEDIR/SQL-12-13.out"
mysql_exec "$DB" <<SQL > "$OUT" 2>&1
INSERT INTO eb_cashier_v3_command_receipt (idempotency_key,action,store_id,operator_id,status,add_time)
VALUES ('KEY-UPPER','test',1,1,1,UNIX_TIMESTAMP());
INSERT INTO eb_cashier_v3_command_receipt (idempotency_key,action,store_id,operator_id,status,add_time)
VALUES ('key-upper','test',1,1,1,UNIX_TIMESTAMP());
SELECT COUNT(*) AS idem_case_count FROM eb_cashier_v3_command_receipt WHERE idempotency_key IN ('KEY-UPPER','key-upper');
SQL
python3 - <<'PY' "$OUT" || { echo "FAIL SQL-12-13 exact count"; exit 1; }
import sys,re
text=open(sys.argv[1]).read()
assert 'idem_case_count' in text
assert re.search(r'\b2\b', text.split('idem_case_count',1)[-1][:80])
print('SQL-12-13 exact ok')
PY
echo "GATE_PASS=SQL-12-13"

# S14 resource ID 大小写
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
OUT="$SCENEDIR/SQL-12-14.out"
mysql_exec "$DB" <<SQL > "$OUT" 2>&1
INSERT INTO eb_cashier_v3_resource_version (scope_type,scope_id,resource_kind,resource_id,current_version,add_time,update_time)
VALUES ('store','1','service_order','ResA',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
       ('store','1','service_order','resa',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
SELECT COUNT(*) AS res_case_count FROM eb_cashier_v3_resource_version WHERE resource_id IN ('ResA','resa');
SQL
python3 - <<'PY' "$OUT" || { echo "FAIL SQL-12-14 exact count"; exit 1; }
import sys,re
text=open(sys.argv[1]).read()
assert 'res_case_count' in text
assert re.search(r'\b2\b', text.split('res_case_count',1)[-1][:80])
print('SQL-12-14 exact ok')
PY
echo "GATE_PASS=SQL-12-14"

# S15 有业务数据后 03 仍通过
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "INSERT INTO eb_cashier_v3_state_context (state_context_id,store_id,operator_id,client_session_id,current_revision,add_time,last_seen_time) VALUES ('ctxdata',1,1,'SESSION-00000000-0000-4000-8000-000000000003',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());"
run_expect_ok "SQL-12-15" "$DB" "$UPG/03-升级后验证.sql" "VERIFY_OK"

# S16 三表全在且兼容 → 01 兼容分支
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
run_expect_ok "SQL-12-16" "$DB" "$UPG/01-升级前检查.sql" "TARGET_TABLE_COMPAT_OK"

# S17 prefix-index 反例：把全列唯一键改成前缀索引后 03 必须失败
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "ALTER TABLE eb_cashier_v3_command_receipt DROP INDEX uk_idempotency_key; ALTER TABLE eb_cashier_v3_command_receipt ADD UNIQUE KEY uk_idempotency_key (idempotency_key(16));"
OUT="$SCENEDIR/SQL-12-PREFIX.out"
set +e
mysql_file "$DB" "$UPG/03-升级后验证.sql" > "$OUT" 2>&1
RC=$?
set -e
if [ "$RC" = "0" ]; then echo "FAIL prefix-index should fail verify"; exit 1; fi
grep -q "STOP_VERIFY_FAILED" "$OUT" || { echo "FAIL prefix-index missing STOP_VERIFY_FAILED"; exit 1; }
echo "GATE_PASS=SQL-12-PREFIX"
echo "SQL-12-PREFIX" > "$SCENEDIR/SQL-12-PREFIX.token"

# S18 纯 table collation 错误（只改表默认 collation，不得 CONVERT 列）
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
# 只改表默认 collation，不转换字符列
mysql_exec "$DB" -e "ALTER TABLE eb_cashier_v3_command_receipt DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;"
OUT="$SCENEDIR/SQL-12-17.out"
set +e
mysql_file "$DB" "$UPG/03-升级后验证.sql" > "$OUT" 2>&1
RC=$?
set -e
if [ "$RC" = "0" ]; then echo "FAIL SQL-12-17 should fail verify"; exit 1; fi
grep -q "STOP_VERIFY_FAILED" "$OUT" || { echo "FAIL SQL-12-17 missing STOP_VERIFY_FAILED"; exit 1; }
python3 - <<'PY' "$OUT" || { echo "FAIL SQL-12-17 expected verify_col_bad=0"; exit 1; }
import re, sys
text = open(sys.argv[1], encoding='utf-8', errors='replace').read()
lines = text.splitlines()
for i, line in enumerate(lines):
    if 'verify_failure_count' in line and 'verify_col_bad' in line and i + 1 < len(lines):
        nums = [x for x in re.split(r'[\s|]+', lines[i + 1].strip()) if x != '']
        if len(nums) >= 4 and nums[3] == '0' and nums[0] != '0':
            print('SQL-12-17 verify_col_bad=0 table-metadata-only')
            raise SystemExit(0)
raise SystemExit('verify_col_bad not proven =0')
PY
echo "GATE_PASS=SQL-12-17"

# S19 纯列 collation 错误（保持表 general_ci，只改一列）
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "ALTER TABLE eb_cashier_v3_state_context MODIFY state_context_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '';"
run_expect_fail "SQL-12-18" "$DB" "$UPG/03-升级后验证.sql" "STOP_VERIFY_FAILED"

# S20 非 general_ci 库默认值下正向升级仍成功（显式表 collation）
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
run_expect_ok "SQL-12-19" "$DB" "$UPG/03-升级后验证.sql" "VERIFY_OK"

# S21 部分 DDL 恢复：留下 1 张由 02 真实创建的空表（shell 入口）
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "DROP TABLE eb_cashier_v3_resource_version, eb_cashier_v3_state_context;"
OUT="$SCENEDIR/SQL-12-20.out"
set +e
bash "$UPG/05-部分创表恢复.sh" "$DB" > "$OUT" 2>&1
RC=$?
set -e
cat "$OUT"
[ "$RC" = "0" ] || { echo "FAIL SQL-12-20 expected exit 0 got $RC"; exit 1; }
grep -q "PARTIAL_DDL_RECOVERY_OK" "$OUT" || { echo "FAIL SQL-12-20 recovery"; exit 1; }
grep -q "DROPPED_EMPTY eb_cashier_v3_command_receipt" "$OUT" || { echo "FAIL SQL-12-20 drop"; exit 1; }
left=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_command_receipt';")
[ "${left:-1}" = "0" ] || { echo "FAIL SQL-12-20 table still exists"; exit 1; }
echo "GATE_PASS=SQL-12-20"

# S22 部分 DDL 恢复：留下 2 张由 02 真实创建的空表（shell 入口）
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "DROP TABLE eb_cashier_v3_state_context;"
OUT="$SCENEDIR/SQL-12-21.out"
set +e
bash "$UPG/05-部分创表恢复.sh" "$DB" > "$OUT" 2>&1
RC=$?
set -e
cat "$OUT"
[ "$RC" = "0" ] || { echo "FAIL SQL-12-21 expected exit 0 got $RC"; exit 1; }
grep -q "PARTIAL_DDL_RECOVERY_OK" "$OUT" || { echo "FAIL SQL-12-21 recovery"; exit 1; }
left=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version');")
[ "${left:-1}" = "0" ] || { echo "FAIL SQL-12-21 tables still exist count=$left"; exit 1; }
echo "GATE_PASS=SQL-12-21"

# S23 部分 DDL 非空停止
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "INSERT INTO eb_cashier_v3_state_context (state_context_id,store_id,operator_id,client_session_id,current_revision,add_time,last_seen_time) VALUES ('keep',1,1,'SESSION-00000000-0000-4000-8000-000000000099',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());"
OUT="$SCENEDIR/SQL-12-22.out"
set +e
bash "$UPG/05-部分创表恢复.sh" "$DB" > "$OUT" 2>&1
RC=$?
set -e
cat "$OUT"
grep -q "STOP_PARTIAL_DDL_NONEMPTY" "$OUT" || { echo "FAIL SQL-12-22 nonempty stop"; exit 1; }
[ "$RC" != "0" ] || { echo "FAIL SQL-12-22 expected nonzero"; exit 1; }
echo "GATE_PASS=SQL-12-22"

# S24 升级键已登记：禁止部分恢复（完整升级后空表也不得删）
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES ('20260727-001-cashier-v3-command-idem','t','f','x','c',NOW(),'test','ok');"
OUT="$SCENEDIR/SQL-12-23.out"
set +e
bash "$UPG/05-部分创表恢复.sh" "$DB" > "$OUT" 2>&1
RC=$?
set -e
cat "$OUT"
grep -q "STOP_PARTIAL_DDL_UPGRADE_REGISTERED" "$OUT" || { echo "FAIL SQL-12-23 registered key"; exit 1; }
[ "$RC" != "0" ] || { echo "FAIL SQL-12-23 expected nonzero"; exit 1; }
still=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_command_receipt';")
[ "${still:-0}" = "1" ] || { echo "FAIL SQL-12-23 table must remain"; exit 1; }
echo "GATE_PASS=SQL-12-23"

# S25 异构同名表禁止 DROP
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_exec "$DB" -e "CREATE TABLE eb_cashier_v3_command_receipt (foo_only INT) ENGINE=InnoDB;"
OUT="$SCENEDIR/SQL-12-24.out"
set +e
bash "$UPG/05-部分创表恢复.sh" "$DB" > "$OUT" 2>&1
RC=$?
set -e
cat "$OUT"
grep -q "STOP_PARTIAL_DDL_HETEROGENEOUS" "$OUT" || { echo "FAIL SQL-12-24 hetero"; exit 1; }
[ "$RC" != "0" ] || { echo "FAIL SQL-12-24 expected nonzero"; exit 1; }
still=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_command_receipt';")
[ "${still:-0}" = "1" ] || { echo "FAIL SQL-12-24 hetero table must remain"; exit 1; }
echo "GATE_PASS=SQL-12-24"

# S26 直接 SQL 入口：留下 1 张由 02 真实创建的空表
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "DROP TABLE eb_cashier_v3_resource_version, eb_cashier_v3_state_context;"
run_recovery_sql_expect_ok "SQL-12-25" "$DB" "PARTIAL_DDL_RECOVERY_OK"
left=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_command_receipt';")
[ "${left:-1}" = "0" ] || { echo "FAIL SQL-12-25 table still exists"; exit 1; }

# S27 直接 SQL 入口：留下 2 张由 02 真实创建的空表
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "DROP TABLE eb_cashier_v3_state_context;"
run_recovery_sql_expect_ok "SQL-12-26" "$DB" "PARTIAL_DDL_RECOVERY_OK"
left=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version');")
[ "${left:-1}" = "0" ] || { echo "FAIL SQL-12-26 tables still exist count=$left"; exit 1; }

# S28 直接 SQL 入口：非空表整体停止且不得删除
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "INSERT INTO eb_cashier_v3_state_context (state_context_id,store_id,operator_id,client_session_id,current_revision,add_time,last_seen_time) VALUES ('sql-keep',1,1,'SESSION-00000000-0000-4000-8000-000000000120',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());"
run_recovery_sql_expect_fail "SQL-12-27" "$DB" "STOP_PARTIAL_DDL_NONEMPTY"
still=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_state_context';")
[ "${still:-0}" = "1" ] || { echo "FAIL SQL-12-27 nonempty table must remain"; exit 1; }

# S29 直接 SQL 入口：升级键已登记整体停止且不得删除
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES ('20260727-001-cashier-v3-command-idem','t','f','x','c',NOW(),'test','ok');"
run_recovery_sql_expect_fail "SQL-12-28" "$DB" "STOP_PARTIAL_DDL_UPGRADE_REGISTERED"
still=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_command_receipt';")
[ "${still:-0}" = "1" ] || { echo "FAIL SQL-12-28 registered table must remain"; exit 1; }

# S30 直接 SQL 入口：异构同名表整体停止且不得删除
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_exec "$DB" -e "CREATE TABLE eb_cashier_v3_command_receipt (id INT PRIMARY KEY) ENGINE=InnoDB;"
run_recovery_sql_expect_fail "SQL-12-29" "$DB" "STOP_PARTIAL_DDL_HETEROGENEOUS"
still=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_command_receipt';")
[ "${still:-0}" = "1" ] || { echo "FAIL SQL-12-29 heterogeneous table must remain"; exit 1; }

# S31 结构反例：列名相同但类型错误也必须禁止恢复（shell 入口）
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "ALTER TABLE eb_cashier_v3_command_receipt MODIFY idempotency_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '';"
OUT="$SCENEDIR/SQL-12-30.out"
set +e
bash "$UPG/05-部分创表恢复.sh" "$DB" > "$OUT" 2>&1
RC=$?
set -e
cat "$OUT"
grep -q "STOP_PARTIAL_DDL_HETEROGENEOUS" "$OUT" || { echo "FAIL SQL-12-30 type mismatch not blocked"; exit 1; }
[ "$RC" != "0" ] || { echo "FAIL SQL-12-30 expected nonzero"; exit 1; }
still=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_command_receipt';")
[ "${still:-0}" = "1" ] || { echo "FAIL SQL-12-30 heterogeneous table must remain"; exit 1; }
echo "GATE_PASS=SQL-12-30"

# S32 state_context 单表精确结构（shell 入口）
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "DROP TABLE eb_cashier_v3_command_receipt, eb_cashier_v3_resource_version;"
OUT="$SCENEDIR/SQL-12-31.out"
set +e
bash "$UPG/05-部分创表恢复.sh" "$DB" > "$OUT" 2>&1
RC=$?
set -e
cat "$OUT"
[ "$RC" = "0" ] || { echo "FAIL SQL-12-31 expected exit 0 got $RC"; exit 1; }
grep -q "DROPPED_EMPTY eb_cashier_v3_state_context" "$OUT" || { echo "FAIL SQL-12-31 state_context drop"; exit 1; }
left=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_state_context';")
[ "${left:-1}" = "0" ] || { echo "FAIL SQL-12-31 state_context still exists"; exit 1; }
echo "GATE_PASS=SQL-12-31"

# S33 state_context 单表精确结构（直接 SQL 入口）
DB="$(new_db)"
mysql_exec "" -e "CREATE DATABASE \`$DB\`;"
mysql_file "$DB" "$INIT0000"
mysql_file "$DB" "$UPG/02-正式升级.sql"
mysql_exec "$DB" -e "DROP TABLE eb_cashier_v3_command_receipt, eb_cashier_v3_resource_version;"
run_recovery_sql_expect_ok "SQL-12-32" "$DB" "PARTIAL_DDL_RECOVERY_OK"
left=$(mysql_exec "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_state_context';")
[ "${left:-1}" = "0" ] || { echo "FAIL SQL-12-32 state_context still exists"; exit 1; }

echo "SQL_MATRIX_OK=33"
echo "GATE_PASS=SQL-12-ALL"

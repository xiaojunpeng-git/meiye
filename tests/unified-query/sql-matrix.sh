#!/usr/bin/env bash
set -euo pipefail

TESTS="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$TESTS/../.." && pwd)"
MATRIX="$REPO/后端代码/database/upgrades/2026-07-28-统一查询自定义字段/05-本地MySQL56矩阵.sh"
PLAN_LOG="$(mktemp "${TMPDIR:-/tmp}/unified-query-plan.XXXXXX")"

cleanup() {
  rm -f "$PLAN_LOG"
}
trap cleanup EXIT INT TERM

if [ ! -x "$MATRIX" ]; then
  echo "UNIFIED_QUERY_SQL_MATRIX_MISSING=$MATRIX" >&2
  exit 1
fi

# 迁移包内的 05 是唯一矩阵逻辑；本 runner 只把它纳入永久测试账本。
bash "$MATRIX" | tee "$PLAN_LOG"
if ! awk -F '\t' '
  $0 == "UQ_EXPLAIN_EXPIRED_EXPORT_LEASE" { in_target = 1; next }
  $0 ~ /^UQ_EXPLAIN_/ { in_target = 0; next }
  in_target && $3 == "eb_unified_query_export_task" && $6 == "idx_status_lease" {
    selected = 1
  }
  END {
    exit(selected ? 0 : 1)
  }
' "$PLAN_LOG"; then
  echo "UNIFIED_QUERY_WORKER_EXPLAIN_INDEX_MISSING=idx_status_lease" >&2
  exit 1
fi
echo "GATE_PASS=UQ-PERF-03"
if ! awk -F '\t' '
  $0 == "UQ_EXPLAIN_EXPORT_TASK_LOOKUP" { in_target = 1; next }
  $0 ~ /^UQ_EXPLAIN_/ { in_target = 0; next }
  in_target && $3 == "eb_unified_query_export_task" && $6 == "uk_task_no" {
    selected = 1
  }
  END {
    exit(selected ? 0 : 1)
  }
' "$PLAN_LOG"; then
  echo "UNIFIED_QUERY_TASK_LOOKUP_INDEX_MISSING=uk_task_no" >&2
  exit 1
fi
echo "GATE_PASS=UQ-PERF-05"
echo "RUNNER_OK=unified-query/sql-matrix.sh"

#!/usr/bin/env bash
set -euo pipefail

TESTS="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$TESTS/../.." && pwd)"
WORKSPACE="$(cd "$REPO/.." && pwd)"
CANONICAL="$REPO/后端代码/database/upgrades"
MIRROR="$WORKSPACE/任务管理/数据库升级文件"
REQUIRE_DELIVERY_MIRROR="${C1A_REQUIRE_DELIVERY_MIRROR:-0}"
MIRROR_ROOT_PRESENT=0
MIRROR_CHECKED_COUNT=0
MIRROR_SKIPPED_COUNT=0
MIRROR_REGISTRY_CHECKED=0

fail() {
  echo "MIGRATION_MIRROR_FAIL=$1" >&2
  exit 1
}

case "$REQUIRE_DELIVERY_MIRROR" in
  0|1) ;;
  *) fail "delivery_mirror_requirement_invalid" ;;
esac
if [ -d "$MIRROR" ]; then
  MIRROR_ROOT_PRESENT=1
elif [ "$REQUIRE_DELIVERY_MIRROR" = "1" ]; then
  fail "delivery_mirror_required_but_missing"
else
  echo "MIGRATION_MIRROR_SKIPPED=delivery_mirror_not_present"
fi

check_package() {
  local package="$1"
  shift
  local canonical_dir="$CANONICAL/$package"
  local mirror_dir="$MIRROR/$package"
  local expected actual file check_mirror=0

  [ -d "$canonical_dir" ] || fail "canonical_package_missing:$package"
  [ -z "$(find "$canonical_dir" -mindepth 1 -maxdepth 1 ! -type f -print -quit)" ] \
    || fail "canonical_non_file_entry:$package"

  expected="$(printf '%s\n' "$@" | LC_ALL=C sort)"
  actual="$(find "$canonical_dir" -mindepth 1 -maxdepth 1 -type f -exec basename {} \; | LC_ALL=C sort)"
  [ "$actual" = "$expected" ] || fail "canonical_file_set_mismatch:$package"
  (cd "$canonical_dir" && shasum -a 256 -c SHA256SUMS.txt) \
    || fail "canonical_internal_sha_invalid:$package"

  if [ -d "$mirror_dir" ]; then
    check_mirror=1
  elif [ "$REQUIRE_DELIVERY_MIRROR" = "1" ]; then
    fail "delivery_mirror_missing:$package"
  else
    MIRROR_SKIPPED_COUNT=$((MIRROR_SKIPPED_COUNT + 1))
    echo "MIGRATION_MIRROR_SKIPPED=$package"
  fi

  if [ "$check_mirror" = "1" ]; then
    [ -z "$(find "$mirror_dir" -mindepth 1 -maxdepth 1 ! -type f -print -quit)" ] \
      || fail "mirror_non_file_entry:$package"
    actual="$(find "$mirror_dir" -mindepth 1 -maxdepth 1 -type f -exec basename {} \; | LC_ALL=C sort)"
    [ "$actual" = "$expected" ] || fail "mirror_file_set_mismatch:$package"
    (cd "$mirror_dir" && shasum -a 256 -c SHA256SUMS.txt) \
      || fail "mirror_internal_sha_invalid:$package"
    for file in "$@"; do
      cmp -s "$canonical_dir/$file" "$mirror_dir/$file" \
        || fail "mirror_content_mismatch:$package/$file"
    done
    MIRROR_CHECKED_COUNT=$((MIRROR_CHECKED_COUNT + 1))
  fi
}

check_empty_package() {
  local package="$1"
  local canonical_dir="$CANONICAL/$package"
  local mirror_dir="$MIRROR/$package"

  [ -d "$canonical_dir" ] || fail "canonical_package_missing:$package"
  [ -z "$(find "$canonical_dir" -mindepth 1 -maxdepth 1 -print -quit)" ] \
    || fail "canonical_empty_package_gained_files:$package"

  if [ -d "$mirror_dir" ]; then
    [ -z "$(find "$mirror_dir" -mindepth 1 -maxdepth 1 -print -quit)" ] \
      || fail "mirror_empty_package_gained_files:$package"
    MIRROR_CHECKED_COUNT=$((MIRROR_CHECKED_COUNT + 1))
  elif [ "$REQUIRE_DELIVERY_MIRROR" = "1" ]; then
    fail "delivery_mirror_missing:$package"
  else
    MIRROR_SKIPPED_COUNT=$((MIRROR_SKIPPED_COUNT + 1))
    echo "MIGRATION_MIRROR_SKIPPED=$package"
  fi
}

[ -f "$CANONICAL/0000-升级登记表初始化.sql" ] \
  || fail "canonical_upgrade_registry_missing"
if [ "$MIRROR_ROOT_PRESENT" = "1" ] && [ -f "$MIRROR/0000-升级登记表初始化.sql" ]; then
  [ -f "$MIRROR/0000-升级登记表初始化.sql" ] \
    || fail "mirror_upgrade_registry_missing"
  cmp -s "$CANONICAL/0000-升级登记表初始化.sql" "$MIRROR/0000-升级登记表初始化.sql" \
    || fail "upgrade_registry_mirror_mismatch"
  MIRROR_REGISTRY_CHECKED=1
elif [ "$REQUIRE_DELIVERY_MIRROR" = "1" ]; then
  fail "mirror_upgrade_registry_missing"
fi

[ -z "$(find "$CANONICAL" -mindepth 1 -maxdepth 1 ! -type f ! -type d -print -quit)" ] \
  || fail "canonical_root_entry_type_invalid"
[ -z "$(find "$CANONICAL" -type l -print -quit)" ] \
  || fail "canonical_symlink_forbidden"

canonical_root_expected="$(printf '%s\n' \
  "0000-升级登记表初始化.sql" \
  "2026-07-27-收银V3命令与幂等底座" \
  "2026-07-28-C5会员建档一致性" \
  "2026-07-28-库存批次与成本分配底座" \
  "2026-07-28-收银V3权益购物车草稿" \
  "2026-07-28-收银V3统一事件与Outbox" \
  "2026-07-28-统一查询自定义字段" | LC_ALL=C sort)"
canonical_root_actual="$(find "$CANONICAL" -mindepth 1 -maxdepth 1 -exec basename {} \; | LC_ALL=C sort)"
[ "$canonical_root_actual" = "$canonical_root_expected" ] \
  || fail "canonical_root_entry_set_mismatch"

check_package "2026-07-27-收银V3命令与幂等底座" \
  "00-升级清单.md" \
  "01-升级前检查.sql" \
  "02-正式升级.sql" \
  "03-升级后验证.sql" \
  "04-回滚或应急说明.md" \
  "05-部分创表恢复.sh" \
  "05-部分创表恢复.sql" \
  "SHA256SUMS.txt"

check_package "2026-07-28-收银V3统一事件与Outbox" \
  "00-升级清单.md" \
  "01-升级前检查.sql" \
  "02-正式升级.sql" \
  "03-升级后验证.sql" \
  "04-回滚或应急说明.md" \
  "05-本地MySQL56矩阵.sh" \
  "SHA256SUMS.txt"

check_package "2026-07-28-C5会员建档一致性" \
  "00-升级清单.md" \
  "01-升级前检查.sql" \
  "02-正式升级.sql" \
  "03-升级后验证.sql" \
  "04-回滚或应急说明.md" \
  "05-本地MySQL56矩阵.sh" \
  "SHA256SUMS.txt"

check_package "2026-07-28-收银V3权益购物车草稿" \
  "00-升级清单.md" \
  "01-升级前检查.sql" \
  "02-正式升级.sql" \
  "03-升级后验证.sql" \
  "04-回滚或应急说明.md" \
  "SHA256SUMS.txt"

check_package "2026-07-28-统一查询自定义字段" \
  "00-升级清单.md" \
  "01-升级前检查.sql" \
  "02-正式升级.sql" \
  "03-升级后验证.sql" \
  "04-回滚或应急说明.md" \
  "05-本地MySQL56矩阵.sh" \
  "SHA256SUMS.txt"

# Inventory has reserved its canonical directory but has not written a package
# yet. New files must be explicitly registered by that task, never auto-accepted.
check_empty_package "2026-07-28-库存批次与成本分配底座"

for runner in \
  "$TESTS/run-all.sh" \
  "$TESTS/sql/run-sql-matrix.sh" \
  "$TESTS/event-outbox-sql-matrix.sh" \
  "$TESTS/c5-member-sql-matrix.sh" \
  "$TESTS/c2-entitlement-sql-matrix.sh" \
  "$REPO/tests/unified-query/sql-matrix.sh"; do
  if grep -q '任务管理/数据库升级文件' "$runner"; then
    fail "runner_uses_delivery_mirror:$(basename "$runner")"
  fi
done

mkdir -p "$TESTS/_tmp"
tree_file="$(mktemp "$TESTS/_tmp/migration-tree.XXXXXX")"
trap 'rm -f "$tree_file"' EXIT INT TERM
find "$CANONICAL" -type f -print | LC_ALL=C sort | while IFS= read -r file; do
  relative="${file#"$CANONICAL/"}"
  printf '%s  %s\n' "$(shasum -a 256 "$file" | awk '{print $1}')" "$relative"
done > "$tree_file"
tree_sha="$(shasum -a 256 "$tree_file" | awk '{print $1}')"

echo "CANONICAL_MIGRATION_TREE_SHA256=$tree_sha"
printf 'GATE_PASS=%s\n' MIG-12-01 MIG-12-03
if [ "$MIRROR_REGISTRY_CHECKED" = "1" ] && [ "$MIRROR_SKIPPED_COUNT" -eq 0 ]; then
  echo "GATE_PASS=MIG-12-02"
else
  echo "MIGRATION_MIRROR_CHECKED_COUNT=$MIRROR_CHECKED_COUNT"
  echo "MIGRATION_MIRROR_SKIPPED_COUNT=$MIRROR_SKIPPED_COUNT"
  echo "GATE_SKIP=MIG-12-02"
fi

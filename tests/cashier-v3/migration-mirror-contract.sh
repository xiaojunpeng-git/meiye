#!/usr/bin/env bash
set -euo pipefail

TESTS="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$TESTS/../.." && pwd)"
WORKSPACE="$(cd "$REPO/.." && pwd)"
CANONICAL="$REPO/后端代码/database/upgrades"
MIRROR="$WORKSPACE/任务管理/数据库升级文件"
REQUIRE_DELIVERY_MIRROR="${C1A_REQUIRE_DELIVERY_MIRROR:-0}"
CHECK_DELIVERY_MIRROR=0

fail() {
  echo "MIGRATION_MIRROR_FAIL=$1" >&2
  exit 1
}

case "$REQUIRE_DELIVERY_MIRROR" in
  0|1) ;;
  *) fail "delivery_mirror_requirement_invalid" ;;
esac
if [ -d "$MIRROR" ]; then
  CHECK_DELIVERY_MIRROR=1
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
  local expected actual file

  [ -d "$canonical_dir" ] || fail "canonical_package_missing:$package"
  [ -z "$(find "$canonical_dir" -mindepth 1 -maxdepth 1 ! -type f -print -quit)" ] \
    || fail "canonical_non_file_entry:$package"

  expected="$(printf '%s\n' "$@" | LC_ALL=C sort)"
  actual="$(find "$canonical_dir" -mindepth 1 -maxdepth 1 -type f -exec basename {} \; | LC_ALL=C sort)"
  [ "$actual" = "$expected" ] || fail "canonical_file_set_mismatch:$package"
  (cd "$canonical_dir" && shasum -a 256 -c SHA256SUMS.txt) \
    || fail "canonical_internal_sha_invalid:$package"

  if [ "$CHECK_DELIVERY_MIRROR" = "1" ]; then
    [ -d "$mirror_dir" ] || fail "delivery_mirror_missing:$package"
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
  fi
}

[ -f "$CANONICAL/0000-升级登记表初始化.sql" ] \
  || fail "canonical_upgrade_registry_missing"
if [ "$CHECK_DELIVERY_MIRROR" = "1" ]; then
  [ -f "$MIRROR/0000-升级登记表初始化.sql" ] \
    || fail "mirror_upgrade_registry_missing"
  cmp -s "$CANONICAL/0000-升级登记表初始化.sql" "$MIRROR/0000-升级登记表初始化.sql" \
    || fail "upgrade_registry_mirror_mismatch"
fi

canonical_root_expected="$(printf '%s\n' \
  "0000-升级登记表初始化.sql" \
  "2026-07-27-收银V3命令与幂等底座" \
  "2026-07-28-收银V3统一事件与Outbox" \
  "2026-07-28-C5会员建档一致性" | LC_ALL=C sort)"
canonical_root_actual="$(find "$CANONICAL" -mindepth 1 -maxdepth 1 -exec basename {} \; | LC_ALL=C sort)"
[ "$canonical_root_actual" = "$canonical_root_expected" ] \
  || fail "canonical_root_entry_set_mismatch"
[ -z "$(find "$CANONICAL" -type l -print -quit)" ] \
  || fail "canonical_symlink_forbidden"

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

for runner in \
  "$TESTS/run-all.sh" \
  "$TESTS/sql/run-sql-matrix.sh" \
  "$TESTS/event-outbox-sql-matrix.sh" \
  "$TESTS/c5-member-sql-matrix.sh"; do
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
if [ "$CHECK_DELIVERY_MIRROR" = "1" ]; then
  echo "GATE_PASS=MIG-12-02"
else
  echo "GATE_SKIP=MIG-12-02"
fi

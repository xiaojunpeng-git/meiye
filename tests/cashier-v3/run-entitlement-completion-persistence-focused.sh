#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
upgrade_dir="$repo_dir/后端代码/database/upgrades/2026-07-29-收银V3权益完成权威写入"
draft_upgrade="$repo_dir/后端代码/database/upgrades/2026-07-28-收银V3权益购物车草稿/02-正式升级.sql"
fact_upgrade="$repo_dir/后端代码/database/upgrades/2026-07-29-收银V3统一结账事实底座/02-正式升级.sql"
init_sql="$repo_dir/后端代码/database/upgrades/0000-升级登记表初始化.sql"
fixture_sql="$repo_dir/tests/cashier-v3/sql/entitlement-completion-persistence-fixture.sql"

php "$repo_dir/tests/cashier-v3/php/c2-entitlement-completion-persistence-contract.php"
find "$repo_dir/后端代码/app/services/cashier/v3/checkout/persistence" -type f -name '*.php' \
  -exec php -l {} \;
php -l "$repo_dir/tests/cashier-v3/php/c2-entitlement-completion-persistence-integration.php"
(cd "$upgrade_dir" && shasum -a 256 -c SHA256SUMS.txt)

if [[ "${ECP_SKIP_MYSQL56:-0}" == "1" ]]; then
  echo 'ECP_MYSQL56_MATRIX=SKIPPED'
  echo 'ECP_FOCUSED_SUITE=PASS_WITH_MYSQL56_SKIPPED'
  exit 0
fi

mysql_image="${ECP_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
php_image="${ECP_PHP_IMAGE:-$(docker inspect --format '{{.Config.Image}}' mohe-app)}"
mysql_container="ecp-mysql56-$$"
work_dir="$(mktemp -d "${TMPDIR:-/tmp}/ecp-mysql56.XXXXXX")"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$mysql_container" >&2 || true
    for file in "$work_dir"/*; do
      [[ -f "$file" ]] && { echo "== $file =="; sed -n '1,240p' "$file"; }
    done >&2 || true
  fi
  docker rm -fv "$mysql_container" >/dev/null 2>&1 || true
  rm -rf "$work_dir"
}
trap cleanup EXIT INT TERM

docker run -d --name "$mysql_container" --platform linux/amd64 --cpus 1 --memory 768m \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$mysql_image" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --sql-mode=STRICT_ALL_TABLES \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  docker logs "$mysql_container" 2>&1 | grep 'MySQL init process done' >/dev/null && break
  sleep 1
done
docker logs "$mysql_container" 2>&1 | grep 'MySQL init process done' >/dev/null
for _ in $(seq 1 90); do
  docker exec "$mysql_container" mysqladmin ping -uroot --silent >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$mysql_container" mysqladmin ping -uroot --silent >/dev/null
mysql_version="$(docker exec "$mysql_container" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$mysql_version" == '5.6.51' ]]
echo "MYSQL_VERSION=$mysql_version"

mysql_file() {
  local db="$1" file="$2"
  docker exec -i "$mysql_container" mysql -uroot --database="$db" < "$file"
}

mysql_sql() {
  local db="$1" sql="$2"
  docker exec "$mysql_container" mysql -uroot --database="$db" -e "$sql"
}

init_db() {
  local db="$1"
  docker exec "$mysql_container" mysql -uroot -e \
    "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql_file "$db" "$init_sql" >/dev/null
}

run_php() {
  docker run --rm --cpus 1 --memory 256m \
    --network "container:$mysql_container" \
    --volume "$repo_dir:/workspace:ro" \
    --volume "$repo_dir/后端代码:/var/www/html:ro" \
    --tmpfs /var/www/html/runtime:rw,size=128m \
    --env ECP_BACKEND_ROOT=/workspace/后端代码 \
    --env CACHE_DRIVER=file --env PHP_CACHE_DRIVER=file \
    --env DB_HOST=127.0.0.1 --env DB_PORT=3306 \
    --env DB_DATABASE=ecp_completion --env DB_USERNAME=root --env DB_PASSWORD= \
    --entrypoint php "$php_image" \
    /workspace/tests/cashier-v3/php/c2-entitlement-completion-persistence-integration.php
}

db='ecp_completion'
init_db "$db"
mysql_file "$db" "$fixture_sql" >/dev/null
mysql_file "$db" "$draft_upgrade" >/dev/null
mysql_file "$db" "$fact_upgrade" >/dev/null
mysql_file "$db" "$upgrade_dir/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$db" "$upgrade_dir/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$db" "$upgrade_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
mysql_file "$db" "$upgrade_dir/02-正式升级.sql" | grep -q 'APPLY_OK'
run_php | tee "$work_dir/integration.out"
grep -q 'RUNNER_OK=C2_ENTITLEMENT_COMPLETION_PERSISTENCE_MYSQL56' "$work_dir/integration.out"
mysql_file "$db" "$upgrade_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'ECP_FRESH_REPLAY_AND_BUSINESS_FLOW=PASS'

partial_db='ecp_completion_partial'
init_db "$partial_db"
mysql_file "$partial_db" "$upgrade_dir/02-正式升级.sql" >/dev/null
mysql_sql "$partial_db" 'DROP TABLE eb_cashier_v3_entitlement_writeoff_fact,eb_cashier_v3_entitlement_service_fact' >/dev/null
if mysql_file "$partial_db" "$upgrade_dir/01-升级前检查.sql" >"$work_dir/partial-precheck.out" 2>&1; then
  echo 'partial precheck unexpectedly passed' >&2
  exit 1
fi
grep -q 'STOP_ENTITLEMENT_COMPLETION_PRECHECK_FAILED' "$work_dir/partial-precheck.out"
mysql_file "$partial_db" "$upgrade_dir/05-部分创表恢复.sql" | grep -q 'PARTIAL_DDL_RECOVERY_READY'
mysql_file "$partial_db" "$upgrade_dir/02-正式升级.sql" >/dev/null
mysql_file "$partial_db" "$upgrade_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'ECP_PARTIAL_EMPTY_RECOVERY=PASS'

malformed_db='ecp_completion_malformed'
init_db "$malformed_db"
mysql_sql "$malformed_db" 'CREATE TABLE eb_cashier_v3_entitlement_completion_receipt (id bigint unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci' >/dev/null
if mysql_file "$malformed_db" "$upgrade_dir/05-部分创表恢复.sql" >"$work_dir/malformed.out" 2>&1; then
  echo 'malformed partial recovery unexpectedly passed' >&2
  exit 1
fi
grep -q 'STOP_ENTITLEMENT_COMPLETION_PARTIAL_RECOVERY_FAILED' "$work_dir/malformed.out"
echo 'ECP_PARTIAL_MALFORMED_FAIL_CLOSED=PASS'

echo 'ECP_MYSQL56_MATRIX=PASS'
echo 'ECP_FOCUSED_SUITE=PASS'

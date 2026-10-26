#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
test_dir="$repo_dir/tests/cashier-v3-member-balance"
migration_dir="$repo_dir/后端代码/database/upgrades/2026-07-29-收银V3会员余额权威"
init_sql="$repo_dir/后端代码/database/upgrades/0000-升级登记表初始化.sql"
mysql_image="${CHECKOUT_BALANCE_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
php_dockerfile="$repo_dir/tests/cashier-v3/docker/php74-runtime.Dockerfile"
php_image="${CHECKOUT_BALANCE_PHP_IMAGE:-}"
php_platform="${CHECKOUT_BALANCE_PHP_PLATFORM:-}"
mysql_container="checkout-balance-my56-$$"
tmp_root="$test_dir/_tmp"
mkdir -p "$tmp_root"
work_dir="$(mktemp -d "$tmp_root/mysql56.XXXXXX")"
failure_log="$work_dir/expected-failure.log"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$mysql_container" >&2 || true
    find "$work_dir" -maxdepth 1 -type f -print -exec sed -n '1,240p' {} \; >&2 || true
  fi
  docker rm -fv "$mysql_container" >/dev/null 2>&1 || true
  rm -rf "$work_dir"
}
trap cleanup EXIT INT TERM

if [[ -z "$php_platform" ]]; then
  docker_arch="$(docker version --format '{{.Server.Arch}}')"
  case "$docker_arch" in
    amd64|x86_64) php_platform='linux/amd64' ;;
    arm64|aarch64) php_platform='linux/arm64' ;;
    *) echo "CHECKOUT_BALANCE_UNSUPPORTED_DOCKER_ARCH=$docker_arch" >&2; exit 1 ;;
  esac
fi

if [[ -z "$php_image" ]]; then
  php_image_sha="$(shasum -a 256 "$php_dockerfile" | awk '{print substr($1,1,12)}')"
  php_image="c1a-cashier-v3-php74:${php_image_sha}-${php_platform#linux/}"
  if ! docker image inspect "$php_image" >/dev/null 2>&1; then
    docker build --platform "$php_platform" \
      -f "$php_dockerfile" \
      -t "$php_image" "$repo_dir/tests/cashier-v3/docker"
  fi
fi

docker run --rm --platform "$php_platform" --entrypoint php "$php_image" -r '
  $required = ["bcmath", "pdo_mysql"];
  $missing = [];
  foreach ($required as $extension) {
    if (!extension_loaded($extension)) {
      $missing[] = $extension;
    }
  }
  echo "PHP_VERSION=" . PHP_VERSION . "\n";
  echo "CHECKOUT_BALANCE_PHP_MISSING_EXTENSIONS=" . implode(",", $missing) . "\n";
  exit($missing ? 1 : 0);
'

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
  mysql_file "$db" "$test_dir/sql/base-schema.sql" >/dev/null
}
expect_failure() {
  local db="$1" file="$2" marker="$3"
  if mysql_file "$db" "$file" >"$failure_log" 2>&1; then
    echo "EXPECTED_FAILURE_MISSING=$marker" >&2
    exit 1
  fi
  grep -q "$marker" "$failure_log"
  : > "$failure_log"
  echo "$marker=PASS"
}

db='checkout_balance'
init_db "$db"
mysql_file "$db" "$migration_dir/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$db" "$migration_dir/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$db" "$migration_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
mysql_file "$db" "$migration_dir/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$db" "$migration_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'CHECKOUT_BALANCE_MYSQL56_FRESH_REPLAY=PASS'

mysql_sql "$db" "INSERT INTO eb_user(uid,now_money,ben_money,give_money,status,is_del,belong_store_id) VALUES(301,10.00,8.00,2.00,1,0,7); UPDATE eb_user SET now_money=9.00,ben_money=7.00,give_money=2.00 WHERE uid=301; UPDATE eb_user SET balance_version=99 WHERE uid=301; SELECT IF(balance_version=2,'TRIGGER_OK','TRIGGER_BAD') AS trigger_result FROM eb_user WHERE uid=301" \
  | grep -q 'TRIGGER_OK'
echo 'CHECKOUT_BALANCE_TRIGGER_AUTHORITY=PASS'

partial_db='checkout_balance_partial'
init_db "$partial_db"
mysql_sql "$partial_db" "ALTER TABLE eb_user ADD COLUMN balance_version bigint(20) unsigned NOT NULL DEFAULT '1' AFTER give_money" >/dev/null
mysql_file "$partial_db" "$migration_dir/05-部分升级恢复审计.sql" | grep -q 'PARTIAL_UPGRADE_RECOVERY_READY'
mysql_file "$partial_db" "$migration_dir/01-升级前检查.sql" | grep -q 'PRECHECK_OK'
mysql_file "$partial_db" "$migration_dir/02-正式升级.sql" | grep -q 'APPLY_OK'
mysql_file "$partial_db" "$migration_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
echo 'CHECKOUT_BALANCE_PARTIAL_RECOVERY=PASS'

malformed_db='checkout_balance_malformed'
init_db "$malformed_db"
mysql_sql "$malformed_db" "ALTER TABLE eb_user ADD COLUMN balance_version int(11) NOT NULL DEFAULT '1'" >/dev/null
expect_failure "$malformed_db" "$migration_dir/05-部分升级恢复审计.sql" 'STOP_MEMBER_BALANCE_PARTIAL_UPGRADE_HETEROGENEOUS'
echo 'CHECKOUT_BALANCE_MALFORMED_FAIL_CLOSED=PASS'

conflict_db='checkout_balance_trigger_conflict'
init_db "$conflict_db"
mysql_sql "$conflict_db" 'CREATE TRIGGER conflicting_user_before_update BEFORE UPDATE ON eb_user FOR EACH ROW SET NEW.status=NEW.status' >/dev/null
expect_failure "$conflict_db" "$migration_dir/01-升级前检查.sql" 'STOP_CASHIER_V3_MEMBER_BALANCE_PRECHECK_FAILED'
echo 'CHECKOUT_BALANCE_TRIGGER_CONFLICT_FAIL_CLOSED=PASS'

run_php_test() {
  local script="$1" marker="$2" output="$3"
  docker run --rm --platform "$php_platform" --cpus 1 --memory 384m \
    --network "container:$mysql_container" \
    --volume "$repo_dir:/workspace:ro" \
    --tmpfs /workspace/后端代码/runtime:rw,noexec,nosuid,size=64m \
    --env CHECKOUT_BALANCE_BACKEND_ROOT=/workspace/后端代码 \
    --env DB_HOST=127.0.0.1 --env DB_PORT=3306 \
    --env DB_DATABASE="$db" --env DB_USERNAME=root --env DB_PASSWORD= \
    --entrypoint php "$php_image" "$script" | tee "$output"
  grep -q "$marker" "$output"
}

run_php_test \
  /workspace/tests/cashier-v3-member-balance/php/mysql-integration.php \
  'CHECKOUT_BALANCE_MYSQL_INTEGRATION.*failed=0' \
  "$work_dir/mysql-integration.out"
run_php_test \
  /workspace/tests/cashier-v3-member-balance/php/mysql-concurrency.php \
  'CHECKOUT_BALANCE_CONCURRENCY=PASS' \
  "$work_dir/mysql-concurrency.out"

mysql_file "$db" "$migration_dir/03-升级后验证.sql" | grep -q 'POSTCHECK_OK'
(cd "$migration_dir" && shasum -a 256 -c SHA256SUMS.txt)
echo 'CHECKOUT_BALANCE_MYSQL56_MATRIX=PASS'

#!/usr/bin/env bash
# 统一查询独立永久回归：只装载必要数据底座，避免其他在途业务模块阻断本闭环。
set -euo pipefail

TESTS="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$TESTS/../.." && pwd)"
BACKEND="$REPO/后端代码"
CASHIER_TESTS="$REPO/tests/cashier-v3"
UPGRADES="$BACKEND/database/upgrades"
C1_UPG="$UPGRADES/2026-07-27-收银V3命令与幂等底座"
EVENT_UPG="$UPGRADES/2026-07-28-收银V3统一事件与Outbox"
C5_UPG="$UPGRADES/2026-07-28-C5会员建档一致性"
UQ_UPG="$UPGRADES/2026-07-28-统一查询自定义字段"
RUN_ID="${UQ_RUN_ID:-$(date '+%Y%m%d-%H%M%S')-$$-${RANDOM}}"
EVIDENCE_ROOT="${UQ_EVIDENCE_ROOT:-$TESTS/_evidence}"
EV="${UQ_EVIDENCE_DIR:-$EVIDENCE_ROOT/runs/$RUN_ID}"
INPUT_MANIFEST_START="$EV/unified-query-inputs.start.sha256"
INPUT_MANIFEST_END="$EV/unified-query-inputs.end.sha256"
INPUT_MANIFEST_DIFF="$EV/unified-query-inputs.diff"
COPIED_VENDOR_SNAPSHOT="$EV/unified-query-copied-vendor.sha256"

NET="uq-focus-net-$$"
MYSQL_CONTAINER="uq-focus-my56-$$"
REDIS_CONTAINER="uq-focus-redis-$$"
HTML_VOLUME="uq-focus-html-$$"
MYSQL_IMAGE="docker.m.daocloud.io/library/mysql:5.6.51"
REDIS_IMAGE="docker.m.daocloud.io/library/redis:7-alpine"
ALPINE_IMAGE="docker.m.daocloud.io/library/alpine:3.18"
PHP_DOCKERFILE="$CASHIER_TESTS/docker/php74-runtime.Dockerfile"
PHP_IMAGE_SHA=""
PHP_PLATFORM=""
PHP_IMAGE=""
NODE_LOCK="$CASHIER_TESTS/package-lock.json"
NODE_PACKAGE="$CASHIER_TESTS/package.json"
NODE_DEPS_STAMP="$CASHIER_TESTS/_tmp/uq-node-dependencies.stamp"
NODE_DEPS_START="$EV/unified-query-node-dependencies.start.sha256"
NODE_DEPS_END="$EV/unified-query-node-dependencies.end.sha256"
NODE_DEPS_LOCK_ROOT="${UQ_NODE_DEPS_LOCK_ROOT:-$CASHIER_TESTS/_tmp/.uq-node-dependency-locks}"
NODE_DEPS_LOCK_DIR=""
NODE_DEPS_LOCK_HELD=0
NODE_LOCK_SHA=""
NODE_PACKAGE_SHA=""
NODE_VERSION=""
NODE_PLATFORM=""
EVIDENCE_LOCK_ROOT="${UQ_EVIDENCE_LOCK_ROOT:-$EVIDENCE_ROOT/.locks}"
EVIDENCE_LOCK_ID="$(printf '%s' "$EV" | shasum -a 256 | awk '{print $1}')"
EVIDENCE_LOCK_DIR="$EVIDENCE_LOCK_ROOT/$EVIDENCE_LOCK_ID"
EVIDENCE_LOCK_HELD=0

mkdir -p "$EV" "$CASHIER_TESTS/_tmp"
TEMP_ENV_DIR="$(mktemp -d "$CASHIER_TESTS/_tmp/uq-env.XXXXXX")"
TEMP_ENV="$TEMP_ENV_DIR/test.env"
TEMP_TMP="$(mktemp -d "$CASHIER_TESTS/_tmp/uq-tmp.XXXXXX")"
REAL_ENV="$BACKEND/.env"
REAL_ENV_SHA_START="$(
  if [ -f "$REAL_ENV" ]; then shasum -a 256 "$REAL_ENV" | awk '{print $1}'; else echo ABSENT; fi
)"

# 运行过程中，后端会复制到 disposable volume，而 PHP/JS 测试与前端源码仍以只读
# 挂载或宿主 Node 读取。完整输入清单在开始与结束各算一次：任一输入变更都会让本次
# 证据失败，避免把两个工作版本混成一份 PASS 证据。
input_manifest() {
  local input
  local -a inputs=(
    "$BACKEND/app"
    "$BACKEND/config"
    "$BACKEND/mohe"
    "$BACKEND/route"
    "$BACKEND/think"
    "$BACKEND/composer.json"
    "$BACKEND/composer.lock"
    "$UPGRADES/0000-升级登记表初始化.sql"
    "$C1_UPG"
    "$EVENT_UPG"
    "$C5_UPG"
    "$UQ_UPG"
    "$CASHIER_TESTS/lib"
    "$CASHIER_TESTS/php/member-prepare-schema.php"
    "$CASHIER_TESTS/js/unified-query-frontend-contract.mjs"
    "$CASHIER_TESTS/package.json"
    "$NODE_LOCK"
    "$CASHIER_TESTS/docker"
    "$TESTS/run-all.sh"
    "$TESTS/sql-matrix.sh"
    "$TESTS/php"
    "$TESTS/js"
    "$REPO/前端代码/cashier-v3/src/services/cashierV3Bridge.js"
    "$REPO/前端代码/cashier-v3/src/services/cashierV3ActionManifest.js"
    "$REPO/前端代码/cashier-v3/src/services/unifiedQueryContract.js"
    "$REPO/前端代码/cashier-v3/src/components/query"
    "$REPO/前端代码/cashier-v3/src/composables/useUnifiedQueryPage.js"
    "$REPO/前端代码/cashier-v3/src/dev/UnifiedQueryCustomFieldPreview.vue"
    "$REPO/前端代码/cashier-v3/src/views/MemberListView.vue"
  )

  for input in "${inputs[@]}"; do
    if [ ! -e "$input" ]; then
      echo "UNIFIED_QUERY_INPUT_MISSING=$input" >&2
      return 1
    fi
  done

  (
    for input in "${inputs[@]}"; do
      if [ -d "$input" ]; then
        find "$input" -type f -print
      else
        printf '%s\n' "$input"
      fi
    done
  ) | LC_ALL=C sort | while IFS= read -r input; do
    shasum -a 256 "$input"
  done
}

# vendor 是 Composer 派生产物，不属于可编辑源码；完整后端会先被复制到本次
# 专用的只读 Docker volume。将其在复制完成后签名，既记录实际执行的依赖，又不让
# 其他本地任务的 composer install 在运行结束时伪造“源码发生变化”。composer.lock
# 仍在输入清单中，保证依赖声明的权威版本没有漂移。
capture_copied_vendor_snapshot() {
  local snapshot
  snapshot="$(docker run --rm --platform "$PHP_PLATFORM" \
    -v "$HTML_VOLUME:/dst:ro" \
    "$ALPINE_IMAGE" sh -lc '
      set -eu
      test -f /dst/composer.lock
      test -f /dst/vendor/autoload.php
      {
        sha256sum /dst/composer.lock
        find /dst/vendor -type f -print | LC_ALL=C sort | while IFS= read -r file; do
          sha256sum "$file"
        done
      } | sha256sum | awk "{print \$1}"
    ')"
  if ! printf '%s\n' "$snapshot" | grep -Eq '^[a-f0-9]{64}$'; then
    echo "UNIFIED_QUERY_COPIED_VENDOR_SNAPSHOT_INVALID=$snapshot" >&2
    return 1
  fi
  printf '%s\n' "$snapshot" > "$COPIED_VENDOR_SNAPSHOT"
  echo "UQ_COPIED_VENDOR_SNAPSHOT_SHA256=$snapshot"
  echo "GATE_PASS=UQ-VENDOR-SNAPSHOT-01"
}

node_dependency_stamp() {
  local input
  local -a dependency_inputs=(
    "$CASHIER_TESTS/node_modules/esbuild"
    "$CASHIER_TESTS/node_modules/@esbuild"
  )

  for input in "${dependency_inputs[@]}"; do
    if [ ! -d "$input" ]; then
      echo "UNIFIED_QUERY_NODE_DEPENDENCY_MISSING=$input" >&2
      return 1
    fi
  done

  printf 'lock_sha256=%s\n' "$NODE_LOCK_SHA"
  printf 'package_sha256=%s\n' "$NODE_PACKAGE_SHA"
  printf 'node_version=%s\n' "$NODE_VERSION"
  printf 'node_platform=%s\n' "$NODE_PLATFORM"
  (
    for input in "${dependency_inputs[@]}"; do
      find "$input" -type f -print
    done
  ) | LC_ALL=C sort | while IFS= read -r input; do
    shasum -a 256 "$input"
  done
}

prepare_node_dependencies() {
  if [ ! -f "$NODE_LOCK" ] || [ ! -f "$NODE_PACKAGE" ]; then
    echo "UNIFIED_QUERY_NODE_MANIFEST_MISSING=$NODE_PACKAGE,$NODE_LOCK" >&2
    return 1
  fi
  NODE_LOCK_SHA="$(shasum -a 256 "$NODE_LOCK" | awk '{print $1}')"
  NODE_PACKAGE_SHA="$(shasum -a 256 "$NODE_PACKAGE" | awk '{print $1}')"
  NODE_VERSION="$(node --version)"
  NODE_PLATFORM="$(node -p 'process.platform + "-" + process.arch')"
  # node_modules 是整个前端测试工程共享的可变目录；锁不能按 lockfile 分片，
  # 否则两个不同版本的 npm ci 仍可能并发覆盖同一目录。
  NODE_DEPS_LOCK_DIR="$NODE_DEPS_LOCK_ROOT/node_modules"
  mkdir -p "$NODE_DEPS_LOCK_ROOT"
  if ! mkdir "$NODE_DEPS_LOCK_DIR" 2>/dev/null; then
    echo "UNIFIED_QUERY_NODE_DEPENDENCY_LOCKED=$NODE_DEPS_LOCK_DIR" >&2
    return 1
  fi
  NODE_DEPS_LOCK_HELD=1

  local current="$TEMP_TMP/unified-query-node-dependencies.current.sha256"
  local needs_install=0
  if [ ! -d "$CASHIER_TESTS/node_modules" ] || [ ! -f "$NODE_DEPS_STAMP" ]; then
    needs_install=1
  elif ! node_dependency_stamp > "$current" || ! cmp -s "$current" "$NODE_DEPS_STAMP"; then
    needs_install=1
  fi

  if [ "$needs_install" = "1" ]; then
    (cd "$CASHIER_TESTS" && npm ci --no-audit --no-fund)
    node_dependency_stamp > "$NODE_DEPS_STAMP"
  fi
  node_dependency_stamp > "$NODE_DEPS_START"
  if ! cmp -s "$NODE_DEPS_START" "$NODE_DEPS_STAMP"; then
    echo "UNIFIED_QUERY_NODE_DEPENDENCY_STAMP_MISMATCH=$NODE_DEPS_STAMP" >&2
    return 1
  fi
  echo "UQ_NODE_DEPENDENCY_LOCK_SHA256=$NODE_LOCK_SHA"
}

verify_node_dependencies() {
  node_dependency_stamp > "$NODE_DEPS_END"
  if ! cmp -s "$NODE_DEPS_START" "$NODE_DEPS_END"; then
    echo "UNIFIED_QUERY_NODE_DEPENDENCIES_CHANGED=$NODE_DEPS_END" >&2
    return 1
  fi
  echo "GATE_PASS=UQ-NODE-DEPS-01"
}

verify_input_manifest() {
  input_manifest > "$INPUT_MANIFEST_END"
  if ! cmp -s "$INPUT_MANIFEST_START" "$INPUT_MANIFEST_END"; then
    diff -u "$INPUT_MANIFEST_START" "$INPUT_MANIFEST_END" > "$INPUT_MANIFEST_DIFF" || true
    echo "UNIFIED_QUERY_INPUTS_CHANGED=$INPUT_MANIFEST_DIFF" >&2
    return 1
  fi
  echo "GATE_PASS=UQ-INPUT-SNAPSHOT-01"
}

cleanup() {
  local rc=$?
  docker rm -f "$MYSQL_CONTAINER" "$REDIS_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  docker volume rm -f "$HTML_VOLUME" >/dev/null 2>&1 || true
  rm -f "$TEMP_ENV"
  rm -rf "$TEMP_ENV_DIR" "$TEMP_TMP"
  if [ "$NODE_DEPS_LOCK_HELD" = "1" ] && [ -n "$NODE_DEPS_LOCK_DIR" ]; then
    rmdir "$NODE_DEPS_LOCK_DIR" >/dev/null 2>&1 || true
  fi
  if [ "$EVIDENCE_LOCK_HELD" = "1" ]; then
    rmdir "$EVIDENCE_LOCK_DIR" >/dev/null 2>&1 || true
  fi
  local real_env_sha_end
  real_env_sha_end="$(
    if [ -f "$REAL_ENV" ]; then shasum -a 256 "$REAL_ENV" | awk '{print $1}'; else echo ABSENT; fi
  )"
  if [ "$REAL_ENV_SHA_START" != "$real_env_sha_end" ]; then
    echo "REAL_ENV_CHANGED=$REAL_ENV_SHA_START->$real_env_sha_end" >&2
    exit 1
  fi
  exit "$rc"
}
trap cleanup EXIT INT TERM

mkdir -p "$EVIDENCE_LOCK_ROOT"
if ! mkdir "$EVIDENCE_LOCK_DIR" 2>/dev/null; then
  echo "UNIFIED_QUERY_EVIDENCE_LOCKED=$EV" >&2
  exit 73
fi
EVIDENCE_LOCK_HELD=1

printf '%s\n' \
  'APP_DEBUG = true' \
  "HOSTNAME = $MYSQL_CONTAINER" \
  'DATABASE = lin8' \
  'USERNAME = root' \
  'PASSWORD = localdev123' \
  'HOSTPORT = 3306' \
  "REDIS_HOSTNAME = $REDIS_CONTAINER" \
  'DRIVER = file' \
  'CACHE_DRIVER = file' > "$TEMP_ENV"

if [ ! -f "$BACKEND/vendor/autoload.php" ]; then
  echo "UNIFIED_QUERY_VENDOR_AUTOLOAD_MISSING=$BACKEND/vendor/autoload.php" >&2
  exit 1
fi
prepare_node_dependencies

# 输入清单必须在构建 PHP 镜像、复制后端 volume、启动数据库之前固定；结束时再验一次。
input_manifest > "$INPUT_MANIFEST_START"

PHP_IMAGE_SHA="$(shasum -a 256 "$PHP_DOCKERFILE" | awk '{print substr($1,1,12)}')"
DOCKER_ARCH="$(docker version --format '{{.Server.Arch}}')"
case "$DOCKER_ARCH" in
  amd64|x86_64) PHP_PLATFORM="linux/amd64" ;;
  arm64|aarch64) PHP_PLATFORM="linux/arm64" ;;
  *) echo "UNSUPPORTED_DOCKER_ARCH=$DOCKER_ARCH" >&2; exit 1 ;;
esac
PHP_IMAGE="c1a-cashier-v3-php74:${PHP_IMAGE_SHA}-${PHP_PLATFORM#linux/}"
if ! docker image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
  docker build --platform "$PHP_PLATFORM" \
    -f "$PHP_DOCKERFILE" \
    -t "$PHP_IMAGE" "$CASHIER_TESTS/docker"
fi

PHP_RUNTIME_RC=0
PHP_RUNTIME_OUT="$(docker run --rm --platform "$PHP_PLATFORM" --entrypoint php \
  "$PHP_IMAGE" -r '
    $required = ["bcmath", "zip", "mbstring", "pdo_mysql", "dom", "xml", "xmlreader", "xmlwriter", "simplexml", "fileinfo", "gd", "iconv", "ctype", "libxml", "zlib"];
    $missing = [];
    foreach ($required as $extension) {
      if (!extension_loaded($extension)) {
        $missing[] = $extension;
      }
    }
    echo "PHP_VERSION=" . PHP_VERSION . "\n";
    echo "PHP_REQUIRED_EXTENSIONS=" . implode(",", $required) . "\n";
    echo "PHP_MISSING_EXTENSIONS=" . implode(",", $missing) . "\n";
    exit($missing ? 1 : 0);
  ' 2>&1)" || PHP_RUNTIME_RC=$?
printf '%s\n' "$PHP_RUNTIME_OUT" | tee "$EV/php-runtime.out"
if [ "$PHP_RUNTIME_RC" != "0" ] \
  || ! printf '%s\n' "$PHP_RUNTIME_OUT" | grep -q '^PHP_VERSION=7\.4\.' \
  || ! printf '%s\n' "$PHP_RUNTIME_OUT" | grep -q '^PHP_MISSING_EXTENSIONS=$'; then
  echo "UNIFIED_QUERY_PHP_RUNTIME_NOT_READY=$EV/php-runtime.out" >&2
  exit 1
fi
echo "GATE_PASS=UQ-ENV-01"

docker volume create "$HTML_VOLUME" >/dev/null
docker run --rm --platform "$PHP_PLATFORM" \
  -v "$BACKEND:/src:ro" \
  -v "$TEMP_ENV_DIR:/env:ro" \
  -v "$HTML_VOLUME:/dst" \
  "$ALPINE_IMAGE" sh -lc '
    set -e
    tar -C /src --exclude="./.env" --exclude="./.env.*" -cf - . | tar -C /dst -xf -
    mkdir -p /dst/runtime /dst/public/uploads
    test ! -f /dst/.env
    cp /env/test.env /dst/.env
    grep -q "PASSWORD = localdev123" /dst/.env
  '
capture_copied_vendor_snapshot

docker network create "$NET" >/dev/null
# MySQL 5.6 镜像在本机 arm64 Docker 环境中由引擎的兼容路径启动；显式强制
# linux/amd64 会在初始化阶段触发旧镜像异常退出。这里不指定 platform，但仍在
# 启动后强制校验精确版本 5.6.51，避免把较高版本误当成兼容证据。
docker run -d --name "$MYSQL_CONTAINER" --network "$NET" \
  -e MYSQL_ROOT_PASSWORD=localdev123 \
  -e MYSQL_DATABASE=lin8 \
  "$MYSQL_IMAGE" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null
docker run -d --name "$REDIS_CONTAINER" --network "$NET" "$REDIS_IMAGE" >/dev/null

ready=0
for _ in $(seq 1 90); do
  if docker logs "$MYSQL_CONTAINER" 2>&1 \
      | grep -F 'MySQL init process done. Ready for start up.' >/dev/null \
    && docker exec "$MYSQL_CONTAINER" \
      mysql -uroot -plocaldev123 -e 'SELECT 1' >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 2
done
if [ "$ready" != "1" ]; then
  docker logs "$MYSQL_CONTAINER" >&2
  exit 1
fi
MYSQL_VERSION="$(docker exec "$MYSQL_CONTAINER" mysql -uroot -plocaldev123 -Nse 'SELECT VERSION()')"
case "$MYSQL_VERSION" in
  5.6.51*) ;;
  *) echo "MYSQL_VERSION_MISMATCH=$MYSQL_VERSION" >&2; exit 1 ;;
esac

docker_php() {
  docker run --rm --entrypoint sh \
    --network "$NET" \
    --platform "$PHP_PLATFORM" \
    -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE=lin8 \
    -e DB_USERNAME=root -e DB_PASSWORD=localdev123 \
    -e HOSTNAME="$MYSQL_CONTAINER" -e HOSTPORT=3306 -e DATABASE=lin8 \
    -e USERNAME=root -e PASSWORD=localdev123 \
    -e REDIS_HOSTNAME="$REDIS_CONTAINER" -e CACHE_DRIVER=file -e DRIVER=file \
    -e C1A_EVIDENCE_DIR=/evidence -e C1A_TMP_DIR=/tmp/uq \
    -e C1A_TEMP_ENV_MARKER="$MYSQL_CONTAINER" \
    -v "$HTML_VOLUME:/var/www/html:ro" \
    -v "$CASHIER_TESTS:/tests:ro" \
    -v "$REPO/tests:/all-tests:ro" \
    -v "$EV:/evidence:rw" \
    --tmpfs /tmp/uq:rw,size=64m \
    --tmpfs /var/www/html/runtime:rw,size=64m \
    --tmpfs /var/www/html/public/uploads:rw,size=16m \
    -w /var/www/html "$PHP_IMAGE" -lc "$1"
}

mysql_file() {
  docker exec -i "$MYSQL_CONTAINER" mysql -uroot -plocaldev123 lin8 < "$1"
}

register_upgrade() {
  local key="$1" title="$2" file="$3" checksum
  checksum="$(shasum -a 256 "$file" | awk '{print $1}')"
  docker exec "$MYSQL_CONTAINER" mysql -uroot -plocaldev123 lin8 -e "
    INSERT INTO eb_database_upgrade_log
      (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
    VALUES
      ('$key','$title','02-正式升级.sql','$checksum','local-test',NOW(),'codex-test','unified query isolated gate');
  "
}

mysql_file "$UPGRADES/0000-升级登记表初始化.sql"
mysql_file "$C1_UPG/02-正式升级.sql"
register_upgrade \
  '20260727-001-cashier-v3-command-idem' \
  'cashier v3 command dependency' \
  "$C1_UPG/02-正式升级.sql"

PREPARE_C5_OUT="$(docker_php 'php /tests/php/member-prepare-schema.php')"
printf '%s\n' "$PREPARE_C5_OUT" | tee "$EV/member-prepare-schema.out"
if ! printf '%s\n' "$PREPARE_C5_OUT" | grep -q '^C5_LEGACY_SCHEMA_READY=1$'; then
  echo "UNIFIED_QUERY_C5_LEGACY_SCHEMA_NOT_READY=$EV/member-prepare-schema.out" >&2
  exit 1
fi
echo "GATE_PASS=UQ-C5-LEGACY-01"

mysql_file "$EVENT_UPG/01-升级前检查.sql"
mysql_file "$EVENT_UPG/02-正式升级.sql"
mysql_file "$EVENT_UPG/03-升级后验证.sql"
register_upgrade \
  '20260728-003-cashier-v3-event-outbox' \
  'cashier v3 event dependency' \
  "$EVENT_UPG/02-正式升级.sql"

mysql_file "$C5_UPG/01-升级前检查.sql"
mysql_file "$C5_UPG/02-正式升级.sql"
mysql_file "$C5_UPG/03-升级后验证.sql"
register_upgrade \
  '20260728-002-cashier-v3-member-consistency' \
  'cashier v3 member dependency' \
  "$C5_UPG/02-正式升级.sql"

mysql_file "$UQ_UPG/01-升级前检查.sql"
mysql_file "$UQ_UPG/02-正式升级.sql"
mysql_file "$UQ_UPG/02-正式升级.sql"
mysql_file "$UQ_UPG/03-升级后验证.sql" | tee "$EV/unified-query-postcheck.out"
register_upgrade \
  '20260728-004-unified-query-custom-fields' \
  'unified query custom fields' \
  "$UQ_UPG/02-正式升级.sql"
UQ_UPGRADE_CHECKSUM="$(shasum -a 256 "$UQ_UPG/02-正式升级.sql" | awk '{print $1}')"
UQ_REGISTRATION_COUNT="$(docker exec "$MYSQL_CONTAINER" mysql -uroot -plocaldev123 lin8 -Nse "
  SELECT COUNT(*)
  FROM eb_database_upgrade_log
  WHERE upgrade_key='20260728-004-unified-query-custom-fields'
    AND title='unified query custom fields'
    AND task_file='02-正式升级.sql'
    AND sql_checksum='$UQ_UPGRADE_CHECKSUM'
    AND code_version='local-test';
")"
if [ "$UQ_REGISTRATION_COUNT" != "1" ]; then
  echo "UNIFIED_QUERY_UPGRADE_REGISTRATION_INVALID=$UQ_REGISTRATION_COUNT" >&2
  exit 1
fi
if mysql_file "$UQ_UPG/01-升级前检查.sql" > "$EV/unified-query-reapply-precheck.out" 2>&1; then
  echo "UNIFIED_QUERY_REGISTERED_REAPPLY_NOT_REJECTED" >&2
  exit 1
fi
echo "GATE_PASS=UQ-MIGRATION-REGISTRATION-01"

docker_php 'php /all-tests/unified-query/php/contract.php' \
  | tee "$EV/unified-query-contract.out"
docker_php 'php /all-tests/unified-query/php/metadata-integration.php' \
  | tee "$EV/unified-query-metadata.out"
docker_php 'php /all-tests/unified-query/php/gateway-integration.php' \
  | tee "$EV/unified-query-gateway.out"
docker_php 'php /all-tests/unified-query/php/member-provider-integration.php' \
  | tee "$EV/unified-query-member-provider.out"

C1A_EVIDENCE_DIR="$EV" C1A_TMP_DIR="$TEMP_TMP" \
  node "$TESTS/js/gateway-envelope-contract.mjs" \
  "$EV/unified-query-gateway-samples.json" \
  | tee "$EV/unified-query-gateway-envelope.out"
node "$CASHIER_TESTS/js/unified-query-frontend-contract.mjs" \
  | tee "$EV/unified-query-frontend-contract.out"
bash "$TESTS/sql-matrix.sh" | tee "$EV/unified-query-sql-matrix.out"

verify_node_dependencies
verify_input_manifest

echo "UQ_FOCUSED_SUITE=PASS"
echo "UQ_EVIDENCE_DIR=$EV"

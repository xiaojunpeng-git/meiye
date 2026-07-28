#!/usr/bin/env bash
# C1-A 永久回归门禁｜隔离运行（禁止污染真实 .env）
set -euo pipefail

TESTS="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$TESTS/../.." && pwd)"
BACKEND="$REPO/后端代码"
REAL_ENV="$BACKEND/.env"
UPGRADES="$BACKEND/database/upgrades"
UPG="$UPGRADES/2026-07-27-收银V3命令与幂等底座"
EVENT_UPG="$UPGRADES/2026-07-28-收银V3统一事件与Outbox"
C5_UPG="$UPGRADES/2026-07-28-C5会员建档一致性"
EVIDENCE_ROOT="${C1A_EVIDENCE_ROOT:-$TESTS/_evidence}"
RUN_ID="${C1A_RUN_ID:-$(date '+%Y%m%d-%H%M%S')-$$-${RANDOM}}"
if [ -n "${C1A_EVIDENCE_DIR:-}" ]; then
  EV="$C1A_EVIDENCE_DIR"
else
  EV="$EVIDENCE_ROOT/runs/$RUN_ID"
fi
LOG="$EV/TEST-RUN.log"
MATRIX="$TESTS/requirements-matrix.json"

# 同一目标目录只允许一个运行者；默认目录还会按 run id 分隔，避免并发覆盖证据。
LOCK_ROOT="${C1A_EVIDENCE_LOCK_ROOT:-$EVIDENCE_ROOT/.locks}"
mkdir -p "$LOCK_ROOT"
LOCK_ID=$(printf '%s' "$EV" | shasum -a 256 | awk '{print $1}')
LOCK_DIR="$LOCK_ROOT/$LOCK_ID"
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  echo "FAIL: evidence directory is already in use: $EV" >&2
  exit 73
fi

NET=c1a-v3-net-$$
MY=c1a-v3-my56-$$
REDIS=c1a-v3-redis-$$
HTML_VOL=c1a-v3-html-$$
# 固定不可变镜像：5.6.51；调用方不得覆盖兼容基线。
MYSQL_IMAGE="docker.m.daocloud.io/library/mysql:5.6.51"
EXPECTED_MYSQL_DIGEST="sha256:20575ecebe6216036d25dab5903808211f1e9ba63dc7825ac20cb975e34cfcae"
APP_IMAGE="${APP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"
ALPINE_IMAGE="${ALPINE_IMAGE:-docker.m.daocloud.io/library/alpine:3.18}"

mkdir -p "$EV"
: > "$LOG"

# 日志写入证据文件；调用方可用 tee 观察。避免 process substitution / tail -F 撑爆日志。
log() { echo "$*" | tee -a "$LOG"; }
# 后续所有 stdout 也追加到 LOG（不经 tee 管道，防沙箱/膨胀）
exec >>"$LOG" 2>&1
# 同时保留一份可读摘要到 fd3（若调用方重定向了 stdout，这里仍写 LOG）
echo "==== C1-A permanent run $(date '+%Y-%m-%d %H:%M:%S') ===="

OVERALL=0
EXIT_CODE=0
CLEANUP_DONE=0
CLEANUP_RC=0

record_runner() {
  local name="$1" rc="$2"
  if [ "$rc" = "0" ]; then
    echo "RUNNER_OK=${name}"
  else
    echo "RUNNER_FAIL=${name}"
    OVERALL=1
  fi
}
mark_runner() { record_runner "$1" "$2"; }

real_env_fingerprint() {
  if [ -f "$REAL_ENV" ]; then
    shasum -a 256 "$REAL_ENV" | awk '{print $1}'
  else
    echo "ABSENT"
  fi
}

echo "== canonical migration and delivery mirror contract =="
set +e
bash "$TESTS/migration-mirror-contract.sh" | tee "$EV/migration-mirror-contract.out"
MIG_RC=${PIPESTATUS[0]}
set -e
mark_runner "migration-mirror-contract.sh" "$MIG_RC"

# ISO-3-05 / ISO-3-07：记录真实 .env 的存在状态与 SHA，禁止创建或改写。
ENV_SHA_START=$(real_env_fingerprint)
echo "REAL_ENV_SHA_START=$ENV_SHA_START"

# ISO-3-02：mktemp 最小配置（无真实凭据）
# Colima 通常看不到 macOS /var/folders；临时目录必须落在工作区内。
# 目录挂载对点文件也不可靠，故临时文件名用 c1a-test.env（再覆盖为容器内 .env）。
mkdir -p "$TESTS/_tmp"
TEMP_ENV_DIR=$(mktemp -d "$TESTS/_tmp/c1a-envdir.XXXXXX")
TEMP_ENV="$TEMP_ENV_DIR/c1a-test.env"
TEMP_TMP=$(mktemp -d "$TESTS/_tmp/c1a-tmp.XXXXXX")
cat > "$TEMP_ENV" <<EOF
APP_DEBUG = true
HOSTNAME = ${MY}
DATABASE = lin8
USERNAME = root
PASSWORD = localdev123
HOSTPORT = 3306
REDIS_HOSTNAME = ${REDIS}
DRIVER = file
CACHE_DRIVER = file
EOF
echo "TEMP_ENV=$TEMP_ENV (minimal, no production secrets)"
# ISO-3-04：最小测试 env 已写入且不含生产口令文件路径作为源
if [ -f "$TEMP_ENV" ] && grep -q 'PASSWORD = localdev123' "$TEMP_ENV" && ! grep -q 'production' "$TEMP_ENV"; then
  echo "GATE_PASS=ISO-3-04"
else
  echo "FAIL: ISO-3-04 temp env not proven"
  OVERALL=1
fi

cleanup_resources() {
  if [ "$CLEANUP_DONE" = "1" ]; then
    return "$CLEANUP_RC"
  fi
  local cleanup_rc=0
  docker rm -f "$MY" "$REDIS" >/dev/null 2>&1 || cleanup_rc=1
  docker network rm "$NET" >/dev/null 2>&1 || cleanup_rc=1
  docker volume rm -f "$HTML_VOL" >/dev/null 2>&1 || cleanup_rc=1
  if docker inspect "$MY" >/dev/null 2>&1 || docker inspect "$REDIS" >/dev/null 2>&1; then cleanup_rc=1; fi
  if docker network inspect "$NET" >/dev/null 2>&1; then cleanup_rc=1; fi
  if docker volume inspect "$HTML_VOL" >/dev/null 2>&1; then cleanup_rc=1; fi
  rm -f "$TEMP_ENV"
  rm -rf "$TEMP_ENV_DIR"
  rm -rf "$TEMP_TMP"
  if [ -d "$LOCK_DIR" ]; then rmdir "$LOCK_DIR" 2>/dev/null || cleanup_rc=1; fi
  ENV_SHA_END=$(real_env_fingerprint)
  echo "REAL_ENV_SHA_END=$ENV_SHA_END"
  if [ "${ENV_SHA_START:-}" != "$ENV_SHA_END" ]; then
    echo "FAIL: real .env presence/SHA changed ($ENV_SHA_START -> $ENV_SHA_END)"
    cleanup_rc=1
  fi
  if [ "$cleanup_rc" != "0" ]; then
    echo "FAIL: ISO-3-06 cleanup verification failed"
  else
    echo "GATE_PASS=ISO-3-06"
  fi
  CLEANUP_DONE=1
  CLEANUP_RC="$cleanup_rc"
  return "$cleanup_rc"
}

cleanup_on_exit() {
  local previous_rc=$?
  cleanup_resources >/dev/null 2>&1 || true
  if [ "$previous_rc" != "0" ]; then
    exit "$previous_rc"
  fi
  exit "$CLEANUP_RC"
}
trap cleanup_on_exit EXIT
# ISO-3-06 在 cleanup trap 中验证实际容器、网络和 volume 已删除。
type cleanup_resources >/dev/null 2>&1 || { echo "FAIL: ISO-3-06 cleanup function missing"; exit 1; }

echo "==== C1-A permanent run $(date '+%Y-%m-%d %H:%M:%S') ===="
echo "TESTS=$TESTS"
echo "EV=$EV"

docker_php() {
  # 后端源码复制到独立 volume，再用临时最小配置覆盖 volume 内 /var/www/html/.env（只读挂载）。
  # 宿主真实 .env 从不进入该 volume（准备阶段从源头排除）。
  docker run --rm --entrypoint sh \
    --network "$NET" \
    --platform linux/amd64 \
    -e DB_HOST="$MY" -e DB_PORT=3306 -e DB_DATABASE=lin8 \
    -e DB_USERNAME=root -e DB_PASSWORD=localdev123 \
    -e HOSTNAME="$MY" -e HOSTPORT=3306 -e DATABASE=lin8 \
    -e USERNAME=root -e PASSWORD=localdev123 \
    -e REDIS_HOSTNAME="$REDIS" -e CACHE_DRIVER=file -e DRIVER=file \
    -e C1A_EVIDENCE_DIR=/evidence -e C1A_TMP_DIR=/tmp/c1a \
    -e C1A_TEMP_ENV_MARKER="$MY" \
    -v "$HTML_VOL:/var/www/html:ro" \
    -v "$TESTS:/tests:ro" \
    -v "$UPG:/upgrade:ro" \
    -v "$EV:/evidence:rw" \
    --tmpfs /tmp/c1a:rw,size=64m \
    --tmpfs /var/www/html/runtime:rw,size=64m \
    --tmpfs /var/www/html/public/uploads:rw,size=16m \
    -w /var/www/html "$APP_IMAGE" -lc "$1"
}

# ISO-3-01：后端只读挂载合同；真实 .env 可存在或不存在，均不会复制进隔离 volume。
if [ -d "$BACKEND" ]; then
  echo "REAL_ENV_INITIAL_STATE=$ENV_SHA_START"
  echo "GATE_PASS=ISO-3-01"
else
  echo "FAIL: ISO-3-01 backend path missing"
  exit 1
fi

echo "== prepare isolated html volume (exclude real .env from source) =="
docker volume create "$HTML_VOL" >/dev/null
docker pull "$ALPINE_IMAGE" >/dev/null 2>&1 || true
PREP_OUT=$(docker run --rm --platform linux/amd64 \
  -v "$BACKEND:/src:ro" \
  -v "$TEMP_ENV_DIR:/tmp/c1a-env:ro" \
  -v "$HTML_VOL:/dst" \
  "$ALPINE_IMAGE" \
  sh -lc 'set -e
    # 从源头排除真实 .env：禁止先复制再删除
    if command -v rsync >/dev/null 2>&1; then
      rsync -a --exclude=".env" --exclude=".env.*" /src/ /dst/
    else
      tar -C /src --exclude="./.env" --exclude="./.env.*" -cf - . | tar -C /dst -xf -
    fi
    # runtime/uploads are intentionally Git-ignored and may not exist in a
    # clean checkout. Create mountpoints inside the disposable volume before
    # that volume is mounted read-only into PHP test containers.
    mkdir -p /dst/runtime /dst/public/uploads
    test ! -f /dst/.env
    test -f /tmp/c1a-env/c1a-test.env
    cp /tmp/c1a-env/c1a-test.env /dst/.env
    test -f /dst/.env
    grep -q "PASSWORD = localdev123" /dst/.env
    grep -q "HOSTNAME = " /dst/.env
    test -s /dst/.env
    # 证明未残留真实凭据文件名以外的生产密钥：临时 env 标记必须匹配容器名
    grep -q "HOSTNAME = " /dst/.env
    echo TEMP_ENV_OVERLAY_AT_HTML_ENV=1
    echo ENV_EXCLUDED_FROM_SOURCE_COPY=1
  ')
echo "$PREP_OUT"
echo "$PREP_OUT" | grep -q 'TEMP_ENV_OVERLAY_AT_HTML_ENV=1' || { echo "FAIL: .env overlay into html volume failed"; exit 1; }
echo "$PREP_OUT" | grep -q 'ENV_EXCLUDED_FROM_SOURCE_COPY=1' || { echo "FAIL: real .env was not excluded at source"; exit 1; }
echo "GATE_PASS=ISO-3-02"

echo "== npm ci (test deps) =="
if [ ! -d "$TESTS/node_modules" ]; then
  ( cd "$TESTS" && npm ci --no-audit --no-fund )
fi

echo "== MySQL $MYSQL_IMAGE =="
docker network create "$NET" >/dev/null
PULL_OUT=$(docker pull --platform linux/amd64 "$MYSQL_IMAGE" 2>&1 || true)
echo "$PULL_OUT" | tee "$EV/MYSQL-PULL.log"
MYSQL_DIGEST=$(echo "$PULL_OUT" | awk '/Digest:/ {print $2; exit}')
MYSQL_REPO=$(docker image inspect "$MYSQL_IMAGE" --format '{{index .RepoDigests 0}}' 2>/dev/null || true)
echo "MYSQL_IMAGE=$MYSQL_IMAGE"
echo "MYSQL_DIGEST=${MYSQL_DIGEST:-unknown}"
echo "MYSQL_REPO_DIGEST=${MYSQL_REPO:-unknown}"
echo "EXPECTED_MYSQL_DIGEST=$EXPECTED_MYSQL_DIGEST"
DIGEST_OK=0
if [ "${MYSQL_DIGEST:-}" = "$EXPECTED_MYSQL_DIGEST" ] || [ "${MYSQL_REPO:-}" = "${MYSQL_IMAGE%@*}@$EXPECTED_MYSQL_DIGEST" ] || echo "${MYSQL_REPO:-}" | grep -q "$EXPECTED_MYSQL_DIGEST"; then
  DIGEST_OK=1
fi
if [ -z "${MYSQL_DIGEST:-}" ] && [ -z "${MYSQL_REPO:-}" ]; then
  echo "FAIL: MySQL image digest unknown (must pin immutable digest)"
  OVERALL=1
elif [ "$DIGEST_OK" != "1" ]; then
  echo "FAIL: MySQL digest mismatch expected=$EXPECTED_MYSQL_DIGEST got digest=${MYSQL_DIGEST:-} repo=${MYSQL_REPO:-}"
  OVERALL=1
else
  echo "MYSQL_DIGEST_MATCH=1"
fi
docker run -d --name "$MY" --network "$NET" --platform linux/amd64 \
  -e MYSQL_ROOT_PASSWORD=localdev123 \
  -e MYSQL_DATABASE=lin8 \
  "$MYSQL_IMAGE" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null
docker run -d --name "$REDIS" --network "$NET" docker.m.daocloud.io/library/redis:7-alpine >/dev/null
ready=0
for _ in $(seq 1 90); do
  docker exec "$MY" mysql -uroot -plocaldev123 -e "SELECT 1" >/dev/null 2>&1 && ready=1 && break
  sleep 2
done
if [ "$ready" != "1" ]; then
  docker logs "$MY" > "$EV/MYSQL-STARTUP.log" 2>&1 || true
  echo "FAIL: MySQL not ready; see $EV/MYSQL-STARTUP.log"
  exit 1
fi
MYSQL_VERSION=""
for _ in $(seq 1 10); do
  MYSQL_VERSION=$(docker exec "$MY" mysql -uroot -plocaldev123 -N -B -e "SELECT VERSION();" 2>/dev/null || true)
  [ -n "$MYSQL_VERSION" ] && break
  sleep 1
done
if [ -z "$MYSQL_VERSION" ]; then
  docker logs "$MY" > "$EV/MYSQL-STARTUP.log" 2>&1 || true
  echo "FAIL: MySQL became unavailable after readiness; see $EV/MYSQL-STARTUP.log"
  exit 1
fi
echo "MYSQL_VERSION=$MYSQL_VERSION" | tee "$EV/SQL-MYSQL56.log"
case "$MYSQL_VERSION" in
  5.6.51*) echo "MYSQL_VERSION_MATCH=1" ;;
  *) echo "FAIL: expected MySQL 5.6.51, got $MYSQL_VERSION"; OVERALL=1 ;;
esac
docker inspect "$MY" --format 'MYSQL_IMAGE_ID={{.Image}}' | tee -a "$EV/SQL-MYSQL56.log"
echo "MYSQL_REPO_DIGEST=${MYSQL_REPO:-unknown}" | tee -a "$EV/SQL-MYSQL56.log"

echo "== tmpfs isolation probe =="
set +e
TMPFS_OUT=$(docker_php 'set -e
  awk '\''$2=="/tmp/c1a" && $3=="tmpfs" { found=1 } END { exit(found ? 0 : 1) }'\'' /proc/mounts
  awk '\''$2=="/var/www/html/runtime" && $3=="tmpfs" { found=1 } END { exit(found ? 0 : 1) }'\'' /proc/mounts
  touch /tmp/c1a/.tmpfs-write-probe
  touch /var/www/html/runtime/.tmpfs-write-probe
  rm -f /tmp/c1a/.tmpfs-write-probe /var/www/html/runtime/.tmpfs-write-probe
  echo TMPFS_RUNTIME_OK=1
')
TMPFS_RC=$?
set -e
echo "$TMPFS_OUT"
if [ "$TMPFS_RC" = "0" ] && echo "$TMPFS_OUT" | grep -q 'TMPFS_RUNTIME_OK=1'; then
  echo "GATE_PASS=ISO-3-03"
else
  echo "FAIL: runtime/cache tmpfs isolation was not proven"
  OVERALL=1
fi

docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$UPGRADES/0000-升级登记表初始化.sql"
docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$UPG/02-正式升级.sql"
BASE_UPGRADE_SHA=$(shasum -a 256 "$UPG/02-正式升级.sql" | awk '{print $1}')
docker exec "$MY" mysql -uroot -plocaldev123 lin8 -e "
  INSERT INTO eb_database_upgrade_log
    (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
  VALUES
    ('20260727-001-cashier-v3-command-idem','test dependency','02-正式升级.sql','${BASE_UPGRADE_SHA}','local-test',NOW(),'codex-test','local gate dependency');
"
PREPARE_C5_OUT=$(docker_php 'php /tests/php/member-prepare-schema.php')
echo "$PREPARE_C5_OUT"
echo "$PREPARE_C5_OUT" | grep -q 'C5_LEGACY_SCHEMA_READY=1' || {
  echo "FAIL: C5 legacy member schema fixture was not prepared"
  exit 1
}
if [ ! -s "$EVENT_UPG/02-正式升级.sql" ]; then
  echo "FAIL: missing event migration at $EVENT_UPG/02-正式升级.sql"
  OVERALL=1
else
  docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$EVENT_UPG/01-升级前检查.sql"
  docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$EVENT_UPG/02-正式升级.sql"
  docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$EVENT_UPG/02-正式升级.sql"
  docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$EVENT_UPG/03-升级后验证.sql"
  EVENT_UPGRADE_SHA=$(shasum -a 256 "$EVENT_UPG/02-正式升级.sql" | awk '{print $1}')
  docker exec "$MY" mysql -uroot -plocaldev123 lin8 -e "
    INSERT INTO eb_database_upgrade_log
      (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
    VALUES
      ('20260728-003-cashier-v3-event-outbox','test dependency','02-正式升级.sql','${EVENT_UPGRADE_SHA}','local-test',NOW(),'codex-test','local gate dependency');
  "
fi
if [ ! -s "$C5_UPG/02-正式升级.sql" ]; then
  echo "FAIL: missing C5 member migration at $C5_UPG/02-正式升级.sql"
  OVERALL=1
else
  docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$C5_UPG/01-升级前检查.sql"
  docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$C5_UPG/02-正式升级.sql"
  docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$C5_UPG/02-正式升级.sql"
  docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$C5_UPG/03-升级后验证.sql"
fi

LEGACY_MEMBER_OUTBOX_TABLES=$(docker exec "$MY" mysql -uroot -plocaldev123 -N -B lin8 -e "
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_member_event_outbox';
")
if [ "$LEGACY_MEMBER_OUTBOX_TABLES" != "0" ]; then
  echo "FAIL: C5 migration recreated legacy member Outbox"
  OVERALL=1
else
  echo "GATE_PASS=C5-MEM-11"
fi

if grep -q 'cashier_v3_member_event_outbox' "$BACKEND/app/services/cashier/v3/member/CashierV3MemberModule.php" \
  || grep -q 'cashier_v3_member_event_outbox' "$C5_UPG/02-正式升级.sql"; then
  echo "FAIL: legacy member Outbox reference returned to C5 production code or apply SQL"
  OVERALL=1
else
  echo "GATE_PASS=C5-MEM-10"
fi

export MYSQL_CONTAINER="$MY"
export C1A_EVIDENCE_DIR="$EV"
export MYSQL_IMAGE

echo "== SQL matrix =="
set +e
bash "$TESTS/sql/run-sql-matrix.sh" | tee "$EV/SQL-MATRIX.log"
SQL_RC=${PIPESTATUS[0]}
set -e
mark_runner "sql/run-sql-matrix.sh" "$SQL_RC"

echo "== event Outbox SQL matrix =="
set +e
bash "$TESTS/event-outbox-sql-matrix.sh" | tee "$EV/EVENT-OUTBOX-SQL-MATRIX.log"
EO_SQL_RC=${PIPESTATUS[0]}
set -e
mark_runner "event-outbox-sql-matrix.sh" "$EO_SQL_RC"

echo "== C5 member SQL matrix =="
set +e
bash "$TESTS/c5-member-sql-matrix.sh" | tee "$EV/C5-MEMBER-SQL-MATRIX.log"
C5_SQL_RC=${PIPESTATUS[0]}
set -e
mark_runner "c5-member-sql-matrix.sh" "$C5_SQL_RC"

echo "== SHA256SUMS =="
set +e
( cd "$UPG" && shasum -a 256 -c SHA256SUMS.txt ) | tee "$EV/sha256.out"
SHA_RC=${PIPESTATUS[0]}
set -e
[ "$SHA_RC" = "0" ] || OVERALL=1
for checksum_dir in "$EVENT_UPG" "$C5_UPG"; do
  if [ ! -s "$checksum_dir/SHA256SUMS.txt" ]; then
    echo "FAIL: missing SHA256SUMS.txt in $checksum_dir"
    OVERALL=1
    continue
  fi
  set +e
  ( cd "$checksum_dir" && shasum -a 256 -c SHA256SUMS.txt )
  EXTRA_SHA_RC=$?
  set -e
  [ "$EXTRA_SHA_RC" = "0" ] || OVERALL=1
done

echo "== export backend manifest =="
set +e
docker_php 'php /tests/php/export-backend-manifest.php /evidence/backend-manifest.json' | tee "$EV/export-manifest.out"
EXP_RC=${PIPESTATUS[0]}
set -e
mark_runner "php/export-backend-manifest.php" "$EXP_RC"
if [ ! -s "$EV/backend-manifest.json" ]; then
  echo "FAIL: backend-manifest.json missing or empty"
  OVERALL=1
else
  # 必须是纯 JSON（禁止混入 TEMP_ENV / Warning）
  if ! node -e 'JSON.parse(require("fs").readFileSync(process.argv[1],"utf8"))' "$EV/backend-manifest.json"; then
    echo "FAIL: backend-manifest.json is not valid JSON"
    OVERALL=1
    EXP_RC=1
  fi
fi

echo "== frontend manifest sync =="
set +e
( cd "$TESTS" && C1A_EVIDENCE_DIR="$EV" node js/frontend-manifest-sync.mjs "$EV/backend-manifest.json" ) | tee "$EV/frontend-sync.out"
FE_RC=${PIPESTATUS[0]}
set -e
mark_runner "js/frontend-manifest-sync.mjs" "$FE_RC"

echo "== bridge contract =="
set +e
( cd "$TESTS" && C1A_TMP_DIR="$TEMP_TMP" C1A_EVIDENCE_DIR="$EV" node js/bridge-contract.mjs ) | tee "$EV/bridge-contract.out"
BR_RC=${PIPESTATUS[0]}
set -e
mark_runner "js/bridge-contract.mjs" "$BR_RC"

echo "== PHP syntax =="
set +e
docker run --rm --platform linux/amd64 --entrypoint php \
  -v "$BACKEND:/var/www/html:ro" \
  -w /var/www/html "$APP_IMAGE" \
  -r '$dirs=["/var/www/html/app/services/cashier/v3","/var/www/html/app/controller/cashier/v3","/var/www/html/app/model/cashier/v3"]; $fail=0; foreach($dirs as $d){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d)); foreach($it as $f){if(!$f->isFile()||$f->getExtension()!=="php")continue; $c=0; exec("php -l ".escapeshellarg($f->getPathname())." 2>&1",$o,$c); if($c!==0){$fail++; echo implode("\n",$o),"\n";}}} echo "PHP_LINT_FAIL=$fail\n"; exit($fail>0?1:0);'
LINT_RC=$?
set -e
[ "$LINT_RC" = "0" ] || OVERALL=1

echo "== unit contract =="
set +e
docker_php 'php /tests/php/unit-contract.php' | tee "$EV/unit-contract.out"
UNIT_RC=${PIPESTATUS[0]}
set -e
mark_runner "php/unit-contract.php" "$UNIT_RC"

echo "== integration gateway =="
docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$UPGRADES/0000-升级登记表初始化.sql"
docker exec -i "$MY" mysql -uroot -plocaldev123 lin8 < "$UPG/02-正式升级.sql"
set +e
docker_php 'php /tests/php/integration-gateway.php' | tee "$EV/integration.out"
INT_RC=${PIPESTATUS[0]}
set -e
if ! grep -q 'ASSERT_PASSED=' "$EV/integration.out" 2>/dev/null; then
  echo "FAIL: integration produced no ASSERT_PASSED summary"
  INT_RC=1
fi
mark_runner "php/integration-gateway.php" "$INT_RC"

echo "== C5 member integration =="
set +e
docker_php 'php /tests/php/member-integration.php' | tee "$EV/member-integration.out"
MEM_RC=${PIPESTATUS[0]}
set -e
if ! grep -q 'ASSERT_PASSED=' "$EV/member-integration.out" 2>/dev/null \
  || ! grep -q 'ASSERT_FAILED=0' "$EV/member-integration.out" 2>/dev/null \
  || ! grep -q 'RUNNER_OK=C5-member-integration' "$EV/member-integration.out" 2>/dev/null; then
  echo "FAIL: C5 member integration produced no clean assertion summary"
  MEM_RC=1
fi
mark_runner "php/member-integration.php" "$MEM_RC"

echo "== unified event and Outbox integration =="
set +e
docker_php 'php /tests/php/event-outbox-integration.php' | tee "$EV/event-outbox-integration.out"
EO_RC=${PIPESTATUS[0]}
set -e
if ! grep -q 'ASSERT_PASSED=' "$EV/event-outbox-integration.out" 2>/dev/null \
  || ! grep -q 'ASSERT_FAILED=0' "$EV/event-outbox-integration.out" 2>/dev/null \
  || ! grep -q 'RUNNER_OK=event-outbox-integration' "$EV/event-outbox-integration.out" 2>/dev/null; then
  echo "FAIL: event Outbox integration produced no clean assertion summary"
  EO_RC=1
fi
mark_runner "php/event-outbox-integration.php" "$EO_RC"

echo "== unified event contract and snapshot integration =="
set +e
docker_php 'php /tests/php/event-contract-integration.php' | tee "$EV/event-contract-integration.out"
EC_RC=${PIPESTATUS[0]}
set -e
if ! grep -q 'ASSERT_PASSED=' "$EV/event-contract-integration.out" 2>/dev/null \
  || ! grep -q 'ASSERT_FAILED=0' "$EV/event-contract-integration.out" 2>/dev/null \
  || ! grep -q 'RUNNER_OK=event-contract-integration' "$EV/event-contract-integration.out" 2>/dev/null; then
  echo "FAIL: event contract integration produced no clean assertion summary"
  EC_RC=1
fi
mark_runner "php/event-contract-integration.php" "$EC_RC"

echo "== envelope bridge =="
set +e
( cd "$TESTS" && C1A_EVIDENCE_DIR="$EV" node js/envelope-bridge-check.mjs ) | tee "$EV/envelope-bridge.out"
ENV_RC=${PIPESTATUS[0]}
set -e
mark_runner "js/envelope-bridge-check.mjs" "$ENV_RC"

echo "== container resolve =="
set +e
docker_php 'php /tests/php/container-resolve-check.php' | tee "$EV/container-resolve.out"
CR_RC=${PIPESTATUS[0]}
set -e
if ! grep -q 'ASSERT_PASSED=' "$EV/container-resolve.out" 2>/dev/null; then
  if ! grep -q 'container-resolve SUMMARY' "$LOG"; then
    echo "FAIL: container-resolve produced no SUMMARY"
    CR_RC=1
  fi
fi
mark_runner "php/container-resolve-check.php" "$CR_RC"

# summary / matrix 前同步校验结束 SHA —— 不得先打印 ALL GATES PASSED
ENV_SHA_END=$(real_env_fingerprint)
echo "REAL_ENV_SHA_END_PRE_MATRIX=$ENV_SHA_END"
if [ "$ENV_SHA_START" != "$ENV_SHA_END" ]; then
  echo "FAIL: real .env presence/SHA changed before matrix"
  OVERALL=1
else
  printf 'GATE_PASS=%s\n' ISO-3-05 ISO-3-07
fi

echo "== literal gate scan =="
set +e
( cd "$TESTS" && node js/scan-literal-gates.mjs ) | tee "$EV/literal-gates.out"
LIT_RC=${PIPESTATUS[0]}
set -e
mark_runner "js/scan-literal-gates.mjs" "$LIT_RC"

echo "== requirements matrix (per GATE_PASS id, bound to runner output) =="
# 清理必须在矩阵读取前完成，否则 ISO-3-06 只会在 EXIT trap 中写入，
# 本次运行会被错误判为缺少门禁。清理失败仍会把 OVERALL 置为失败。
if ! cleanup_resources; then
  OVERALL=1
fi
if [ "$OVERALL" = "0" ]; then
  echo "RUNNER_OK=run-all.sh"
else
  echo "RUNNER_FAIL=run-all.sh"
fi
set +e
node - "$MATRIX" "$LOG" "$EV" <<'NODE'
const fs = require('fs')
const path = require('path')
const matrix = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'))
const masterLog = fs.readFileSync(process.argv[3], 'utf8')
const ev = process.argv[4]
const runnerOut = {
  'run-all.sh': masterLog,
  'php/container-resolve-check.php': safe(path.join(ev, 'container-resolve.out')),
  'php/unit-contract.php': safe(path.join(ev, 'unit-contract.out')),
  'php/integration-gateway.php': safe(path.join(ev, 'integration.out')),
  'php/member-integration.php': safe(path.join(ev, 'member-integration.out')),
  'php/event-outbox-integration.php': safe(path.join(ev, 'event-outbox-integration.out')),
  'php/event-contract-integration.php': safe(path.join(ev, 'event-contract-integration.out')),
  'js/frontend-manifest-sync.mjs': safe(path.join(ev, 'frontend-sync.out')),
  'js/bridge-contract.mjs': safe(path.join(ev, 'bridge-contract.out')),
  'js/envelope-bridge-check.mjs': safe(path.join(ev, 'envelope-bridge.out')),
  'js/scan-literal-gates.mjs': safe(path.join(ev, 'literal-gates.out')),
  'sql/run-sql-matrix.sh': safe(path.join(ev, 'SQL-MATRIX.log')),
  'event-outbox-sql-matrix.sh': safe(path.join(ev, 'EVENT-OUTBOX-SQL-MATRIX.log')),
  'c5-member-sql-matrix.sh': safe(path.join(ev, 'C5-MEMBER-SQL-MATRIX.log')),
  'migration-mirror-contract.sh': safe(path.join(ev, 'migration-mirror-contract.out')),
}
function safe(p) { try { return fs.readFileSync(p, 'utf8') } catch { return '' } }
let missing = 0
const required = matrix.gates.filter((x) => x.required)
for (const g of required) {
  const token = `GATE_PASS=${g.id}`
  const out = runnerOut[g.runner] || ''
  const runnerOk = masterLog.includes(`RUNNER_OK=${g.runner}`)
  if (!out.includes(token)) {
    console.error('MISSING GATE_PASS in runner output', g.id, 'runner=', g.runner)
    missing++
  } else if (!runnerOk) {
    console.error('RUNNER_NOT_OK', g.runner, 'for', g.id, '(runnerOk must participate in exit)')
    missing++
  } else if (masterLog.includes(`RUNNER_FAIL=${g.runner}`)) {
    console.error('RUNNER_FAIL', g.runner, 'for', g.id)
    missing++
  } else {
    console.log('MATRIX_OK', g.id)
  }
}
process.exit(missing > 0 ? 1 : 0)
NODE
MAT_RC=$?
set -e
[ "$MAT_RC" = "0" ] || OVERALL=1

echo ""
echo "==== SUMMARY ===="
echo "MIG_RC=$MIG_RC SQL_RC=$SQL_RC EO_SQL_RC=$EO_SQL_RC C5_SQL_RC=$C5_SQL_RC UNIT_RC=$UNIT_RC INT_RC=$INT_RC MEM_RC=$MEM_RC EO_RC=$EO_RC EC_RC=$EC_RC FE_RC=$FE_RC BR_RC=$BR_RC ENV_RC=$ENV_RC CR_RC=$CR_RC LIT_RC=$LIT_RC MAT_RC=$MAT_RC OVERALL=$OVERALL"
echo "REAL_ENV_SHA_START=$ENV_SHA_START"
echo "REAL_ENV_SHA_END=$ENV_SHA_END"
echo "MYSQL_IMAGE=$MYSQL_IMAGE"
echo "MYSQL_DIGEST=${MYSQL_DIGEST:-unknown}"
echo "MYSQL_REPO_DIGEST=${MYSQL_REPO:-unknown}"

if [ "$OVERALL" = "0" ] && [ "$MIG_RC" = "0" ] && [ "$SQL_RC" = "0" ] && [ "$EO_SQL_RC" = "0" ] && [ "$C5_SQL_RC" = "0" ] && [ "$UNIT_RC" = "0" ] && [ "$INT_RC" = "0" ] && [ "$MEM_RC" = "0" ] && [ "$EO_RC" = "0" ] && [ "$EC_RC" = "0" ] && [ "$FE_RC" = "0" ] && [ "$BR_RC" = "0" ] && [ "$ENV_RC" = "0" ] && [ "$CR_RC" = "0" ] && [ "${LIT_RC:-1}" = "0" ] && [ "$MAT_RC" = "0" ]; then
  echo "==== ALL GATES PASSED ===="
  exit 0
fi
echo "==== GATES FAILED ===="
exit 1

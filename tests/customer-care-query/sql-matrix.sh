#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
C1_MIGRATION="$ROOT_DIR/后端代码/database/upgrades/2026-07-27-收银V3命令与幂等底座/02-正式升级.sql"
CARE_MIGRATION="$ROOT_DIR/后端代码/database/upgrades/2026-07-29-客情任务与记录内核/02-正式升级.sql"
DOCUMENT_MIGRATION="$ROOT_DIR/后端代码/database/upgrades/2026-08-03-客情正式单号与下钻/02-正式升级.sql"
MYSQL_IMAGE="${CUSTOMER_CARE_QUERY_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
PHP_IMAGE="${CUSTOMER_CARE_QUERY_PHP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"
ALPINE_IMAGE="${CUSTOMER_CARE_QUERY_ALPINE_IMAGE:-docker.m.daocloud.io/library/alpine:3.18}"
NETWORK="care-query-net-$$"
MYSQL_CONTAINER="care-query-mysql56-$$"
PHP_CONTAINER="care-query-php74-$$"
HTML_VOLUME="care-query-html-$$"
TMP_ROOT="$ROOT_DIR/tests/customer-care-query/_tmp"
mkdir -p "$TMP_ROOT"
WORK_DIR="$(mktemp -d "$TMP_ROOT/care-query-mysql56.XXXXXX")"
TEMP_ENV="$WORK_DIR/care-query.env"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$MYSQL_CONTAINER" >&2 || true
    [[ -f "$WORK_DIR/integration.out" ]] && sed -n '1,260p' "$WORK_DIR/integration.out" >&2 || true
  fi
  docker rm -fv "$PHP_CONTAINER" "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  docker volume rm -f "$HTML_VOLUME" >/dev/null 2>&1 || true
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

printf '%s\n' \
  'APP_DEBUG = true' \
  "HOSTNAME = $MYSQL_CONTAINER" \
  'DATABASE = care_query' \
  'USERNAME = root' \
  'PASSWORD = ' \
  'HOSTPORT = 3306' \
  'DRIVER = file' \
  'CACHE_DRIVER = file' > "$TEMP_ENV"

docker network create "$NETWORK" >/dev/null
docker run -d --name "$MYSQL_CONTAINER" --network "$NETWORK" --platform linux/amd64 \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  "$MYSQL_IMAGE" \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_general_ci \
  --innodb-large-prefix=0 \
  --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  docker logs "$MYSQL_CONTAINER" 2>&1 | grep 'MySQL init process done' >/dev/null && break
  sleep 1
done
docker logs "$MYSQL_CONTAINER" 2>&1 | grep 'MySQL init process done' >/dev/null
for _ in $(seq 1 90); do
  docker exec "$MYSQL_CONTAINER" mysqladmin ping -uroot --silent >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$MYSQL_CONTAINER" mysqladmin ping -uroot --silent >/dev/null

MYSQL_VERSION="$(docker exec "$MYSQL_CONTAINER" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$MYSQL_VERSION" == '5.6.51' ]]
echo "CUSTOMER_CARE_QUERY_MYSQL_VERSION=$MYSQL_VERSION"

docker exec "$MYSQL_CONTAINER" mysql -uroot -e \
  'CREATE DATABASE care_query CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;'
docker exec -i "$MYSQL_CONTAINER" mysql -uroot care_query < "$C1_MIGRATION" >/dev/null
docker exec -i "$MYSQL_CONTAINER" mysql -uroot care_query < "$CARE_MIGRATION" >/dev/null
docker exec -i "$MYSQL_CONTAINER" mysql -uroot care_query < "$DOCUMENT_MIGRATION" >/dev/null

# Build a disposable backend snapshot so the host .env never enters the PHP container and
# Docker does not need to overlay a file mount inside another read-only bind mount.
docker volume create "$HTML_VOLUME" >/dev/null
docker run --rm \
  -v "$ROOT_DIR/后端代码:/src:ro" \
  -v "$WORK_DIR:/env:ro" \
  -v "$HTML_VOLUME:/dst" \
  "$ALPINE_IMAGE" sh -lc '
    set -e
    tar -C /src --exclude="./.env" --exclude="./.env.*" -cf - . | tar -C /dst -xf -
    mkdir -p /dst/runtime /dst/public/uploads
    cp /env/care-query.env /dst/.env
    test -s /dst/.env
  '

docker run --rm --name "$PHP_CONTAINER" --network "$NETWORK" \
  -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE=care_query \
  -e DB_USERNAME=root -e DB_PASSWORD= \
  -e HOSTNAME="$MYSQL_CONTAINER" -e HOSTPORT=3306 -e DATABASE=care_query \
  -e USERNAME=root -e PASSWORD= -e CACHE_DRIVER=file -e DRIVER=file \
  -v "$HTML_VOLUME:/var/www/html:ro" \
  -v "$ROOT_DIR/tests:/tests:ro" \
  --tmpfs /var/www/html/runtime:rw,size=64m \
  --entrypoint php \
  "$PHP_IMAGE" /tests/customer-care-query/php/mysql56-integration.php \
  | tee "$WORK_DIR/integration.out"

echo 'CUSTOMER_CARE_QUERY_MYSQL56_MATRIX=PASS'

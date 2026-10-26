#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
MYSQL_IMAGE="${HANG_ROOM_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
PHP_DOCKERFILE="$ROOT_DIR/tests/cashier-v3/docker/php74-runtime.Dockerfile"
PHP_PLATFORM="${HANG_ROOM_PHP_PLATFORM:-}"
PHP_IMAGE="${HANG_ROOM_PHP_IMAGE:-}"
NETWORK="hang-room-net-$$"
MYSQL_CONTAINER="hang-room-mysql56-$$"
PHP_CONTAINER="hang-room-php74-$$"
WORK_DIR="$(mktemp -d "$(dirname "$ROOT_DIR")/hang-room.XXXXXX")"
TEMP_ENV="$WORK_DIR/hang-room.env"
DB_NAME="hang_room_atomicity"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 ]]; then
    docker logs "$MYSQL_CONTAINER" >&2 || true
  fi
  docker rm -fv "$PHP_CONTAINER" "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

if [[ -z "$PHP_PLATFORM" ]]; then
  case "$(docker version --format '{{.Server.Arch}}')" in
    amd64|x86_64) PHP_PLATFORM='linux/amd64' ;;
    arm64|aarch64) PHP_PLATFORM='linux/arm64' ;;
    *) echo 'HANG_ROOM_UNSUPPORTED_DOCKER_ARCH' >&2; exit 1 ;;
  esac
fi
if [[ -z "$PHP_IMAGE" ]]; then
  PHP_IMAGE_SHA="$(shasum -a 256 "$PHP_DOCKERFILE" | awk '{print substr($1,1,12)}')"
  PHP_IMAGE="c1a-cashier-v3-php74:${PHP_IMAGE_SHA}-${PHP_PLATFORM#linux/}"
  if ! docker image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
    docker build --platform "$PHP_PLATFORM" -f "$PHP_DOCKERFILE" \
      -t "$PHP_IMAGE" "$ROOT_DIR/tests/cashier-v3/docker"
  fi
fi

printf '%s\n' \
  'APP_DEBUG = true' \
  "HOSTNAME = $MYSQL_CONTAINER" \
  "DATABASE = $DB_NAME" \
  'USERNAME = root' \
  'PASSWORD = ' \
  'HOSTPORT = 3306' \
  'DRIVER = file' \
  'CACHE_DRIVER = file' > "$TEMP_ENV"

docker network create "$NETWORK" >/dev/null
docker run -d --name "$MYSQL_CONTAINER" --network "$NETWORK" --platform linux/amd64 \
  --cpus 1 --memory 1g -e MYSQL_ALLOW_EMPTY_PASSWORD=yes "$MYSQL_IMAGE" \
  --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci \
  --sql-mode=STRICT_ALL_TABLES --innodb-large-prefix=0 --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  docker exec "$MYSQL_CONTAINER" mysqladmin ping -uroot --silent >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$MYSQL_CONTAINER" mysqladmin ping -uroot --silent >/dev/null
MYSQL_VERSION="$(docker exec "$MYSQL_CONTAINER" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$MYSQL_VERSION" == '5.6.51' ]]
echo "MYSQL_VERSION=$MYSQL_VERSION"

docker exec "$MYSQL_CONTAINER" mysql -uroot -e \
  "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
for sql in \
  "$ROOT_DIR/tests/cashier-v3/sql/hang-order-room-atomicity-fixture.sql" \
  "$ROOT_DIR/后端代码/database/upgrades/2026-07-28-收银V3权益购物车草稿/02-正式升级.sql" \
  "$ROOT_DIR/后端代码/database/upgrades/2026-07-29-房间开放服务占用锁/02-正式升级.sql" \
  "$ROOT_DIR/后端代码/database/upgrades/2026-07-29-收银V3正式挂单权威/02-正式升级.sql" \
  "$ROOT_DIR/后端代码/database/upgrades/2026-07-30-收银V3普通商品挂单恢复结账/02-正式升级.sql"; do
  docker exec -i "$MYSQL_CONTAINER" mysql -uroot --database="$DB_NAME" < "$sql"
done

docker run --rm --name "$PHP_CONTAINER" --network "$NETWORK" --platform "$PHP_PLATFORM" \
  --cpus 1 --memory 384m \
  -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE="$DB_NAME" \
  -e DB_USERNAME=root -e DB_PASSWORD= -e CACHE_DRIVER=file -e DRIVER=file \
  -v "$ROOT_DIR/后端代码:/source:ro" -v "$ROOT_DIR/tests:/tests:ro" \
  -v "$WORK_DIR:/test-env:ro" --tmpfs /var/www/html:rw,size=384m \
  --entrypoint sh "$PHP_IMAGE" -lc '
    set -e
    tar -C /source --exclude="./.env" --exclude="./.env.*" \
      --exclude="./runtime" --exclude="./public" -cf - . | tar -C /var/www/html -xf -
    mkdir -p /var/www/html/runtime /var/www/html/public/uploads
    cp /test-env/hang-room.env /var/www/html/.env
    php -l /tests/cashier-v3/php/hang-order-room-atomicity-integration.php
    OUT="$(php /tests/cashier-v3/php/hang-order-room-atomicity-integration.php 2>&1)"
    printf "%s\n" "$OUT"
    printf "%s\n" "$OUT" | grep -Eq "HANG_ROOM_ATOMICITY_MYSQL passed=[1-9][0-9]* failed=0"
  '

echo 'HANG_ROOM_ATOMICITY_MYSQL56=PASS'

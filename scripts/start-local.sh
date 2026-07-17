#!/bin/bash
# 本地启动「美容源码」后端（Colima + Docker + Nginx + Swoole）
set -euo pipefail

export PATH="$HOME/bin:$PATH"
docker context use colima >/dev/null

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PROJECT="$ROOT/后端代码"
SQL="$ROOT/database/008.cc3798.com.sql"
NGINX_CONF="$ROOT/docker/nginx/local.conf"

if ! command -v docker >/dev/null; then
  echo "请先安装 Colima/Docker，并确保 ~/bin 在 PATH 中"
  exit 1
fi

if [ ! -d "$PROJECT" ]; then
  echo "找不到后端目录：$PROJECT"
  exit 1
fi

if [ ! -f "$PROJECT/.env.docker" ]; then
  echo "缺少本地环境文件：$PROJECT/.env.docker"
  exit 1
fi

# 平台/门店 webpack 热编译峰值高；6GiB 易 OOM 杀 mohe-admin-src（exit 137）
colima status >/dev/null 2>&1 || colima start --cpu 4 --memory 10 --disk 60

docker network create mohe-net >/dev/null 2>&1 || true

# 已有同名容器（含已停止）则直接 start，避免 name conflict
ensure_container() {
  local name="$1"
  if docker ps --format '{{.Names}}' | grep -qx "$name"; then
    return 0
  fi
  if docker ps -a --format '{{.Names}}' | grep -qx "$name"; then
    docker start "$name" >/dev/null
    return 0
  fi
  return 1
}

if ! ensure_container mohe-mysql; then
  docker run -d --name mohe-mysql --platform linux/amd64 --network mohe-net --network-alias mysql \
    -e MYSQL_ROOT_PASSWORD=localdev123 -e MYSQL_DATABASE=lin8 \
    -p 3307:3306 \
    docker.m.daocloud.io/library/mysql:5.7 \
    --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci \
    --max_allowed_packet=512M --sql_mode=NO_ENGINE_SUBSTITUTION
fi

if ! ensure_container mohe-redis; then
  docker run -d --name mohe-redis --platform linux/amd64 --network mohe-net --network-alias redis \
    -p 6380:6379 docker.m.daocloud.io/library/redis:7-alpine
fi

echo "等待 MySQL..."
for i in $(seq 1 60); do
  docker exec mohe-mysql mysqladmin ping -h127.0.0.1 -uroot -plocaldev123 --silent 2>/dev/null && break
  sleep 2
done

TABLE_COUNT=$(docker exec mohe-mysql mysql -uroot -plocaldev123 -Nse \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='lin8';" 2>/dev/null || echo 0)

if [ "${TABLE_COUNT:-0}" -lt 10 ]; then
  if [ -f "$SQL" ]; then
    echo "导入数据库（首次较慢）..."
    docker exec -i mohe-mysql mysql -uroot -plocaldev123 lin8 < "$SQL"
  else
    echo "警告：库表不足且未找到 SQL：$SQL"
    echo "本地库可能为空。如需导入，请把备份 SQL 放到上述路径后重跑本脚本。"
  fi
fi

docker rm -f mohe-app mohe-nginx >/dev/null 2>&1 || true

# 使用预构建镜像 mohe-app:local（含 gd，登录验证码需要）；没有则先构建
if ! docker image inspect mohe-app:local >/dev/null 2>&1; then
  echo "首次构建本地应用镜像（含 GD，约数分钟）..."
  docker build -t mohe-app:local -f "$ROOT/docker/Dockerfile" "$ROOT"
fi

# Apple Silicon 上本地构建的 mohe-app:local 为 arm64；强制 amd64 会误去 Docker Hub 拉不存在的 tag
APP_PLATFORM=""
if [ "$(uname -m)" = "x86_64" ]; then
  APP_PLATFORM="--platform linux/amd64"
fi
# shellcheck disable=SC2086
docker run -d --name mohe-app $APP_PLATFORM --network mohe-net \
  -p 20800:20800 \
  -v "$PROJECT:/var/www/html" \
  -w /var/www/html \
  mohe-app:local

sleep 6

docker run -d --name mohe-nginx --platform linux/amd64 --network mohe-net \
  -p 8080:80 \
  -v "$PROJECT:/var/www/html:ro" \
  -v "$NGINX_CONF:/etc/nginx/conf.d/default.conf:ro" \
  docker.m.daocloud.io/library/nginx:1.25-alpine

# 平台前端开发预览（18081，热更新；仅首次缺依赖时 install，避免每次启动重装）
ADMIN_SRC="$ROOT/前端代码/admin"
ADMIN_NM_VOLUME="mohe_admin_src_nm"
ADMIN_DEV_CMD='if [ ! -x ./node_modules/.bin/vue-cli-service ]; then npm config set registry https://registry.npmmirror.com && npm install --no-audit --no-fund; fi && ./node_modules/.bin/vue-cli-service serve --mode=dev --host 0.0.0.0 --port 8081'

ensure_admin_dev() {
  if docker ps -a --format '{{.Names}}' | grep -qx mohe-admin-src; then
    local cmd
    cmd="$(docker inspect mohe-admin-src --format '{{join .Config.Cmd " "}}' 2>/dev/null || true)"
    if echo "$cmd" | grep -q 'npm install --no-audit --no-fund --progress=false'; then
      echo "移除旧版 mohe-admin-src（启动时会重复 npm install）..."
      docker rm -f mohe-admin-src >/dev/null 2>&1 || true
    elif docker ps --format '{{.Names}}' | grep -qx mohe-admin-src; then
      return 0
    else
      docker start mohe-admin-src >/dev/null
      return 0
    fi
  fi

  docker volume create "$ADMIN_NM_VOLUME" >/dev/null 2>&1 || true
  # 直连 Docker Hub 在国内易 EOF；优先 DaoCloud 镜像并打本地 tag
  if ! docker image inspect node:14-bullseye >/dev/null 2>&1; then
    docker pull --platform linux/amd64 docker.m.daocloud.io/library/node:14-bullseye
    docker tag docker.m.daocloud.io/library/node:14-bullseye node:14-bullseye
  fi
  docker run -d --name mohe-admin-src --platform linux/amd64 \
    -p 18081:8081 \
    -v "$ADMIN_SRC:/app" \
    -v "$ADMIN_NM_VOLUME:/app/node_modules" \
    -w /app \
    node:14-bullseye \
    bash -lc "$ADMIN_DEV_CMD"
}

ensure_admin_dev

# 门店前端开发预览（18082，热更新）
STORE_SRC="$ROOT/前端代码/store"
STORE_NM_VOLUME="mohe_store_src_nm"
STORE_DEV_CMD='if [ ! -x ./node_modules/.bin/vue-cli-service ]; then npm config set registry https://registry.npmmirror.com && npm install --no-audit --no-fund; fi && ./node_modules/.bin/vue-cli-service serve --mode=dev --host 0.0.0.0 --port 8082'

ensure_store_dev() {
  if docker ps -a --format '{{.Names}}' | grep -qx mohe-store-src; then
    if docker ps --format '{{.Names}}' | grep -qx mohe-store-src; then
      return 0
    else
      docker start mohe-store-src >/dev/null
      return 0
    fi
  fi

  docker volume create "$STORE_NM_VOLUME" >/dev/null 2>&1 || true
  if ! docker image inspect node:14-bullseye >/dev/null 2>&1; then
    docker pull --platform linux/amd64 docker.m.daocloud.io/library/node:14-bullseye
    docker tag docker.m.daocloud.io/library/node:14-bullseye node:14-bullseye
  fi
  docker run -d --name mohe-store-src --platform linux/amd64 \
    -p 18082:8082 \
    -e VUE_APP_API_URL='http://127.0.0.1:8080/storeapi' \
    -v "$STORE_SRC:/app" \
    -v "$STORE_NM_VOLUME:/app/node_modules" \
    -w /app \
    node:14-bullseye \
    bash -lc "$STORE_DEV_CMD"
}

ensure_store_dev

# 收银台前端开发预览（18083，热更新）
CASHIER_SRC="$ROOT/前端代码/cashier"
CASHIER_NM_VOLUME="mohe_cashier_src_nm"
CASHIER_DEV_CMD='if [ ! -x ./node_modules/.bin/vue-cli-service ]; then npm config set registry https://registry.npmjs.org && npm cache clean --force && npm install --no-audit --no-fund; fi && ./node_modules/.bin/vue-cli-service serve --mode=dev --host 0.0.0.0 --port 8083'

ensure_cashier_dev() {
  if docker ps -a --format '{{.Names}}' | grep -qx mohe-cashier-src; then
    if docker ps --format '{{.Names}}' | grep -qx mohe-cashier-src; then
      return 0
    else
      docker start mohe-cashier-src >/dev/null
      return 0
    fi
  fi

  docker volume create "$CASHIER_NM_VOLUME" >/dev/null 2>&1 || true
  if ! docker image inspect node:14-bullseye >/dev/null 2>&1; then
    docker pull --platform linux/amd64 docker.m.daocloud.io/library/node:14-bullseye
    docker tag docker.m.daocloud.io/library/node:14-bullseye node:14-bullseye
  fi
  docker run -d --name mohe-cashier-src --platform linux/amd64 \
    -p 18083:8083 \
    -e VUE_APP_API_URL='http://127.0.0.1:8080/cashierapi' \
    -v "$CASHIER_SRC:/app" \
    -v "$CASHIER_NM_VOLUME:/app/node_modules" \
    -w /app \
    node:14-bullseye \
    bash -lc "$CASHIER_DEV_CMD"
}

ensure_cashier_dev

echo
echo "已启动（Nginx + Swoole，挂载：美容源码/后端代码）："
echo "  平台开发预览: http://127.0.0.1:18081/admin/login  （改代码用，首次编译约 5-10 分钟）"
echo "  门店开发预览: http://127.0.0.1:18082/            （热更新）"
echo "  收银台开发预览: http://127.0.0.1:18083/          （热更新，接口走本地 8080/cashierapi）"
echo "  平台集成预览: http://127.0.0.1:8080/admin/login    （build 产物，上线验证用）"
echo "  前台 H5:     http://127.0.0.1:8080/"
echo "  收银台(构建): http://127.0.0.1:8080/cashier.html"
echo "  手机同网访问: http://$(ipconfig getifaddr en0 2>/dev/null || echo '你的Mac局域网IP'):8080"
echo "  开发日志:    docker logs -f mohe-admin-src / mohe-cashier-src"
echo "  后端日志:    docker logs -f mohe-app"

#!/bin/bash
# 本地启动「美容源码」后端（Colima + Docker + Nginx + Swoole）
set -euo pipefail

export PATH="$HOME/bin:$PATH"
docker context use colima >/dev/null

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PROJECT="$ROOT/后端代码"
SQL="$ROOT/database/008.cc3798.com.sql"
NGINX_CONF="$ROOT/docker/nginx/local.conf"
PLATFORM_NGINX_CONF="$ROOT/docker/nginx/local-platform.conf"
CASHIER_NGINX_CONF="$ROOT/docker/nginx/local-cashier.conf"
PLATFORM_API_PORT="${PLATFORM_API_PORT:-18093}"
CASHIER_API_PORT="${CASHIER_API_PORT:-18092}"

# 18081 / 18091 只能从“美容源码”主工作目录启动。链接 worktree 的
# .git 是文件而不是目录；在这里直接拒绝，避免调试端口再次挂到临时
# 分支或已废弃工作树。平台与门店前端各用独立目录，后端统一复用一套
# PHP 源码正本。
if [ ! -d "$ROOT/.git" ]; then
  echo "拒绝启动：当前不是美容源码主工作目录（可能是 Git worktree）：$ROOT"
  exit 1
fi
SOURCE_TOPLEVEL="$(git -C "$ROOT" rev-parse --show-toplevel 2>/dev/null || true)"
if [ "$SOURCE_TOPLEVEL" != "$ROOT" ]; then
  echo "拒绝启动：Git 源码根目录不一致，期望 $ROOT，实际 $SOURCE_TOPLEVEL"
  exit 1
fi
SOURCE_BRANCH="$(git -C "$ROOT" branch --show-current 2>/dev/null || true)"
SOURCE_COMMIT="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || true)"
echo "唯一源码：${ROOT}（branch=${SOURCE_BRANCH:-detached}, commit=${SOURCE_COMMIT:-unknown}）"
echo "平台端：${ROOT}/前端代码/admin -> 18081"
echo "门店端：${ROOT}/前端代码/cashier-v3 -> 18091"
echo "统一后端：${ROOT}/后端代码 -> 18093 / 18092"

# 本地数据库名以当前正本环境文件为准，禁止沿用旧的 lin8 固定值。
DB_NAME="$(awk -F= '/^[[:space:]]*DATABASE[[:space:]]*=/{gsub(/[[:space:]]/, "", $2); print $2; exit}' "$PROJECT/.env.docker")"
DB_NAME="${DB_NAME:-ruihao_rh_20260812}"

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
    -e MYSQL_ROOT_PASSWORD=localdev123 -e MYSQL_DATABASE="$DB_NAME" \
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
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" 2>/dev/null || echo 0)

if [ "${TABLE_COUNT:-0}" -lt 10 ]; then
  if [ -f "$SQL" ]; then
    echo "导入数据库（首次较慢）..."
    docker exec -i mohe-mysql mysql -uroot -plocaldev123 "$DB_NAME" < "$SQL"
  else
    echo "警告：库表不足且未找到 SQL：$SQL"
    echo "本地库可能为空。如需导入，请把备份 SQL 放到上述路径后重跑本脚本。"
  fi
fi

# 每次启动都清理三套后端/网关，避免 Swoole 保留旧路由；随后按顺序重建。
docker rm -f mohe-app mohe-nginx \
  mohe-platform-app mohe-platform-nginx \
  mohe-cashier-app mohe-cashier-nginx mohe-cashier-api \
  mohe-ai-execution-worker mohe-ai-export-worker mohe-ai-supervisor >/dev/null 2>&1 || true

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

# 收银 V3 专用 PHP/Swoole 进程。它与 8080 使用同一源码和本地数据库，
# 但不复用 mohe-app 进程，便于独立加载路由、重启和验收。
docker run -d --name mohe-cashier-app $APP_PLATFORM --network mohe-net \
  -v "$PROJECT:/var/www/html" \
  -w /var/www/html \
  mohe-app:local

sleep 6

docker run -d --name mohe-nginx --platform linux/amd64 --network mohe-net \
  -p 8080:80 \
  -v "$PROJECT:/var/www/html:ro" \
  -v "$NGINX_CONF:/etc/nginx/conf.d/default.conf:ro" \
  docker.m.daocloud.io/library/nginx:1.25-alpine

# 方案 2：平台端与收银/库存端各自使用独立的 PHP/Swoole + Nginx 实例。
# 三套实例仍挂载同一份源码正本、使用同一份本地数据库，仅隔离进程、路由缓存和端口：
#   8080  -> mohe-app / mohe-nginx（集成构建预览）
#   18093 -> mohe-platform-app / mohe-platform-nginx（平台热更新 API）
#   18092 -> mohe-cashier-app / mohe-cashier-api（收银/库存热更新 API）
# 每次启动都重建两个热更新 API 实例，避免沿用旧 Swoole 路由缓存。

docker run -d --name mohe-platform-app $APP_PLATFORM --network mohe-net \
  -v "$PROJECT:/var/www/html" \
  -w /var/www/html \
  mohe-app:local

# AI uses durable Run records, but a record can only become an answer when a
# dedicated consumer is actually resident. Keep the consumer and its recovery
# supervisor separate from each HTTP/Swoole instance: this mirrors production
# systemd services and avoids silently accepting work after a local restart.
# These containers share the one backend source and database; they do not
# create a second implementation or a terminal-specific capability path.
docker run -d --name mohe-ai-execution-worker $APP_PLATFORM --network mohe-net \
  --restart unless-stopped \
  -v "$PROJECT:/var/www/html" \
  -w /var/www/html \
  --entrypoint /bin/sh \
  mohe-app:local -lc 'exec php think mohe-ai:execution-worker --sleep=1'

# Excel runs after the answer on an instance-specific Redis queue. Resolve
# its name from this backend config and keep its consumer restartable; a
# missing consumer must not leave a misleading enabled export option.
AI_EXPORT_QUEUE=$(docker exec -w /var/www/html mohe-platform-app php -r 'require "vendor/autoload.php"; $app=new think\App(); $app->initialize(); echo app\jobs\query\AiUnifiedQueryExportJob::queueName();')
[[ "$AI_EXPORT_QUEUE" =~ ^MOHE_PRO_AI_EXPORT:[a-f0-9]{32}$ ]] || { echo '无法确定本地魔核 AI Excel 队列' >&2; exit 2; }
docker run -d --name mohe-ai-export-worker $APP_PLATFORM --network mohe-net \
  --restart unless-stopped \
  -v "$PROJECT:/var/www/html" \
  -w /var/www/html \
  --entrypoint /bin/sh \
  mohe-app:local -lc "exec php think queue:work --queue='$AI_EXPORT_QUEUE' --timeout=245 --memory=512 --sleep=1 --tries=1 -q"

docker run -d --name mohe-ai-supervisor $APP_PLATFORM --network mohe-net \
  --restart unless-stopped \
  -v "$PROJECT:/var/www/html" \
  -w /var/www/html \
  --entrypoint /bin/sh \
  mohe-app:local -lc 'exec php think mohe-ai:supervise --watch'

docker run -d --name mohe-platform-nginx --platform linux/amd64 --network mohe-net \
  -p "$PLATFORM_API_PORT:80" \
  -v "$PROJECT:/var/www/html:ro" \
  -v "$PLATFORM_NGINX_CONF:/etc/nginx/conf.d/default.conf:ro" \
  docker.m.daocloud.io/library/nginx:1.25-alpine

# 收银 V3 独立后端网关（18092）。18091 的 Vite 仅代理到此端口，不再
# 依赖 8080 的集成路由；库存 /storeapi 与收银 /cashierapi 走同一收银实例。
docker run -d --name mohe-cashier-nginx --platform linux/amd64 --network mohe-net \
  -p "$CASHIER_API_PORT:80" \
  -v "$PROJECT:/var/www/html:ro" \
  -v "$CASHIER_NGINX_CONF:/etc/nginx/conf.d/default.conf:ro" \
  docker.m.daocloud.io/library/nginx:1.25-alpine

# 平台前端开发预览（18081，热更新；仅首次缺依赖时 install，避免每次启动重装）
ADMIN_SRC="$ROOT/前端代码/admin"
ADMIN_NM_VOLUME="mohe_admin_src_nm"
ADMIN_DEV_CMD="if [ ! -x ./node_modules/.bin/vue-cli-service ]; then npm config set registry https://registry.npmmirror.com && npm install --no-audit --no-fund; fi && VUE_APP_API_URL='http://127.0.0.1:${PLATFORM_API_PORT}/adminapi' VUE_APP_INVENTORY_V3_DEV_ORIGIN='http://127.0.0.1:18086' VUE_APP_INVENTORY_V3_DEV_PROXY_TARGET='http://host.docker.internal:18086' VUE_APP_FUND_V3_DEV_PROXY_TARGET='http://host.docker.internal:18089' VUE_APP_CASHIER_V3_DEV_ORIGIN='http://127.0.0.1:18091' VUE_APP_ADMIN_DEV_PUBLIC='127.0.0.1:18081' VUE_APP_ADMIN_DEV_SOCKET_HOST='127.0.0.1' VUE_APP_ADMIN_DEV_SOCKET_PORT='18081' ./node_modules/.bin/vue-cli-service serve --mode=dev --host 0.0.0.0 --port 8081"

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
    --add-host host.docker.internal:host-gateway \
    -v "$ADMIN_SRC:/app" \
    -v "$ROOT/前端代码/shared:/shared:ro" \
    -v "$ADMIN_NM_VOLUME:/app/node_modules" \
    -w /app \
    node:14-bullseye \
    bash -lc "$ADMIN_DEV_CMD"
}

# API 目标属于容器启动环境变量；切换到 18093 后不能复用旧的 18081 容器。
docker rm -f mohe-admin-src >/dev/null 2>&1 || true
ensure_admin_dev

# 库存 Vue 3 独立热更新入口（18086）。平台 18081 只承载 Admin，
# 库存前端通过 iframe 指向本端口；API 仍由 18092 收银/库存后端提供。
INVENTORY_V3_SRC="$ROOT/前端代码/inventory-vue3"
INVENTORY_V3_NM_VOLUME="mohe_inventory_v3_src_nm"
INVENTORY_NODE_IMAGE="node:20-bullseye"
INVENTORY_V3_DEV_CMD='if [ ! -x ./node_modules/.bin/vite ]; then npm config set registry https://registry.npmjs.org && npm install --no-audit --no-fund; fi && VITE_LOCAL_BACKEND_ORIGIN="http://host.docker.internal:'"${CASHIER_API_PORT}"'" ./node_modules/.bin/vite --host 0.0.0.0 --port 18086 --strictPort'

docker rm -f mohe-inventory-v3-src >/dev/null 2>&1 || true
docker volume create "$INVENTORY_V3_NM_VOLUME" >/dev/null 2>&1 || true
if ! docker image inspect "$INVENTORY_NODE_IMAGE" >/dev/null 2>&1; then
  docker pull --platform linux/amd64 docker.m.daocloud.io/library/node:20-bullseye
  docker tag docker.m.daocloud.io/library/node:20-bullseye "$INVENTORY_NODE_IMAGE"
fi
docker run -d --name mohe-inventory-v3-src --platform linux/amd64 \
  -p 18086:18086 \
  --add-host host.docker.internal:host-gateway \
  -v "$INVENTORY_V3_SRC:/app" \
  -v "$ROOT/前端代码/shared:/shared:ro" \
  -v "$INVENTORY_V3_NM_VOLUME:/app/node_modules" \
  -w /app \
  "$INVENTORY_NODE_IMAGE" \
  bash -lc "$INVENTORY_V3_DEV_CMD"

# 费用 Vue 3 独立热更新入口（18089）。门店与平台均通过 iframe 复用该界面，
# 分别将 /storeapi 和 /adminapi 代理到各自独立网关。
FUND_V3_SRC="$ROOT/前端代码/fund-vue3"
FUND_V3_NM_VOLUME="mohe_fund_v3_src_nm"
FUND_V3_DEV_CMD='if [ ! -x ./node_modules/.bin/vite ]; then npm config set registry https://registry.npmjs.org && npm install --no-audit --no-fund; fi && FUND_V3_STORE_API_PROXY_TARGET="http://host.docker.internal:'"${CASHIER_API_PORT}"'" FUND_V3_PLATFORM_API_PROXY_TARGET="http://host.docker.internal:'"${PLATFORM_API_PORT}"'" ./node_modules/.bin/vite --host 0.0.0.0 --port 18089 --strictPort'

docker rm -f mohe-fund-v3-src >/dev/null 2>&1 || true
docker volume create "$FUND_V3_NM_VOLUME" >/dev/null 2>&1 || true
docker run -d --name mohe-fund-v3-src --platform linux/amd64 \
  -p 18089:18089 \
  --add-host host.docker.internal:host-gateway \
  -v "$FUND_V3_SRC:/app" \
  -v "$FUND_V3_NM_VOLUME:/app/node_modules" \
  -w /app \
  "$INVENTORY_NODE_IMAGE" \
  bash -lc "$FUND_V3_DEV_CMD"

echo "等待费用 Vue 3 热更新服务..."
fund_ready=0
for i in $(seq 1 60); do
  if docker exec mohe-fund-v3-src node -e "fetch('http://127.0.0.1:18089/').then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))" >/dev/null 2>&1; then
    fund_ready=1
    break
  fi
  sleep 1
done
if [ "$fund_ready" -ne 1 ]; then
  echo "费用 Vue 3 热更新服务未在 60 秒内就绪"
  docker logs --tail 40 mohe-fund-v3-src || true
  exit 1
fi

# 旧门店端 18082 已迁移到 美容源码/旧端口/18082/，仅作备份，不再启动热更新。

# 当前门店端唯一开发入口（18091，热更新）。源码正本为 cashier-v3。
CASHIER_V3_SRC="$ROOT/前端代码/cashier-v3"
CASHIER_V3_NM_VOLUME="mohe_cashier_v3_src_nm"
CASHIER_V3_DEV_CMD='if [ ! -x ./node_modules/.bin/vite ]; then npm config set registry https://registry.npmjs.org && npm install --no-audit --no-fund; fi && ./node_modules/.bin/vite --host 0.0.0.0 --port 18087 --strictPort'

ensure_cashier_v3_dev() {
  if docker ps -a --format '{{.Names}}' | grep -qx mohe-cashier-v3-src; then
    if docker ps --format '{{.Names}}' | grep -qx mohe-cashier-v3-src; then
      return 0
    else
      docker start mohe-cashier-v3-src >/dev/null
      return 0
    fi
  fi

  docker volume create "$CASHIER_V3_NM_VOLUME" >/dev/null 2>&1 || true
  if ! docker image inspect node:14-bullseye >/dev/null 2>&1; then
    docker pull --platform linux/amd64 docker.m.daocloud.io/library/node:14-bullseye
    docker tag docker.m.daocloud.io/library/node:14-bullseye node:14-bullseye
  fi
  docker run -d --name mohe-cashier-v3-src --platform linux/amd64 \
    -p 18091:18087 \
    --add-host host.docker.internal:host-gateway \
    -e CASHIER_V3_API_PROXY_TARGET="http://host.docker.internal:${CASHIER_API_PORT}" \
    -e PLATFORM_API_PROXY_TARGET="http://host.docker.internal:${PLATFORM_API_PORT}" \
    -e FUND_V3_DEV_PROXY_TARGET="http://host.docker.internal:18089" \
    -v "$CASHIER_V3_SRC:/app" \
    -v "$ROOT/前端代码/inventory-vue3:/inventory-vue3:ro" \
    -v "$ROOT/前端代码/shared:/shared:ro" \
    -v "$CASHIER_V3_NM_VOLUME:/app/node_modules" \
    -w /app \
    node:14-bullseye \
    bash -lc "$CASHIER_V3_DEV_CMD"
}

# API 目标属于容器启动环境变量；切换到 18092 后不能复用旧的 18091 容器。
docker rm -f mohe-cashier-v3-src >/dev/null 2>&1 || true
ensure_cashier_v3_dev

echo
echo "已启动（Nginx + Swoole，挂载：美容源码/后端代码）："
echo "  平台开发预览: http://127.0.0.1:18081/admin/login  （改代码用，首次编译约 5-10 分钟）"
echo "  库存前端开发预览: http://127.0.0.1:18086/view_inventory_v3/  （inventory-vue3 热更新）"
echo "  费用前端开发预览: http://127.0.0.1:18089/?source=store  （fund-vue3 热更新）"
echo "  当前门店端开发预览: http://127.0.0.1:18091/view_cashier_v3/#/cashier  （cashier-v3 热更新）"
echo "  收银 V3 专用后端: http://127.0.0.1:${CASHIER_API_PORT}  （独立 PHP/Swoole + Nginx）"
echo "  平台专用后端: http://127.0.0.1:${PLATFORM_API_PORT}  （独立 PHP/Swoole + Nginx）"
echo "  旧门店端 18082: 已停用（源码备份于 美容源码/旧端口/18082/）"
echo "  旧收银台 18083: 已停用（源码备份于 美容源码/旧端口/18083/）"
echo "  平台集成预览: http://127.0.0.1:8080/admin/login    （build 产物，上线验证用）"
echo "  前台 H5:     http://127.0.0.1:8080/"
echo "  收银台(构建): http://127.0.0.1:8080/cashier.html"
echo "  手机同网访问: http://$(ipconfig getifaddr en0 2>/dev/null || echo '你的Mac局域网IP'):8080"
echo "  开发日志:    docker logs -f mohe-admin-src / mohe-inventory-v3-src / mohe-cashier-v3-src"
echo "  后端日志:    docker logs -f mohe-app / mohe-platform-app / mohe-cashier-app"

#!/bin/bash
# 本地启动「美容代码」后端（Colima + Docker + Nginx + Swoole）
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

colima status >/dev/null 2>&1 || colima start --cpu 4 --memory 6 --disk 60

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

docker run -d --name mohe-app --platform linux/amd64 --network mohe-net \
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

echo
echo "已启动（Nginx + Swoole，挂载：美容代码/后端代码）："
echo "  前台 H5:     http://127.0.0.1:8080/"
echo "  后台登录:    http://127.0.0.1:8080/admin/login"
echo "  收银台:      http://127.0.0.1:8080/cashier.html"
echo "  手机同网访问: http://$(ipconfig getifaddr en0 2>/dev/null || echo '你的Mac局域网IP'):8080"
echo "  查看日志:    docker logs -f mohe-app"

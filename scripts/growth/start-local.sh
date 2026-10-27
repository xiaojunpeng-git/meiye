#!/bin/sh
# 同一源码的隔离本地体验环境，不修改原18081服务或客户实例。
set -eu
growth_source=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
if ! docker inspect mohe-growth-local-redis >/dev/null 2>&1; then
  docker run -d --name mohe-growth-local-redis --network mohe-net docker.m.daocloud.io/library/redis:7-alpine
fi
docker start mohe-growth-local-redis >/dev/null
if ! docker inspect mohe-growth-local-app >/dev/null 2>&1; then
  docker run -d --name mohe-growth-local-app --network mohe-net \
    -v "$growth_source/后端代码:/var/www/html" -v "$growth_source:/workspace" \
    -w /var/www/html --entrypoint php mohe-app:local \
    -d upload_max_filesize=50M -d post_max_size=52M -d memory_limit=512M \
    -S 0.0.0.0:20700 /workspace/scripts/growth/local-http.php
fi
docker start mohe-growth-local-app >/dev/null
# 转换器属于本地容器依赖，不修改共享PHP镜像；原始文档保存在隔离私有目录。
if ! docker exec mohe-growth-local-app sh -c 'command -v soffice >/dev/null && command -v pdfinfo >/dev/null && command -v ffprobe >/dev/null'; then
  docker exec -u root mohe-growth-local-app apk add --no-cache libreoffice poppler-utils ffmpeg font-noto-cjk
fi
if ! docker inspect mohe-growth-local-web >/dev/null 2>&1; then
  growth_admin_ip=$(docker inspect mohe-admin-src --format '{{.NetworkSettings.Networks.bridge.IPAddress}}')
  docker run -d --name mohe-growth-local-web --network mohe-net --add-host "growth-admin-source:$growth_admin_ip" -p 127.0.0.1:18115:80 \
    -v "$growth_source/scripts/growth/local-nginx-main.conf:/etc/nginx/nginx.conf:ro" \
    -v "$growth_source/scripts/growth/local-nginx.conf:/etc/nginx/conf.d/default.conf:ro" \
    docker.m.daocloud.io/library/nginx:1.25-alpine
fi
docker start mohe-growth-local-web >/dev/null
docker network connect bridge mohe-growth-local-web 2>/dev/null || true
# 恢复奖励、超时交卷和提醒只访问受脚本硬性限制的本地测试库。
if ! docker inspect mohe-growth-local-worker >/dev/null 2>&1; then
  docker run -d --name mohe-growth-local-worker --network mohe-net --restart unless-stopped --log-opt max-size=5m --log-opt max-file=2 \
    -v "$growth_source/后端代码:/var/www/html" -v "$growth_source:/workspace" \
    -w /var/www/html --entrypoint /bin/sh mohe-app:local \
    -c 'while true; do php /workspace/scripts/growth/process-local.php; sleep 10; done'
fi
docker start mohe-growth-local-worker >/dev/null
docker update --restart unless-stopped mohe-growth-local-redis mohe-growth-local-app mohe-growth-local-web >/dev/null
printf '%s\n' '成长本地体验：http://127.0.0.1:18115/admin/growth/content'

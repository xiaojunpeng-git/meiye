#!/usr/bin/env bash
# 只同步后端 PHP（app/route/mohe），保留服务器 .env / runtime / public / vendor
# 用法：
#   bash 美容源码/scripts/deploy-backend.sh 008
#   bash 美容源码/scripts/deploy-backend.sh rh --allow-rh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/后端代码"
SITE_KEY="${1:-}"
ALLOW_RH="${2:-}"
SSH_HOST="${MOHE_SSH_HOST:-aliyun-ecs}"

if [ -z "$SITE_KEY" ]; then
  echo "必须点名站点：008 | 007 | 012 | rh"
  exit 2
fi

case "$SITE_KEY" in
  008) REMOTE="/www/wwwroot/008.cc3798.com" ;;
  007) REMOTE="/www/wwwroot/007.cc3798.com" ;;
  012) REMOTE="/www/wwwroot/012.cc3798.com" ;;
  rh)
    if [ "$ALLOW_RH" != "--allow-rh" ]; then
      echo "rh 为生产。确认后加：--allow-rh"
      exit 3
    fi
    REMOTE="/www/wwwroot/rh.cc3798.com"
    ;;
  *) echo "未知站点 $SITE_KEY"; exit 2 ;;
esac

echo "→ rsync 后端 app/route/mohe → $REMOTE （不覆盖 .env/runtime/public/vendor）"
rsync -az --delete \
  --exclude 'runtime/' \
  "$SRC/app/" "$SSH_HOST:$REMOTE/app/"
rsync -az --delete "$SRC/route/" "$SSH_HOST:$REMOTE/route/"
rsync -az "$SRC/mohe/" "$SSH_HOST:$REMOTE/mohe/"
# 清缓存（不重启 Swoole，调用方按需重启）
ssh "$SSH_HOST" "rm -rf '$REMOTE/runtime/cache/'* 2>/dev/null || true; echo CACHE_CLEARED"
echo "DEPLOY_BACKEND_OK $SITE_KEY"
echo "若改了业务 PHP：请按该站 Swoole 脚本重启（瑞昊 /etc/init.d/ruihao_swoole；008 按现网进程）。"

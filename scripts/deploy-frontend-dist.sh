#!/usr/bin/env bash
# 只上传前端 dist 到目标站点（不在服务器上 npm install / build）
# 用法：
#   bash 美容源码/scripts/deploy-frontend-dist.sh 008
#   bash 美容源码/scripts/deploy-frontend-dist.sh 008 admin
#   bash 美容源码/scripts/deploy-frontend-dist.sh 008 store
#   bash 美容源码/scripts/deploy-frontend-dist.sh 007
#
# 前置：已用 build-frontend.sh 打好对应 dist；SSH 别名 aliyun-ecs 可用。
# 约束：必须点名站点（008/007/…）；禁止对 rh 生产静默上传（需显式 --allow-rh）。
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE_KEY="${1:-}"
WHICH="${2:-all}"
ALLOW_RH="${3:-}"

if [ -z "$SITE_KEY" ]; then
  echo "必须点名站点：008 | 007 | 012 | rh"
  echo "例：bash 美容源码/scripts/deploy-frontend-dist.sh 008"
  exit 2
fi

case "$SITE_KEY" in
  008) REMOTE_ROOT="/www/wwwroot/008.cc3798.com" ;;
  007) REMOTE_ROOT="/www/wwwroot/007.cc3798.com" ;;
  012) REMOTE_ROOT="/www/wwwroot/012.cc3798.com" ;;
  rh)
    if [ "$ALLOW_RH" != "--allow-rh" ]; then
      echo "rh 为生产站点。确认后请加参数：--allow-rh"
      echo "例：bash 美容源码/scripts/deploy-frontend-dist.sh rh all --allow-rh"
      exit 3
    fi
    REMOTE_ROOT="/www/wwwroot/rh.cc3798.com"
    ;;
  *)
    echo "未知站点：$SITE_KEY（仅支持 008|007|012|rh）"
    exit 2
    ;;
esac

SSH_HOST="${MOHE_SSH_HOST:-aliyun-ecs}"
PUBLIC="$REMOTE_ROOT/public"

deploy_admin() {
  local dist="$ROOT/前端代码/admin/dist"
  test -d "$dist/view_admin"
  local html="$dist/system.html"
  [ -f "$html" ] || html="$dist/index.html"
  test -f "$html"
  echo "→ 上传 admin → $PUBLIC/view_admin + system.html"
  ssh "$SSH_HOST" "mkdir -p '$PUBLIC/view_admin' && rm -rf '$PUBLIC/view_admin'/*"
  rsync -az --delete "$dist/view_admin/" "$SSH_HOST:$PUBLIC/view_admin/"
  scp "$html" "$SSH_HOST:$PUBLIC/system.html"
  ssh "$SSH_HOST" "chown -R www:www '$PUBLIC/view_admin' '$PUBLIC/system.html' 2>/dev/null || true"
}

deploy_store() {
  local dist="$ROOT/前端代码/store/dist"
  test -d "$dist/view_store"
  local html="$dist/store.html"
  [ -f "$html" ] || html="$dist/index.html"
  test -f "$html"
  echo "→ 上传 store → $PUBLIC/view_store + store.html"
  ssh "$SSH_HOST" "mkdir -p '$PUBLIC/view_store' && rm -rf '$PUBLIC/view_store'/*"
  rsync -az --delete "$dist/view_store/" "$SSH_HOST:$PUBLIC/view_store/"
  scp "$html" "$SSH_HOST:$PUBLIC/store.html"
  ssh "$SSH_HOST" "chown -R www:www '$PUBLIC/view_store' '$PUBLIC/store.html' 2>/dev/null || true"
}

echo "目标：渼约/瑞昊站点 key=$SITE_KEY  path=$REMOTE_ROOT  which=$WHICH"
echo "请确认服务器名与站点（铁律 2）：渼约=121 / 瑞昊=47；008 为测试站。"

case "$WHICH" in
  admin) deploy_admin ;;
  store) deploy_store ;;
  all)
    deploy_admin
    deploy_store
    ;;
  *)
    echo "第二参数应为 all|admin|store"
    exit 2
    ;;
esac

echo "DEPLOY_FRONTEND_OK $SITE_KEY $WHICH"

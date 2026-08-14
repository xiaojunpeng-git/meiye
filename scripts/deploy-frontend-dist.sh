#!/usr/bin/env bash
# 只上传前端 dist 到目标站点（不在服务器上 npm install / build）
# 用法：
#   bash 美容源码/scripts/deploy-frontend-dist.sh 008
#   bash 美容源码/scripts/deploy-frontend-dist.sh 008 admin
#   bash 美容源码/scripts/deploy-frontend-dist.sh 008 cashier
#   bash 美容源码/scripts/deploy-frontend-dist.sh 008 inventory
#   bash 美容源码/scripts/deploy-frontend-dist.sh 007
#
# 前置：已用 build-frontend.sh 打好对应 dist；SSH 别名 aliyun-ecs 可用。
# 约束：必须点名站点（008/007/…）；禁止对 rh 生产静默上传（需显式 --allow-rh）。
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FRONTEND_DIST_ROOT="${FRONTEND_DIST_ROOT:-$ROOT/前端代码}"
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
  local dist="$FRONTEND_DIST_ROOT/admin/dist"
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

deploy_cashier_v3() {
  local dist="$FRONTEND_DIST_ROOT/cashier-v3/dist"
  test -f "$dist/index.html"
  echo "→ 上传 cashier-v3 → $PUBLIC/view_cashier_v3"
  ssh "$SSH_HOST" "mkdir -p '$PUBLIC/view_cashier_v3' && rm -rf '$PUBLIC/view_cashier_v3'/*"
  rsync -az --delete "$dist/" "$SSH_HOST:$PUBLIC/view_cashier_v3/"
  ssh "$SSH_HOST" "chown -R www:www '$PUBLIC/view_cashier_v3' 2>/dev/null || true"
}

deploy_inventory_v3() {
  local dist="$FRONTEND_DIST_ROOT/inventory-vue3/dist"
  test -f "$dist/index.html"
  echo "→ 上传 inventory-v3 → $PUBLIC/view_inventory_v3"
  ssh "$SSH_HOST" "mkdir -p '$PUBLIC/view_inventory_v3' && rm -rf '$PUBLIC/view_inventory_v3'/*"
  rsync -az --delete "$dist/" "$SSH_HOST:$PUBLIC/view_inventory_v3/"
  ssh "$SSH_HOST" "chown -R www:www '$PUBLIC/view_inventory_v3' 2>/dev/null || true"
}

echo "目标：渼约/瑞昊站点 key=$SITE_KEY  path=$REMOTE_ROOT  which=$WHICH"
echo "请确认服务器名与站点（铁律 2）：渼约=121 / 瑞昊=47；008 为测试站。"

case "$WHICH" in
  admin) deploy_admin ;;
  cashier) deploy_cashier_v3 ;;
  inventory) deploy_inventory_v3 ;;
  all)
    deploy_admin
    deploy_cashier_v3
    deploy_inventory_v3
    ;;
  *)
    echo "第二参数应为 all|admin|cashier|inventory"
    exit 2
    ;;
esac

echo "DEPLOY_FRONTEND_OK $SITE_KEY $WHICH"

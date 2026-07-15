#!/usr/bin/env bash
# 本机串行构建 admin / store 生产包（原生 arm64 Node，禁止 Docker amd64 并行）
# 用法：
#   bash 美容源码/scripts/build-frontend.sh           # 先 admin 再 store
#   bash 美容源码/scripts/build-frontend.sh admin     # 只打 admin
#   bash 美容源码/scripts/build-frontend.sh store     # 只打 store
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NODE_HOME="${MOHE_NODE_HOME:-$HOME/.local/node-v16.20.2-darwin-arm64}"
export PATH="$NODE_HOME/bin:$PATH"

TARGET="${1:-all}"

if ! command -v node >/dev/null 2>&1; then
  echo "未找到 node。请确认已安装：$NODE_HOME"
  echo "或在本机执行：见 XJPMD/11-前端构建与部署SOP.md"
  exit 1
fi

ARCH="$(node -p process.arch)"
if [ "$ARCH" != "arm64" ] && [ "$(uname -m)" = "arm64" ]; then
  echo "警告：当前 node 不是 arm64（$ARCH）。本机为 Apple Silicon，请用原生 Node，勿用 amd64/Rosetta。"
fi

echo "node=$(node -v) npm=$(npm -v) arch=$ARCH"

build_one() {
  local name="$1"
  local dir="$ROOT/前端代码/$name"
  echo "========== BUILD $name START $(date '+%F %T') =========="
  cd "$dir"
  if [ ! -x node_modules/.bin/vue-cli-service ]; then
    npm config set registry https://registry.npmmirror.com
    npm install --no-audit --no-fund
  fi
  # 8G 内存机器：串行 + 限制堆，避免 OOM
  NODE_OPTIONS="${NODE_OPTIONS:---max_old_space_size=3072}" npm run build
  if [ "$name" = "admin" ]; then
    test -f dist/system.html || test -f dist/index.html
    test -d dist/view_admin
  else
    test -f dist/index.html || test -f dist/store.html
    test -d dist/view_store
  fi
  echo "========== BUILD $name OK $(date '+%F %T') =========="
}

case "$TARGET" in
  admin) build_one admin ;;
  store) build_one store ;;
  all)
    build_one admin
    build_one store
    ;;
  *)
    echo "用法: $0 [all|admin|store]"
    exit 2
    ;;
esac

echo
echo "产物目录："
echo "  admin: $ROOT/前端代码/admin/dist/  （system.html + view_admin/）"
echo "  store: $ROOT/前端代码/store/dist/  （index.html/store.html + view_store/）"
echo "下一步上传：bash 美容源码/scripts/deploy-frontend-dist.sh <站点名>"

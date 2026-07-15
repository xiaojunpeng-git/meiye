#!/bin/bash
# 打开本地 uni-app 编译后的小程序（需先用 HBuilderX 发行一次）
set -euo pipefail

UNIAPP_DIR="$(cd "$(dirname "$0")/../前端代码/uniapp" && pwd)"
MP_DEV="$UNIAPP_DIR/unpackage/dist/dev/mp-weixin"
MP_BUILD="$UNIAPP_DIR/unpackage/dist/build/mp-weixin"
CLI="/Applications/wechatwebdevtools.app/Contents/MacOS/cli"

echo "小程序源码：$UNIAPP_DIR"
echo "编译命令：HBuilderX → 发行 → 小程序-微信"
echo

HX_CLI="/Applications/HBuilderX.app/Contents/MacOS/cli"
MP_DIR="$MP_DEV"
if [ ! -f "$MP_DEV/project.config.json" ]; then
  MP_DIR="$MP_BUILD"
fi

if [ -x "$HX_CLI" ]; then
  echo "正在用 HBuilderX 编译并打开微信开发者工具..."
  "$HX_CLI" open >/dev/null 2>&1 || true
  sleep 2
  "$HX_CLI" project open --path "$UNIAPP_DIR" >/dev/null 2>&1 || true
  "$HX_CLI" launch mp-weixin --project "$UNIAPP_DIR" --runtime-log true
elif [ -x "$CLI" ]; then
  echo "正在用微信开发者工具打开编译产物..."
  "$CLI" open --project "$MP_DIR"
else
  echo "微信开发者工具未安装。手动导入目录："
  echo "  $MP_DIR"
  echo "AppID: wxce4f61fc9c4eaaa6（manifest.json 中 mp-weixin.appid）"
fi

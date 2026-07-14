#!/bin/bash
# 打开本地 uni-app 编译后的小程序（需先用 HBuilderX 发行一次）
set -euo pipefail

UNIAPP_DIR="$(cd "$(dirname "$0")/../后端代码/view/uniapp" && pwd)"
MP_BUILD="$UNIAPP_DIR/unpackage/dist/build/mp-weixin"
CLI="/Applications/wechatwebdevtools.app/Contents/MacOS/cli"

echo "小程序源码：$UNIAPP_DIR"
echo "编译命令：HBuilderX → 发行 → 小程序-微信"
echo

if [ ! -f "$MP_BUILD/project.config.json" ]; then
  echo "尚未编译。请先用 HBuilderX 打开上述目录并发行到微信小程序。"
  echo "编译产物将出现在：$MP_BUILD"
  exit 1
fi

if [ -x "$CLI" ]; then
  echo "正在用微信开发者工具打开编译产物..."
  "$CLI" open --project "$MP_BUILD"
else
  echo "微信开发者工具未安装。手动导入目录："
  echo "  $MP_BUILD"
  echo "AppID: wx29d9b63c92555701"
fi

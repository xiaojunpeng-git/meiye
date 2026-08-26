#!/bin/sh

set -eu

ROOT=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
MOBILE_ROOT="$ROOT/前端代码/mobile-vue3"
HX_CLI="/Applications/HBuilderX.app/Contents/MacOS/cli"
WECHAT_CLI="/Applications/wechatwebdevtools.app/Contents/MacOS/cli"
API_ORIGIN="${MOBILE_API_ORIGIN:-http://127.0.0.1:18093}"

if [ ! -f "$MOBILE_ROOT/manifest.json" ] || [ ! -f "$MOBILE_ROOT/pages.json" ]; then
	echo "错误：商家端唯一源码工程不存在：$MOBILE_ROOT" >&2
	exit 1
fi

if [ "${START_LOCAL_BACKEND:-0}" = "1" ]; then
	echo "启动本地后端（START_LOCAL_BACKEND=1）..."
	sh "$ROOT/scripts/start-local.sh"
fi

SOURCE_FINGERPRINT=$(find "$MOBILE_ROOT/src" -type f -print0 | xargs -0 shasum -a 256 | shasum -a 256 | awk '{print $1}')
echo "商家端源码：$MOBILE_ROOT"
echo "源码指纹：$SOURCE_FINGERPRINT"
echo "接口地址：$API_ORIGIN"
echo "微信小程序产物：$MOBILE_ROOT/unpackage/dist/dev/mp-weixin"

API_STATUS=""
if command -v curl >/dev/null 2>&1; then
	API_STATUS=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 2 "$API_ORIGIN/api/mobile/merchant/bootstrap" 2>/dev/null || true)
fi
if [ -n "$API_STATUS" ] && [ "$API_STATUS" != "000" ]; then
	echo "本地接口：可访问（HTTP ${API_STATUS}；未携带商家会话时返回未授权属于正常现象）"
else
	echo "提示：本地接口当前不可访问；如需同时启动后端，请使用 START_LOCAL_BACKEND=1。"
fi

if [ -x "$HX_CLI" ]; then
	echo "正在打开 HBuilderX 的正确商家端工程并编译 mp-weixin..."
	"$HX_CLI" launch mp-weixin --project "$MOBILE_ROOT" --runtime-log true
	exit 0
fi

MP_DIR="$MOBILE_ROOT/unpackage/dist/dev/mp-weixin"
if [ -x "$WECHAT_CLI" ] && [ -f "$MP_DIR/project.config.json" ]; then
	echo "HBuilderX CLI 不可用，直接打开已生成的微信小程序产物..."
	"$WECHAT_CLI" open --project "$MP_DIR"
	exit 0
fi

echo "未找到 HBuilderX 或微信开发者工具。请手动打开："
echo "  $MOBILE_ROOT"
echo "编译产物目录："
echo "  $MP_DIR"

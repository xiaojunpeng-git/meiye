#!/bin/sh

set -eu

ROOT=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
MOBILE_ROOT="$ROOT/前端代码/mobile-vue3"
SRC_ROOT="$MOBILE_ROOT/src"

required_paths="
$SRC_ROOT/app/pages/bootstrap/index.uvue
$SRC_ROOT/merchant/pages/workbench/index.uvue
$SRC_ROOT/merchant/api/mobile-login-client.uts
$SRC_ROOT/merchant/platform/mobile-merchant-session.uts
$SRC_ROOT/shared/api/mobile-http.uts
$SRC_ROOT/shared/platform/mobile-runtime-config.uts
"

for required_path in $required_paths; do
	[ -f "$required_path" ] || { echo "缺少商家端必要文件：$required_path" >&2; exit 1; }
done

if rg -n "shared/api/mobile-(customer-care|customer|login|merchant-session-client|personal-target|reservation|warehouse)-client|shared/platform/mobile-(merchant-session|navigation)" "$SRC_ROOT"; then
	echo "错误：商家业务仍从 shared 边界引入。" >&2
	exit 1
fi

shared_api_files=$(find "$SRC_ROOT/shared/api" -maxdepth 1 -type f -print | sed "s#^$SRC_ROOT/shared/api/##" | sort)
expected_shared_api="mobile-http.uts
request-context.uts"
[ "$shared_api_files" = "$expected_shared_api" ] || {
	echo "错误：shared/api 只能保留底层传输文件，当前为：" >&2
printf '%s\n' "$shared_api_files" >&2
	exit 1
}

if ! grep -q 'src/merchant/pages/workbench/index' "$MOBILE_ROOT/pages.json"; then
	echo "错误：pages.json 未登记商家端工作台。" >&2
	exit 1
fi

echo "商家端源码边界检查通过：$MOBILE_ROOT"
echo "运行完整移动端契约检查..."
sh "$ROOT/tests/mobile-vue3/run-all.sh"

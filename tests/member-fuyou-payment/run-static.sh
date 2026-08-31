#!/usr/bin/env bash
set -euo pipefail

test_dir="$(cd "$(dirname "$0")" && pwd)"
repo_root="$(cd "$test_dir/../.." && pwd)"

php "$test_dir/php/static-contract.php"

php_files=(
  "后端代码/app/controller/admin/v1/system/config/SystemConfig.php"
  "后端代码/app/controller/api/v1/Pay.php"
  "后端代码/app/controller/api/v1/order/OtherOrder.php"
  "后端代码/app/listener/pay/PayNotifyListener.php"
  "后端代码/app/services/order/StoreDebtServices.php"
  "后端代码/app/services/order/StoreOrderCreateServices.php"
  "后端代码/app/services/order/StoreOrderServices.php"
  "后端代码/app/services/pay/OrderPayServices.php"
  "后端代码/app/services/pay/PayServices.php"
  "后端代码/app/services/system/config/SystemConfigServices.php"
  "后端代码/mohe/services/wechat/HwcPayService.php"
  "后端代码/route/api.php"
)

for file in "${php_files[@]}"; do
  php -l "$repo_root/$file" >/dev/null
done

echo "member fuyou payment php lint: PASS"

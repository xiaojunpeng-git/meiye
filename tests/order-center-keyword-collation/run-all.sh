#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
php -l "$repo_dir/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php"
php -l "$repo_dir/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php"
php "$repo_dir/tests/order-center-keyword-collation/php/contract.php"

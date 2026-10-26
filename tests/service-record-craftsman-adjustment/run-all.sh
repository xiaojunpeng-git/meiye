#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"

php -l "$repo_dir/后端代码/app/services/cashier/v3/order/CashierV3ServiceRecordCraftsmanAdjustmentServices.php"
php -l "$repo_dir/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php"
php -l "$repo_dir/后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php"
php "$repo_dir/tests/service-record-craftsman-adjustment/php/contract.php"

echo "SERVICE_RECORD_CRAFTSMAN_ADJUSTMENT_FOCUSED_SUITE=PASS"

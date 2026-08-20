#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

php "$ROOT/tests/report-product-dashboard/php/static-contract.php"
php -l "$ROOT/后端代码/app/services/report/ProductManagementDashboardServices.php"
php -l "$ROOT/后端代码/app/controller/admin/v1/report/UnifiedReport.php"
php -l "$ROOT/后端代码/route/admin.php"
(cd "$ROOT/前端代码/cashier-v3" && npm run build >/dev/null)

echo "product dashboard static checks: PASS"

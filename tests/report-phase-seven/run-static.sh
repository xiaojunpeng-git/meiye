#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
php "$ROOT/tests/report-phase-seven/php/member-dashboard-contract.php"
php "$ROOT/tests/report-phase-seven/php/group-dashboard-stability-contract.php"
php "$ROOT/tests/report-phase-seven/php/store-target-dashboard-contract.php"
node "$ROOT/tests/report-phase-seven/js/group-dashboard-refresh-contract.mjs"
node "$ROOT/tests/report-phase-seven/js/store-target-dashboard-contract.mjs"
php -l "$ROOT/后端代码/app/services/report/MemberManagementDashboardServices.php"
php -l "$ROOT/后端代码/app/controller/admin/v1/report/UnifiedReport.php"
php -l "$ROOT/后端代码/route/admin.php"
php -l "$ROOT/后端代码/app/services/cashier/v3/dashboard/CashierV3StoreTargetDashboardReadModel.php"

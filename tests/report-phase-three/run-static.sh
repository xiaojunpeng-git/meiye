#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

php -l "$ROOT/后端代码/app/services/report/StoreUnifiedReportPhaseThreeServices.php"
php -l "$ROOT/后端代码/app/controller/admin/v1/report/UnifiedReport.php"
php -l "$ROOT/后端代码/app/controller/store/report/UnifiedReport.php"
php -l "$ROOT/后端代码/app/controller/cashier/v3/Report.php"
php "$ROOT/tests/report-phase-three/php/service-contract.php"

if [[ "${PHASE3_MYSQL_INTEGRATION:-0}" == "1" ]]; then
  php "$ROOT/tests/report-phase-three/php/mysql-integration.php"
  php "$ROOT/tests/report-phase-three/php/report-query-smoke.php"
  php "$ROOT/tests/report-phase-three/php/report-performance-smoke.php"
fi

#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
php "$ROOT/tests/report-phase-seven/php/member-dashboard-contract.php"
php -l "$ROOT/后端代码/app/services/report/MemberManagementDashboardServices.php"
php -l "$ROOT/后端代码/app/controller/admin/v1/report/UnifiedReport.php"
php -l "$ROOT/后端代码/route/admin.php"

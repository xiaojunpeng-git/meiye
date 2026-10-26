#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

php -l "$ROOT/后端代码/app/services/report/StoreUnifiedReportServices.php"
php "$ROOT/tests/report-member-consumption-receipt-total/php/contract.php"

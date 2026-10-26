#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

php -l "$ROOT/后端代码/app/services/report/StoreUnifiedReportServices.php"
php -l "$ROOT/后端代码/app/services/report/StoreReportServiceCategorySnapshotServices.php"
php "$ROOT/tests/report-store-item-analysis/php/contract.php"

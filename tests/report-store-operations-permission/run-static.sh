#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
php "$ROOT/tests/report-store-operations-permission/php/contract.php"

php -l "$ROOT/后端代码/app/controller/admin/Common.php"
php -l "$ROOT/后端代码/app/controller/admin/v1/report/UnifiedReport.php"

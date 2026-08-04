#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
php "$ROOT/tests/cashier-v3-sale-inventory/php/contract.php"
php -l "$ROOT/后端代码/app/services/cashier/v3/settlement/CashierV3SaleInventorySettlementServices.php"
php -l "$ROOT/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php"
php -l "$ROOT/后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php"
php -l "$ROOT/tests/cashier-v3-sale-inventory/php/gateway-mysql-integration.php"
bash -n "$ROOT/tests/cashier-v3-sale-inventory/mysql56-matrix.sh"
bash -n "$ROOT/tests/cashier-v3-sale-inventory/gateway-mysql56-matrix.sh"

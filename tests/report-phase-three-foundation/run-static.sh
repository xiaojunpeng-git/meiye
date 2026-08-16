#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

php -l "$ROOT/后端代码/app/services/report/StoreUnifiedReportPhaseThreeFoundationServices.php"
php -l "$ROOT/后端代码/app/services/report/CardSaleItemAllocationFactServices.php"
php -l "$ROOT/后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php"
php -l "$ROOT/后端代码/app/services/cashier/v3/card/CashierV3CardOperationAuthorityServices.php"
php -l "$ROOT/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php"
php -l "$ROOT/后端代码/app/services/user/UserServices.php"
php -l "$ROOT/后端代码/app/services/user/UserBelongStoreServices.php"
find "$ROOT/后端代码/app/model/report" -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php "$ROOT/tests/report-phase-three-foundation/php/contract.php"

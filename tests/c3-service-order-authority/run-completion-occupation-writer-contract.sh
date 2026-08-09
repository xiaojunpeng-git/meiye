#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
export C3_BACKEND_ROOT="$repo_dir/后端代码"

php -l "$C3_BACKEND_ROOT/app/services/cashier/v3/service/ThinkPhpCashierV3EntitlementCompletionOccupationWriter.php"
php -l "$C3_BACKEND_ROOT/app/services/cashier/v3/service/CashierV3ServiceOrderState.php"
php -l "$C3_BACKEND_ROOT/app/services/cashier/v3/service/ThinkPhpCashierV3ServiceOrderRepository.php"
php -l "$repo_dir/tests/c3-service-order-authority/php/completion-occupation-writer-contract.php"
php "$repo_dir/tests/c3-service-order-authority/php/completion-occupation-writer-contract.php"

echo "C3_COMPLETION_OCCUPATION_WRITER_FOCUSED=PASS"

#!/usr/bin/env bash
set -euo pipefail

# Local contracts/fixtures only. Some suites use disposable SQLite and real
# framework class loading, but never load application .env, a customer DB or a model.
test_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source_root="$(cd "$test_dir/../.." && pwd)"
php_bin="${MOHE_TEST_PHP:-php}"

"$php_bin" -v
for suite in config-contract metric-contract metric-registry-contract round3-reaudit-regressions runtime-contract export-source-contract plan-contract \
    query-read-view cash-report-projection state-store-contract state-attempt-contract state-concurrency state-export-contract state-admission-contract \
    gateway-components client-runtime-compat gateway-integration gateway-review-regressions monitor-contract \
    http-routing-contract http-actions-contract http-guard-contract principal-resolvers \
    export-worker-contract export-runtime-contract; do
    "$php_bin" -d auto_prepend_file="$test_dir/fixture-autoload.php" "$test_dir/$suite.php"
done
for suite in semantic-guidance registry-execution state-guidance-contract gateway-r5-guidance r5-holdout management-core management-gateway management-menu analysis-capability-catalog analysis-object-resolution personnel-analysis; do
    "$php_bin" -d auto_prepend_file="$test_dir/fixture-autoload.php" "$test_dir/$suite.php"
done

while IFS= read -r source_file; do
    "$php_bin" -l "$source_file"
done < <(rg --files "$source_root/后端代码/app/services/ai" "$source_root/后端代码/app/services/query/metric" "$test_dir" | rg '\.php$' | sort)
"$php_bin" -l "$source_root/后端代码/app/services/query/UnifiedQueryExportSourcePolicy.php"

"$php_bin" "$source_root/tests/report-phase-seven/php/group-dashboard-stability-contract.php"
"$php_bin" "$source_root/tests/cashier-v3/php/order-center-unified-export-contract.php"

node_bin="${MOHE_TEST_NODE:-node}"
for suite in device-session-contract browser-entry-contract browser-transport-contract browser-workflow-contract browser-guidance-contract browser-mobile-guidance-contract order-export-snapshot; do
    "$node_bin" "$test_dir/$suite.mjs"
done
bash "$test_dir/local-entrypoint-permissions.sh"

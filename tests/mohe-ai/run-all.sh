#!/usr/bin/env bash
set -euo pipefail

# Offline contracts only. No framework boot, Docker, database, model or queue.
test_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source_root="$(cd "$test_dir/../.." && pwd)"
php_bin="${MOHE_TEST_PHP:-php}"

"$php_bin" -v
for suite in metric-contract runtime-contract export-source-contract plan-contract; do
    "$php_bin" "$test_dir/$suite.php"
done

while IFS= read -r source_file; do
    "$php_bin" -l "$source_file"
done < <(find "$source_root/后端代码/app/services/ai" "$source_root/后端代码/app/services/query/metric" "$test_dir" -type f -name '*.php' | sort)
"$php_bin" -l "$source_root/后端代码/app/services/query/UnifiedQueryExportSourcePolicy.php"

"$php_bin" "$source_root/tests/report-phase-seven/php/group-dashboard-stability-contract.php"
"$php_bin" "$source_root/tests/cashier-v3/php/order-center-unified-export-contract.php"

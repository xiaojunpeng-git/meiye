#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
php "$ROOT/tests/mobile-warehouse/php/hierarchy-contract.php"
php "$ROOT/tests/mobile-warehouse/php/ranking-scope-contract.php"
php "$ROOT/tests/mobile-warehouse/php/project-ranking-contract.php"
php "$ROOT/tests/mobile-warehouse/php/employee-metrics-contract.php"
node --test "$ROOT/tests/mobile-warehouse/js/page-contract.mjs"

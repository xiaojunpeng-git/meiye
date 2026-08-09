#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
php "$repo_dir/tests/c3-service-order-generic-lines/php/contract.php"
echo "C3_GENERIC_SERVICE_ORDER_LINES=PASS"


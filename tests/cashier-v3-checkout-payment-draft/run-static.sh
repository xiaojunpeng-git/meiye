#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

php "$ROOT_DIR/tests/cashier-v3-checkout-payment-draft/php/contract.php"
node "$ROOT_DIR/tests/cashier-v3-checkout-payment-draft/js/static-contract.mjs"

#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SERVICE="$ROOT/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSubmissionPreparationServices.php"
CONTRACT="$ROOT/tests/cashier-v3-checkout-submission-preparation/php/contract.php"

node "$ROOT/tests/cashier-v3-checkout-submission-preparation/js/static-contract.mjs"

if command -v php >/dev/null 2>&1; then
  php -l "$SERVICE"
  php -l "$CONTRACT"
  php "$CONTRACT"
else
  echo "SKIP: host PHP is unavailable; PHP lint and behavioral contract were not executed." >&2
fi

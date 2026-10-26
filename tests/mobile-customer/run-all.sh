#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
php "$ROOT/php/audience-contract.php"
php "$ROOT/php/mobile-api-contract.php"
if [ "${RUN_MYSQL56_MOBILE_CUSTOMER_UQ:-0}" = "1" ]; then
  bash "$ROOT/mysql56-unified-query-command.sh"
fi

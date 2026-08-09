#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
php "$ROOT/tests/mobile-warehouse/php/hierarchy-contract.php"
node --test "$ROOT/tests/mobile-warehouse/js/page-contract.mjs"

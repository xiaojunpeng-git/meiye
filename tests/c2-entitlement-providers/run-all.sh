#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
PROVIDER_DIR="$ROOT_DIR/后端代码/app/services/cashier/v3/checkout/provider"
MIGRATION_DIR="$ROOT_DIR/后端代码/database/upgrades/2026-07-29-C2权益完成依赖提供者"
PHP_IMAGE="${C2_PROVIDER_PHP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"

(cd "$MIGRATION_DIR" && shasum -a 256 -c SHA256SUMS.txt)

docker run --rm \
  -e C2_PROVIDER_MIGRATION_DIR=/backend/database/upgrades/2026-07-29-C2权益完成依赖提供者 \
  -v "$ROOT_DIR/后端代码:/var/www/html:ro" \
  -v "$ROOT_DIR/后端代码:/backend:ro" \
  -v "$ROOT_DIR/tests:/tests:ro" \
  --entrypoint sh "$PHP_IMAGE" -lc '
    set -e
    find /var/www/html/app/services/cashier/v3/checkout/provider /tests/c2-entitlement-providers/php \
      -type f -name "*.php" -exec php -l {} \;
    php /tests/c2-entitlement-providers/php/contract.php
    php /tests/c2-entitlement-providers/php/migration-contract.php
  '

bash "$ROOT_DIR/tests/c2-entitlement-providers/mysql56-matrix.sh"

echo "C2_ENTITLEMENT_PROVIDER_FOCUSED_SUITE=PASS"

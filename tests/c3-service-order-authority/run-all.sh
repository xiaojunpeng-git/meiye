#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
service_dir="$repo_dir/后端代码/app/services/cashier/v3/service"
test_dir="$repo_dir/tests/c3-service-order-authority"
php_image="${C3_PHP_IMAGE:-$(docker inspect --format '{{.Config.Image}}' mohe-app)}"

node "$test_dir/js/static-contract.mjs"

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --env C3_BACKEND_ROOT=/workspace/后端代码 \
  --env C3_MIGRATION_DIR=/workspace/后端代码/database/upgrades/2026-07-29-C3服务单权益占用权威源 \
  --entrypoint sh "$php_image" -lc '
    set -e
    find /workspace/后端代码/app/services/cashier/v3/service \
      /workspace/tests/c3-service-order-authority/php \
      -type f -name "*.php" -exec php -l {} \;
    php /workspace/tests/c3-service-order-authority/php/contract.php
    php /workspace/tests/c3-service-order-authority/php/migration-contract.php
  '

if [[ "${C3_SERVICE_ORDER_SKIP_MYSQL56:-0}" != "1" ]]; then
  bash "$test_dir/sql-matrix.sh"
else
  echo "C3_MYSQL56_MATRIX=SKIPPED"
fi

echo "C3_SERVICE_ORDER_AUTHORITY_FOCUSED_SUITE=PASS"

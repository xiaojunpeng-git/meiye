#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
php_image="$(docker inspect --format '{{.Config.Image}}' mohe-app)"
docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/completion-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/gateway-adapter-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/batch-query-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/batch-analytics-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/manual-inbound-request-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/manual-inbound-detail-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/error-message-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/read-model-projection-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/expiry-risk-projection-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/inventory-batch-reconciliation-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/manual-document-reversal-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/request-transfer-lifecycle-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/document-lifecycle-route-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/salon-usage-project-selector-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/multi-warehouse-rollout-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/cross-subject-transfer-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/hq-request-party-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/platform-operational-query-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/operational-unified-query-registration-contract.php

docker run --rm --cpus 1 --memory 256m \
  --volume "$repo_dir:/workspace:ro" \
  --entrypoint php \
  "$php_image" \
  /workspace/tests/inventory/php/excel-import-contract.php

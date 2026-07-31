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
  /workspace/tests/inventory/php/error-message-contract.php

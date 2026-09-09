#!/usr/bin/env bash
set -euo pipefail
# Read-only source, disposable /tmp, no network, application boot, customer DB or model.
# Caller supplies an already-installed local PHP 7.4 image; this script never pulls.
source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_image="${MOHE_TEST_PHP74_IMAGE:?Set an existing local PHP 7.4 image ID}"
docker image inspect "$test_image" >/dev/null
docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,nodev \
    --mount "type=bind,src=$source_root,dst=/workspace,readonly" \
    --workdir /workspace --entrypoint /bin/sh "$test_image" -c '
set -eu
php -r "exit(PHP_VERSION_ID >= 70400 && PHP_VERSION_ID < 70500 ? 0 : 1);"
php -v
for suite in config-contract metric-contract runtime-contract export-source-contract plan-contract \
    query-read-view cash-report-projection state-store-contract state-attempt-contract state-concurrency state-export-contract state-admission-contract \
    gateway-components client-runtime-compat gateway-integration gateway-review-regressions monitor-contract \
    http-routing-contract http-actions-contract http-guard-contract principal-resolvers \
    export-worker-contract export-runtime-contract semantic-guidance registry-execution state-guidance-contract gateway-r5-guidance r5-holdout management-core management-gateway management-menu; do
    if [ "$suite" = state-concurrency ] && ! php -r "exit(function_exists(\"pcntl_fork\") ? 0 : 1);"; then
        echo "SKIP PHP74 state-concurrency: image lacks pcntl; mandatory host run-all executes real multi-process test."
        continue
    fi
    php -d auto_prepend_file=/workspace/tests/mohe-ai/fixture-autoload.php "/workspace/tests/mohe-ai/$suite.php"
done
'

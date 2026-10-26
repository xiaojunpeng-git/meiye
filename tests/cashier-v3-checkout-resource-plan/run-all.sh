#!/usr/bin/env bash
set -euo pipefail

TEST_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$TEST_DIR/run-static.sh"

if [[ "${CHECKOUT_RESOURCE_PLAN_RUN_MYSQL56:-0}" == '1' ]]; then
  bash "$TEST_DIR/mysql56-matrix.sh"
else
  echo 'CHECKOUT_RESOURCE_PLAN_MYSQL56=SKIPPED_REQUIRES_EXPLICIT_WINDOW'
fi

echo 'CHECKOUT_RESOURCE_PLAN_SUITE=PASS'

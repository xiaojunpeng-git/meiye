#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

node "$ROOT_DIR/tests/legacy-debt-guard-writer/js/contract.mjs"

echo "LEGACY_DEBT_GUARD_WRITER_STATIC_SUITE=PASS"

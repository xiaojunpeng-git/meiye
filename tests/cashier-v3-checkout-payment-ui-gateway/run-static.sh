#!/usr/bin/env bash
set -euo pipefail

test_dir="$(cd "$(dirname "$0")" && pwd)"
node "$test_dir/js/static-contract.mjs"
node "$test_dir/js/production-http-contract.mjs"

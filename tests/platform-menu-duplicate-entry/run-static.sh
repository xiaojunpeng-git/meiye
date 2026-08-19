#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
"${NODE_BIN:-node}" "$repo_dir/tests/platform-menu-duplicate-entry/js/contract.mjs"

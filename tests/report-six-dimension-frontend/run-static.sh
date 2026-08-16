#!/usr/bin/env bash
set -euo pipefail

test_dir="$(cd "$(dirname "$0")" && pwd)"
repo_dir="$(cd "$test_dir/../.." && pwd)"
REPO_ROOT="$repo_dir" node "$test_dir/js/contract.mjs"

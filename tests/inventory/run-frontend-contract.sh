#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
node_bin="${NODE_BIN:-node}"
"$node_bin" "$repo_dir/tests/inventory/js/store-menu-contract.mjs"
"$node_bin" "$repo_dir/tests/inventory/js/inventory-api-contract.mjs"
"$node_bin" "$repo_dir/tests/inventory/js/document-lifecycle-frontend-contract.mjs"
"$node_bin" "$repo_dir/tests/inventory/js/transfer-confirmation-contract.mjs"
"$node_bin" "$repo_dir/tests/inventory/js/request-dialog-contract.mjs"
"$node_bin" "$repo_dir/tests/inventory/js/recipe-frontend-contract.mjs"
"$node_bin" "$repo_dir/tests/inventory/js/expiry-risk-frontend-contract.mjs"

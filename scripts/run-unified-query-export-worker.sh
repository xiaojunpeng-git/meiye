#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
BACKEND_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/../后端代码" && pwd)

cd "$BACKEND_DIR"
exec php think unified-query:export-worker --loop --sleep=2 --limit=20

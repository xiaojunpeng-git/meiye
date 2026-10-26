#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
BACKEND_DIR=${MOHE_AI_APP_ROOT:-"$SCRIPT_DIR/../后端代码"}
BACKEND_DIR=$(CDPATH= cd -- "$BACKEND_DIR" && pwd)
PHP_BIN=${MOHE_AI_PHP_BIN:-php}
cd "$BACKEND_DIR"

# Dedicated worker only: do not replace the ordinary queue consumer and do
# not use this script for an unverified customer instance.
exec "$PHP_BIN" think mohe-ai:execution-worker --sleep=1

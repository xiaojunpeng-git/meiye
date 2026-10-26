#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
BACKEND_DIR=${MOHE_AI_APP_ROOT:-"$SCRIPT_DIR/../后端代码"}
BACKEND_DIR=$(CDPATH= cd -- "$BACKEND_DIR" && pwd)
PHP_BIN=${MOHE_AI_PHP_BIN:-php}
cd "$BACKEND_DIR"

# Keep this process separate from the execution consumer.  It restores only
# unclaimed durable envelopes, retires expired temporary inputs, and fences a
# worker after its recorded local process has actually stopped.
exec "$PHP_BIN" think mohe-ai:supervise --watch

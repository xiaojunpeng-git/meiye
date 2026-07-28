#!/bin/sh

set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
NODE_BIN=${HBUILDERX_NODE:-/Applications/HBuilderX.app/Contents/HBuilderX/plugins/node/node}

if [ ! -x "$NODE_BIN" ]; then
	echo "HBuilderX Node runtime not found: $NODE_BIN" >&2
	exit 1
fi

"$NODE_BIN" --test "$SCRIPT_DIR"/js/*.test.mjs

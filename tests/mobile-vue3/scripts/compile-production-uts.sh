#!/bin/sh

set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
TEST_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
REPO_ROOT=$(CDPATH= cd -- "$TEST_ROOT/../.." && pwd)
SOURCE_FILE="$REPO_ROOT/前端代码/mobile-vue3/src/app/state/root-state-core.uts"
OUTPUT_DIR="$TEST_ROOT/.generated"
NODE_BIN=${HBUILDERX_NODE:-/Applications/HBuilderX.app/Contents/HBuilderX/plugins/node/node}
UTS_PLUGIN=/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-uts-v1
ROLLUP_ENTRY=/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli-vite/node_modules/rollup/dist/rollup.js

[ -x "$NODE_BIN" ] || { echo "HBuilderX Node runtime not found: $NODE_BIN" >&2; exit 1; }
[ -f "$SOURCE_FILE" ] || { echo "Production UTS source not found: $SOURCE_FILE" >&2; exit 1; }
[ -d "$UTS_PLUGIN" ] || { echo "HBuilderX UTS compiler not found: $UTS_PLUGIN" >&2; exit 1; }
[ -f "$ROLLUP_ENTRY" ] || { echo "HBuilderX Rollup runtime not found: $ROLLUP_ENTRY" >&2; exit 1; }

mkdir -p "$OUTPUT_DIR"
"$NODE_BIN" - "$SOURCE_FILE" "$OUTPUT_DIR/root-state-core.cjs" "$UTS_PLUGIN" "$ROLLUP_ENTRY" <<'NODE'
const [sourceFile, outputFile, utsPluginPath, rollupPath] = process.argv.slice(2)
process.env.HX_APP_ROOT = '/Applications/HBuilderX.app/Contents/HBuilderX'
process.env.UNI_UTS_PLATFORM = 'web'
const { rollup } = require(rollupPath)
const { uts2js } = require(utsPluginPath)

async function main() {
  const plugins = uts2js({
    inputDir: sourceFile.slice(0, sourceFile.lastIndexOf('/src/') + 4),
    platform: 'web',
    tsconfigOverride: { compilerOptions: {} }
  })
  const bundle = await rollup({ input: sourceFile, plugins })
  await bundle.write({ file: outputFile, format: 'cjs', exports: 'named' })
  await bundle.close()
}

main().catch((error) => {
  console.error(error.stack || error)
  process.exit(1)
})
NODE

#!/usr/bin/env bash
# Install only the two dedicated 魔核 AI long-running processes for one
# already-deployed instance.  This script never copies application source,
# edits database data, or turns on async admission flags.
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
APP_ROOT=${MOHE_AI_APP_ROOT:?Set MOHE_AI_APP_ROOT to the deployed backend directory}
PHP_BIN=${MOHE_AI_PHP_BIN:-php}
SYSTEMD_DIR=${MOHE_AI_SYSTEMD_DIR:-/etc/systemd/system}
WORKER_COUNT=${MOHE_AI_EXECUTION_WORKER_COUNT:-1}
EXPORT_WORKER_COUNT=${MOHE_AI_EXPORT_WORKER_COUNT:-1}

[[ $EUID -eq 0 ]] || { echo 'Run as root.' >&2; exit 2; }
[[ -f "$APP_ROOT/think" ]] || { echo "Missing ThinkPHP entry point: $APP_ROOT/think" >&2; exit 2; }
[[ -x "$PHP_BIN" ]] || command -v "$PHP_BIN" >/dev/null 2>&1 || { echo "PHP binary unavailable: $PHP_BIN" >&2; exit 2; }
[[ "$WORKER_COUNT" =~ ^[1-4]$ ]] || { echo 'MOHE_AI_EXECUTION_WORKER_COUNT must be 1..4.' >&2; exit 2; }
[[ "$EXPORT_WORKER_COUNT" =~ ^[1-4]$ ]] || { echo 'MOHE_AI_EXPORT_WORKER_COUNT must be 1..4.' >&2; exit 2; }

# The separate file queue is instance-bound. Resolve it with the deployed
# application's own configuration, never with a hard-coded customer value.
QUEUE_NAME=$(cd "$APP_ROOT" && "$PHP_BIN" -r 'require "vendor/autoload.php"; $app=new think\App(); $app->initialize(); echo app\jobs\query\AiUnifiedQueryExportJob::queueName();')
[[ "$QUEUE_NAME" =~ ^MOHE_PRO_AI_EXPORT:[a-f0-9]{32}$ ]] || { echo 'Invalid AI export queue name.' >&2; exit 2; }

escape_sed() { printf '%s' "$1" | sed 's/[\\&|]/\\\\&/g'; }
app_escaped=$(escape_sed "$APP_ROOT")
php_escaped=$(escape_sed "$PHP_BIN")
queue_escaped=$(escape_sed "$QUEUE_NAME")
tmp_dir=$(mktemp -d)
trap 'rm -rf "$tmp_dir"' EXIT

render_unit() {
  local source=$1 target=$2
  sed -e "s|@APP_ROOT@|$app_escaped|g" -e "s|@PHP_BIN@|$php_escaped|g" -e "s|@QUEUE_NAME@|$queue_escaped|g" "$source" >"$tmp_dir/$target"
  install -m 0644 "$tmp_dir/$target" "$SYSTEMD_DIR/$target"
}

render_unit "$SCRIPT_DIR/systemd/mohe-ai-execution-worker@.service" 'mohe-ai-execution-worker@.service'
render_unit "$SCRIPT_DIR/systemd/mohe-ai-export-worker@.service" 'mohe-ai-export-worker@.service'
render_unit "$SCRIPT_DIR/systemd/mohe-ai-supervisor.service" 'mohe-ai-supervisor.service'
systemctl daemon-reload
for ((i=1;i<=WORKER_COUNT;i++)); do systemctl enable --now "mohe-ai-execution-worker@$i.service"; done
for ((i=1;i<=EXPORT_WORKER_COUNT;i++)); do systemctl enable --now "mohe-ai-export-worker@$i.service"; done
systemctl enable --now mohe-ai-supervisor.service
for ((i=1;i<=WORKER_COUNT;i++)); do systemctl is-active --quiet "mohe-ai-execution-worker@$i.service"; done
for ((i=1;i<=EXPORT_WORKER_COUNT;i++)); do systemctl is-active --quiet "mohe-ai-export-worker@$i.service"; done
systemctl is-active --quiet mohe-ai-supervisor.service
echo "MOHE_AI_SYSTEMD_READY workers=$WORKER_COUNT export_workers=$EXPORT_WORKER_COUNT app_root=$APP_ROOT"

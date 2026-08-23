#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPT="$ROOT/scripts/rh-swoole-init.sh"

test -x "$SCRIPT" || { echo "script must be executable: $SCRIPT" >&2; exit 1; }
bash -n "$SCRIPT"
grep -q 'php think swoole' "$SCRIPT"
grep -q 'flock -n' "$SCRIPT"
grep -q 'readlink -f "/proc/\$pid/cwd"' "$SCRIPT"
grep -q 'ps -eo pid= -o args=' "$SCRIPT"
grep -q 'manager process process for ThinkPHP' "$SCRIPT"
grep -q 'ss -ltn' "$SCRIPT"
grep -q 'think swoole 9>&-' "$SCRIPT"
grep -q 'memory_limit=\$PHP_MEMORY_LIMIT' "$SCRIPT"
grep -q 'RH_SWOOLE_PHP_MEMORY_LIMIT' "$SCRIPT"
grep -q 'Swoole already has target processes' "$SCRIPT"
grep -q 'Swoole port .*still occupied after stop' "$SCRIPT"
grep -q 'proc_starttime' "$SCRIPT"
grep -q 'is_alive()' "$SCRIPT"
if grep -q 'think swoole stop' "$SCRIPT"; then
  echo 'obsolete think swoole stop invocation is forbidden' >&2
  exit 1
fi
echo 'RH_SWOOLE_INIT_CONTRACT_OK'

#!/usr/bin/env bash
set -euo pipefail

# 瑞昊生产实例的单实例 Swoole 管理脚本。
# ThinkPHP 的 `php think swoole` 只有启动命令，没有 `stop` 子命令；
# 旧 init 脚本把 stop 当成合法子命令，失败后仍继续启动，最终会叠加多个 manager。

APP_ROOT="${RH_SWOOLE_APP_ROOT:-/www/wwwroot/rh.cc3798.com}"
PHP_BIN="${RH_SWOOLE_PHP_BIN:-/www/server/php/74/bin/php}"
PORT="${RH_SWOOLE_PORT:-20800}"
LOG_FILE="${RH_SWOOLE_LOG_FILE:-/www/wwwlogs/ruihao_swoole.log}"
PID_FILE="${RH_SWOOLE_PID_FILE:-/run/ruihao_swoole.pid}"
LOCK_FILE="${RH_SWOOLE_LOCK_FILE:-/run/lock/ruihao_swoole.lock}"
START_TIMEOUT="${RH_SWOOLE_START_TIMEOUT:-30}"
APP_ROOT_REAL="$(readlink -f "$APP_ROOT" 2>/dev/null || printf '%s' "$APP_ROOT")"

proc_starttime() {
  local pid="$1"
  awk -F') ' '{split($2, fields, " "); print fields[20]}' "/proc/$pid/stat" 2>/dev/null || true
}

proc_args() {
  ps -p "$1" -o args= 2>/dev/null || true
}

target_process_records() {
  local pid args cwd start
  while read -r pid args; do
    [[ "$pid" =~ ^[0-9]+$ ]] || continue
    cwd="$(readlink -f "/proc/$pid/cwd" 2>/dev/null || true)"
    [[ "$cwd" == "$APP_ROOT_REAL" ]] || continue
    [[ "$args" == "swoole:"* && "$args" == *"process for ThinkPHP"* ]] || continue
    start="$(proc_starttime "$pid")"
    [[ "$start" =~ ^[0-9]+$ ]] || continue
    printf '%s|%s\n' "$pid" "$start"
  done < <(ps -eo pid= -o args=)
}

record_is_current_target() {
  local record="$1" pid start args cwd
  IFS='|' read -r pid start <<< "$record"
  [[ "$pid" =~ ^[0-9]+$ && "$start" =~ ^[0-9]+$ ]] || return 1
  [[ "$(proc_starttime "$pid")" == "$start" ]] || return 1
  cwd="$(readlink -f "/proc/$pid/cwd" 2>/dev/null || true)"
  [[ "$cwd" == "$APP_ROOT_REAL" ]] || return 1
  args="$(proc_args "$pid")"
  [[ "$args" == "swoole:"* && "$args" == *"process for ThinkPHP"* ]]
}

is_alive() {
  kill -0 "$1" 2>/dev/null
}

port_is_listening() {
  command -v ss >/dev/null 2>&1 || {
    echo "ss command is required to verify Swoole port state" >&2
    return 2
  }
  ss -ltnH | awk -v port=":$PORT" '$4 ~ port"$" { found=1 } END { exit !found }'
}

manager_pids() {
  local pid args
  while IFS='|' read -r pid _; do
    args="$(proc_args "$pid")"
    [[ "$args" == *"manager process process for ThinkPHP"* ]] && printf '%s\n' "$pid"
  done < <(target_process_records)
}

stop_managers() {
  local -a records=()
  local record pid deadline
  mapfile -t records < <(target_process_records | sort -t'|' -k1,1n -u)
  ((${#records[@]})) || { rm -f "$PID_FILE"; return 0; }

  for record in "${records[@]}"; do
    IFS='|' read -r pid _ <<< "$record"
    record_is_current_target "$record" && kill -TERM "$pid" 2>/dev/null || true
  done
  deadline=$((SECONDS + 15))
  while ((SECONDS < deadline)); do
    local alive=0
    for record in "${records[@]}"; do
      record_is_current_target "$record" && alive=1
    done
    ((alive == 0)) && break
    sleep 1
  done
  for record in "${records[@]}"; do
    IFS='|' read -r pid _ <<< "$record"
    record_is_current_target "$record" && kill -KILL "$pid" 2>/dev/null || true
  done
  if port_is_listening; then
    echo "Swoole port $PORT is still occupied after stop" >&2
    return 1
  fi
  rm -f "$PID_FILE"
}

start_manager() {
  [[ -x "$PHP_BIN" ]] || { echo "PHP binary not executable: $PHP_BIN" >&2; return 1; }
  [[ -d "$APP_ROOT" ]] || { echo "Application directory not found: $APP_ROOT" >&2; return 1; }
  command -v ss >/dev/null 2>&1 || { echo "ss command is required to start Swoole safely" >&2; return 1; }
  local -a existing=()
  mapfile -t existing < <(target_process_records | sort -t'|' -k1,1n -u)
  if ((${#existing[@]})); then
    echo "Swoole already has target processes; use restart instead: ${existing[*]}" >&2
    return 1
  fi
  if port_is_listening; then
    echo "Swoole port $PORT is occupied by an unknown process; refusing to start" >&2
    return 1
  fi
  mkdir -p "$(dirname "$LOCK_FILE")" "$(dirname "$LOG_FILE")"
  (
    cd "$APP_ROOT"
    # Do not let the long-lived manager inherit the orchestration lock fd;
    # otherwise every later status/stop call would see the lock as occupied.
    nohup "$PHP_BIN" think swoole 9>&- >>"$LOG_FILE" 2>&1 &
    echo "$!" >"$PID_FILE"
  )
  local deadline=$((SECONDS + START_TIMEOUT))
  while ((SECONDS < deadline)); do
    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [[ "$pid" =~ ^[0-9]+$ ]] && is_alive "$pid"; then
      if port_is_listening; then
        echo "ruihao swoole started pid=$pid port=$PORT"
        return 0
      fi
    fi
    sleep 1
  done
  echo "Swoole did not listen on port $PORT within ${START_TIMEOUT}s" >&2
  stop_managers
  return 1
}

main() {
  local action="${1:-}"
  mkdir -p "$(dirname "$LOCK_FILE")"
  exec 9>"$LOCK_FILE"
  flock -n 9 || { echo "another ruihao_swoole operation is in progress" >&2; return 1; }
  case "$action" in
    start) start_manager ;;
    stop) stop_managers ;;
    restart) stop_managers; start_manager ;;
    status)
      local -a pids=()
      mapfile -t pids < <(manager_pids | sort -n -u)
      printf 'manager_count=%s\n' "${#pids[@]}"
      ((${#pids[@]})) && printf 'manager_pids=%s\n' "$(IFS=,; echo "${pids[*]}")"
      ;;
    *) echo "Usage: $0 {start|stop|restart|status}" >&2; return 2 ;;
  esac
}

main "$@"

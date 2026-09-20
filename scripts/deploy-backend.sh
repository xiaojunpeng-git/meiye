#!/usr/bin/env bash
# 同步后端 PHP（app/route/mohe），保留服务器 .env / runtime / public / vendor。
# rh 在同步后还会成组重启并校验 Swoole、AI Worker 和 Supervisor。
# 用法：
#   bash 美容源码/scripts/deploy-backend.sh 008
#   bash 美容源码/scripts/deploy-backend.sh rh --allow-rh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/后端代码"
SITE_KEY="${1:-}"
ALLOW_RH="${2:-}"
SSH_HOST="${MOHE_SSH_HOST:-aliyun-ecs}"

if [ -z "$SITE_KEY" ]; then
  echo "必须点名站点：008 | 007 | 012 | rh"
  exit 2
fi

case "$SITE_KEY" in
  008) REMOTE="/www/wwwroot/008.cc3798.com" ;;
  007) REMOTE="/www/wwwroot/007.cc3798.com" ;;
  012) REMOTE="/www/wwwroot/012.cc3798.com" ;;
  rh)
    if [ "$ALLOW_RH" != "--allow-rh" ]; then
      echo "rh 为生产。确认后加：--allow-rh"
      exit 3
    fi
    REMOTE="/www/wwwroot/rh.cc3798.com"
    # Use the project SSH alias so the configured identity and host policy are
    # applied consistently; callers can still override it for controlled CI.
    SSH_HOST="${MOHE_RH_SSH_HOST:-rh-server}"
    ;;
  *) echo "未知站点 $SITE_KEY"; exit 2 ;;
esac

echo "→ rsync 后端 app/route/mohe 与魔核 AI 配置 → $REMOTE （不覆盖 .env/runtime/public/vendor）"
rsync -az \
  --exclude 'runtime/' \
  "$SRC/app/" "$SSH_HOST:$REMOTE/app/"
# The AI namespace is one source-owned unit and contains no instance data.
# Mirror it exactly so a source deletion cannot leave an executable-looking
# legacy planner or validator on a customer server after an additive deploy.
rsync -az --delete "$SRC/app/services/ai/" "$SSH_HOST:$REMOTE/app/services/ai/"
rsync -az "$SRC/route/" "$SSH_HOST:$REMOTE/route/"
rsync -az "$SRC/mohe/" "$SSH_HOST:$REMOTE/mohe/"
# Deploy only the two source-controlled AI configuration files.  In
# particular, never rsync the whole config directory because .env and
# instance-specific connection settings must remain on the target instance.
rsync -az "$SRC/config/console.php" "$SSH_HOST:$REMOTE/config/console.php"
rsync -az "$SRC/config/mohe_ai.php" "$SSH_HOST:$REMOTE/config/mohe_ai.php"
# Clear cached PHP metadata after every source sync. Non-RH instances retain
# their existing per-instance restart procedure because their process managers
# are not uniform.
ssh "$SSH_HOST" "rm -rf '$REMOTE/runtime/cache/'* 2>/dev/null || true; echo CACHE_CLEARED"

if [ "$SITE_KEY" = "rh" ]; then
  echo "→ 同批重启瑞昊 Swoole、魔核 AI Worker 与 Supervisor"
  # A source sync is not a completed RH deployment while long-lived processes
  # still hold different registry versions. Discover every resident worker
  # instance, validate its systemd unit name, then restart all three runtime
  # roles from the just-synchronised source before reporting success.
  AI_WORKERS=$(ssh "$SSH_HOST" "systemctl list-units --all --plain --no-legend 'mohe-ai-execution-worker@*.service' | awk '{print \$1}'")
  [ -n "$AI_WORKERS" ] || { echo "未发现瑞昊魔核 AI execution worker，停止发布" >&2; exit 4; }
  for AI_UNIT in $AI_WORKERS; do
    case "$AI_UNIT" in
      mohe-ai-execution-worker@*.service) ;;
      *) echo "发现非法 AI worker unit：$AI_UNIT" >&2; exit 4 ;;
    esac
  done
  # shellcheck disable=SC2086
  ssh "$SSH_HOST" "set -e; /etc/init.d/ruihao_swoole restart; systemctl restart $AI_WORKERS mohe-ai-supervisor.service; systemctl is-active --quiet $AI_WORKERS mohe-ai-supervisor.service; /etc/init.d/ruihao_swoole status; echo RH_AI_RUNTIME_READY"
fi

echo "DEPLOY_BACKEND_OK $SITE_KEY"
if [ "$SITE_KEY" != "rh" ]; then
  echo "若改了业务 PHP：请按该站 Swoole 脚本重启（008/007/012 按各自现网进程）。"
fi

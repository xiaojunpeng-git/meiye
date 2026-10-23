#!/bin/sh
# 只停止成长专属服务；不停止共享数据库、平台热更新或其他业务调试服务。
set -eu
for growth_container in mohe-growth-local-worker mohe-growth-local-web mohe-growth-local-app mohe-growth-local-redis; do
  if docker inspect "$growth_container" >/dev/null 2>&1; then
    docker stop "$growth_container" >/dev/null
  fi
done

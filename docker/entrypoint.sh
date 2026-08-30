#!/bin/sh
set -e

cd /var/www/html

mkdir -p runtime/log runtime/cache runtime/temp runtime/session
chmod -R 777 runtime 2>/dev/null || true

if [ -f .env.docker ] && [ ! -f .env.local-dev ] && [ ! -f .env ]; then
  cp -f .env.docker .env
fi

exec php think swoole

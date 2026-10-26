#!/bin/sh
set -e

cd /var/www/html

mkdir -p runtime/log runtime/cache runtime/temp runtime/session
# Legacy writable cache paths must not make AI keys/evidence world-readable.
chmod 755 runtime
chmod -R 777 runtime/log runtime/cache runtime/temp runtime/session
if [ -L runtime/private ]; then
  echo 'PRIVATE_RUNTIME_SYMLINK_REJECTED' >&2
  exit 1
fi
if [ -d runtime/private ]; then
  find runtime/private -type d -exec chmod 700 {} \;
  find runtime/private -type f -exec chmod 600 {} \;
fi

if [ -f .env.docker ] && [ ! -f .env.local-dev ] && [ ! -f .env ]; then
  cp -f .env.docker .env
fi

exec php think swoole

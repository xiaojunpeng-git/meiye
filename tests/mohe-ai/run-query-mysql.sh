#!/usr/bin/env bash
set -euo pipefail
# Task-owned disposable DB only. Never reads .env, starts the app or uses an existing DB.
test_dir="$(cd "$(dirname "$0")" && pwd)"
test_container="mohe-ai-query-fixture-$$-$(openssl rand -hex 4)"
export MOHE_QUERY_TEST_PASSWORD="$(openssl rand -hex 24)"
export MOHE_QUERY_TEST_DISPOSABLE=yes
if [[ "${1:-}" == '--scale' ]]; then export MOHE_QUERY_TEST_SCALE=yes; fi
cleanup_fixture() {
  docker rm -fv "$test_container" >/dev/null 2>&1 || true
  unset MOHE_QUERY_TEST_PASSWORD MOHE_QUERY_TEST_PORT MOHE_QUERY_TEST_DISPOSABLE
}
trap cleanup_fixture EXIT INT TERM
docker run -d --user mysql --name "$test_container" --label mohe.task=ai-query-fixture \
  -p 127.0.0.1::3306 -e MYSQL_ROOT_PASSWORD="$MOHE_QUERY_TEST_PASSWORD" \
  -e MYSQL_DATABASE=mohe_query_fixture -e MYSQL_ROOT_HOST=% \
  docker.m.daocloud.io/library/mysql:5.7 --innodb-buffer-pool-size=64M >/dev/null
export MOHE_QUERY_TEST_PORT="$(docker port "$test_container" 3306/tcp | sed 's/.*://')"
ready=no
for ((attempt=0;attempt<120;attempt++)); do
  if [[ "$(docker inspect --format '{{.State.Running}}' "$test_container")" != true ]]; then echo 'Disposable MySQL process exited before readiness' >&2; exit 1; fi
  if docker exec "$test_container" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping --protocol=tcp -h127.0.0.1 -uroot --silent' >/dev/null 2>&1; then ready=yes; break; fi
  sleep 1
done
if [[ "$ready" != yes ]]; then echo 'Disposable MySQL did not become ready' >&2; exit 1; fi
php "$test_dir/query-mysql.php"
php "$test_dir/state-mysql-concurrency.php"
php "$test_dir/management-mysql.php"

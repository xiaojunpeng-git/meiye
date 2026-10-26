#!/usr/bin/env bash
set -euo pipefail
project_dir="$(cd "$(dirname "$0")/../.." && pwd)"
test_image='docker.m.daocloud.io/library/redis:7-alpine'
test_name="mohe-ai-queue-fixture-$$-$(date +%s)"
test_container=''
if [[ -n "${DOCKER_HOST:-}" ]]; then
  echo 'Refusing overridden Docker host; only verified local Docker is permitted.' >&2
  exit 1
fi
docker_endpoint="$(docker context inspect --format '{{.Endpoints.docker.Host}}')"
[[ "$docker_endpoint" == unix://* ]] || { echo 'Local Unix Docker endpoint required.' >&2; exit 1; }
docker image inspect "$test_image" >/dev/null
cleanup_fixture() {
  if [[ -n "$test_container" ]]; then docker rm -f "$test_container" >/dev/null; fi
}
trap cleanup_fixture EXIT
# New container, no volumes, no application network/config, random localhost-only port.
test_container="$(docker run -d --name "$test_name" -p 127.0.0.1::6379 "$test_image" redis-server --save '' --appendonly no)"
for attempt in {1..30}; do
  if docker exec "$test_container" redis-cli ping 2>/dev/null | rg -q '^PONG'; then break; fi
  sleep 0.1
done
test_address="$(docker port "$test_container" 6379/tcp)"
[[ "$test_address" == 127.0.0.1:* ]] || { echo 'Unexpected Redis binding.' >&2; exit 1; }
AI_TEST_REDIS_PORT="${test_address##*:}" php "$project_dir/tests/mohe-ai/export-queue-contract.php"

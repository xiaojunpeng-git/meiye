#!/usr/bin/env bash
set -euo pipefail
project_dir="$(cd "$(dirname "$0")/../.." && pwd)"
image='docker.m.daocloud.io/library/redis:7-alpine'
name="mohe-ai-execution-queue-$$_$(date +%s)"
container=''
[[ -z "${DOCKER_HOST:-}" ]] || { echo 'Refusing overridden Docker host.' >&2; exit 1; }
[[ "$(docker context inspect --format '{{.Endpoints.docker.Host}}')" == unix://* ]] || { echo 'Local Unix Docker required.' >&2; exit 1; }
docker image inspect "$image" >/dev/null
cleanup(){ [[ -z "$container" ]] || docker rm -f "$container" >/dev/null; }
trap cleanup EXIT
container="$(docker run -d --name "$name" -p 127.0.0.1::6379 "$image" redis-server --save '' --appendonly no)"
for _ in {1..30}; do docker exec "$container" redis-cli ping 2>/dev/null | rg -q '^PONG' && break; sleep .1; done
port="$(docker port "$container" 6379/tcp)"; [[ "$port" == 127.0.0.1:* ]] || { echo 'Unexpected Redis binding.' >&2; exit 1; }
AI_TEST_REDIS_PORT="${port##*:}" php "$project_dir/tests/mohe-ai/async-execution-queue-contract.php"

#!/usr/bin/env bash
# Isolated migration proof only: no project database, volume or credentials.
set -euo pipefail

root_dir="$(cd "$(dirname "$0")/../.." && pwd)"
migration="$root_dir/后端代码/database/upgrades/2026-09-15-魔核AI可靠异步执行/01-执行消费者与耗时.sql"
container_base="mohe-ai-execution-migration-$$"
container=""
image="docker.m.daocloud.io/library/mysql:5.7"

cleanup() { docker rm -f "$container" >/dev/null 2>&1 || true; }
trap cleanup EXIT

mysql_query() {
  local sql="$1"
  for _ in $(seq 1 8); do
    if docker exec "$container" mysql --protocol=TCP -h127.0.0.1 -uroot -ptest-only-root-password -Nse "$sql"; then return 0; fi
    sleep 1
  done
  if docker inspect "$container" >/dev/null 2>&1; then docker logs "$container" >&2 || true; fi
  return 1
}

ready=0
for attempt in $(seq 1 3); do
  container="${container_base}-${attempt}"
  docker run -d --rm --name "$container" -e MYSQL_ROOT_PASSWORD=test-only-root-password "$image" >/dev/null
  for _ in $(seq 1 60); do
    # mysqladmin ping can return success before authentication is ready; an
    # authenticated scalar query is the actual readiness condition.
    if docker exec "$container" mysql --protocol=TCP -h127.0.0.1 -uroot -ptest-only-root-password -Nse 'SELECT 1' >/dev/null 2>&1; then ready=1; break; fi
    if [[ "$(docker inspect -f '{{.State.Running}}' "$container" 2>/dev/null || true)" != "true" ]]; then break; fi
    sleep 1
  done
  [[ "$ready" == "1" ]] && break
  if docker inspect "$container" >/dev/null 2>&1; then docker logs "$container" >&2 || true; fi
  docker rm -f "$container" >/dev/null 2>&1 || true
  sleep 1
done
if [[ "$ready" != "1" ]]; then exit 1; fi
mysql_query 'CREATE DATABASE mohe_ai_contract CHARACTER SET utf8mb4;'
loaded=0
for _ in $(seq 1 8); do
  if docker exec -i "$container" mysql --protocol=TCP -h127.0.0.1 -uroot -ptest-only-root-password mohe_ai_contract < "$migration" >/dev/null; then loaded=1; break; fi
  sleep 1
done
if [[ "$loaded" != "1" ]]; then if docker inspect "$container" >/dev/null 2>&1; then docker logs "$container" >&2 || true; fi; exit 1; fi

table_type="$(mysql_query "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='mohe_ai_contract' AND TABLE_NAME='eb_mohe_ai_execution_worker'")"
indexes="$(mysql_query "SELECT GROUP_CONCAT(DISTINCT INDEX_NAME ORDER BY INDEX_NAME SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='mohe_ai_contract' AND TABLE_NAME='eb_mohe_ai_execution_worker'")"
columns="$(mysql_query "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='mohe_ai_contract' AND TABLE_NAME='eb_mohe_ai_execution_worker'")"

[[ "$table_type" == "InnoDB" ]]
[[ "$indexes" == *"PRIMARY"* && "$indexes" == *"liveness"* && "$indexes" == *"expiry"* ]]
[[ "$columns" == "instance_id,worker_id,host_name,process_id,heartbeat_at,expires_at" ]]
echo 'PASS MySQL 5.7 isolated AI execution-worker migration contract'

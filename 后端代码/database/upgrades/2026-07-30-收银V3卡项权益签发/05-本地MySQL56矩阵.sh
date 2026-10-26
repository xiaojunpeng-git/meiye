#!/usr/bin/env bash
set -euo pipefail

sql_file="$(cd "$(dirname "$0")" && pwd)/02-正式升级.sql"
mysql --protocol=TCP -h "${MYSQL_HOST:-127.0.0.1}" -P "${MYSQL_PORT:-3306}" \
  -u "${MYSQL_USER:-root}" "${MYSQL_DATABASE:?MYSQL_DATABASE is required}" < "$sql_file"

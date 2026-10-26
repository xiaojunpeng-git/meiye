#!/usr/bin/env bash
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
exec bash "$REPO/后端代码/database/upgrades/2026-07-28-收银V3统一事件与Outbox/05-本地MySQL56矩阵.sh"

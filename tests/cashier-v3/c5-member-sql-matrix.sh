#!/usr/bin/env bash
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
exec bash "$REPO/后端代码/database/upgrades/2026-07-28-C5会员建档一致性/05-本地MySQL56矩阵.sh"

#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"

php -l "$repo_root/后端代码/app/services/activity/combination/MemberCombinationRecordServices.php"
php -l "$repo_root/后端代码/app/controller/api/v1/activity/StoreCombination.php"
php -l "$repo_root/后端代码/route/api.php"
node "$repo_root/tests/member-combination-records/contract.mjs"


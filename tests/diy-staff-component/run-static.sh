#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

node "${SCRIPT_DIR}/diy-staff-component-contract.mjs"
php -l "${PROJECT_ROOT}/后端代码/app/services/store/SystemStoreStaffServices.php"
php -l "${PROJECT_ROOT}/后端代码/app/model/store/SystemStoreStaff.php"
php -l "${PROJECT_ROOT}/后端代码/app/controller/api/v1/store/Store.php"
php -l "${PROJECT_ROOT}/后端代码/app/controller/api/v1/reservation/ReservationStaff.php"
php -l "${PROJECT_ROOT}/后端代码/app/controller/admin/v1/merchant/SystemStoreStaff.php"
php -l "${PROJECT_ROOT}/后端代码/route/api.php"

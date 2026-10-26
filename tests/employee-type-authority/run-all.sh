#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
MIGRATION_DIR="$ROOT_DIR/后端代码/database/upgrades/2026-07-29-员工人员类型权威源"
PHP_IMAGE="${ETA_PHP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"
START_MANIFEST="$(mktemp /tmp/employee-type-input-start.XXXXXX)"
END_MANIFEST="$(mktemp /tmp/employee-type-input-end.XXXXXX)"

cleanup_manifests() {
  rm -f "$START_MANIFEST" "$END_MANIFEST"
}
trap cleanup_manifests EXIT INT TERM

collect_input_manifest() {
  (
    cd "$ROOT_DIR"
    {
      printf '%s\n' \
        '前端代码/admin/src/pages/setting/staff/add.vue' \
        '后端代码/app/controller/admin/v1/merchant/SystemStoreStaff.php' \
        '后端代码/app/services/employee/EmployeePersonCompleteWriteServices.php' \
        '后端代码/app/services/employee/EmployeeTypeAuthorityServices.php' \
        '后端代码/app/services/organization/OrganizationManageServices.php' \
        '后端代码/app/services/organization/OrganizationScopeService.php' \
        '后端代码/app/services/organization/OrganizationStrictIdempotencyServices.php' \
        '后端代码/app/services/organization/OrganizationWorkspaceWriteGate.php' \
        '后端代码/app/services/system/SystemMenusServices.php' \
        '后端代码/app/services/system/SystemRoleServices.php' \
        '后端代码/app/services/cashier/v3/CashierV3CommandException.php' \
        '后端代码/app/services/cashier/v3/CashierV3DataScopeContext.php' \
        '后端代码/app/services/cashier/v3/CashierV3ResultCode.php' \
        '后端代码/app/services/cashier/v3/CashierV3TransactionGuard.php' \
        '后端代码/app/services/cashier/v3/checkout/provider/CashierV3EmployeeTypeAuthority.php' \
        '后端代码/app/services/cashier/v3/checkout/provider/CashierV3EntitlementProviderContractException.php' \
        '后端代码/app/services/cashier/v3/checkout/provider/CashierV3EntitlementProviderContracts.php' \
        '后端代码/app/services/cashier/v3/checkout/provider/CashierV3EntitlementProviderDataScope.php' \
        '后端代码/app/services/cashier/v3/checkout/provider/CashierV3EntitlementProviderSchemaProbe.php' \
        '后端代码/app/services/cashier/v3/checkout/provider/CashierV3StaffTypeAuthority.php' \
        '后端代码/app/dao/system/SystemMenusDao.php' \
        '后端代码/app/dao/system/SystemRoleDao.php' \
        '后端代码/app/model/system/SystemMenus.php' \
        '后端代码/app/model/system/SystemRole.php' \
        'tests/cashier-v3/lib/boot-env.php' \
        'tests/cashier-v3/lib/_lib.php'
      find '后端代码/database/upgrades/2026-07-29-员工人员类型权威源' -type f
      find 'tests/employee-type-authority' -type f -not -path '*/_tmp/*'
    } | LC_ALL=C sort -u | while IFS= read -r file; do
      test -f "$file"
      shasum -a 256 "$file"
    done
  )
}

collect_input_manifest > "$START_MANIFEST"

node "$ROOT_DIR/tests/employee-type-authority/js/frontend-contract.mjs"
(cd "$MIGRATION_DIR" && shasum -a 256 -c SHA256SUMS.txt)

docker run --rm \
  -v "$ROOT_DIR/后端代码:/var/www/html:ro" \
  -v "$ROOT_DIR/后端代码:/backend:ro" \
  -v "$ROOT_DIR/tests:/tests:ro" \
  --entrypoint sh "$PHP_IMAGE" -lc '
    set -e
    php -l /var/www/html/app/services/employee/EmployeeTypeAuthorityServices.php
    php -l /var/www/html/app/services/employee/EmployeePersonCompleteWriteServices.php
    php -l /var/www/html/app/controller/admin/v1/merchant/SystemStoreStaff.php
    php -l /var/www/html/app/services/cashier/v3/checkout/provider/CashierV3EmployeeTypeAuthority.php
    find /tests/employee-type-authority/php -type f -name "*.php" -exec php -l {} \;
    php /tests/employee-type-authority/php/contract.php
    php /tests/employee-type-authority/php/migration-contract.php
  '

bash "$ROOT_DIR/tests/employee-type-authority/mysql56-matrix.sh"

collect_input_manifest > "$END_MANIFEST"
diff -u "$START_MANIFEST" "$END_MANIFEST"
INPUT_MANIFEST_SHA="$(shasum -a 256 "$END_MANIFEST" | awk '{print $1}')"
echo "EMPLOYEE_TYPE_INPUT_MANIFEST_START_END_SHA=$INPUT_MANIFEST_SHA"

echo "EMPLOYEE_TYPE_AUTHORITY_FOCUSED_SUITE=PASS"

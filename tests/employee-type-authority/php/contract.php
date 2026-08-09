<?php

require_once '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\checkout\provider\CashierV3EmployeeTypeAuthority;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementProviderContracts;
use app\services\cashier\v3\checkout\provider\CashierV3StaffTypeAuthority;
use app\services\employee\EmployeeTypeAuthorityServices;

$passed = 0;
$failed = 0;

function etaAssert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
}

$root = '/var/www/html';
$personSource = (string)file_get_contents($root . '/app/services/employee/EmployeePersonCompleteWriteServices.php');
$controllerSource = (string)file_get_contents($root . '/app/controller/admin/v1/merchant/SystemStoreStaff.php');
$typeAuthoritySource = (string)file_get_contents($root . '/app/services/employee/EmployeeTypeAuthorityServices.php');
$bootstrapSource = (string)file_get_contents($root . '/app/services/cashier/v3/bootstrap/CashierV3Bootstrap.php');
$manifestSource = (string)file_get_contents($root . '/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
$routeSource = (string)file_get_contents($root . '/route/cashier-v3.php');

etaAssert(
    'employee type enum is exact and stable',
    EmployeeTypeAuthorityServices::typeCodes() === ['internal', 'partner', 'outsourced']
        && EmployeeTypeAuthorityServices::AUTH_MANAGE === 'setting-staff-employment-type'
        && EmployeeTypeAuthorityServices::ACTION === 'employee_employment_type_save'
);
etaAssert(
    'C2 adapter implements frozen authority interface',
    is_subclass_of(CashierV3EmployeeTypeAuthority::class, CashierV3StaffTypeAuthority::class)
        && (new CashierV3EmployeeTypeAuthority())->contractVersion()
            === CashierV3EntitlementProviderContracts::STAFF_TYPE_AUTHORITY
);
etaAssert(
    'authority exposes versioned transactional and idempotent entry points',
    method_exists(EmployeeTypeAuthorityServices::class, 'saveType')
        && method_exists(EmployeeTypeAuthorityServices::class, 'saveTypeInTx')
        && method_exists(EmployeeTypeAuthorityServices::class, 'readSnapshot')
        && method_exists(EmployeeTypeAuthorityServices::class, 'assertManagePermission')
        && method_exists(EmployeeTypeAuthorityServices::class, 'assertStoreManagePermission')
        && method_exists(EmployeeTypeAuthorityServices::class, 'canManagePermission')
);
etaAssert(
    'person complete idempotency payload freezes type and expected version',
    strpos($personSource, "'employment_type_present'") !== false
        && strpos($personSource, "'employment_type_code'") !== false
        && strpos($personSource, "'employment_type_version'") !== false
);
etaAssert(
    'store person complete only reads and writes type through current-store assignment checks',
    strpos($personSource, "readSnapshot(\$employeeId, 'store', \$staffId, \$storeId)") !== false
        && strpos($personSource, "\$source,\n                \$staffId,\n                \$storeId") !== false
        && strpos($typeAuthoritySource, 'assertStoreManagePermission') !== false
);
etaAssert(
    'employment type transaction guard supports dynamic database proxies',
    strpos($typeAuthoritySource, '$connection->getPdo()') !== false
        && strpos($typeAuthoritySource, "method_exists(\$connection, 'getPdo')") === false
);
etaAssert(
    'HQ controller preserves field presence and applies server read permission',
    strpos($controllerSource, "foreach (['employment_type_code', 'employment_type_version'] as \$k)") !== false
        && strpos($controllerSource, "array_key_exists(\$k, \$raw)") !== false
        && strpos($controllerSource, 'canManagePermission') !== false
        && strpos($controllerSource, "'hq', \$canReadEmploymentType") !== false
);
etaAssert(
    'unchanged internal account does not become an account write command',
    strpos($controllerSource, "array_key_exists('account', \$raw)") !== false
        && strpos($controllerSource, "'account' => \$account") !== false
);
etaAssert(
    'Gateway and action registries remain inactive',
    strpos($bootstrapSource, 'CashierV3EmployeeTypeAuthority') === false
        && strpos($manifestSource, 'CashierV3EmployeeTypeAuthority') === false
        && strpos($routeSource, 'CashierV3EmployeeTypeAuthority') === false
);
etaAssert(
    'adapter never reads legacy share or partner flags',
    strpos((string)file_get_contents(
        $root . '/app/services/cashier/v3/checkout/provider/CashierV3EmployeeTypeAuthority.php'
    ), 'is_fencheng') === false
        && strpos((string)file_get_contents(
            $root . '/app/services/cashier/v3/checkout/provider/CashierV3EmployeeTypeAuthority.php'
        ), 'is_hezuofang') === false
);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
if ($failed > 0) {
    exit(1);
}
echo "EMPLOYEE_TYPE_AUTHORITY_CONTRACT=PASS\n";

<?php
declare(strict_types=1);

/**
 * Support craftsman identity contract.
 *
 * Organization/department staff use a virtual staff resource ID during
 * checkout, while immutable facts continue to use their real employee ID.
 * This test stays database-free and protects the authority-discovery bridge.
 */

$root = dirname(__DIR__, 3);
$identityPath = $root . '/后端代码/app/services/cashier/v3/CashierV3PersonnelIdentity.php';
$authorityPath = $root . '/后端代码/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php';
$profilePath = $root . '/后端代码/app/services/cashier/v3/checkout/provider/CashierV3StaffProfileProvider.php';

require_once $identityPath;

use app\services\cashier\v3\CashierV3PersonnelIdentity;

$passed = 0;
$failed = 0;

function supportCraftsmanAssert(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

$authority = is_file($authorityPath) ? (string)file_get_contents($authorityPath) : '';
$profile = is_file($profilePath) ? (string)file_get_contents($profilePath) : '';
$virtualStaffId = CashierV3PersonnelIdentity::organizationStaffId(490);

supportCraftsmanAssert(
    'organization support uses a stable virtual staff resource identity',
    CashierV3PersonnelIdentity::isOrganizationStaffId($virtualStaffId)
    && CashierV3PersonnelIdentity::employeeIdFromStaffId($virtualStaffId) === 490
);
supportCraftsmanAssert(
    'resource discovery forwards the selected personnel source',
    strpos($authority, "['personnelSource'] ?? 'store'") !== false
    && strpos($authority, '$this->staffDiscoveryVersion(') !== false
);
supportCraftsmanAssert(
    'organization support bypasses only the store-staff lookup',
    strpos($authority, "\$personnelSource === 'other'") !== false
    && strpos($authority, 'CashierV3PersonnelIdentity::isOrganizationStaffId($staffId)') !== false
    && strpos($authority, 'organizationStaffDiscoveryVersion') !== false
);
supportCraftsmanAssert(
    'organization staff profile is scoped to the checkout store and remains versioned',
    strpos($authority, "'storeId' => \$dataScope->forcedStoreId()") !== false
    && strpos($authority, "'personnelSource' => 'other'") !== false
    && strpos($authority, 'staffProfileExpectedVersion') !== false
    && strpos($profile, 'lockOrganizationProfile') !== false
);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);

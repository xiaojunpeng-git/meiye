<?php

require_once '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementActivationReadinessEvaluator;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementDebtGuardProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementOccupationProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementProviderContracts;
use app\services\cashier\v3\checkout\provider\CashierV3InventoryCompletionReadinessProbe;
use app\services\cashier\v3\checkout\provider\CashierV3ServiceOrderOccupationAuthority;
use app\services\cashier\v3\checkout\provider\CashierV3StaffProfileProvider;
use app\services\cashier\v3\checkout\provider\CashierV3StaffTypeAuthority;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;

$passed = 0;
$failed = 0;

function c2pAssert(string $name, bool $condition, string $detail = ''): void
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

c2pAssert(
    'provider contracts are frozen',
    CashierV3EntitlementProviderContracts::DEBT_GUARD === 'c2-entitlement-debt-guard-provider-v1'
        && CashierV3EntitlementProviderContracts::DEBT_GUARD_WRITER === 'c2-entitlement-debt-guard-writer-v1'
        && CashierV3EntitlementProviderContracts::STAFF_PROFILE === 'c2-entitlement-staff-profile-provider-v1'
        && CashierV3EntitlementProviderContracts::STAFF_TYPE_AUTHORITY === 'cashier-staff-type-authority-v1'
        && CashierV3EntitlementProviderContracts::OCCUPATION === 'c2-entitlement-occupation-provider-v1'
        && CashierV3EntitlementProviderContracts::RESERVATION_OCCUPATION === 'legacy-store-reservation-occupation-v1'
        && CashierV3EntitlementProviderContracts::SERVICE_ORDER_OCCUPATION === 'c3-service-order-occupation-v1'
        && CashierV3EntitlementProviderContracts::INVENTORY_COMPLETION
            === 'inventory-entitlement-completion-provider-v1'
        && CashierV3EntitlementProviderContracts::INVENTORY_SHORTAGE_CURSOR_GATE
            === 'explicit_resource_plan_locked_v1'
        && CashierV3EntitlementProviderContracts::ACTIVATION_READINESS === 'c2-entitlement-activation-readiness-v1'
);
c2pAssert(
    'consumer contract remains exact C2 B1 contract',
    CashierV3EntitlementCompletionKernel::CONTRACT_VERSION === 'c2-entitlement-completion-v3'
        && CashierV3EntitlementCompletionKernel::INVENTORY_PROVIDER_CONTRACT_VERSION
            === 'inventory-entitlement-completion-provider-v1'
        && CashierV3EntitlementCompletionKernel::INVENTORY_SHORTAGE_CURSOR_GATE
            === 'explicit_resource_plan_locked_v1'
);
c2pAssert(
    'inventory readiness freezes explicit shortage cursor resource semantics',
    method_exists(CashierV3InventoryCompletionReadinessProbe::class, 'readinessStatus')
        && InventoryEntitlementCompletionContract::LOCK_ORDER_SHORTAGE_CURSOR === 56
        && InventoryEntitlementCompletionContract::SHORTAGE_CURSOR_GATE
            === CashierV3EntitlementProviderContracts::INVENTORY_SHORTAGE_CURSOR_GATE
        && InventoryEntitlementCompletionContract::shortageCursorResourceId(502, 901, 50)
            === '502:901:50'
);
$inventoryReadinessSource = (string)file_get_contents(
    (new ReflectionClass(CashierV3InventoryCompletionReadinessProbe::class))->getFileName()
);
c2pAssert(
    'inventory readiness requires cursor id version lock order and exact gate',
    strpos($inventoryReadinessSource, "'id', 'tenant_id', 'store_id'") !== false
        && strpos($inventoryReadinessSource, "'allocated_quantity_units', 'version'") !== false
        && strpos($inventoryReadinessSource, 'LOCK_ORDER_SHORTAGE_CURSOR === 56') !== false
        && strpos($inventoryReadinessSource, 'INVENTORY_SHORTAGE_CURSOR_GATE') !== false
);
c2pAssert(
    'debt guard implements DataScoped version provider',
    is_subclass_of(CashierV3EntitlementDebtGuardProvider::class, CashierV3DataScopedVersionProvider::class)
);
c2pAssert(
    'staff profile implements DataScoped version provider',
    is_subclass_of(CashierV3StaffProfileProvider::class, CashierV3DataScopedVersionProvider::class)
);
c2pAssert(
    'debt guard exposes idempotent mutation advance API',
    method_exists(CashierV3EntitlementDebtGuardProvider::class, 'lockOrCreateSnapshotInTx')
        && method_exists(CashierV3EntitlementDebtGuardProvider::class, 'advanceAfterDebtMutationInTx')
        && CashierV3EntitlementDebtGuardProvider::identityForOrder(77) === 'order:77'
);
c2pAssert(
    'staff provider requires explicit type authority interface',
    interface_exists(CashierV3StaffTypeAuthority::class)
        && method_exists(CashierV3StaffTypeAuthority::class, 'lockTypeSnapshotInTx')
        && CashierV3EntitlementProviderContracts::staffTypes() === ['internal', 'partner', 'outsourced']
);
c2pAssert(
    'occupation provider requires explicit C3 service authority interface',
    interface_exists(CashierV3ServiceOrderOccupationAuthority::class)
        && method_exists(CashierV3EntitlementOccupationProvider::class, 'lockSnapshotInTx')
        && method_exists(CashierV3ServiceOrderOccupationAuthority::class, 'lockContributorsInTx')
);
c2pAssert(
    'activation evaluator is read-only and has no registration method',
    method_exists(CashierV3EntitlementActivationReadinessEvaluator::class, 'evaluate')
        && !method_exists(CashierV3EntitlementActivationReadinessEvaluator::class, 'register')
        && !method_exists(CashierV3EntitlementActivationReadinessEvaluator::class, 'activate')
);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
if ($failed > 0) {
    exit(1);
}
echo "C2_ENTITLEMENT_PROVIDER_CONTRACT=PASS\n";

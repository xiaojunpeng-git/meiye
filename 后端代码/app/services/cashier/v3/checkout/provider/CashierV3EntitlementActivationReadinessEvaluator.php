<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;

/**
 * Read-only activation decision. It does not register an action, a provider,
 * a route, or any business writer.
 */
final class CashierV3EntitlementActivationReadinessEvaluator
{
    /** @var CashierV3EntitlementDebtGuardProvider */
    private $debtGuard;

    /** @var CashierV3StaffProfileProvider */
    private $staffProfile;

    /** @var CashierV3EntitlementOccupationProvider */
    private $occupation;

    /** @var CashierV3InventoryCompletionReadinessProbe */
    private $inventory;

    /** @var CashierV3LegacyDebtGuardIntegrationProbe */
    private $legacyDebtWriters;

    public function __construct(
        CashierV3EntitlementDebtGuardProvider $debtGuard,
        CashierV3StaffProfileProvider $staffProfile,
        CashierV3EntitlementOccupationProvider $occupation,
        CashierV3InventoryCompletionReadinessProbe $inventory,
        CashierV3LegacyDebtGuardIntegrationProbe $legacyDebtWriters
    ) {
        $this->debtGuard = $debtGuard;
        $this->staffProfile = $staffProfile;
        $this->occupation = $occupation;
        $this->inventory = $inventory;
        $this->legacyDebtWriters = $legacyDebtWriters;
    }

    public function evaluate(): array
    {
        $dependencies = [
            'debtGuard' => $this->debtGuard->readinessStatus(),
            'legacyDebtWriters' => $this->legacyDebtWriters->readinessStatus(),
            'staffProfile' => $this->staffProfile->readinessStatus(),
            'occupation' => $this->occupation->readinessStatus(),
            'inventory' => $this->inventory->readinessStatus(),
        ];
        $contractMatches = [
            'consumer' => CashierV3EntitlementCompletionKernel::CONTRACT_VERSION
                === 'c2-entitlement-completion-v3',
            'debtGuard' => ($dependencies['debtGuard']['contractVersion'] ?? '')
                === CashierV3EntitlementProviderContracts::DEBT_GUARD,
            'legacyDebtWriters' => ($dependencies['legacyDebtWriters']['contractVersion'] ?? '')
                === CashierV3EntitlementProviderContracts::DEBT_GUARD_WRITER,
            'staffProfile' => ($dependencies['staffProfile']['contractVersion'] ?? '')
                === CashierV3EntitlementProviderContracts::STAFF_PROFILE,
            'occupation' => ($dependencies['occupation']['contractVersion'] ?? '')
                === CashierV3EntitlementProviderContracts::OCCUPATION,
            'inventoryProvider' => ($dependencies['inventory']['providerContractVersion'] ?? '')
                === CashierV3EntitlementCompletionKernel::INVENTORY_PROVIDER_CONTRACT_VERSION,
            'inventoryCursorGate' => ($dependencies['inventory']['shortageCursorGate'] ?? '')
                === CashierV3EntitlementCompletionKernel::INVENTORY_SHORTAGE_CURSOR_GATE,
        ];
        $ready = !in_array(false, $contractMatches, true);
        foreach ($dependencies as $dependency) {
            $ready = $ready && !empty($dependency['ready']);
        }
        $blocking = [];
        foreach ($contractMatches as $name => $matches) {
            if (!$matches) {
                $blocking[] = 'contract_mismatch:' . $name;
            }
        }
        foreach ($dependencies as $name => $dependency) {
            if (empty($dependency['ready'])) {
                $blocking[] = 'dependency_not_ready:' . $name;
            }
        }
        return [
            'contractVersion' => CashierV3EntitlementProviderContracts::ACTIVATION_READINESS,
            'consumerContractVersion' => CashierV3EntitlementCompletionKernel::CONTRACT_VERSION,
            'ready' => $ready,
            'gatewayActivationPerformed' => false,
            'blockingReasons' => $blocking,
            'contractMatches' => $contractMatches,
            'dependencies' => $dependencies,
        ];
    }
}

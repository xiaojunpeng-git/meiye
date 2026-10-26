<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\product\inventory\completion\InventoryCompletionDataScope;
use app\services\product\inventory\completion\InventoryCompletionFactServices;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use app\services\product\inventory\completion\InventoryEntitlementCompletionDiscovery;
use app\services\product\inventory\completion\InventoryEntitlementCompletionProvider;

/**
 * Small integration boundary used by the checkout orchestrator.
 *
 * Discovery is read-only. Revalidation and snapshot reconstruction happen only
 * after Gateway has acquired the complete resource plan. Persistence delegates
 * to inventory's transaction-only fact writer.
 */
final class CashierV3InventoryCompletionGatewayAdapter
{
    public const CONTRACT_VERSION = 'cashier-v3-inventory-completion-gateway-adapter-v1';

    /** @var InventoryEntitlementCompletionDiscovery */
    private $discovery;

    /** @var InventoryEntitlementCompletionProvider */
    private $provider;

    /** @var InventoryCompletionFactServices */
    private $facts;

    /** @var CashierV3InventoryResourceVersionProvider */
    private $versions;

    public function __construct(
        ?InventoryEntitlementCompletionDiscovery $discovery = null,
        ?InventoryEntitlementCompletionProvider $provider = null,
        ?InventoryCompletionFactServices $facts = null,
        ?CashierV3InventoryResourceVersionProvider $versions = null
    ) {
        $this->discovery = $discovery ?: new InventoryEntitlementCompletionDiscovery();
        $this->provider = $provider ?: new InventoryEntitlementCompletionProvider();
        $this->facts = $facts ?: new InventoryCompletionFactServices();
        $this->versions = $versions ?: new CashierV3InventoryResourceVersionProvider();
    }

    /** Read-only phase used before Gateway acquires any business-resource lock. */
    public function discover(array $request, InventoryCompletionDataScope $scope): array
    {
        $pack = $this->discovery->discover($request, $scope);
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'providerContractVersion' => (string)$pack['contractVersion'],
            'resources' => (array)$pack['resources'],
        ];
    }

    /**
     * Rebuild the exact provider snapshot under Gateway's already-held locks.
     * InventoryEntitlementCompletionProvider asserts the outer transaction.
     */
    public function lockSnapshotAfterGatewayLocks(
        array $request,
        InventoryCompletionDataScope $scope
    ): array {
        return $this->provider->lockSnapshot($request, $scope);
    }

    /** Gateway discovery recheck callback payload. */
    public function revalidateAfterGatewayLocks(
        array $request,
        InventoryCompletionDataScope $scope
    ): array {
        $snapshot = $this->lockSnapshotAfterGatewayLocks($request, $scope);
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'providerContractVersion' => (string)$snapshot['contractVersion'],
            'resources' => InventoryEntitlementCompletionDiscovery::gatewayResourcesFromLockedSnapshot(
                $snapshot
            ),
        ];
    }

    /** Inventory facts remain inside the checkout orchestrator's transaction. */
    public function persistCompletionInTx(
        array $command,
        array $completionPlan,
        array $providerSnapshot,
        InventoryCompletionDataScope $scope
    ): array {
        if (empty($providerSnapshot['inventoryPersistenceRequired'])) {
            return ['contractVersion' => InventoryEntitlementCompletionContract::CONTRACT_VERSION, 'receiptId' => 0, 'receiptKey' => (string)($command['receiptKey'] ?? ''), 'replayed' => false, 'skipped' => true, 'actualCostCents' => 0, 'estimatedShortageCostCents' => 0, 'costComplete' => true, 'batchFactCount' => 0, 'shortageFactCount' => 0];
        }
        return $this->facts->persistCompletion(
            $command,
            $completionPlan,
            $providerSnapshot,
            $scope
        );
    }

    public function versionProvider(): CashierV3InventoryResourceVersionProvider
    {
        return $this->versions;
    }
}

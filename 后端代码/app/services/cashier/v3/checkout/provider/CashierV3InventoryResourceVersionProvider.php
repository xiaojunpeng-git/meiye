<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\product\inventory\completion\InventoryCompletionContractException;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use app\services\product\inventory\completion\InventoryEntitlementCompletionDiscovery;
use think\facade\Db;

/** Gateway version and scope adapter for inventory completion resources. */
final class CashierV3InventoryResourceVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const KINDS = [
        'inventory_policy',
        'inventory_recipe',
        'inventory_stock',
        'inventory_batch',
        'inventory_shortage_cursor',
    ];

    public function contractVersion(): string
    {
        return InventoryEntitlementCompletionDiscovery::RESOURCE_CONTRACT_VERSION;
    }

    public function resolveScopeWithDataScope(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        try {
            CashierV3EntitlementProviderDataScope::assertBase($operatorScope, $dataScope);
            CashierV3EntitlementProviderDataScope::assertStore(
                $dataScope->forcedStoreId(),
                $dataScope
            );
            $storeId = $this->resourceStoreId($kind, $resourceId, $dataScope, false);
            if ($this->tenantKind($kind)) {
                return CashierV3ResourceScope::of(
                    CashierV3ResourceScope::TYPE_TENANT,
                    $dataScope->tenantId()
                );
            }
            CashierV3EntitlementProviderDataScope::assertStore($storeId, $dataScope);
            return CashierV3ResourceScope::of(
                CashierV3ResourceScope::TYPE_STORE,
                (string)$storeId
            );
        } catch (InventoryCompletionContractException $exception) {
            return null;
        } catch (CashierV3EntitlementProviderContractException $exception) {
            return null;
        }
    }

    public function lockAndReadVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope
    ) {
        CashierV3TransactionGuard::assertInTransaction('inventoryResourceVersionLock:' . $kind);
        CashierV3EntitlementProviderDataScope::assertStore(
            $dataScope->forcedStoreId(),
            $dataScope
        );
        $storeId = $this->resourceStoreId($kind, $resourceId, $dataScope, true);
        if ($this->tenantKind($kind)) {
            if ($scope->type() !== CashierV3ResourceScope::TYPE_TENANT
                || !hash_equals($scope->id(), $dataScope->tenantId())) {
                return null;
            }
        } else {
            if ($scope->type() !== CashierV3ResourceScope::TYPE_STORE
                || $scope->id() !== (string)$storeId) {
                return null;
            }
            CashierV3EntitlementProviderDataScope::assertStore($storeId, $dataScope);
        }
        return $this->readVersion($kind, $resourceId, $dataScope, true);
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('inventoryResourceVersionBump:' . $kind);
        throw self::failure('inventory_version_advance_owned_by_inventory_fact_writer', [
            'kind' => $kind,
            'id' => $resourceId,
            'action' => $action,
        ]);
    }

    private function resourceStoreId(
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): int {
        $this->assertKind($kind);
        CashierV3EntitlementProviderDataScope::assertTenant($dataScope->tenantId(), $dataScope);
        if ($kind === 'inventory_policy') {
            $this->project($this->positiveId($resourceId), $dataScope->forcedStoreId());
            return $dataScope->forcedStoreId();
        }
        if ($kind === 'inventory_recipe') {
            $this->recipe($this->positiveId($resourceId), $lock);
            return $dataScope->forcedStoreId();
        }
        if ($kind === 'inventory_stock') {
            $stock = $this->stock($this->positiveId($resourceId), $dataScope, $lock);
            return (int)$stock['store_id'];
        }
        if ($kind === 'inventory_batch') {
            $batch = $this->batch($this->positiveId($resourceId), $lock);
            $stock = $this->stock((int)$batch['stock_id'], $dataScope, false);
            return (int)$stock['store_id'];
        }
        [$stockId, $recipeId, $estimatedCost] = $this->cursorIdentity($resourceId);
        $stock = $this->stock($stockId, $dataScope, false);
        $this->recipe($recipeId, false);
        if ((int)$stock['estimated_unit_cost_cents'] !== $estimatedCost) {
            throw self::failure('inventory_shortage_cursor_cost_changed');
        }
        return (int)$stock['store_id'];
    }

    private function readVersion(
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): int {
        if ($kind === 'inventory_policy') {
            return $this->policyVersion($this->positiveId($resourceId), $dataScope, $lock);
        }
        if ($kind === 'inventory_recipe') {
            return (int)$this->recipe($this->positiveId($resourceId), $lock)['version'];
        }
        if ($kind === 'inventory_stock') {
            return (int)$this->stock($this->positiveId($resourceId), $dataScope, $lock)['version'];
        }
        if ($kind === 'inventory_batch') {
            return (int)$this->batch($this->positiveId($resourceId), $lock)['version'];
        }
        [$stockId, $recipeId, $estimatedCost] = $this->cursorIdentity($resourceId);
        $stock = $this->stock($stockId, $dataScope, false);
        if ((int)$stock['estimated_unit_cost_cents'] !== $estimatedCost) {
            throw self::failure('inventory_shortage_cursor_cost_changed');
        }
        $this->recipe($recipeId, false);
        return $this->cursorVersion(
            $resourceId,
            $dataScope->tenantId(),
            (int)$stock['store_id'],
            $stockId,
            $recipeId,
            $estimatedCost,
            $lock
        );
    }

    private function policyVersion(
        int $projectId,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): int {
        $this->project($projectId, $dataScope->forcedStoreId());
        $merchantQuery = Db::name('inventory_shortage_policy')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('policy_scope', 'MERCHANT')
            ->where('project_id', 0);
        if ($lock) {
            $merchantQuery->lock(true);
        }
        $merchant = $merchantQuery->find();
        $projectQuery = Db::name('inventory_shortage_policy')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('policy_scope', 'PROJECT')
            ->where('project_id', $projectId);
        if ($lock) {
            $projectQuery->lock(true);
        }
        $project = $projectQuery->find();
        if (!$merchant || !$project) {
            throw self::failure('inventory_policy_not_ready');
        }
        InventoryEntitlementCompletionContract::resolvePolicy(
            (string)$merchant['policy_value'],
            (string)$project['policy_value']
        );
        return InventoryEntitlementCompletionContract::policyVersion(
            (int)$merchant['version'],
            (int)$project['version']
        );
    }

    private function project(int $projectId, int $storeId): array
    {
        $row = Db::name('store_product')
            ->where('id', $projectId)
            ->field('id,type,relation_id,product_type')
            ->find();
        // The project identity is taken from the already-selected entitlement
        // snapshot. A catalogue lifecycle change after selection does not
        // invalidate its consumable-inventory authority at final completion.
        if (!$row
            || (int)($row['product_type'] ?? 0) !== 6
            || !in_array((int)($row['type'] ?? -1), [0, 1], true)
            || ((int)$row['type'] === 1 && (int)$row['relation_id'] !== $storeId)) {
            throw self::failure('inventory_policy_project_not_found');
        }
        return $row;
    }

    private function recipe(int $recipeId, bool $lock): array
    {
        $query = Db::name('store_project_consumable_recipe')->where('id', $recipeId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!$row
            || (int)($row['status'] ?? 0) !== 1
            || (int)($row['type'] ?? -1) !== 0
            || (int)($row['relation_id'] ?? -1) !== 0
            || (int)($row['version'] ?? 0) <= 0) {
            throw self::failure('inventory_recipe_not_found');
        }
        return $row;
    }

    private function stock(
        int $stockId,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): array {
        $query = Db::name('inventory_stock')->where('id', $stockId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!$row
            || (string)($row['tenant_id'] ?? '') !== $dataScope->tenantId()
            || (int)($row['store_id'] ?? 0) !== $dataScope->forcedStoreId()
            || (string)($row['stock_status'] ?? '')
                !== InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD
            || (int)($row['version'] ?? 0) <= 0
            || (int)($row['estimated_unit_cost_cents'] ?? -1) < 0) {
            throw self::failure('inventory_stock_not_found');
        }
        return $row;
    }

    private function batch(int $batchId, bool $lock): array
    {
        $query = Db::name('inventory_batch')->where('id', $batchId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!$row
            || (string)($row['batch_status'] ?? '') !== 'ACTIVE'
            || (int)($row['stock_id'] ?? 0) <= 0
            || (int)($row['version'] ?? 0) <= 0) {
            throw self::failure('inventory_batch_not_found');
        }
        return $row;
    }

    private function cursorVersion(
        string $resourceId,
        string $tenantId,
        int $storeId,
        int $stockId,
        int $recipeId,
        int $estimatedCost,
        bool $lock
    ): int {
        if ($lock) {
            $now = time();
            Db::execute(
                'INSERT IGNORE INTO `eb_inventory_shortage_cost_cursor`'
                . ' (`tenant_id`,`store_id`,`stock_id`,`recipe_id`,`estimated_unit_cost_cents`,'
                . ' `allocated_quantity_units`,`version`,`created_at`,`updated_at`)'
                . ' VALUES (?,?,?,?,?,0,1,?,?)',
                [$tenantId, $storeId, $stockId, $recipeId, $estimatedCost, $now, $now]
            );
        }
        $query = Db::name('inventory_shortage_cost_cursor')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('stock_id', $stockId)
            ->where('recipe_id', $recipeId)
            ->where('estimated_unit_cost_cents', $estimatedCost);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!$row && !$lock) {
            return 1;
        }
        if (!$row || (int)($row['version'] ?? 0) <= 0
            || (int)($row['allocated_quantity_units'] ?? -1) < 0
            || !hash_equals(
                $resourceId,
                InventoryEntitlementCompletionContract::shortageCursorResourceId(
                    (int)$row['stock_id'],
                    (int)$row['recipe_id'],
                    (int)$row['estimated_unit_cost_cents']
                )
            )) {
            throw self::failure('inventory_shortage_cursor_not_found');
        }
        return (int)$row['version'];
    }

    /** @return array{0:int,1:int,2:int} */
    private function cursorIdentity(string $resourceId): array
    {
        $parts = explode(':', $resourceId);
        if (count($parts) !== 3) {
            throw self::failure('inventory_shortage_cursor_identity_invalid');
        }
        [$stock, $recipe, $cost] = $parts;
        if (preg_match('/^[1-9][0-9]*$/D', $stock) !== 1
            || preg_match('/^[1-9][0-9]*$/D', $recipe) !== 1
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $cost) !== 1
            || (string)(int)$stock !== $stock
            || (string)(int)$recipe !== $recipe
            || (string)(int)$cost !== $cost) {
            throw self::failure('inventory_shortage_cursor_identity_invalid');
        }
        return [(int)$stock, (int)$recipe, (int)$cost];
    }

    private function positiveId(string $resourceId): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $resourceId) !== 1
            || (string)(int)$resourceId !== $resourceId) {
            throw self::failure('inventory_resource_identity_invalid');
        }
        return (int)$resourceId;
    }

    private function tenantKind(string $kind): bool
    {
        return in_array($kind, ['inventory_policy', 'inventory_recipe'], true);
    }

    private function assertKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw self::failure('inventory_resource_kind_invalid', ['kind' => $kind]);
        }
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3EntitlementProviderContractException {
        return new CashierV3EntitlementProviderContractException($reason, $detail);
    }
}

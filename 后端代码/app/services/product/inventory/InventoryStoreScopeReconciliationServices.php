<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/** Keeps current store warehouse and stock ownership aligned after organization moves; settled facts retain their historical snapshots. */
final class InventoryStoreScopeReconciliationServices
{
    /** The caller holds an inventory transaction and a row lock on the unique default location. */
    public function reconcileLocation(array $location, array $scope, int $now): array
    {
        // Scope repair and the business write must commit or roll back together.
        if (!Db::connect()->getPdo()->inTransaction()) {
            throw new \RuntimeException('inventory_store_location_scope_invalid');
        }
        if ((int)($location['id'] ?? 0) <= 0
            || (string)($location['tenant_id'] ?? '') !== (string)$scope['tenantId']
            || (int)($location['store_id'] ?? 0) !== (int)$scope['storeId']
            || (int)($location['owner_id'] ?? 0) !== (int)$scope['storeId']
            || (string)($location['location_type'] ?? '') !== 'STORE'
            || (int)($location['is_default'] ?? 0) !== 1
            || (string)($location['location_status'] ?? '') !== 'ACTIVE'
            || (int)($location['version'] ?? 0) <= 0) {
            throw new \RuntimeException('inventory_store_location_scope_invalid');
        }

        $locationId = (int)$location['id'];
        $stocks = Db::name('inventory_stock')->where('location_id', $locationId)
            ->order('id', 'asc')->lock(true)->select()->toArray();
        foreach ($stocks as $stock) {
            // Only current balance ownership moves. Historical movement facts and confirmed documents
            // remain under the organization in which each business event originally settled.
            if ((string)$stock['tenant_id'] !== (string)$scope['tenantId']
                || (int)$stock['store_id'] !== (int)$scope['storeId']
                || (int)$stock['version'] <= 0) {
                throw new \RuntimeException('inventory_store_stock_scope_invalid');
            }
            if ((string)$stock['organization_id'] === (string)$scope['organizationId']
                && (string)$stock['organization_path'] === (string)$scope['organizationPath']) continue;
            $updated = Db::name('inventory_stock')->where('id', (int)$stock['id'])
                ->where('version', (int)$stock['version'])->update([
                    'organization_id' => (string)$scope['organizationId'],
                    'organization_path' => (string)$scope['organizationPath'],
                    'version' => (int)$stock['version'] + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) throw new \RuntimeException('inventory_store_stock_scope_invalid');
        }
        if ((string)$location['organization_id'] === (string)$scope['organizationId']
            && (string)$location['organization_path'] === (string)$scope['organizationPath']) return $location;
        $updated = Db::name('inventory_location')->where('id', $locationId)
            ->where('version', (int)$location['version'])->update([
                'organization_id' => (string)$scope['organizationId'],
                'organization_path' => (string)$scope['organizationPath'],
                'version' => (int)$location['version'] + 1,
                'updated_at' => $now,
            ]);
        if ($updated !== 1) throw new \RuntimeException('inventory_store_location_scope_invalid');
        $location['organization_id'] = (string)$scope['organizationId'];
        $location['organization_path'] = (string)$scope['organizationPath'];
        $location['version'] = (int)$location['version'] + 1;
        return $location;
    }

    /** Run inside the organization-edit transaction so every descendant store follows the new tree atomically. */
    public function reconcileMovedOrganization(int $movedOrganizationId, int $now): void
    {
        $rows = Db::name('organization')->where('is_del', 0)->field('id,pid')->select()->toArray();
        $parents = []; $children = [];
        foreach ($rows as $row) {
            $id = (int)$row['id']; $parent = (int)$row['pid'];
            $parents[$id] = $parent;
            $children[$parent][] = $id;
        }
        if (!isset($parents[$movedOrganizationId])) throw new \RuntimeException('inventory_store_organization_scope_invalid');
        $affected = [$movedOrganizationId];
        for ($i = 0; $i < count($affected); $i++) {
            if ($i > count($rows)) throw new \RuntimeException('inventory_store_organization_scope_invalid');
            foreach ($children[$affected[$i]] ?? [] as $childId) $affected[] = $childId;
        }
        $bindings = Db::name('organization_store')->whereIn('org_id', $affected)
            ->field('store_id,org_id')->order('store_id', 'asc')->select()->toArray();
        foreach ($bindings as $binding) {
            $this->reconcileBoundStoreWithParents((int)$binding['store_id'], (int)$binding['org_id'], $parents, $now);
        }
    }

    /** Rebind of one store uses the same current-balance repair as a tree move, within the caller's organization transaction. */
    public function reconcileBoundStore(int $storeId, int $organizationId, int $now): void
    {
        if ($storeId <= 0 || $organizationId <= 0) throw new \RuntimeException('inventory_store_organization_scope_invalid');
        $rows = Db::name('organization')->where('is_del', 0)->field('id,pid')->select()->toArray();
        $parents = [];
        foreach ($rows as $row) $parents[(int)$row['id']] = (int)$row['pid'];
        $this->reconcileBoundStoreWithParents($storeId, $organizationId, $parents, $now);
    }

    /** Validate the live tree before changing only the active default warehouse and current stock ownership. */
    private function reconcileBoundStoreWithParents(int $storeId, int $orgId, array $parents, int $now): void
    {
        $path = []; $seen = []; $current = $orgId;
        while ($current > 0) {
            if (isset($seen[$current]) || !isset($parents[$current])) {
                throw new \RuntimeException('inventory_store_organization_scope_invalid');
            }
            $seen[$current] = true;
            $path[] = $current;
            $current = $parents[$current];
        }
        $locations = Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_type', 'STORE')
            ->where('is_default', 1)->where('location_status', 'ACTIVE')
            ->order('id', 'asc')->limit(2)->lock(true)->select()->toArray();
        if (!$locations) return; // A store may not have initialized inventory yet.
        if (count($locations) !== 1) throw new \RuntimeException('inventory_store_location_scope_invalid');
        $this->reconcileLocation($locations[0], [
            'tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID,
            'storeId' => $storeId,
            'organizationId' => (string)$orgId,
            'organizationPath' => '/' . implode('/', array_reverse($path)) . '/',
        ], $now);
    }
}

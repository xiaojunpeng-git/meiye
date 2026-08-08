<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * Creates a non-default store warehouse through the platform authority.
 *
 * A location is inventory master data, not an inventory movement fact: this
 * command never changes stock, batches, costs, sales, or service facts.
 */
final class InventoryPlatformWarehouseCommandServices
{
    public function create(array $adminInfo, array $input): array
    {
        InventoryV3RolloutPolicy::assertMultiWarehouseEnabled();
        $command = $this->normalize($input);
        $policy = new InventoryPlatformAccessPolicy();
        $access = $policy->resolve($adminInfo);
        $policy->assertFeature($access, 'inventory.location.manage', '当前岗位未配置“平台仓库管理”权限。');
        $nameLock = 'inventory-location:' . substr(
            hash('sha256', CashierV3ScopeResolver::TENANT_SCOPE_ID . ':' . $command['storeId'] . ':' . $command['locationName']),
            0,
            40
        );
        $lock = Db::query("SELECT GET_LOCK('{$nameLock}', 5) AS acquired");
        if ((int)($lock[0]['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('inventory_platform_warehouse_name_lock_timeout');
        }
        try {
            return Db::transaction(function () use ($access, $command): array {
            if (empty($access['is_super_admin']) && !in_array($command['storeId'], (array)$access['store_ids'], true)) {
                throw new \RuntimeException('inventory_platform_warehouse_store_scope_denied');
            }
            $scope = $this->lockStoreScope($command['storeId']);
            $code = $this->locationCode($command['storeId'], $command['idempotencyKey']);
            $existing = Db::name('inventory_location')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('location_code', $code)
                ->lock(true)
                ->find();
            if ($existing) {
                return $this->replay((array)$existing, $scope, $command, $code);
            }

            $sameName = Db::name('inventory_location')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('store_id', $command['storeId'])
                ->where('location_name', $command['locationName'])
                ->lock(true)
                ->find();
            if ($sameName) {
                throw new \RuntimeException('inventory_platform_warehouse_name_taken');
            }

            try {
                $id = (int)Db::name('inventory_location')->insertGetId([
                    'tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID,
                    'organization_id' => $scope['organizationId'],
                    'organization_path' => $scope['organizationPath'],
                    'organization_name_snapshot' => $scope['organizationName'],
                    'location_type' => 'STORE',
                    'owner_id' => $command['storeId'],
                    'location_code' => $code,
                    'location_name' => $command['locationName'],
                    'store_id' => $command['storeId'],
                    'store_name_snapshot' => $scope['storeName'],
                    'is_default' => 0,
                    'location_status' => 'ACTIVE',
                    'version' => 1,
                    'created_by_admin_id' => (int)$access['admin_id'],
                    'created_at' => $command['recordedAt'],
                    'updated_at' => $command['recordedAt'],
                ]);
            } catch (\Throwable $exception) {
                $colliding = Db::name('inventory_location')
                    ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                    ->where('location_code', $code)
                    ->lock(true)
                    ->find();
                if ($colliding) {
                    return $this->replay((array)$colliding, $scope, $command, $code);
                }
                throw $exception;
            }

            $location = Db::name('inventory_location')->where('id', $id)->lock(true)->find();
            if (!$location) {
                throw new \RuntimeException('inventory_platform_warehouse_create_failed');
            }
            return ['location' => $this->present((array)$location), 'idempotent' => false];
            });
        } finally {
            Db::query("SELECT RELEASE_LOCK('{$nameLock}')");
        }
    }

    private function normalize(array $input): array
    {
        if (array_keys($input) !== ['idempotency_key', 'store_id', 'location_name']) {
            throw new \InvalidArgumentException('inventory_platform_warehouse_input_invalid');
        }
        $key = trim((string)$input['idempotency_key']);
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('inventory_platform_warehouse_idempotency_invalid');
        }
        if (!is_int($input['store_id']) || $input['store_id'] <= 0) {
            throw new \InvalidArgumentException('inventory_platform_warehouse_store_invalid');
        }
        $name = trim((string)$input['location_name']);
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new \InvalidArgumentException('inventory_platform_warehouse_name_invalid');
        }
        return ['idempotencyKey' => $key, 'storeId' => (int)$input['store_id'], 'locationName' => $name, 'recordedAt' => time()];
    }

    private function lockStoreScope(int $storeId): array
    {
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->where('is_show', 1)
            ->field('id,name')->lock(true)->find();
        $binding = Db::name('organization_store')->where('store_id', $storeId)->field('org_id')->lock(true)->find();
        $organizationId = (int)($binding['org_id'] ?? 0);
        if (!$store || trim((string)($store['name'] ?? '')) === '' || $organizationId <= 0) {
            throw new \RuntimeException('inventory_platform_warehouse_store_scope_invalid');
        }
        $ids = [];
        $seen = [];
        $name = '';
        $current = $organizationId;
        for ($depth = 0; $depth < 64; $depth++) {
            if (isset($seen[$current])) throw new \RuntimeException('inventory_platform_warehouse_organization_invalid');
            $seen[$current] = true;
            $node = Db::name('organization')->where('id', $current)->where('is_del', 0)->field('id,pid,name')->lock(true)->find();
            if (!$node || trim((string)($node['name'] ?? '')) === '') throw new \RuntimeException('inventory_platform_warehouse_organization_invalid');
            if ($name === '') $name = trim((string)$node['name']);
            $ids[] = (int)$node['id'];
            $parentId = (int)($node['pid'] ?? 0);
            if ($parentId === 0) break;
            if ($parentId < 0) throw new \RuntimeException('inventory_platform_warehouse_organization_invalid');
            $current = $parentId;
        }
        if (!$ids || (int)end($ids) !== $current) throw new \RuntimeException('inventory_platform_warehouse_organization_invalid');
        return [
            'organizationId' => (string)$organizationId,
            'organizationPath' => '/' . implode('/', array_reverse($ids)) . '/',
            'organizationName' => mb_substr($name, 0, 100),
            'storeName' => mb_substr(trim((string)$store['name']), 0, 100),
        ];
    }

    private function locationCode(int $storeId, string $idempotencyKey): string
    {
        return 'STORE-' . $storeId . '-' . strtoupper(substr(hash('sha256', 'warehouse:' . $idempotencyKey), 0, 24));
    }

    private function replay(array $location, array $scope, array $command, string $code): array
    {
        if ((string)($location['organization_id'] ?? '') !== $scope['organizationId']
            || (string)($location['organization_path'] ?? '') !== $scope['organizationPath']
            || (int)($location['store_id'] ?? 0) !== $command['storeId']
            || (string)($location['location_code'] ?? '') !== $code
            || (string)($location['location_name'] ?? '') !== $command['locationName']
            || (int)($location['is_default'] ?? -1) !== 0) {
            throw new \RuntimeException('inventory_platform_warehouse_idempotency_conflict');
        }
        return ['location' => $this->present($location), 'idempotent' => true];
    }

    private function present(array $location): array
    {
        return [
            'id' => (int)$location['id'],
            'location_code' => (string)$location['location_code'],
            'location_name' => (string)$location['location_name'],
            'store_id' => (int)$location['store_id'],
            'store_name_snapshot' => (string)$location['store_name_snapshot'],
            'is_default' => (int)$location['is_default'],
            'location_status' => (string)$location['location_status'],
            'version' => (int)$location['version'],
            'created_by_admin_id' => (int)($location['created_by_admin_id'] ?? 0),
            'created_at' => (int)$location['created_at'],
        ];
    }
}

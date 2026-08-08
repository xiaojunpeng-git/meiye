<?php
declare(strict_types=1);

namespace app\services\product\inventory\completion;

use think\facade\Db;

/**
 * Read-only discovery for the cashier Gateway lock plan.
 *
 * This class deliberately performs no row lock and no write. The Gateway uses
 * the returned identities to acquire the global lock order; the authoritative
 * provider then rebuilds the full snapshot under those locks.
 */
final class InventoryEntitlementCompletionDiscovery
{
    public const RESOURCE_CONTRACT_VERSION = 'inventory-completion-gateway-resource-v1';

    private const MAX_LINES = 100;

    public function discover(array $request, InventoryCompletionDataScope $scope): array
    {
        $request = $this->normalizeRequest($request);
        $scope->assertTenantAndStore($request['tenantId'], $request['storeId']);

        $request['lines'] = $this->inventoryManagedLines($request['lines']);
        if ($request['lines'] === []) {
            return ['contractVersion' => InventoryEntitlementCompletionContract::CONTRACT_VERSION, 'resourceContractVersion' => self::RESOURCE_CONTRACT_VERSION, 'tenantId' => $request['tenantId'], 'storeId' => $request['storeId'], 'resources' => []];
        }

        $resources = [];
        $policyByProject = $this->readPolicies(
            $request['tenantId'],
            array_values(array_unique(array_column($request['lines'], 'projectId')))
        );
        $recipesByProject = $this->readRecipes($request['lines'], $request['storeId']);
        $defaultLocationId = $this->defaultLocationId($request['tenantId'], $request['storeId']);
        $stockCache = [];
        $batchCache = [];

        foreach ($request['lines'] as $line) {
            $lineId = $line['lineId'];
            $projectId = $line['projectId'];
            $policy = $policyByProject[$projectId];
            $this->addResource(
                $resources,
                'inventory_policy',
                (string)$projectId,
                InventoryEntitlementCompletionContract::LOCK_ORDER_POLICY,
                $policy['version'],
                'inventory_policy:' . $lineId
            );

            $recipeSet = $recipesByProject[$this->projectKey($projectId, $line['projectUnique'])];
            $recipeId = (int)$recipeSet['recipe']['id'];
            $this->addResource(
                $resources,
                'inventory_recipe',
                (string)$recipeId,
                InventoryEntitlementCompletionContract::LOCK_ORDER_RECIPE,
                (int)$recipeSet['recipe']['version'],
                'inventory_recipe:' . $lineId
            );

            $seenStocksForRecipe = [];
            foreach ($recipeSet['details'] as $detail) {
                $material = $this->material(
                    (int)$detail['consumable_product_id'],
                    (string)$detail['consumable_unique'],
                    (string)$detail['qty_per_writeoff'],
                    $request['storeId']
                );
                $stockKey = $material['storeProductId'] . ':' . $material['storeSkuId'];
                if (isset($seenStocksForRecipe[$stockKey])) {
                    throw $this->failure('inventory_recipe_material_duplicate_after_mapping', [
                        'recipeId' => $recipeId,
                        'stockKey' => $stockKey,
                    ]);
                }
                $seenStocksForRecipe[$stockKey] = true;

                $stock = $this->stock(
                    $request['tenantId'],
                    $request['storeId'],
                    $defaultLocationId,
                    $material['storeProductId'],
                    $material['storeSkuId']
                );
                $stockId = (int)$stock['id'];
                $stockCache[$stockId] = $stock;
                $this->addResource(
                    $resources,
                    'inventory_stock',
                    (string)$stockId,
                    InventoryEntitlementCompletionContract::LOCK_ORDER_STOCK,
                    (int)$stock['version'],
                    'inventory_stock:' . $stockId
                );

                if (!isset($batchCache[$stockId])) {
                    $batchCache[$stockId] = $this->batches($stock);
                }
                foreach ($batchCache[$stockId] as $batch) {
                    if ((int)$batch['available_quantity_units'] <= 0) {
                        continue;
                    }
                    $batchId = (int)$batch['id'];
                    $this->addResource(
                        $resources,
                        'inventory_batch',
                        (string)$batchId,
                        InventoryEntitlementCompletionContract::LOCK_ORDER_BATCH,
                        (int)$batch['version'],
                        'inventory_batch:' . $stockId . ':' . $batchId
                    );
                }

                InventoryEntitlementCompletionContract::decimalToUnits(
                    $material['quantityPerService'],
                    (int)$stock['quantity_scale']
                );
                $cursor = $this->shortageCursor(
                    $request['tenantId'],
                    $request['storeId'],
                    $stockId,
                    $recipeId,
                    (int)$stock['estimated_unit_cost_cents']
                );
                $this->addResource(
                    $resources,
                    'inventory_shortage_cursor',
                    $cursor['resourceId'],
                    InventoryEntitlementCompletionContract::LOCK_ORDER_SHORTAGE_CURSOR,
                    $cursor['version'],
                    'inventory_shortage_cursor:' . $lineId . ':' . $stockId
                );
            }
        }

        $resources = array_values($resources);
        usort($resources, static function (array $left, array $right): int {
            $order = $left['lockOrder'] <=> $right['lockOrder'];
            if ($order !== 0) {
                return $order;
            }
            $kind = strcmp($left['kind'], $right['kind']);
            return $kind !== 0
                ? $kind
                : InventoryEntitlementCompletionContract::compareResourceIds($left['id'], $right['id']);
        });
        foreach ($resources as &$resource) {
            sort($resource['roles'], SORT_STRING);
            $resource['authorityFingerprint'] = self::authorityFingerprint($resource);
            unset($resource['lockOrder']);
        }
        unset($resource);

        return [
            'contractVersion' => InventoryEntitlementCompletionContract::CONTRACT_VERSION,
            'resourceContractVersion' => self::RESOURCE_CONTRACT_VERSION,
            'tenantId' => $request['tenantId'],
            'storeId' => $request['storeId'],
            'resources' => $resources,
        ];
    }

    public static function gatewayResourcesFromLockedSnapshot(array $snapshot): array
    {
        if (($snapshot['contractVersion'] ?? '') !== InventoryEntitlementCompletionContract::CONTRACT_VERSION
            || !isset($snapshot['lockedInventoryResources'])
            || !is_array($snapshot['lockedInventoryResources'])) {
            throw new InventoryCompletionContractException('inventory_locked_snapshot_resources_invalid');
        }
        $resources = [];
        foreach ($snapshot['lockedInventoryResources'] as $index => $row) {
            if (!is_array($row)
                || !isset($row['kind'], $row['id'], $row['lockOrder'], $row['lockedVersion'], $row['roles'])
                || !is_int($row['lockOrder'])
                || !is_int($row['lockedVersion'])
                || $row['lockedVersion'] <= 0
                || !is_array($row['roles'])
                || !$row['roles']) {
                throw new InventoryCompletionContractException(
                    'inventory_locked_snapshot_resource_invalid',
                    ['index' => $index]
                );
            }
            $resource = [
                'kind' => (string)$row['kind'],
                'id' => (string)$row['id'],
                'expectedVersion' => $row['lockedVersion'],
                'roles' => array_values(array_unique(array_map('strval', $row['roles']))),
                'accessMode' => 'read',
                'providerContractVersion' => self::RESOURCE_CONTRACT_VERSION,
                'lockOrder' => $row['lockOrder'],
            ];
            sort($resource['roles'], SORT_STRING);
            $resource['authorityFingerprint'] = self::authorityFingerprint($resource);
            $resources[] = $resource;
        }
        usort($resources, static function (array $left, array $right): int {
            $order = $left['lockOrder'] <=> $right['lockOrder'];
            if ($order !== 0) {
                return $order;
            }
            $kind = strcmp($left['kind'], $right['kind']);
            return $kind !== 0
                ? $kind
                : InventoryEntitlementCompletionContract::compareResourceIds($left['id'], $right['id']);
        });
        foreach ($resources as &$resource) {
            unset($resource['lockOrder']);
        }
        unset($resource);
        return $resources;
    }

    private function readPolicies(string $tenantId, array $projectIds): array
    {
        usort($projectIds, static function ($left, $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds((string)$left, (string)$right);
        });
        $merchant = Db::name('inventory_shortage_policy')
            ->where('tenant_id', $tenantId)
            ->where('policy_scope', 'MERCHANT')
            ->where('project_id', 0)
            ->find();
        if (!$merchant || (int)($merchant['version'] ?? 0) <= 0) {
            throw $this->failure('inventory_merchant_policy_not_ready');
        }
        $merchantPolicy = (string)$merchant['policy_value'];
        $merchantVersion = (int)$merchant['version'];
        InventoryEntitlementCompletionContract::resolvePolicy(
            $merchantPolicy,
            InventoryEntitlementCompletionContract::POLICY_INHERIT
        );
        $result = [];
        foreach ($projectIds as $projectId) {
            $project = Db::name('inventory_shortage_policy')
                ->where('tenant_id', $tenantId)
                ->where('policy_scope', 'PROJECT')
                ->where('project_id', $projectId)
                ->find();
            if (!$project || (int)($project['version'] ?? 0) <= 0) {
                throw $this->failure('inventory_project_policy_not_ready', ['projectId' => $projectId]);
            }
            InventoryEntitlementCompletionContract::resolvePolicy(
                $merchantPolicy,
                (string)$project['policy_value']
            );
            $result[(int)$projectId] = [
                'version' => InventoryEntitlementCompletionContract::policyVersion(
                    $merchantVersion,
                    (int)$project['version']
                ),
            ];
        }
        return $result;
    }

    private function inventoryManagedLines(array $lines): array
    {
        $managed = [];
        foreach ($lines as $line) {
            $project = Db::name('store_product')->where('id', $line['projectId'])->where('is_del', 0)->field('is_inventory')->find();
            if (!$project) {
                throw $this->failure('inventory_project_not_found', ['projectId' => $line['projectId']]);
            }
            if ((int)$project['is_inventory'] === 1) {
                $managed[] = $line;
            }
        }
        return $managed;
    }

    private function readRecipes(array $lines, int $storeId): array
    {
        $result = [];
        foreach ($lines as $line) {
            [$platformProjectId, $platformUnique] = $this->resolvePlatformProductSku(
                $line['projectId'],
                $line['projectUnique'],
                $storeId
            );
            $recipe = Db::name('store_project_consumable_recipe')
                ->where('type', 0)
                ->where('relation_id', 0)
                ->where('project_product_id', $platformProjectId)
                ->where('project_unique', $platformUnique)
                ->where('status', 1)
                ->find();
            if (!$recipe || (int)($recipe['id'] ?? 0) <= 0 || (int)($recipe['version'] ?? 0) <= 0) {
                throw $this->failure('inventory_recipe_not_ready', ['projectId' => $line['projectId']]);
            }
            $details = Db::name('store_project_consumable_recipe_detail')
                ->where('recipe_id', (int)$recipe['id'])
                ->order('id asc')
                ->select();
            if (is_object($details) && method_exists($details, 'toArray')) {
                $details = $details->toArray();
            }
            if (!is_array($details) || !$details) {
                throw $this->failure('inventory_recipe_details_empty', ['recipeId' => (int)$recipe['id']]);
            }
            $result[$this->projectKey($line['projectId'], $line['projectUnique'])] = [
                'recipe' => $recipe,
                'details' => $details,
            ];
        }
        return $result;
    }

    private function stock(
        string $tenantId,
        int $storeId,
        int $locationId,
        int $consumableId,
        int $skuId
    ): array {
        $stock = Db::name('inventory_stock')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('location_id', $locationId)
            ->where('consumable_product_id', $consumableId)
            ->where('sku_id', $skuId)
            ->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)
            ->find();
        if (!$stock
            || (int)($stock['id'] ?? 0) <= 0
            || (int)($stock['version'] ?? 0) <= 0
            || (int)($stock['available_quantity_units'] ?? -1) < 0
            || (int)($stock['estimated_unit_cost_cents'] ?? -1) < 0
            || (int)($stock['quantity_scale'] ?? -1) < 0
            || (int)($stock['quantity_scale'] ?? -1) > 4) {
            throw $this->failure('inventory_stock_not_ready', [
                'storeId' => $storeId,
                'consumableId' => $consumableId,
                'skuId' => $skuId,
            ]);
        }
        return $stock;
    }

    private function batches(array $stock): array
    {
        $rows = Db::name('inventory_batch')
            ->where('stock_id', (int)$stock['id'])
            ->where('batch_status', 'ACTIVE')
            ->order('id asc')
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        if (!is_array($rows)) {
            throw $this->failure('inventory_batch_query_failed');
        }
        $total = 0;
        foreach ($rows as $row) {
            if ((int)($row['id'] ?? 0) <= 0
                || (int)($row['version'] ?? 0) <= 0
                || (int)($row['available_quantity_units'] ?? -1) < 0
                || (int)($row['unit_cost_cents'] ?? -1) < 0
                || (int)($row['cost_allocated_quantity_units'] ?? -1) < 0) {
                throw $this->failure('inventory_batch_changed');
            }
            $total += (int)$row['available_quantity_units'];
        }
        if ($total !== (int)$stock['available_quantity_units']) {
            throw $this->failure('inventory_stock_batch_balance_mismatch', [
                'stockId' => (int)$stock['id'],
                'stockQuantityUnits' => (int)$stock['available_quantity_units'],
                'batchQuantityUnits' => $total,
            ]);
        }
        return $rows;
    }

    private function shortageCursor(
        string $tenantId,
        int $storeId,
        int $stockId,
        int $recipeId,
        int $estimatedUnitCostCents
    ): array {
        $resourceId = InventoryEntitlementCompletionContract::shortageCursorResourceId(
            $stockId,
            $recipeId,
            $estimatedUnitCostCents
        );
        $row = Db::name('inventory_shortage_cost_cursor')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('stock_id', $stockId)
            ->where('recipe_id', $recipeId)
            ->where('estimated_unit_cost_cents', $estimatedUnitCostCents)
            ->find();
        if (!$row) {
            return ['resourceId' => $resourceId, 'version' => 1];
        }
        if ((int)($row['id'] ?? 0) <= 0
            || (int)($row['version'] ?? 0) <= 0
            || (int)($row['allocated_quantity_units'] ?? -1) < 0) {
            throw $this->failure('inventory_shortage_cost_cursor_not_ready', [
                'shortageCursorId' => $resourceId,
            ]);
        }
        return ['resourceId' => $resourceId, 'version' => (int)$row['version']];
    }

    private function defaultLocationId(string $tenantId, int $storeId): int
    {
        $rows = Db::name('inventory_location')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('location_type', 'STORE')
            ->where('is_default', 1)
            ->where('location_status', 'ACTIVE')
            ->field('id')
            ->order('id asc')
            ->limit(2)
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        if (!is_array($rows) || count($rows) !== 1 || (int)($rows[0]['id'] ?? 0) <= 0) {
            throw $this->failure('inventory_default_location_not_ready', [
                'storeId' => $storeId,
                'matchedLocations' => is_array($rows) ? count($rows) : 0,
            ]);
        }
        return (int)$rows[0]['id'];
    }

    /** @return array{storeProductId:int,storeSkuId:int,quantityPerService:string} */
    private function material(
        int $platformProductId,
        string $platformUnique,
        string $quantityPerService,
        int $storeId
    ): array {
        $platformProduct = Db::name('store_product')
            ->where('id', $platformProductId)
            ->where('is_del', 0)
            ->field('id,pid,type,relation_id,is_inventory')
            ->find();
        if (!$platformProduct) {
            throw $this->failure('inventory_consumable_not_found', ['consumableId' => $platformProductId]);
        }
        if ((int)$platformProduct['type'] === 1 && (int)$platformProduct['relation_id'] === $storeId) {
            $storeProduct = $platformProduct;
            $storeUnique = $platformUnique;
        } else {
            $storeProduct = Db::name('store_product')
                ->where('pid', $platformProductId)
                ->where('type', 1)
                ->where('relation_id', $storeId)
                ->where('is_del', 0)
                ->field('id,is_inventory')
                ->find();
            if (!$storeProduct) {
                throw $this->failure('inventory_store_consumable_not_ready');
            }
            $platformSku = Db::name('store_product_attr_value')
                ->where('product_id', $platformProductId)
                ->where('unique', $platformUnique)
                ->where('type', 0)
                ->field('suk')
                ->find();
            if (!$platformSku || (string)$platformSku['suk'] === '') {
                throw $this->failure('inventory_platform_consumable_sku_not_found');
            }
            $storeSkuByName = Db::name('store_product_attr_value')
                ->where('product_id', (int)$storeProduct['id'])
                ->where('suk', (string)$platformSku['suk'])
                ->where('type', 0)
                ->field('id,unique')
                ->find();
            if (!$storeSkuByName) {
                throw $this->failure('inventory_store_consumable_sku_not_ready');
            }
            $storeUnique = (string)$storeSkuByName['unique'];
        }
        if ((int)($storeProduct['is_inventory'] ?? 0) !== 1) {
            throw $this->failure('inventory_consumable_management_disabled');
        }
        $storeSku = Db::name('store_product_attr_value')
            ->where('product_id', (int)$storeProduct['id'])
            ->where('unique', $storeUnique)
            ->where('type', 0)
            ->field('id')
            ->find();
        if (!$storeSku || (int)($storeSku['id'] ?? 0) <= 0) {
            throw $this->failure('inventory_store_consumable_sku_not_ready');
        }
        return [
            'storeProductId' => (int)$storeProduct['id'],
            'storeSkuId' => (int)$storeSku['id'],
            'quantityPerService' => $quantityPerService,
        ];
    }

    /** @return array{0:int,1:string} */
    private function resolvePlatformProductSku(int $productId, string $unique, int $storeId): array
    {
        $product = Db::name('store_product')
            ->where('id', $productId)
            ->where('is_del', 0)
            ->field('id,pid,type,relation_id')
            ->find();
        if (!$product) {
            throw $this->failure('inventory_project_not_found', ['projectId' => $productId]);
        }
        if ((int)$product['type'] === 0 || (int)$product['pid'] <= 0) {
            return [$productId, $unique];
        }
        if ((int)$product['relation_id'] !== $storeId) {
            throw $this->failure('inventory_project_store_scope_mismatch', ['projectId' => $productId]);
        }
        $storeSku = Db::name('store_product_attr_value')
            ->where('product_id', $productId)
            ->where('unique', $unique)
            ->where('type', 0)
            ->field('suk')
            ->find();
        if (!$storeSku || (string)$storeSku['suk'] === '') {
            throw $this->failure('inventory_project_sku_not_found', ['projectId' => $productId]);
        }
        $platformSku = Db::name('store_product_attr_value')
            ->where('product_id', (int)$product['pid'])
            ->where('suk', (string)$storeSku['suk'])
            ->where('type', 0)
            ->field('unique')
            ->find();
        if (!$platformSku || (string)$platformSku['unique'] === '') {
            throw $this->failure('inventory_platform_project_sku_not_found', ['projectId' => $productId]);
        }
        return [(int)$product['pid'], (string)$platformSku['unique']];
    }

    private function normalizeRequest(array $request): array
    {
        $this->assertExactKeys($request, ['contractVersion', 'tenantId', 'storeId', 'lines']);
        if ($request['contractVersion'] !== InventoryEntitlementCompletionContract::CONTRACT_VERSION) {
            throw $this->failure('inventory_contract_version_mismatch');
        }
        $tenantId = trim((string)$request['tenantId']);
        $storeId = is_int($request['storeId']) ? $request['storeId'] : 0;
        if ($tenantId === '' || strlen($tenantId) > 32
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $tenantId) !== 1
            || $storeId <= 0
            || !is_array($request['lines'])
            || !$request['lines']
            || count($request['lines']) > self::MAX_LINES
            || !$this->isList($request['lines'])) {
            throw $this->failure('inventory_discovery_request_invalid');
        }
        $lines = [];
        $seen = [];
        foreach ($request['lines'] as $index => $line) {
            if (!is_array($line)) {
                throw $this->failure('inventory_request_line_invalid', ['index' => $index]);
            }
            $this->assertExactKeys($line, ['lineId', 'projectId', 'projectUnique']);
            $lineId = trim((string)$line['lineId']);
            $projectId = is_int($line['projectId']) ? $line['projectId'] : 0;
            $projectUnique = trim((string)$line['projectUnique']);
            if ($lineId === '' || strlen($lineId) > 64
                || preg_match('/^[A-Za-z0-9:._-]+$/D', $lineId) !== 1
                || $projectId <= 0
                || $projectUnique === '' || strlen($projectUnique) > 64
                || preg_match('/^[A-Za-z0-9:._-]+$/D', $projectUnique) !== 1
                || isset($seen[$lineId])) {
                throw $this->failure('inventory_request_line_invalid', ['index' => $index]);
            }
            $seen[$lineId] = true;
            $lines[] = compact('lineId', 'projectId', 'projectUnique');
        }
        usort($lines, static function (array $left, array $right): int {
            $project = $left['projectId'] <=> $right['projectId'];
            if ($project !== 0) {
                return $project;
            }
            $sku = strcmp($left['projectUnique'], $right['projectUnique']);
            return $sku !== 0 ? $sku : strcmp($left['lineId'], $right['lineId']);
        });
        return compact('tenantId', 'storeId', 'lines') + [
            'contractVersion' => InventoryEntitlementCompletionContract::CONTRACT_VERSION,
        ];
    }

    private function addResource(
        array &$resources,
        string $kind,
        string $id,
        int $lockOrder,
        int $version,
        string $role
    ): void {
        if ($id === '' || $version <= 0 || $role === '') {
            throw $this->failure('inventory_discovery_resource_invalid');
        }
        $key = $kind . "\0" . $id;
        if (!isset($resources[$key])) {
            $resources[$key] = [
                'kind' => $kind,
                'id' => $id,
                'expectedVersion' => $version,
                'roles' => [],
                'accessMode' => 'read',
                'providerContractVersion' => self::RESOURCE_CONTRACT_VERSION,
                'lockOrder' => $lockOrder,
            ];
        } elseif ($resources[$key]['expectedVersion'] !== $version
            || $resources[$key]['lockOrder'] !== $lockOrder) {
            throw $this->failure('inventory_discovery_resource_version_inconsistent', [
                'kind' => $kind,
                'id' => $id,
            ]);
        }
        if (!in_array($role, $resources[$key]['roles'], true)) {
            $resources[$key]['roles'][] = $role;
        }
    }

    private static function authorityFingerprint(array $resource): string
    {
        return hash('sha256', json_encode([
            'contractVersion' => self::RESOURCE_CONTRACT_VERSION,
            'kind' => (string)$resource['kind'],
            'id' => (string)$resource['id'],
            'expectedVersion' => (int)$resource['expectedVersion'],
            'roles' => array_values($resource['roles']),
            'accessMode' => 'read',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function projectKey(int $projectId, string $unique): string
    {
        return $projectId . ':' . $unique;
    }

    private function assertExactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw $this->failure('inventory_discovery_shape_invalid');
        }
    }

    private function isList(array $value): bool
    {
        return array_keys($value) === ($value ? range(0, count($value) - 1) : []);
    }

    private function failure(string $reason, array $detail = []): InventoryCompletionContractException
    {
        return new InventoryCompletionContractException($reason, $detail);
    }
}

<?php
declare(strict_types=1);

namespace app\services\product\inventory\completion;

use think\facade\Db;

/**
 * Inventory-owned provider for the cashier C2 entitlement completion kernel.
 *
 * The caller must already be inside the final business transaction. This
 * provider locks only inventory resources, in canonical order 46/47/50/55/56,
 * and returns the exact inventory snapshot consumed by the C2 kernel.
 */
final class InventoryEntitlementCompletionProvider
{
    private const MAX_LINES = 100;

    public function lockSnapshot(array $request, InventoryCompletionDataScope $scope): array
    {
        $this->assertTransaction();
        $request = $this->normalizeRequest($request);
        $scope->assertTenantAndStore($request['tenantId'], $request['storeId']);

        $policyByProject = $this->lockPolicies(
            $request['tenantId'],
            array_values(array_unique(array_column($request['lines'], 'projectId')))
        );
        $recipeSets = $this->lockRecipes($request['lines'], $request['storeId']);
        $materialLines = $this->resolveMaterialLines($recipeSets, $request['storeId']);
        $defaultLocationId = $this->resolveDefaultLocationId(
            $request['tenantId'],
            $request['storeId']
        );
        $stockSets = $this->lockStocksAndBatches(
            $request['tenantId'],
            $request['storeId'],
            $defaultLocationId,
            $materialLines
        );
        $shortageCostCursors = $this->lockShortageCostCursors(
            $request['tenantId'],
            $request['storeId'],
            $materialLines,
            $stockSets['stocks']
        );

        $lineInventory = [];
        $resourceRows = [];
        foreach ($request['lines'] as $line) {
            $lineId = $line['lineId'];
            $projectId = $line['projectId'];
            $recipeKey = $this->projectKey($projectId, $line['projectUnique']);
            $recipeSet = $recipeSets[$recipeKey];
            $policy = $policyByProject[$projectId];
            $consumables = [];
            $formulaLines = [];
            foreach ($materialLines[$recipeSet['recipe']['id']] as $material) {
                $stock = $stockSets['stocks'][(string)$material['stockId']];
                $cursorKey = $this->shortageCostCursorKey(
                    $material['stockId'],
                    (int)$recipeSet['recipe']['id'],
                    (int)$stock['estimated_unit_cost_cents']
                );
                if (!array_key_exists($cursorKey, $shortageCostCursors)) {
                    throw $this->failure('inventory_shortage_cost_cursor_snapshot_missing');
                }
                $cursor = $shortageCostCursors[$cursorKey];
                $quantityUnits = InventoryEntitlementCompletionContract::decimalToUnits(
                    (string)$material['qtyPerService'],
                    (int)$stock['quantity_scale']
                );
                $consumables[] = [
                    'stockId' => (string)$material['stockId'],
                    'quantityUnitsPerService' => $quantityUnits,
                    'shortageEstimatedUnitCostCents' => (int)$stock['estimated_unit_cost_cents'],
                    'shortageCostAllocatedQuantityUnitsBefore' => $cursor['allocatedQuantityUnits'],
                    'shortageCursorId' => $cursor['resourceId'],
                    'shortageCursorVersion' => $cursor['version'],
                ];
                $this->addResource(
                    $resourceRows,
                    'inventory_shortage_cursor',
                    $cursor['resourceId'],
                    InventoryEntitlementCompletionContract::LOCK_ORDER_SHORTAGE_CURSOR,
                    $cursor['version'],
                    'inventory_shortage_cursor:' . $lineId . ':' . $material['stockId']
                );
                $formulaLines[] = [
                    'stockId' => (string)$material['stockId'],
                    'consumableId' => (int)$material['storeProductId'],
                    'skuId' => (int)$material['storeSkuId'],
                    'quantityUnitsPerService' => $quantityUnits,
                    'stockUnitScale' => (int)$stock['quantity_scale'],
                ];
            }
            usort($consumables, static function (array $left, array $right): int {
                return InventoryEntitlementCompletionContract::compareResourceIds(
                    $left['stockId'],
                    $right['stockId']
                );
            });
            $formulaHash = InventoryEntitlementCompletionContract::recipeFormulaHash(
                (int)$recipeSet['recipe']['id'],
                (int)$recipeSet['recipe']['version'],
                $formulaLines
            );
            $lineInventory[$lineId] = [
                'merchantDefaultPolicy' => $policy['merchantPolicy'],
                'merchantDefaultPolicyVersion' => $policy['merchantVersion'],
                'productPolicyOverride' => $policy['projectPolicy'],
                'productPolicyVersion' => $policy['projectVersion'],
                'policy' => $policy['resolvedPolicy'],
                'policyVersion' => $policy['resolvedVersion'],
                'recipeId' => (int)$recipeSet['recipe']['id'],
                'recipeVersion' => (int)$recipeSet['recipe']['version'],
                'recipeFormulaHash' => $formulaHash,
                'consumables' => $consumables,
            ];
            $this->addResource(
                $resourceRows,
                'inventory_policy',
                (string)$projectId,
                InventoryEntitlementCompletionContract::LOCK_ORDER_POLICY,
                $policy['resolvedVersion'],
                'inventory_policy:' . $lineId
            );
            $this->addResource(
                $resourceRows,
                'inventory_recipe',
                (string)$recipeSet['recipe']['id'],
                InventoryEntitlementCompletionContract::LOCK_ORDER_RECIPE,
                (int)$recipeSet['recipe']['version'],
                'inventory_recipe:' . $lineId
            );
        }

        $inventoryStocks = [];
        foreach ($stockSets['snapshots'] as $snapshot) {
            $inventoryStocks[] = $snapshot;
            $stockId = $snapshot['stockId'];
            $this->addResource(
                $resourceRows,
                'inventory_stock',
                $stockId,
                InventoryEntitlementCompletionContract::LOCK_ORDER_STOCK,
                $snapshot['stockVersion'],
                'inventory_stock:' . $stockId
            );
            foreach ($snapshot['batches'] as $batch) {
                $this->addResource(
                    $resourceRows,
                    'inventory_batch',
                    (string)$batch['batchId'],
                    InventoryEntitlementCompletionContract::LOCK_ORDER_BATCH,
                    $batch['batchVersion'],
                    'inventory_batch:' . $stockId . ':' . $batch['batchId']
                );
            }
        }
        $lockedResources = array_values($resourceRows);
        usort($lockedResources, static function (array $left, array $right): int {
            $order = $left['lockOrder'] <=> $right['lockOrder'];
            if ($order !== 0) {
                return $order;
            }
            $kind = strcmp($left['kind'], $right['kind']);
            return $kind !== 0
                ? $kind
                : InventoryEntitlementCompletionContract::compareResourceIds($left['id'], $right['id']);
        });
        foreach ($lockedResources as &$resource) {
            sort($resource['roles'], SORT_STRING);
        }
        unset($resource);

        return [
            'contractVersion' => InventoryEntitlementCompletionContract::CONTRACT_VERSION,
            'shortageCursorLockGate' => InventoryEntitlementCompletionContract::SHORTAGE_CURSOR_GATE,
            'tenantId' => $request['tenantId'],
            'storeId' => $request['storeId'],
            'lineInventoryByLineId' => $lineInventory,
            'inventoryStocks' => $inventoryStocks,
            'lockedInventoryResources' => $lockedResources,
            'persistenceSnapshot' => $this->persistenceSnapshot($stockSets, $materialLines),
            'projectNameSnapshotByLineId' => $this->projectNameSnapshotByLineId($request['lines']),
            'dataScopeSnapshot' => [
                'organizationId' => $scope->organizationId(),
                'organizationPath' => $scope->organizationPath(),
                'operatorId' => $scope->operatorId(),
            ],
        ];
    }

    private function normalizeRequest(array $request): array
    {
        $this->assertExactKeys($request, ['contractVersion', 'tenantId', 'storeId', 'lines'], 'request');
        if ($request['contractVersion'] !== InventoryEntitlementCompletionContract::CONTRACT_VERSION) {
            throw $this->failure('inventory_contract_version_mismatch');
        }
        $tenantId = trim((string)$request['tenantId']);
        $storeId = is_int($request['storeId']) ? $request['storeId'] : 0;
        if ($tenantId === '' || strlen($tenantId) > 32 || preg_match('/^[A-Za-z0-9:._-]+$/D', $tenantId) !== 1) {
            throw $this->failure('inventory_tenant_invalid');
        }
        if ($storeId <= 0 || !is_array($request['lines']) || !$request['lines']
            || count($request['lines']) > self::MAX_LINES || !$this->isList($request['lines'])) {
            throw $this->failure('inventory_request_lines_invalid');
        }
        $lines = [];
        $seen = [];
        foreach ($request['lines'] as $index => $line) {
            if (!is_array($line)) {
                throw $this->failure('inventory_request_line_invalid', ['index' => $index]);
            }
            $this->assertExactKeys($line, ['lineId', 'projectId', 'projectUnique'], 'line');
            $lineId = trim((string)$line['lineId']);
            $projectId = is_int($line['projectId']) ? $line['projectId'] : 0;
            $projectUnique = trim((string)$line['projectUnique']);
            if ($lineId === '' || strlen($lineId) > 64 || preg_match('/^[A-Za-z0-9:._-]+$/D', $lineId) !== 1
                || $projectId <= 0 || $projectUnique === '' || strlen($projectUnique) > 64
                || preg_match('/^[A-Za-z0-9:._-]+$/D', $projectUnique) !== 1) {
                throw $this->failure('inventory_request_line_invalid', ['index' => $index]);
            }
            if (isset($seen[$lineId])) {
                throw $this->failure('inventory_request_line_duplicate', ['lineId' => $lineId]);
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
        return [
            'contractVersion' => $request['contractVersion'],
            'tenantId' => $tenantId,
            'storeId' => $storeId,
            'lines' => $lines,
        ];
    }

    private function lockPolicies(string $tenantId, array $projectIds): array
    {
        // Match the cashier catalog: canonical decimal ids use numeric order;
        // opaque or leading-zero ids retain byte order.
        usort($projectIds, static function ($left, $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds(
                (string)$left,
                (string)$right
            );
        });
        $merchant = Db::name('inventory_shortage_policy')
            ->where('tenant_id', $tenantId)
            ->where('policy_scope', 'MERCHANT')
            ->where('project_id', 0)
            ->lock(true)
            ->find();
        if (!$merchant) {
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
                ->lock(true)
                ->find();
            if (!$project) {
                throw $this->failure('inventory_project_policy_not_ready', ['projectId' => $projectId]);
            }
            $projectPolicy = (string)$project['policy_value'];
            $projectVersion = (int)$project['version'];
            $result[$projectId] = [
                'merchantPolicy' => $merchantPolicy,
                'merchantVersion' => $merchantVersion,
                'projectPolicy' => $projectPolicy,
                'projectVersion' => $projectVersion,
                'resolvedPolicy' => InventoryEntitlementCompletionContract::resolvePolicy($merchantPolicy, $projectPolicy),
                'resolvedVersion' => InventoryEntitlementCompletionContract::policyVersion($merchantVersion, $projectVersion),
            ];
        }
        return $result;
    }

    private function lockRecipes(array $lines, int $storeId): array
    {
        $candidates = [];
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
            if (!$recipe) {
                throw $this->failure('inventory_recipe_not_ready', ['projectId' => $line['projectId']]);
            }
            $candidates[$this->projectKey($line['projectId'], $line['projectUnique'])] = [
                'id' => (int)$recipe['id'],
                'platformProjectId' => $platformProjectId,
                'platformUnique' => $platformUnique,
            ];
        }
        $ids = array_values(array_unique(array_column($candidates, 'id')));
        usort($ids, static function ($left, $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds(
                (string)$left,
                (string)$right
            );
        });
        $lockedById = [];
        foreach ($ids as $id) {
            $recipe = Db::name('store_project_consumable_recipe')->where('id', $id)->lock(true)->find();
            if (!$recipe || (int)$recipe['status'] !== 1 || (int)$recipe['version'] <= 0) {
                throw $this->failure('inventory_recipe_changed', ['recipeId' => $id]);
            }
            $details = Db::name('store_project_consumable_recipe_detail')
                ->where('recipe_id', $id)
                ->order('id asc')
                ->lock(true)
                ->select()
                ->toArray();
            if (!$details) {
                throw $this->failure('inventory_recipe_details_empty', ['recipeId' => $id]);
            }
            $lockedById[$id] = ['recipe' => $recipe, 'details' => $details];
        }
        $result = [];
        foreach ($candidates as $key => $candidate) {
            $set = $lockedById[$candidate['id']];
            if ((int)$set['recipe']['project_product_id'] !== $candidate['platformProjectId']
                || (string)$set['recipe']['project_unique'] !== $candidate['platformUnique']) {
                throw $this->failure('inventory_recipe_changed', ['recipeId' => $candidate['id']]);
            }
            $result[$key] = $set;
        }
        return $result;
    }

    private function resolveMaterialLines(array $recipeSets, int $storeId): array
    {
        $result = [];
        foreach ($recipeSets as $set) {
            $recipeId = (int)$set['recipe']['id'];
            if (isset($result[$recipeId])) {
                continue;
            }
            $materials = [];
            $seen = [];
            foreach ($set['details'] as $detail) {
                [$storeProductId, $storeSkuId, $storeUnique, $productName, $skuName] = $this->mapConsumableToStore(
                    (int)$detail['consumable_product_id'],
                    (string)$detail['consumable_unique'],
                    $storeId
                );
                $key = $storeProductId . ':' . $storeSkuId;
                if (isset($seen[$key])) {
                    throw $this->failure('inventory_recipe_material_duplicate_after_mapping', [
                        'recipeId' => $recipeId,
                        'stockKey' => $key,
                    ]);
                }
                $seen[$key] = true;
                $materials[] = [
                    'storeProductId' => $storeProductId,
                    'storeSkuId' => $storeSkuId,
                    'storeUnique' => $storeUnique,
                    'productName' => $productName,
                    'skuName' => $skuName,
                    'qtyPerService' => (string)$detail['qty_per_writeoff'],
                    'stockId' => 0,
                ];
            }
            usort($materials, static function (array $left, array $right): int {
                return [$left['storeSkuId'], $left['storeProductId']] <=> [$right['storeSkuId'], $right['storeProductId']];
            });
            $result[$recipeId] = $materials;
        }
        return $result;
    }

    private function lockStocksAndBatches(
        string $tenantId,
        int $storeId,
        int $defaultLocationId,
        array &$materialLines
    ): array
    {
        $stockCandidates = [];
        foreach ($materialLines as $recipeId => &$materials) {
            foreach ($materials as &$material) {
                $stock = Db::name('inventory_stock')
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)
                    ->where('location_id', $defaultLocationId)
                    ->where('consumable_product_id', $material['storeProductId'])
                    ->where('sku_id', $material['storeSkuId'])
                    ->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)
                    ->find();
                if (!$stock) {
                    throw $this->failure('inventory_stock_not_ready', [
                        'storeId' => $storeId,
                        'consumableId' => $material['storeProductId'],
                        'skuId' => $material['storeSkuId'],
                    ]);
                }
                $stockId = (int)$stock['id'];
                $material['stockId'] = $stockId;
                $stockCandidates[$stockId] = $stockId;
            }
            unset($material);
        }
        unset($materials);
        uksort($stockCandidates, static function ($left, $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds(
                (string)$left,
                (string)$right
            );
        });
        $stocks = [];
        foreach ($stockCandidates as $stockId) {
            $stock = Db::name('inventory_stock')->where('id', $stockId)->lock(true)->find();
            if (!$stock || (string)$stock['tenant_id'] !== $tenantId || (int)$stock['store_id'] !== $storeId
                || (int)($stock['location_id'] ?? 0) !== $defaultLocationId
                || (string)$stock['stock_status'] !== InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD
                || (int)$stock['version'] <= 0 || (int)$stock['quantity_scale'] < 0
                || (int)$stock['quantity_scale'] > 4) {
                throw $this->failure('inventory_stock_changed', ['stockId' => $stockId]);
            }
            $stocks[(string)$stockId] = $stock;
        }

        $candidateBatchIds = [];
        foreach ($stockCandidates as $stockId) {
            $ids = Db::name('inventory_batch')
                ->where('stock_id', $stockId)
                ->where('batch_status', 'ACTIVE')
                ->column('id');
            foreach ($ids as $id) {
                $candidateBatchIds[(int)$id] = (int)$id;
            }
        }
        uksort($candidateBatchIds, static function ($left, $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds(
                (string)$left,
                (string)$right
            );
        });
        $batchesByStock = [];
        foreach ($candidateBatchIds as $batchId) {
            $batch = Db::name('inventory_batch')->where('id', $batchId)->lock(true)->find();
            if (!$batch || (string)$batch['batch_status'] !== 'ACTIVE' || (int)$batch['version'] <= 0
                || !isset($stocks[(string)(int)$batch['stock_id']])) {
                throw $this->failure('inventory_batch_changed', ['batchId' => $batchId]);
            }
            $batchesByStock[(int)$batch['stock_id']][] = $batch;
        }

        $snapshots = [];
        foreach ($stocks as $stockId => $stock) {
            $batches = $batchesByStock[(int)$stockId] ?? [];
            usort($batches, static function (array $left, array $right): int {
                $leftExpiry = empty($left['expire_date']) ? '9999-12-31' : (string)$left['expire_date'];
                $rightExpiry = empty($right['expire_date']) ? '9999-12-31' : (string)$right['expire_date'];
                $expiry = strcmp($leftExpiry, $rightExpiry);
                if ($expiry !== 0) {
                    return $expiry;
                }
                $received = (int)$left['received_at'] <=> (int)$right['received_at'];
                return $received !== 0 ? $received : ((int)$left['id'] <=> (int)$right['id']);
            });
            $batchSnapshots = [];
            $batchQuantity = 0;
            foreach ($batches as $allocationOrder => $batch) {
                $available = (int)$batch['available_quantity_units'];
                $batchQuantity += $available;
                if ($available <= 0) {
                    continue;
                }
                $batchSnapshots[] = [
                    'batchId' => (int)$batch['id'],
                    'batchVersion' => (int)$batch['version'],
                    'availableQuantityUnits' => $available,
                    'unitCostCents' => (int)$batch['unit_cost_cents'],
                    'costAllocatedQuantityUnitsBefore' => (int)$batch['cost_allocated_quantity_units'],
                    'allocationOrder' => $allocationOrder,
                ];
            }
            if ($batchQuantity !== (int)$stock['available_quantity_units']) {
                throw $this->failure('inventory_stock_batch_balance_mismatch', [
                    'stockId' => $stockId,
                    'stockQuantityUnits' => (int)$stock['available_quantity_units'],
                    'batchQuantityUnits' => $batchQuantity,
                ]);
            }
            $snapshots[] = [
                'stockId' => (string)$stockId,
                'stockVersion' => (int)$stock['version'],
                'consumableId' => (int)$stock['consumable_product_id'],
                'skuId' => (int)$stock['sku_id'],
                'stockUnitScale' => (int)$stock['quantity_scale'],
                'batches' => $batchSnapshots,
            ];
        }
        usort($snapshots, static function (array $left, array $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds(
                $left['stockId'],
                $right['stockId']
            );
        });
        return ['stocks' => $stocks, 'snapshots' => $snapshots];
    }

    private function resolveDefaultLocationId(string $tenantId, int $storeId): int
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
            ->select()
            ->toArray();
        if (count($rows) !== 1 || (int)($rows[0]['id'] ?? 0) <= 0) {
            throw $this->failure('inventory_default_location_not_ready', [
                'storeId' => $storeId,
                'matchedLocations' => count($rows),
            ]);
        }
        return (int)$rows[0]['id'];
    }

    private function lockShortageCostCursor(
        string $tenantId,
        int $storeId,
        int $stockId,
        int $recipeId,
        int $estimatedUnitCostCents
    ): array
    {
        $resourceId = InventoryEntitlementCompletionContract::shortageCursorResourceId(
            $stockId,
            $recipeId,
            $estimatedUnitCostCents
        );
        $now = time();
        Db::execute(
            'INSERT IGNORE INTO `eb_inventory_shortage_cost_cursor`'
            . ' (`tenant_id`,`store_id`,`stock_id`,`recipe_id`,`estimated_unit_cost_cents`,'
            . ' `allocated_quantity_units`,`version`,`created_at`,`updated_at`)'
            . ' VALUES (?,?,?,?,?,0,1,?,?)',
            [$tenantId, $storeId, $stockId, $recipeId, $estimatedUnitCostCents, $now, $now]
        );
        $row = Db::name('inventory_shortage_cost_cursor')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('stock_id', $stockId)
            ->where('recipe_id', $recipeId)
            ->where('estimated_unit_cost_cents', $estimatedUnitCostCents)
            ->lock(true)
            ->find();
        if (!$row || (int)$row['id'] <= 0 || (int)$row['version'] <= 0
            || (int)$row['allocated_quantity_units'] < 0) {
            throw $this->failure('inventory_shortage_cost_cursor_not_ready', [
                'shortageCursorId' => $resourceId,
            ]);
        }
        return [
            'resourceId' => $resourceId,
            'version' => (int)$row['version'],
            'allocatedQuantityUnits' => (int)$row['allocated_quantity_units'],
        ];
    }

    /**
     * Lock shortage cursors once in the same canonical resource-id order later
     * used by the fact writer. Request line order must never decide row locks.
     *
     * @param array<int,array<int,array>> $materialLines
     * @param array<string,array> $stocks
     * @return array<string,array{resourceId:string,version:int,allocatedQuantityUnits:int}>
     */
    private function lockShortageCostCursors(
        string $tenantId,
        int $storeId,
        array $materialLines,
        array $stocks
    ): array {
        $candidates = [];
        foreach ($materialLines as $recipeId => $materials) {
            foreach ($materials as $material) {
                $stockId = (int)($material['stockId'] ?? 0);
                $stock = $stocks[(string)$stockId] ?? null;
                if ($stockId <= 0 || !is_array($stock)) {
                    throw $this->failure('inventory_shortage_cost_cursor_stock_missing');
                }
                $estimatedUnitCostCents = (int)($stock['estimated_unit_cost_cents'] ?? -1);
                if ($estimatedUnitCostCents < 0) {
                    throw $this->failure('inventory_shortage_estimated_cost_invalid');
                }
                $key = $this->shortageCostCursorKey(
                    $stockId,
                    (int)$recipeId,
                    $estimatedUnitCostCents
                );
                $candidates[$key] = [
                    'stockId' => $stockId,
                    'recipeId' => (int)$recipeId,
                    'estimatedUnitCostCents' => $estimatedUnitCostCents,
                ];
            }
        }
        uksort($candidates, static function ($left, $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds(
                (string)$left,
                (string)$right
            );
        });
        $snapshots = [];
        foreach ($candidates as $key => $candidate) {
            $snapshots[$key] = $this->lockShortageCostCursor(
                $tenantId,
                $storeId,
                $candidate['stockId'],
                $candidate['recipeId'],
                $candidate['estimatedUnitCostCents']
            );
        }
        return $snapshots;
    }

    private function shortageCostCursorKey(
        int $stockId,
        int $recipeId,
        int $estimatedUnitCostCents
    ): string {
        return InventoryEntitlementCompletionContract::shortageCursorResourceId(
            $stockId,
            $recipeId,
            $estimatedUnitCostCents
        );
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

    /** @return array{0:int,1:int,2:string,3:string,4:string} */
    private function mapConsumableToStore(int $platformProductId, string $platformUnique, int $storeId): array
    {
        $platformProduct = Db::name('store_product')
            ->where('id', $platformProductId)
            ->where('is_del', 0)
            ->field('id,pid,type,relation_id,store_name,is_inventory')
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
                ->field('id,store_name,is_inventory')
                ->find();
            if (!$storeProduct) {
                throw $this->failure('inventory_store_consumable_not_ready', [
                    'storeId' => $storeId,
                    'platformConsumableId' => $platformProductId,
                ]);
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
                ->field('id,unique,suk')
                ->find();
            if (!$storeSkuByName) {
                throw $this->failure('inventory_store_consumable_sku_not_ready');
            }
            $storeUnique = (string)$storeSkuByName['unique'];
        }
        if ((int)($storeProduct['is_inventory'] ?? 0) !== 1) {
            throw $this->failure('inventory_consumable_management_disabled', [
                'consumableId' => (int)$storeProduct['id'],
            ]);
        }
        $storeSku = Db::name('store_product_attr_value')
            ->where('product_id', (int)$storeProduct['id'])
            ->where('unique', $storeUnique)
            ->where('type', 0)
            ->field('id,unique,suk')
            ->find();
        if (!$storeSku) {
            throw $this->failure('inventory_store_consumable_sku_not_ready');
        }
        return [
            (int)$storeProduct['id'],
            (int)$storeSku['id'],
            (string)$storeSku['unique'],
            (string)$storeProduct['store_name'],
            (string)$storeSku['suk'],
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
        $key = $kind . "\0" . $id;
        if (!isset($resources[$key])) {
            $resources[$key] = [
                'kind' => $kind,
                'id' => $id,
                'lockOrder' => $lockOrder,
                'lockedVersion' => $version,
                'roles' => [],
            ];
        } elseif ($resources[$key]['lockedVersion'] !== $version) {
            throw $this->failure('inventory_locked_resource_version_inconsistent', [
                'kind' => $kind,
                'id' => $id,
            ]);
        }
        if (!in_array($role, $resources[$key]['roles'], true)) {
            $resources[$key]['roles'][] = $role;
        }
    }

    private function persistenceSnapshot(array $stockSets, array $materialLines): array
    {
        $materialByStock = [];
        foreach ($materialLines as $materials) {
            foreach ($materials as $material) {
                $materialByStock[(string)$material['stockId']] = [
                    'consumableNameSnapshot' => $material['productName'],
                    'skuNameSnapshot' => $material['skuName'],
                ];
            }
        }
        $result = [];
        foreach ($stockSets['stocks'] as $stockId => $stock) {
            $batchRows = Db::name('inventory_batch')
                ->where('stock_id', (int)$stockId)
                ->where('batch_status', 'ACTIVE')
                ->field('id,batch_no')
                ->select()
                ->toArray();
            $batchNumbers = [];
            foreach ($batchRows as $batch) {
                $batchNumbers[(string)(int)$batch['id']] = (string)$batch['batch_no'];
            }
            $result[(string)$stockId] = [
                'stockId' => (string)$stockId,
                'locationId' => (int)$stock['location_id'],
                'consumableId' => (int)$stock['consumable_product_id'],
                'skuId' => (int)$stock['sku_id'],
                'quantityScale' => (int)$stock['quantity_scale'],
                'consumableNameSnapshot' => (string)($materialByStock[(string)$stockId]['consumableNameSnapshot'] ?? ''),
                'skuNameSnapshot' => (string)($materialByStock[(string)$stockId]['skuNameSnapshot'] ?? ''),
                'batchNumberById' => $batchNumbers,
            ];
        }
        uksort($result, static function ($left, $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds(
                (string)$left,
                (string)$right
            );
        });
        return $result;
    }

    private function projectNameSnapshotByLineId(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $name = Db::name('store_product')->where('id', $line['projectId'])->value('store_name');
            if (!is_string($name) || trim($name) === '') {
                throw $this->failure('inventory_project_name_snapshot_missing', [
                    'projectId' => $line['projectId'],
                ]);
            }
            $result[$line['lineId']] = trim($name);
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function assertTransaction(): void
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo || !$pdo->inTransaction()) {
            throw $this->failure('inventory_provider_transaction_required');
        }
    }

    private function projectKey(int $projectId, string $unique): string
    {
        return $projectId . "\0" . $unique;
    }

    private function assertExactKeys(array $value, array $expected, string $label): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw $this->failure('inventory_' . $label . '_shape_invalid');
        }
    }

    private function isList(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $_) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }

    private function failure(string $reason, array $detail = []): InventoryCompletionContractException
    {
        return new InventoryCompletionContractException($reason, $detail);
    }
}

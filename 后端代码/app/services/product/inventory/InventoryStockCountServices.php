<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use app\services\product\inventory\completion\InventoryCompletionContractException;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;

/** Confirms one physical stock count against the location-scoped batch authority. */
final class InventoryStockCountServices
{
    public function confirm(int $storeId, int $operatorId, array $input, array $sessionInfo = []): array
    {
        $draftId = (int)($input['draft_id'] ?? 0);
        $draftVersion = (int)($input['draft_version'] ?? 0);
        $command = $this->normalize([
            'idempotency_key' => $input['idempotency_key'] ?? '', 'business_date' => $input['business_date'] ?? '',
            'remark' => $input['remark'] ?? '', 'lines' => $input['lines'] ?? [],
        ]);
        if ($draftId < 0 || ($draftId === 0 && $draftVersion !== 0)
            || ($draftId > 0 && ($draftVersion <= 0 || $command['key'] !== 'count-draft-' . $draftId))) {
            throw new \InvalidArgumentException('inventory_stock_count_draft_invalid');
        }
        return Db::transaction(function () use ($storeId, $operatorId, $sessionInfo, $command, $draftId, $draftVersion): array {
            $scope = $this->scope($storeId, $operatorId, $sessionInfo);
            $location = $this->location($scope);
            return $this->confirmWithDraftAtScope($scope, $location, $command, $draftId, $draftVersion);
        });
    }

    /** 门店与总部共用草稿结算：事务内锁版本、核对快照、幂等入账，不形成两套库存口径。 */
    private function confirmWithDraftAtScope(array $scope, array $location, array $command, int $draftId, int $draftVersion): array
    {
        if ($draftId > 0) {
            $scope['locationId'] = (int)$location['id'];
            $draft = (new InventoryStockCountDraftServices())->lockForConfirmation($scope, $draftId, $draftVersion);
            $this->assertDraftCommandMatches($draft, $command);
            if ((string)$draft['document_status'] === 'CONFIRMED') {
                $existing = Db::name('inventory_stock_count_document')->where('id', (int)$draft['confirmed_document_id'])
                    ->where('tenant_id', $scope['tenantId'])->where('location_id', (int)$location['id'])->find();
                if (!$existing || (string)$existing['idempotency_key'] !== $command['key']
                    || (string)$existing['request_fingerprint'] !== $command['fingerprint']) {
                    throw new \RuntimeException('inventory_stock_count_idempotency_conflict');
                }
                return ['count_document_id' => (int)$existing['id'], 'count_no' => (string)$existing['count_no'], 'idempotent' => true];
            }
        }
        $result = $this->confirmAtScope($scope, $location, $command);
        if ($draftId > 0) (new InventoryStockCountDraftServices())->markConfirmed($draftId, (int)$result['count_document_id'], $draftVersion);
        return $result;
    }

    /** 草稿结算核对全部已填写的实盘数，不用历史账面排除等值行，仍禁止客户端替换明细。 */
    private function assertDraftCommandMatches(array $draft, array $command): void
    {
        $savedLines = json_decode((string)$draft['lines_json'], true, 512, JSON_THROW_ON_ERROR);
        $changed = [];
        foreach ($savedLines as $line) {
            $counted = trim((string)$line['counted_quantity']);
            if ($counted === '') continue;
            $changed[] = [
                'product_id' => (int)$line['product_id'], 'sku_id' => (int)$line['sku_id'],
                'sku_unique' => (string)$line['sku_unique'], 'counted_quantity' => $counted,
                'surplus_batch_no' => (string)$line['surplus_batch_no'],
                'surplus_unit_cost' => (string)$line['surplus_unit_cost'],
                'surplus_manufactured_date' => (string)$line['surplus_manufactured_date'],
                'surplus_expire_date' => (string)$line['surplus_expire_date'],
                'expected_book_quantity' => (string)$line['book_quantity'],
            ];
            if (($line['count_mode'] ?? '') === 'BATCH') $changed[count($changed) - 1]['count_mode'] = 'BATCH';
        }
        if (!$changed) throw new \RuntimeException('inventory_stock_count_no_difference');
        $savedCommand = $this->normalize([
            'idempotency_key' => $command['key'], 'business_date' => (string)$draft['business_date'],
            'remark' => (string)$draft['remark'], 'lines' => $changed,
        ]);
        if ($savedCommand['fingerprint'] !== $command['fingerprint']) {
            // 已结算的旧版本曾只提交与快照不同的行；只允许其原命令重放，不放宽未结算草稿。
            if ((string)$draft['document_status'] === 'CONFIRMED') {
                $legacyLines = array_values(array_filter($changed, static function (array $line): bool {
                    return (float)$line['counted_quantity'] !== (float)$line['expected_book_quantity'];
                }));
                if ($legacyLines) {
                    $legacy = $this->normalize(['idempotency_key' => $command['key'],
                        'business_date' => (string)$draft['business_date'], 'remark' => (string)$draft['remark'], 'lines' => $legacyLines]);
                    if ($legacy['fingerprint'] === $command['fingerprint']) return;
                }
            }
            throw new \RuntimeException('inventory_stock_count_draft_changed');
        }
    }

    /** Confirms a count against a platform-authenticated headquarters location. */
    public function confirmForHeadquarters(array $adminInfo, int $locationId, array $input): array
    {
        // 草稿身份不是库存写入字段；剥离后按同一正式命令契约校验，并保护创建人版本。
        $draftId = (int)($input['draft_id'] ?? 0);
        $draftVersion = (int)($input['draft_version'] ?? 0);
        unset($input['draft_id'], $input['draft_version']);
        $command = $this->normalize($input);
        if ($draftId < 0 || ($draftId === 0 && $draftVersion !== 0)
            || ($draftId > 0 && ($draftVersion <= 0 || $command['key'] !== 'count-draft-' . $draftId))) {
            throw new \InvalidArgumentException('inventory_stock_count_draft_invalid');
        }
        $hq = new InventoryHqLocationServices();
        $resolved = $hq->writableLocation($adminInfo, $locationId);
        $scope = $hq->scope((array)$resolved['location'], (int)($resolved['access']['admin_id'] ?? 0));
        $scope['operatorType'] = 'PLATFORM_ADMIN';
        $scope['operatorName'] = mb_substr(trim((string)($adminInfo['real_name'] ?? $adminInfo['name'] ?? $adminInfo['account'] ?? '')), 0, 128);
        return Db::transaction(function () use ($scope, $resolved, $command, $draftId, $draftVersion): array {
            $location = Db::name('inventory_location')->where('id', (int)$resolved['location']['id'])->lock(true)->find();
            if (!$location || (string)$location['location_type'] !== 'HQ' || (int)$location['store_id'] !== 0 || (string)$location['location_status'] !== 'ACTIVE') {
                throw new \RuntimeException('inventory_hq_location_invalid');
            }
            return $this->confirmWithDraftAtScope($scope, (array)$location, $command, $draftId, $draftVersion);
        });
    }

    private function confirmAtScope(array $scope, array $location, array $command): array
    {
            $operatorId = (int)$scope['operatorId'];
            $existing = Db::name('inventory_stock_count_document')->where('tenant_id', $scope['tenantId'])->where('idempotency_key', $command['key'])->lock(true)->find();
            if ($existing) {
                if ((string)$existing['request_fingerprint'] !== $command['fingerprint'] || (int)$existing['location_id'] !== (int)$location['id']) throw new \RuntimeException('inventory_stock_count_idempotency_conflict');
                return ['count_document_id' => (int)$existing['id'], 'count_no' => (string)$existing['count_no'], 'idempotent' => true];
            }
            // 先一次性核对整单目录身份，再创建单头和移动事实；失效行必须整批返回，不能让用户逐次试错。
            $this->assertCatalogLines($scope, $location, $command['lines']);
            $documentId = (int)Db::name('inventory_stock_count_document')->insertGetId([
                'count_no' => (new InventoryBusinessDocumentNumberServices())->next($scope['tenantId'], InventoryBusinessDocumentNumberServices::COUNT, $command['businessDate'], $command['now']), 'idempotency_key' => $command['key'], 'request_fingerprint' => $command['fingerprint'],
                'tenant_id' => $scope['tenantId'], 'organization_id' => $scope['organizationId'], 'organization_path' => $scope['organizationPath'], 'location_id' => (int)$location['id'],
                'store_id' => $scope['storeId'], 'operator_id' => $operatorId,
                'operator_type' => $scope['operatorType'] ?? 'PLATFORM_ADMIN',
                'operator_name_snapshot' => (string)($scope['operatorName'] ?? ''),
                'document_status' => 'CONFIRMED', 'remark' => $command['remark'], 'business_date' => $command['businessDate'], 'confirmed_at' => $command['now'], 'recorded_at' => $command['now'],
            ]);
            $document = Db::name('inventory_stock_count_document')->where('id', $documentId)->lock(true)->find();
            $ordered = $command['lines']; usort($ordered, static fn(array $a, array $b): int => [$a['productId'], $a['skuId'], $a['unique'], $a['index']] <=> [$b['productId'], $b['skuId'], $b['unique'], $b['index']]);
            $changed = 0;
            foreach ($ordered as $line) {
                if ($this->confirmLine($scope, $location, $document, $line, $command)) $changed++;
            }
            // 全部等于账面数时回滚单头及任何中间写入，不留下空盘点单。
            if ($changed === 0) throw new \RuntimeException('inventory_stock_count_no_difference');
            return ['count_document_id' => $documentId, 'count_no' => (string)$document['count_no'], 'idempotent' => false];
    }

    /** Locks valid catalog rows and reports every stale product/SKU identity before any count write starts. */
    private function assertCatalogLines(array $scope, array $location, array $lines): void
    {
        $productIds = array_values(array_unique(array_map(static fn(array $line): int => $line['productId'], $lines)));
        $skuIds = array_values(array_unique(array_map(static fn(array $line): int => $line['skuId'], $lines)));
        $catalogRows = Db::name('store_product_attr_value')->alias('a')->join('store_product p', 'p.id=a.product_id')
            ->whereIn('p.id', $productIds)->whereIn('a.id', $skuIds)
            ->where('p.type', (string)$location['location_type'] === 'HQ' ? 0 : 1)
            ->where('p.relation_id', (string)$location['location_type'] === 'HQ' ? 0 : (int)$scope['storeId'])
            ->where('p.is_del', 0)->where('p.is_inventory', 1)->where('a.type', 0)
            ->field('p.id AS product_id,a.id AS sku_id,a.unique AS sku_unique')->lock(true)->select()->toArray();
        $valid = [];
        foreach ($catalogRows as $row) {
            $valid[(int)$row['product_id'] . ':' . (int)$row['sku_id'] . ':' . (string)$row['sku_unique']] = true;
        }
        $invalid = [];
        foreach ($lines as $line) {
            $identity = $line['productId'] . ':' . $line['skuId'] . ':' . $line['unique'];
            if (isset($valid[$identity]) || $this->retiredBatchCanOnlyBeZeroed($scope, $location, $line)) continue;
            $invalid[] = $this->invalidCatalogLine($line);
        }
        if ($invalid) throw new InventoryStockCountCatalogException($invalid);
    }

    /** 失效目录仅可处理本仓已有的正数批次，并且必须把该批实盘置零；绝不放行新增、盘盈或其他仓库存。 */
    private function retiredBatchCanOnlyBeZeroed(array $scope, array $location, array $line): bool
    {
        if (($line['countMode'] ?? '') !== 'BATCH' || $line['batchNo'] === '' || (float)$line['counted'] !== 0.0) return false;
        $stock = Db::name('inventory_stock')->where('tenant_id', $scope['tenantId'])
            ->where('store_id', $scope['storeId'])->where('location_id', (int)$location['id'])
            ->where('consumable_product_id', $line['productId'])->where('sku_id', $line['skuId'])
            ->where('product_unique', $line['unique'])->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)
            ->where('available_quantity_units', '>', 0)->lock(true)->find();
        if (!$stock) return false;
        return (bool)Db::name('inventory_batch')->where('stock_id', (int)$stock['id'])
            ->where('batch_no', $line['batchNo'])->where('batch_status', 'ACTIVE')
            ->where('available_quantity_units', '>', 0)->lock(true)->find();
    }

    /** Returns only stable client identities; names are resolved from the user's unchanged draft snapshot. */
    private function invalidCatalogLine(array $line): array
    {
        return [
            'line_index' => (int)$line['index'] + 1,
            'product_id' => (int)$line['productId'],
            'sku_id' => (int)$line['skuId'],
            'sku_unique' => (string)$line['unique'],
        ];
    }

    /** 实盘为最终数量；锁定当前账面计算调整，零差异不创建明细或移动事实。 */
    private function confirmLine(array $scope, array $location, array $document, array $line, array $command): bool
    {
        $stock = $this->countableStock($scope, $location, $line, $command['now'], false);
        if ((int)$stock['quantity_scale'] < 0 || (int)$stock['quantity_scale'] > 4) throw new \RuntimeException('inventory_stock_count_stock_not_found');
        $scale = (int)$stock['quantity_scale']; $book = (int)$stock['available_quantity_units']; $counted = $this->countUnits($line['counted'], $scale); $difference = $counted - $book;
        if (($line['countMode'] ?? '') === 'BATCH') {
            // 同规格多行按批号独立盘点；未列出的批次保持原状，不得把每行实盘覆盖商品总余额。
            $batch = (int)$stock['id'] > 0 ? Db::name('inventory_batch')->where('stock_id', (int)$stock['id'])
                ->where('batch_no', $line['batchNo'])->lock(true)->find() : null;
            if ($batch && (string)$batch['batch_status'] !== 'ACTIVE') throw new \RuntimeException('inventory_stock_count_surplus_batch_conflict');
            $book = (int)($batch['available_quantity_units'] ?? 0);
            $difference = $counted - $book;
            // 无成本查看权限时导入不泄露单价；已有批次调整使用服务端权威成本和日期。
            if ($batch) {
                if (!$line['costProvided'] || $difference < 0) $line['cost'] = (int)$batch['unit_cost_cents'];
                if (!$line['manufacturedDate']) $line['manufacturedDate'] = $batch['manufactured_date'];
                if (!$line['expireDate']) $line['expireDate'] = $batch['expire_date'];
                if ($difference < 0) { $line['manufacturedDate'] = $batch['manufactured_date']; $line['expireDate'] = $batch['expire_date']; }
            }
            if (!$batch && $difference > 0 && !$line['costProvided']) throw new InventoryStockCountLineException('inventory_stock_count_surplus_batch_required', $line['index'] + 1);
        }
        // 产品口径：即使导出/草稿后的库存变化，也以本次实盘为最终数。
        // expectedBook 仅保留命令指纹兼容，不作为并发库存门禁；事务锁与幂等仍有效。
        if ($difference === 0) return false;
        if ((int)$stock['id'] === 0) $stock = $this->countableStock($scope, $location, $line, $command['now']);
        $surplus = ['batchNo' => '', 'cost' => 0, 'manufacturedDate' => null, 'expireDate' => null];
        if ($difference < 0) $this->countLoss($scope, $location, $stock, -$difference, $document, $line, $command);
        if ($difference > 0) $surplus = $this->countGain($scope, $location, $stock, $difference, $document, $line, $command);
        // 复用既有批次快照列保存按批次盘亏的身份，历史查询仍从移动事实取得真实成本。
        if (($line['countMode'] ?? '') === 'BATCH' && $difference < 0) $surplus = ['batchNo' => $line['batchNo'], 'cost' => $line['cost'], 'manufacturedDate' => $line['manufacturedDate'], 'expireDate' => $line['expireDate']];
        Db::name('inventory_stock_count_line')->insert([
            'document_id' => (int)$document['id'], 'line_no' => $line['index'], 'stock_id' => (int)$stock['id'], 'product_id' => $line['productId'], 'sku_id' => $line['skuId'], 'sku_unique' => $line['unique'], 'quantity_scale' => $scale,
            'book_quantity_units' => $book, 'counted_quantity_units' => $counted, 'difference_quantity_units' => $difference, 'surplus_batch_no' => $surplus['batchNo'], 'surplus_unit_cost_cents' => $surplus['cost'], 'surplus_manufactured_date' => $surplus['manufacturedDate'], 'surplus_expire_date' => $surplus['expireDate'], 'created_at' => $command['now'],
        ]);
        return true;
    }

    /** Physical and book counts may be zero; quantity precision and overflow remain governed by the inventory contract. */
    private function countUnits(string $quantity, int $scale): int
    {
        try {
            return InventoryEntitlementCompletionContract::decimalToUnits($quantity, $scale);
        } catch (InventoryCompletionContractException $exception) {
            if ($exception->reason() !== 'inventory_quantity_not_positive') throw $exception;
            return 0;
        }
    }

    /** A catalog SKU with no historical stock is still countable; its zero authority is created only on final confirmation. */
    private function countableStock(array $scope, array $location, array $line, int $now, bool $createMissing = true): array
    {
        $catalog = Db::name('store_product_attr_value')->alias('a')->join('store_product p', 'p.id=a.product_id')
            ->where('p.id', $line['productId'])->where('p.type', (string)$location['location_type'] === 'HQ' ? 0 : 1)
            ->where('p.relation_id', (string)$location['location_type'] === 'HQ' ? 0 : (int)$scope['storeId'])
            ->where('p.is_del', 0)->where('p.is_inventory', 1)->where('a.id', $line['skuId'])
            ->where('a.unique', $line['unique'])->where('a.type', 0)
            ->field('a.stock_unit,p.salon_stock_enabled')->lock(true)->find();
        // 上游已做整单目录核验；这里再锁定失效商品的现有批次，防止确认途中转为盘盈。
        if (!$catalog && !$this->retiredBatchCanOnlyBeZeroed($scope, $location, $line)) {
            throw new InventoryStockCountCatalogException([$this->invalidCatalogLine($line)]);
        }
        $query = Db::name('inventory_stock')->where('tenant_id', $scope['tenantId'])
            ->where('store_id', $scope['storeId'])->where('location_id', (int)$location['id'])->where('consumable_product_id', $line['productId'])
            // 库存唯一键按商品和 SKU 建立；历史规格编码变化不能分裂同一库存余额。
            ->where('sku_id', $line['skuId'])
            ->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD);
        $stock = $query->lock(true)->find();
        if ($stock) return (array)$stock;
        if (!$catalog) throw new InventoryStockCountCatalogException([$this->invalidCatalogLine($line)]);
        // 未盘或零差异的全量目录行不能因确认请求而创建零库存主体。
        if (!$createMissing) return [
            'id' => 0, 'quantity_scale' => (int)$catalog['salon_stock_enabled'] === 1 ? 2 : 0,
            'available_quantity_units' => 0,
        ];
        // 与手工入库使用同一库存主体与规格精度；并发创建由唯一键保护后重读。
        try {
            $id = (int)Db::name('inventory_stock')->insertGetId([
                'tenant_id' => $scope['tenantId'], 'organization_id' => $scope['organizationId'],
                'organization_path' => $scope['organizationPath'], 'location_id' => (int)$location['id'],
                'store_id' => $scope['storeId'], 'consumable_product_id' => $line['productId'],
                'sku_id' => $line['skuId'], 'product_unique' => $line['unique'],
                'stock_status' => InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD,
                'stock_unit' => trim((string)$catalog['stock_unit']),
                'quantity_scale' => (int)$catalog['salon_stock_enabled'] === 1 ? 2 : 0,
                'available_quantity_units' => 0, 'estimated_unit_cost_cents' => 0,
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $stock = Db::name('inventory_stock')->where('id', $id)->lock(true)->find();
        } catch (\Throwable $exception) {
            $stock = $query->lock(true)->find();
            if (!$stock) throw $exception;
        }
        if (!$stock) throw new \RuntimeException('inventory_stock_count_stock_not_found');
        return (array)$stock;
    }

    private function countLoss(array $scope, array $location, array $stock, int $units, array $document, array $line, array $command): void
    {
        // 批次盘亏只能扣所盘批次；旧商品总量盘点继续使用 FEFO，不能混用两种粒度。
        $batchQuery = Db::name('inventory_batch')->where('stock_id', (int)$stock['id'])->where('batch_status', 'ACTIVE')->where('available_quantity_units', '>', 0);
        if (($line['countMode'] ?? '') === 'BATCH') $batchQuery->where('batch_no', $line['batchNo']);
        $batches = $batchQuery->orderRaw('expire_date IS NULL ASC, expire_date ASC, received_business_date IS NULL ASC, received_business_date ASC, id ASC')->lock(true)->select()->toArray();
        $remaining = $units; $allocations = [];
        foreach ($batches as $batch) { $take = min((int)$batch['available_quantity_units'], $remaining); if ($take) $allocations[] = [$batch, $take]; $remaining -= $take; if (!$remaining) break; }
        if ($remaining) throw new \RuntimeException('inventory_stock_count_loss_exceeds_book');
        if (Db::name('inventory_stock')->where('id', (int)$stock['id'])->where('version', (int)$stock['version'])->update(['available_quantity_units' => (int)$stock['available_quantity_units'] - $units, 'version' => (int)$stock['version'] + 1, 'updated_at' => $command['now']]) !== 1) throw new \RuntimeException('inventory_stock_count_stock_changed');
        foreach ($allocations as $i => [$batch, $take]) {
            if (Db::name('inventory_batch')->where('id', (int)$batch['id'])->where('version', (int)$batch['version'])->update(['available_quantity_units' => (int)$batch['available_quantity_units'] - $take, 'version' => (int)$batch['version'] + 1, 'updated_at' => $command['now']]) !== 1) throw new \RuntimeException('inventory_stock_count_batch_changed');
            $this->fact($scope, $stock, $batch, -1, $take, 'stock_count_loss', (string)$document['count_no'], $line['index'] . ':' . $i, $command);
        }
    }

    private function countGain(array $scope, array $location, array $stock, int $units, array $document, array $line, array $command): array
    {
        // The locked stock decides whether this is a gain; return its original input position so stale drafts can identify the failed row.
        if ($line['batchNo'] === '' || $line['cost'] < 0 || !$line['manufacturedDate'] || !$line['expireDate'] || $line['manufacturedDate'] > $line['expireDate']) throw new InventoryStockCountLineException('inventory_stock_count_surplus_batch_required', $line['index'] + 1);
        $batch = Db::name('inventory_batch')->where('stock_id', (int)$stock['id'])->where('batch_no', $line['batchNo'])->lock(true)->find();
        if ($batch && ((string)$batch['batch_status'] !== 'ACTIVE' || (int)$batch['unit_cost_cents'] !== $line['cost'] || (string)$batch['manufactured_date'] !== $line['manufacturedDate'] || (string)$batch['expire_date'] !== $line['expireDate'])) throw new \RuntimeException('inventory_stock_count_surplus_batch_conflict');
        if (!$batch) { $catalog=Db::name('store_product_attr_value')->alias('a')->join('store_product p','p.id=a.product_id')->where('p.id',$line['productId'])->where('p.relation_id',$scope['storeId'])->where('p.is_del',0)->where('p.is_inventory',1)->where('a.id',$line['skuId'])->where('a.unique',$line['unique'])->where('a.type',0)->field('p.store_name,p.code,a.suk,a.bar_code')->lock(true)->find(); if(!$catalog)throw new InventoryStockCountCatalogException([$this->invalidCatalogLine($line)]); $id = Db::name('inventory_batch')->insertGetId(['stock_id' => (int)$stock['id'], 'origin_batch_id' => 0, 'source_batch_id' => 0, 'batch_no' => $line['batchNo'], 'manufactured_date' => $line['manufacturedDate'], 'expire_date' => $line['expireDate'], 'received_at' => $command['now'], 'received_business_date' => $command['businessDate'], 'available_quantity_units' => 0, 'unit_cost_cents' => $line['cost'], 'cost_allocated_quantity_units' => 0, 'batch_status' => 'ACTIVE', 'version' => 1, 'product_name_snapshot' => (string)$catalog['store_name'], 'sku_name_snapshot' => (string)$catalog['suk'], 'product_code_snapshot' => (string)$catalog['code'], 'barcode_snapshot' => (string)$catalog['bar_code'], 'brand_name_snapshot' => '', 'category_name_snapshot' => '', 'source_order_no_snapshot' => (string)$document['count_no'], 'data_quality' => 'COMPLETE', 'created_at' => $command['now'], 'updated_at' => $command['now']]); Db::name('inventory_batch')->where('id', $id)->update(['origin_batch_id' => $id]); $batch = Db::name('inventory_batch')->where('id', $id)->lock(true)->find(); }
        $old = (int)$stock['available_quantity_units']; $available = $old + $units; $cost = intdiv($old * (int)$stock['estimated_unit_cost_cents'] + $units * $line['cost'], $available);
        if (Db::name('inventory_stock')->where('id', (int)$stock['id'])->where('version', (int)$stock['version'])->update(['available_quantity_units' => $available, 'estimated_unit_cost_cents' => $cost, 'version' => (int)$stock['version'] + 1, 'updated_at' => $command['now']]) !== 1) throw new \RuntimeException('inventory_stock_count_stock_changed');
        if (Db::name('inventory_batch')->where('id', (int)$batch['id'])->where('version', (int)$batch['version'])->update(['available_quantity_units' => (int)$batch['available_quantity_units'] + $units, 'version' => (int)$batch['version'] + 1, 'updated_at' => $command['now']]) !== 1) throw new \RuntimeException('inventory_stock_count_batch_changed');
        $this->fact($scope, $stock, $batch, 1, $units, 'stock_count_gain', (string)$document['count_no'], (string)$line['index'], $command);
        return ['batchNo' => $line['batchNo'], 'cost' => $line['cost'], 'manufacturedDate' => $line['manufacturedDate'], 'expireDate' => $line['expireDate']];
    }

    private function fact(array $scope, array $stock, array $batch, int $direction, int $units, string $type, string $source, string $detail, array $command): void { $scale = (int)$stock['quantity_scale']; (new InventoryBatchMovementFactServices())->append(['factKey' => 'stock-count:' . hash('sha256', $source . ':' . $detail), 'tenantId' => $scope['tenantId'], 'organizationId' => $scope['organizationId'], 'organizationPath' => $scope['organizationPath'], 'storeId' => $scope['storeId'], 'stockId' => (int)$stock['id'], 'batchId' => (int)$batch['id'], 'direction' => $direction, 'quantityUnits' => $units, 'unitCostCents' => (int)$batch['unit_cost_cents'], 'costAmountCents' => intdiv($units * (int)$batch['unit_cost_cents'], 10 ** $scale), 'sourceType' => $type, 'sourceId' => $source, 'sourceDetailId' => $detail, 'reversalOf' => 0, 'businessDate' => $command['businessDate'], 'occurredAt' => $command['now'], 'settledAt' => $command['now'], 'recordedAt' => $command['now'], 'operatorType' => (string)($scope['operatorType'] ?? 'PLATFORM_ADMIN'), 'operatorId' => (int)$scope['operatorId'], 'operatorName' => (string)($scope['operatorName'] ?? '')]); }

    /** Lock the store and organization path, while the creator identity stays stable across organization logins. */
    private function scope(int $storeId, int $operatorId, array $sessionInfo): array
    {
        $actor = (new InventoryStockCountActorServices())->resolve($storeId, $operatorId, $sessionInfo);
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->where('is_show', 1)->lock(true)->find();
        $binding = Db::name('organization_store')->where('store_id', $storeId)->lock(true)->find();
        $org = (int)($binding['org_id'] ?? 0);
        if (!$store || $org <= 0) throw new \RuntimeException('inventory_stock_count_scope_denied');
        $path = []; $seen = []; $current = $org;
        for ($i = 0; $i < 64; $i++) {
            if (isset($seen[$current])) throw new \RuntimeException('inventory_stock_count_scope_denied');
            $seen[$current] = true;
            $node = Db::name('organization')->where('id', $current)->where('is_del', 0)->lock(true)->find();
            if (!$node) throw new \RuntimeException('inventory_stock_count_scope_denied');
            $path[] = (int)$node['id'];
            $parent = (int)$node['pid'];
            if ($parent === 0) break;
            if ($parent < 0) throw new \RuntimeException('inventory_stock_count_scope_denied');
            $current = $parent;
        }
        if (!$path || (int)end($path) !== $current) throw new \RuntimeException('inventory_stock_count_scope_denied');
        return ['tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID,
            'organizationId' => (string)$org, 'organizationPath' => '/' . implode('/', array_reverse($path)) . '/',
            'storeId' => $storeId, 'operatorId' => $actor['id'], 'operatorType' => $actor['type'], 'operatorName' => $actor['name']];
    }
    /** Reconcile a stale organization snapshot under the count transaction; never infer ownership from the client. */
    private function location(array $scope): array
    {
        $rows = Db::name('inventory_location')->where('tenant_id', $scope['tenantId'])
            ->where('store_id', $scope['storeId'])->where('location_type', 'STORE')
            ->where('is_default', 1)->where('location_status', 'ACTIVE')
            ->limit(2)->lock(true)->select()->toArray();
        if (count($rows) !== 1) throw new \RuntimeException('inventory_stock_count_location_invalid');
        return (new InventoryStoreScopeReconciliationServices())->reconcileLocation($rows[0], $scope, time());
    }
    /** One document remains atomic regardless of its SKU count; duplicate SKUs are rejected before stock changes. */
    private function normalize(array $input): array
    {
        if (array_keys($input) !== ['idempotency_key', 'business_date', 'remark', 'lines']
            || !is_array($input['lines']) || !$input['lines']) throw new \InvalidArgumentException('inventory_stock_count_input_invalid');
        $key = trim((string)$input['idempotency_key']);
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1) throw new \InvalidArgumentException('inventory_stock_count_idempotency_invalid');
        $dateText = trim((string)$input['business_date']);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
        if (!$date || $date->format('Y-m-d') !== $dateText) throw new \InvalidArgumentException('inventory_stock_count_date_invalid');
        $lines = []; $seen = []; $modes = [];
        foreach (array_values($input['lines']) as $i => $line) {
            $keys = ['product_id','sku_id','sku_unique','counted_quantity','surplus_batch_no','surplus_unit_cost','surplus_manufactured_date','surplus_expire_date'];
            $withBook = [...$keys, 'expected_book_quantity'];
            $batchKeys = [...$withBook, 'count_mode'];
            if (!is_array($line) || (array_keys($line) !== $keys && array_keys($line) !== $withBook && array_keys($line) !== $batchKeys)
                || !is_int($line['product_id']) || !is_int($line['sku_id'])
                || $line['product_id'] <= 0 || $line['sku_id'] <= 0
                || trim((string)$line['sku_unique']) === ''
                || !preg_match('/^\d+(?:\.\d{1,4})?$/D', trim((string)$line['counted_quantity']))) {
                throw new \InvalidArgumentException('inventory_stock_count_line_invalid');
            }
            $identity = $line['product_id'] . ':' . $line['sku_id'] . ':' . $line['sku_unique'];
            $mode = $line['count_mode'] ?? 'SKU';
            if (!in_array($mode, ['SKU', 'BATCH'], true)) throw new \InvalidArgumentException('inventory_stock_count_line_invalid');
            if ($mode === 'BATCH' && trim((string)$line['surplus_batch_no']) === '') throw new \InvalidArgumentException('inventory_stock_count_batch_required');
            // 同规格不能同时按总量和按批次结算；同批号重复仍整单拒绝。
            if (isset($modes[$identity]) && $modes[$identity] !== $mode) throw new \InvalidArgumentException('inventory_stock_count_mixed_granularity');
            $modes[$identity] = $mode;
            $rowIdentity = $identity . ':' . $mode . ':' . ($mode === 'BATCH' ? trim((string)$line['surplus_batch_no']) : '');
            if (isset($seen[$rowIdentity])) throw new \InvalidArgumentException('inventory_stock_count_duplicate_sku');
            $seen[$rowIdentity] = true;
            $cost = trim((string)$line['surplus_unit_cost']);
            if ($cost !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/D', $cost)) throw new \InvalidArgumentException('inventory_stock_count_cost_invalid');
            [$w, $f] = array_pad(explode('.', $cost === '' ? '0' : $cost, 2), 2, '');
            $expected = $line['expected_book_quantity'] ?? null;
            if ($expected !== null && !preg_match('/^\d+(?:\.\d{1,4})?$/D', trim((string)$expected))) {
                throw new \InvalidArgumentException('inventory_stock_count_line_invalid');
            }
            $normalized = [
                'index' => $i, 'productId' => $line['product_id'], 'skuId' => $line['sku_id'],
                'unique' => trim((string)$line['sku_unique']), 'counted' => trim((string)$line['counted_quantity']),
                'batchNo' => trim((string)$line['surplus_batch_no']),
                'cost' => (int)$w * 100 + (int)str_pad($f, 2, '0'),
                'manufacturedDate' => trim((string)$line['surplus_manufactured_date']) ?: null,
                'expireDate' => trim((string)$line['surplus_expire_date']) ?: null,
            ];
            // 旧请求的指纹必须保持原结构，使部署前已成功的幂等重放仍能命中。
            if ($expected !== null) $normalized['expectedBook'] = trim((string)$expected);
            if ($mode === 'BATCH') { $normalized['countMode'] = 'BATCH'; $normalized['costProvided'] = $cost !== ''; }
            $lines[] = $normalized;
        }
        $remark = mb_substr(trim((string)$input['remark']), 0, 500);
        $fingerprint = hash('sha256', json_encode(['business_date' => $dateText, 'remark' => $remark, 'lines' => $lines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['key' => $key, 'businessDate' => $dateText, 'remark' => $remark, 'lines' => $lines, 'fingerprint' => $fingerprint, 'now' => time()];
    }
}

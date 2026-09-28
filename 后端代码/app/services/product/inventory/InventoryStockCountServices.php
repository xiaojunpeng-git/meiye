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
    public function confirm(int $storeId, int $operatorId, array $input): array
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
        return Db::transaction(function () use ($storeId, $operatorId, $command, $draftId, $draftVersion): array {
            $scope = $this->scope($storeId, $operatorId);
            $location = $this->location($scope);
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
        });
    }

    /** A resumed draft must settle exactly its saved differences, never an unrelated client-substituted line set. */
    private function assertDraftCommandMatches(array $draft, array $command): void
    {
        $savedLines = json_decode((string)$draft['lines_json'], true, 512, JSON_THROW_ON_ERROR);
        $changed = [];
        foreach ($savedLines as $line) {
            $counted = trim((string)$line['counted_quantity']);
            if ($counted === '' || (float)$counted === (float)$line['book_quantity']) continue;
            $changed[] = [
                'product_id' => (int)$line['product_id'], 'sku_id' => (int)$line['sku_id'],
                'sku_unique' => (string)$line['sku_unique'], 'counted_quantity' => $counted,
                'surplus_batch_no' => (string)$line['surplus_batch_no'],
                'surplus_unit_cost' => (string)$line['surplus_unit_cost'],
                'surplus_manufactured_date' => (string)$line['surplus_manufactured_date'],
                'surplus_expire_date' => (string)$line['surplus_expire_date'],
                'expected_book_quantity' => (string)$line['book_quantity'],
            ];
        }
        if (!$changed) throw new \RuntimeException('inventory_stock_count_no_difference');
        $savedCommand = $this->normalize([
            'idempotency_key' => $command['key'], 'business_date' => (string)$draft['business_date'],
            'remark' => (string)$draft['remark'], 'lines' => $changed,
        ]);
        if ($savedCommand['fingerprint'] !== $command['fingerprint']) {
            throw new \RuntimeException('inventory_stock_count_draft_changed');
        }
    }

    /** Confirms a count against a platform-authenticated headquarters location. */
    public function confirmForHeadquarters(array $adminInfo, int $locationId, array $input): array
    {
        $command = $this->normalize($input);
        $hq = new InventoryHqLocationServices();
        $resolved = $hq->writableLocation($adminInfo, $locationId);
        $scope = $hq->scope((array)$resolved['location'], (int)($resolved['access']['admin_id'] ?? 0));
        return Db::transaction(function () use ($scope, $resolved, $command): array {
            $location = Db::name('inventory_location')->where('id', (int)$resolved['location']['id'])->lock(true)->find();
            if (!$location || (string)$location['location_type'] !== 'HQ' || (int)$location['store_id'] !== 0 || (string)$location['location_status'] !== 'ACTIVE') {
                throw new \RuntimeException('inventory_hq_location_invalid');
            }
            return $this->confirmAtScope($scope, (array)$location, $command);
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
            $documentId = (int)Db::name('inventory_stock_count_document')->insertGetId([
                'count_no' => (new InventoryBusinessDocumentNumberServices())->next($scope['tenantId'], InventoryBusinessDocumentNumberServices::COUNT, $command['businessDate'], $command['now']), 'idempotency_key' => $command['key'], 'request_fingerprint' => $command['fingerprint'],
                'tenant_id' => $scope['tenantId'], 'organization_id' => $scope['organizationId'], 'organization_path' => $scope['organizationPath'], 'location_id' => (int)$location['id'],
                'store_id' => $scope['storeId'], 'operator_id' => $operatorId, 'document_status' => 'CONFIRMED', 'remark' => $command['remark'], 'business_date' => $command['businessDate'], 'confirmed_at' => $command['now'], 'recorded_at' => $command['now'],
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

    /** 未变动规格只校验权威账面值，不创建库存主体、盘点明细或移动事实。 */
    private function confirmLine(array $scope, array $location, array $document, array $line, array $command): bool
    {
        $stock = $this->countableStock($scope, $location, $line, $command['now'], false);
        if ((int)$stock['quantity_scale'] < 0 || (int)$stock['quantity_scale'] > 4) throw new \RuntimeException('inventory_stock_count_stock_not_found');
        $scale = (int)$stock['quantity_scale']; $book = (int)$stock['available_quantity_units']; $counted = $this->countUnits($line['counted'], $scale); $difference = $counted - $book;
        // 导出后的账面数若变化，必须重新核对，避免“清零”误清后来入库的数量。
        if (($line['expectedBook'] ?? null) !== null
            && $this->countUnits($line['expectedBook'], $scale) !== $book) {
            throw new \RuntimeException('inventory_stock_count_stock_changed');
        }
        if ($difference === 0) return false;
        if ((int)$stock['id'] === 0) $stock = $this->countableStock($scope, $location, $line, $command['now']);
        $surplus = ['batchNo' => '', 'cost' => 0, 'manufacturedDate' => null, 'expireDate' => null];
        if ($difference < 0) $this->countLoss($scope, $location, $stock, -$difference, $document, $line, $command);
        if ($difference > 0) $surplus = $this->countGain($scope, $location, $stock, $difference, $document, $line, $command);
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
        if (!$catalog) throw new \RuntimeException('inventory_stock_count_catalog_not_found');
        $query = Db::name('inventory_stock')->where('tenant_id', $scope['tenantId'])
            ->where('location_id', (int)$location['id'])->where('consumable_product_id', $line['productId'])
            // 库存唯一键按商品和 SKU 建立；历史规格编码变化不能分裂同一库存余额。
            ->where('sku_id', $line['skuId'])
            ->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD);
        $stock = $query->lock(true)->find();
        if ($stock) return (array)$stock;
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
        $batches = Db::name('inventory_batch')->where('stock_id', (int)$stock['id'])->where('batch_status', 'ACTIVE')->where('available_quantity_units', '>', 0)->orderRaw('expire_date IS NULL ASC, expire_date ASC, received_business_date IS NULL ASC, received_business_date ASC, id ASC')->lock(true)->select()->toArray();
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
        if ($line['batchNo'] === '' || $line['cost'] < 0 || !$line['manufacturedDate'] || !$line['expireDate'] || $line['manufacturedDate'] > $line['expireDate']) throw new \RuntimeException('inventory_stock_count_surplus_batch_required');
        $batch = Db::name('inventory_batch')->where('stock_id', (int)$stock['id'])->where('batch_no', $line['batchNo'])->lock(true)->find();
        if ($batch && ((string)$batch['batch_status'] !== 'ACTIVE' || (int)$batch['unit_cost_cents'] !== $line['cost'] || (string)$batch['manufactured_date'] !== $line['manufacturedDate'] || (string)$batch['expire_date'] !== $line['expireDate'])) throw new \RuntimeException('inventory_stock_count_surplus_batch_conflict');
        if (!$batch) { $catalog=Db::name('store_product_attr_value')->alias('a')->join('store_product p','p.id=a.product_id')->where('p.id',$line['productId'])->where('p.relation_id',$scope['storeId'])->where('p.is_del',0)->where('p.is_inventory',1)->where('a.id',$line['skuId'])->where('a.unique',$line['unique'])->where('a.type',0)->field('p.store_name,p.code,a.suk,a.bar_code')->lock(true)->find(); if(!$catalog)throw new \RuntimeException('inventory_stock_count_catalog_not_found'); $id = Db::name('inventory_batch')->insertGetId(['stock_id' => (int)$stock['id'], 'origin_batch_id' => 0, 'source_batch_id' => 0, 'batch_no' => $line['batchNo'], 'manufactured_date' => $line['manufacturedDate'], 'expire_date' => $line['expireDate'], 'received_at' => $command['now'], 'received_business_date' => $command['businessDate'], 'available_quantity_units' => 0, 'unit_cost_cents' => $line['cost'], 'cost_allocated_quantity_units' => 0, 'batch_status' => 'ACTIVE', 'version' => 1, 'product_name_snapshot' => (string)$catalog['store_name'], 'sku_name_snapshot' => (string)$catalog['suk'], 'product_code_snapshot' => (string)$catalog['code'], 'barcode_snapshot' => (string)$catalog['bar_code'], 'brand_name_snapshot' => '', 'category_name_snapshot' => '', 'source_order_no_snapshot' => (string)$document['count_no'], 'data_quality' => 'COMPLETE', 'created_at' => $command['now'], 'updated_at' => $command['now']]); Db::name('inventory_batch')->where('id', $id)->update(['origin_batch_id' => $id]); $batch = Db::name('inventory_batch')->where('id', $id)->lock(true)->find(); }
        $old = (int)$stock['available_quantity_units']; $available = $old + $units; $cost = intdiv($old * (int)$stock['estimated_unit_cost_cents'] + $units * $line['cost'], $available);
        if (Db::name('inventory_stock')->where('id', (int)$stock['id'])->where('version', (int)$stock['version'])->update(['available_quantity_units' => $available, 'estimated_unit_cost_cents' => $cost, 'version' => (int)$stock['version'] + 1, 'updated_at' => $command['now']]) !== 1) throw new \RuntimeException('inventory_stock_count_stock_changed');
        if (Db::name('inventory_batch')->where('id', (int)$batch['id'])->where('version', (int)$batch['version'])->update(['available_quantity_units' => (int)$batch['available_quantity_units'] + $units, 'version' => (int)$batch['version'] + 1, 'updated_at' => $command['now']]) !== 1) throw new \RuntimeException('inventory_stock_count_batch_changed');
        $this->fact($scope, $stock, $batch, 1, $units, 'stock_count_gain', (string)$document['count_no'], (string)$line['index'], $command);
        return ['batchNo' => $line['batchNo'], 'cost' => $line['cost'], 'manufacturedDate' => $line['manufacturedDate'], 'expireDate' => $line['expireDate']];
    }

    private function fact(array $scope, array $stock, array $batch, int $direction, int $units, string $type, string $source, string $detail, array $command): void { $scale = (int)$stock['quantity_scale']; (new InventoryBatchMovementFactServices())->append(['factKey' => 'stock-count:' . hash('sha256', $source . ':' . $detail), 'tenantId' => $scope['tenantId'], 'organizationId' => $scope['organizationId'], 'organizationPath' => $scope['organizationPath'], 'storeId' => $scope['storeId'], 'stockId' => (int)$stock['id'], 'batchId' => (int)$batch['id'], 'direction' => $direction, 'quantityUnits' => $units, 'unitCostCents' => (int)$batch['unit_cost_cents'], 'costAmountCents' => intdiv($units * (int)$batch['unit_cost_cents'], 10 ** $scale), 'sourceType' => $type, 'sourceId' => $source, 'sourceDetailId' => $detail, 'reversalOf' => 0, 'businessDate' => $command['businessDate'], 'occurredAt' => $command['now'], 'settledAt' => $command['now'], 'recordedAt' => $command['now']]); }

    private function scope(int $storeId, int $operatorId): array { $store = Db::name('system_store')->where('id',$storeId)->where('is_del',0)->where('is_show',1)->lock(true)->find(); $staff = Db::name('system_store_staff')->where('id',$operatorId)->where('store_id',$storeId)->where('status',1)->where('is_del',0)->lock(true)->find(); $binding = Db::name('organization_store')->where('store_id',$storeId)->lock(true)->find(); $org=(int)($binding['org_id']??0); if(!$store||!$staff||$org<=0) throw new \RuntimeException('inventory_stock_count_scope_denied'); $path=[];$seen=[];$current=$org; for($i=0;$i<64;$i++){if(isset($seen[$current]))throw new \RuntimeException('inventory_stock_count_scope_denied');$seen[$current]=true;$node=Db::name('organization')->where('id',$current)->where('is_del',0)->lock(true)->find();if(!$node)throw new \RuntimeException('inventory_stock_count_scope_denied');$path[]=(int)$node['id'];$parent=(int)$node['pid'];if($parent===0)break;if($parent<0)throw new \RuntimeException('inventory_stock_count_scope_denied');$current=$parent;} if(!$path||(int)end($path)!==$current)throw new \RuntimeException('inventory_stock_count_scope_denied'); return ['tenantId'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'organizationId'=>(string)$org,'organizationPath'=>'/'.implode('/',array_reverse($path)).'/','storeId'=>$storeId,'operatorId'=>$operatorId]; }
    private function location(array $scope): array { $rows=Db::name('inventory_location')->where('tenant_id',$scope['tenantId'])->where('store_id',$scope['storeId'])->where('location_type','STORE')->where('is_default',1)->where('location_status','ACTIVE')->limit(2)->lock(true)->select()->toArray(); if(count($rows)!==1||(string)$rows[0]['organization_id']!==$scope['organizationId']||(string)$rows[0]['organization_path']!==$scope['organizationPath']) throw new \RuntimeException('inventory_stock_count_location_invalid'); return $rows[0]; }
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
        $lines = []; $seen = [];
        foreach (array_values($input['lines']) as $i => $line) {
            $keys = ['product_id','sku_id','sku_unique','counted_quantity','surplus_batch_no','surplus_unit_cost','surplus_manufactured_date','surplus_expire_date'];
            $withBook = [...$keys, 'expected_book_quantity'];
            if (!is_array($line) || (array_keys($line) !== $keys && array_keys($line) !== $withBook)
                || !is_int($line['product_id']) || !is_int($line['sku_id'])
                || $line['product_id'] <= 0 || $line['sku_id'] <= 0
                || trim((string)$line['sku_unique']) === ''
                || !preg_match('/^\d+(?:\.\d{1,4})?$/D', trim((string)$line['counted_quantity']))) {
                throw new \InvalidArgumentException('inventory_stock_count_line_invalid');
            }
            $identity = $line['product_id'] . ':' . $line['sku_id'] . ':' . $line['sku_unique'];
            if (isset($seen[$identity])) throw new \InvalidArgumentException('inventory_stock_count_duplicate_sku');
            $seen[$identity] = true;
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
            $lines[] = $normalized;
        }
        $remark = mb_substr(trim((string)$input['remark']), 0, 500);
        $fingerprint = hash('sha256', json_encode(['business_date' => $dateText, 'remark' => $remark, 'lines' => $lines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['key' => $key, 'businessDate' => $dateText, 'remark' => $remark, 'lines' => $lines, 'fingerprint' => $fingerprint, 'now' => time()];
    }
}

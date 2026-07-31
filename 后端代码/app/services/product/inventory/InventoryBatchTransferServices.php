<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;

/**
 * Moves actual, positive batch balances between two locations owned by the
 * current store. It is intentionally separate from the legacy transfer tables.
 */
final class InventoryBatchTransferServices
{
    public function list(int $storeId, int $operatorId, string $keyword, int $page = 1, int $limit = 20, bool $canViewCost = false): array
    {
        $scope = $this->scope($storeId, $operatorId);
        $from = $this->defaultLocation($scope);
        $query = Db::name('inventory_batch_transfer_document')->alias('d')->leftJoin('inventory_batch_transfer_line l', 'l.document_id=d.id')
            ->where('d.tenant_id', $scope['tenantId'])->where('d.from_location_id', (int)$from['id']);
        $keyword = trim($keyword);
        if ($keyword !== '') $query->whereLike('d.transfer_no|d.remark', '%' . $keyword . '%');
        $count = (clone $query)->group('d.id')->count();
        $list = $query->leftJoin('inventory_stock s', 's.id=l.from_stock_id')->field('d.id,d.transfer_no order_sn,d.document_status status_name,d.business_date transfer_date,d.recorded_at add_time,d.from_location_id,d.to_location_id,COUNT(l.id) detail_count,SUM((l.quantity_units*l.unit_cost_cents)/POW(10,s.quantity_scale)) transfer_amount_cents')
            ->group('d.id')->order('d.id desc')->page(max(1, $page), max(1, min(100, $limit)))->select()->toArray();
        if (!$canViewCost) foreach ($list as &$row) $row['transfer_amount_cents'] = null;
        unset($row);
        return ['count' => $count, 'list' => $list];
    }

    public function create(int $storeId, int $operatorId, array $input): array
    {
        $command = $this->normalize($input);
        if ($storeId <= 0 || $operatorId <= 0) throw new \InvalidArgumentException('inventory_batch_transfer_scope_invalid');

        return Db::transaction(function () use ($storeId, $operatorId, $command): array {
            $scope = $this->scope($storeId, $operatorId);
            $from = $this->defaultLocation($scope);
            $to = $this->targetLocation($scope, $command['targetLocationId']);
            if ((int)$from['id'] === (int)$to['id']) throw new \RuntimeException('inventory_batch_transfer_target_same_as_source');

            $existing = Db::name('inventory_batch_transfer_document')->where('tenant_id', $scope['tenantId'])
                ->where('idempotency_key', $command['key'])->lock(true)->find();
            if ($existing) return $this->replay((array)$existing, $from, $to, $command);

            $documentId = (int)Db::name('inventory_batch_transfer_document')->insertGetId([
                'transfer_no' => 'IT-' . substr(hash('sha256', $scope['tenantId'] . ':' . $command['key']), 0, 24),
                'idempotency_key' => $command['key'], 'request_fingerprint' => $command['fingerprint'],
                'tenant_id' => $scope['tenantId'], 'from_location_id' => (int)$from['id'], 'to_location_id' => (int)$to['id'],
                'store_id' => $scope['storeId'], 'operator_id' => $operatorId, 'document_status' => 'CONFIRMED',
                'remark' => $command['remark'], 'business_date' => $command['date'], 'confirmed_at' => $command['now'], 'recorded_at' => $command['now'],
            ]);
            $document = Db::name('inventory_batch_transfer_document')->where('id', $documentId)->lock(true)->find();
            if (!$document) throw new \RuntimeException('inventory_batch_transfer_document_create_failed');

            $ordered = $command['lines'];
            usort($ordered, static function (array $a, array $b): int {
                return [$a['productId'], $a['skuId'], $a['unique'], $a['index']] <=> [$b['productId'], $b['skuId'], $b['unique'], $b['index']];
            });
            $result = [];
            foreach ($ordered as $line) {
                [$sourceStock, $targetStock] = $this->lockStocksInOrder($from, $to, $line, $command['now']);
                if ((int)$sourceStock['quantity_scale'] !== (int)$targetStock['quantity_scale']) throw new \RuntimeException('inventory_batch_transfer_quantity_scale_conflict');
                $units = InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'], (int)$sourceStock['quantity_scale']);
                $allocations = $this->allocate($this->sourceBatches((int)$sourceStock['id']), $units);
                $this->decreaseSource($sourceStock, $allocations, $units, $command['now']);
                $movementIds = [];
                foreach ($allocations as $allocationIndex => $allocation) {
                    $sourceBatch = $allocation['batch'];
                    $targetBatch = $this->lockOrCreateTargetBatch($targetStock, $sourceBatch, $document, $command['now']);
                    $this->increaseTarget($targetStock, $targetBatch, $allocation['units'], $command['now']);
                    $targetStock = (array)Db::name('inventory_stock')->where('id', (int)$targetStock['id'])->lock(true)->find();
                    $lineId = (int)Db::name('inventory_batch_transfer_line')->insertGetId([
                        'document_id' => $documentId, 'line_no' => $line['index'], 'from_stock_id' => (int)$sourceStock['id'], 'to_stock_id' => (int)$targetStock['id'],
                        'from_batch_id' => (int)$sourceBatch['id'], 'to_batch_id' => (int)$targetBatch['id'],
                        'origin_batch_id' => (int)$targetBatch['origin_batch_id'], 'quantity_units' => $allocation['units'],
                        'unit_cost_cents' => (int)$sourceBatch['unit_cost_cents'], 'created_at' => $command['now'],
                    ]);
                    $movementIds[] = $this->facts($scope, $sourceStock, $sourceBatch, $targetStock, $targetBatch, $allocation['units'], $command, $lineId);
                }
                $result[$line['index']] = ['movement_fact_ids' => $movementIds, 'idempotent' => false];
            }
            ksort($result, SORT_NUMERIC);
            return ['transfer_id' => $documentId, 'transfer_no' => (string)$document['transfer_no'], 'idempotency_key' => $command['key'], 'lines' => array_values($result)];
        });
    }

    private function normalize(array $input): array
    {
        if (array_keys($input) !== ['idempotency_key', 'business_date', 'remark', 'target_location_id', 'lines']
            || !is_int($input['target_location_id']) || $input['target_location_id'] <= 0 || !is_array($input['lines']) || !$input['lines'] || count($input['lines']) > 100) {
            throw new \InvalidArgumentException('inventory_batch_transfer_input_invalid');
        }
        $key = trim((string)$input['idempotency_key']);
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1) throw new \InvalidArgumentException('inventory_batch_transfer_idempotency_invalid');
        $date = $this->date((string)$input['business_date']);
        $lines = [];
        foreach (array_values($input['lines']) as $index => $line) {
            if (!is_array($line) || array_keys($line) !== ['product_id', 'sku_id', 'sku_unique', 'quantity']
                || !is_int($line['product_id']) || !is_int($line['sku_id']) || $line['product_id'] <= 0 || $line['sku_id'] <= 0) throw new \InvalidArgumentException('inventory_batch_transfer_line_invalid');
            $unique = trim((string)$line['sku_unique']); $quantity = trim((string)$line['quantity']);
            if ($unique === '' || strlen($unique) > 64 || !preg_match('/^\d+(?:\.\d{1,4})?$/D', $quantity) || (float)$quantity <= 0) throw new \InvalidArgumentException('inventory_batch_transfer_line_invalid');
            $lines[] = ['index' => $index, 'productId' => $line['product_id'], 'skuId' => $line['sku_id'], 'unique' => $unique, 'quantity' => $quantity];
        }
        $remark = mb_substr(trim((string)$input['remark']), 0, 500);
        return ['key' => $key, 'date' => $date, 'remark' => $remark, 'targetLocationId' => $input['target_location_id'], 'lines' => $lines,
            'fingerprint' => hash('sha256', json_encode(['date' => $date, 'remark' => $remark, 'target' => $input['target_location_id'], 'lines' => $lines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'now' => time()];
    }

    private function scope(int $storeId, int $operatorId): array
    {
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->where('is_show', 1)->lock(true)->find();
        $staff = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->lock(true)->find();
        $binding = Db::name('organization_store')->where('store_id', $storeId)->lock(true)->find();
        $organizationId = (int)($binding['org_id'] ?? 0);
        if (!$store || !$staff || $organizationId <= 0) throw new \RuntimeException('inventory_batch_transfer_scope_denied');
        $ids = []; $seen = []; $current = $organizationId;
        for ($i = 0; $i < 64; $i++) {
            if (isset($seen[$current])) throw new \RuntimeException('inventory_batch_transfer_scope_denied');
            $seen[$current] = true; $node = Db::name('organization')->where('id', $current)->where('is_del', 0)->lock(true)->find();
            if (!$node) throw new \RuntimeException('inventory_batch_transfer_scope_denied');
            $ids[] = $current; $parent = (int)$node['pid']; if ($parent === 0) break; if ($parent < 0) throw new \RuntimeException('inventory_batch_transfer_scope_denied'); $current = $parent;
        }
        if (!$ids || (int)end($ids) !== $current) throw new \RuntimeException('inventory_batch_transfer_scope_denied');
        return ['tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'organizationId' => (string)$organizationId, 'organizationPath' => '/' . implode('/', array_reverse($ids)) . '/', 'storeId' => $storeId];
    }

    private function defaultLocation(array $scope): array
    {
        $rows = Db::name('inventory_location')->where('tenant_id', $scope['tenantId'])->where('store_id', $scope['storeId'])->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->limit(2)->lock(true)->select()->toArray();
        if (count($rows) !== 1) throw new \RuntimeException('inventory_batch_transfer_source_location_invalid');
        return $this->assertLocation((array)$rows[0], $scope);
    }

    private function targetLocation(array $scope, int $id): array
    {
        $location = Db::name('inventory_location')->where('id', $id)->where('tenant_id', $scope['tenantId'])->where('location_status', 'ACTIVE')->lock(true)->find();
        if (!$location) throw new \RuntimeException('inventory_batch_transfer_target_location_invalid');
        return $this->assertLocation((array)$location, $scope);
    }

    private function assertLocation(array $location, array $scope): array
    {
        if ((string)$location['organization_id'] !== $scope['organizationId'] || (string)$location['organization_path'] !== $scope['organizationPath'] || (int)$location['store_id'] !== $scope['storeId'] || (int)$location['owner_id'] !== $scope['storeId'] || !in_array((string)$location['location_type'], ['STORE', 'HQ', 'BRANCH'], true)) throw new \RuntimeException('inventory_batch_transfer_location_scope_denied');
        return $location;
    }

    /** Acquire the two aggregate locks in location order so reverse transfers cannot deadlock. */
    private function lockStocksInOrder(array $from, array $to, array $line, int $now): array
    {
        $locations = [(int)$from['id'] => $from, (int)$to['id'] => $to]; ksort($locations, SORT_NUMERIC); $locked = [];
        foreach ($locations as $locationId => $location) {
            $stock = Db::name('inventory_stock')->where('tenant_id', $location['tenant_id'])->where('location_id', $locationId)->where('consumable_product_id', $line['productId'])->where('sku_id', $line['skuId'])->where('product_unique', $line['unique'])->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)->lock(true)->find();
            if (!$stock && $locationId === (int)$to['id']) {
                $source = Db::name('inventory_stock')->where('tenant_id', $from['tenant_id'])->where('location_id', (int)$from['id'])->where('consumable_product_id', $line['productId'])->where('sku_id', $line['skuId'])->where('product_unique', $line['unique'])->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)->lock(true)->find();
                if (!$source) throw new \RuntimeException('inventory_batch_transfer_source_stock_not_found');
                try { $id = Db::name('inventory_stock')->insertGetId(['tenant_id'=>$location['tenant_id'],'organization_id'=>$location['organization_id'],'organization_path'=>$location['organization_path'],'location_id'=>$locationId,'store_id'=>$location['store_id'],'consumable_product_id'=>$line['productId'],'sku_id'=>$line['skuId'],'product_unique'=>$line['unique'],'stock_status'=>InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD,'stock_unit'=>$source['stock_unit'],'quantity_scale'=>$source['quantity_scale'],'available_quantity_units'=>0,'estimated_unit_cost_cents'=>$source['estimated_unit_cost_cents'],'version'=>1,'created_at'=>$now,'updated_at'=>$now]); $stock=Db::name('inventory_stock')->where('id',$id)->lock(true)->find(); } catch (\Throwable $e) { $stock=Db::name('inventory_stock')->where('tenant_id',$location['tenant_id'])->where('location_id',$locationId)->where('consumable_product_id',$line['productId'])->where('sku_id',$line['skuId'])->where('product_unique',$line['unique'])->where('stock_status',InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)->lock(true)->find(); if(!$stock) throw $e; }
            }
            if (!$stock) throw new \RuntimeException('inventory_batch_transfer_source_stock_not_found');
            $locked[$locationId] = (array)$stock;
        }
        $source = $locked[(int)$from['id']]; if ((int)$source['available_quantity_units'] <= 0 || (int)$source['quantity_scale'] < 0 || (int)$source['quantity_scale'] > 4) throw new \RuntimeException('inventory_batch_transfer_stock_insufficient');
        return [$source, $locked[(int)$to['id']]];
    }

    private function sourceBatches(int $stockId): array { return Db::name('inventory_batch')->where('stock_id', $stockId)->where('batch_status', 'ACTIVE')->where('available_quantity_units', '>', 0)->orderRaw('expire_date IS NULL ASC, expire_date ASC, received_business_date IS NULL ASC, received_business_date ASC, id ASC')->lock(true)->select()->toArray(); }
    private function allocate(array $batches, int $units): array { $left=$units;$out=[];foreach($batches as $batch){$take=min($left,(int)$batch['available_quantity_units']);if($take>0)$out[]=['batch'=>(array)$batch,'units'=>$take];$left-=$take;if($left===0)break;}if($left!==0)throw new \RuntimeException('inventory_batch_transfer_stock_insufficient');return $out; }
    private function decreaseSource(array $stock,array $allocations,int $units,int $now): void { if(Db::name('inventory_stock')->where('id',(int)$stock['id'])->where('version',(int)$stock['version'])->where('available_quantity_units','>=',$units)->update(['available_quantity_units'=>(int)$stock['available_quantity_units']-$units,'version'=>(int)$stock['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_batch_transfer_source_stock_changed');foreach($allocations as $a){$batch=$a['batch'];if(Db::name('inventory_batch')->where('id',(int)$batch['id'])->where('version',(int)$batch['version'])->where('available_quantity_units','>=',$a['units'])->update(['available_quantity_units'=>(int)$batch['available_quantity_units']-$a['units'],'version'=>(int)$batch['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_batch_transfer_source_batch_changed');} }

    private function lockOrCreateTargetBatch(array $stock, array $source, array $document, int $now): array
    {
        $batch=Db::name('inventory_batch')->where('stock_id',(int)$stock['id'])->where('batch_no',(string)$source['batch_no'])->lock(true)->find();
        if($batch){if((string)$batch['batch_status']!=='ACTIVE'||(int)$batch['origin_batch_id']!==(int)$source['origin_batch_id']||(int)$batch['source_batch_id']!==(int)$source['id']||(int)$batch['unit_cost_cents']!==(int)$source['unit_cost_cents'])throw new \RuntimeException('inventory_batch_transfer_target_batch_conflict');return $batch;}
        $id=Db::name('inventory_batch')->insertGetId(['stock_id'=>(int)$stock['id'],'origin_batch_id'=>(int)$source['origin_batch_id'],'source_batch_id'=>(int)$source['id'],'batch_no'=>$source['batch_no'],'manufactured_date'=>$source['manufactured_date'],'expire_date'=>$source['expire_date'],'received_at'=>$now,'received_business_date'=>$document['business_date'],'available_quantity_units'=>0,'unit_cost_cents'=>(int)$source['unit_cost_cents'],'cost_allocated_quantity_units'=>0,'batch_status'=>'ACTIVE','version'=>1,'product_name_snapshot'=>$source['product_name_snapshot'],'sku_name_snapshot'=>$source['sku_name_snapshot'],'product_code_snapshot'=>$source['product_code_snapshot'],'barcode_snapshot'=>$source['barcode_snapshot'],'brand_name_snapshot'=>$source['brand_name_snapshot'],'category_name_snapshot'=>$source['category_name_snapshot'],'source_order_no_snapshot'=>$document['transfer_no'],'data_quality'=>$source['data_quality'],'created_at'=>$now,'updated_at'=>$now]);
        return Db::name('inventory_batch')->where('id',$id)->lock(true)->find();
    }
    private function increaseTarget(array $stock,array $batch,int $units,int $now): void { $new=(int)$stock['available_quantity_units']+$units;if(Db::name('inventory_stock')->where('id',(int)$stock['id'])->where('version',(int)$stock['version'])->update(['available_quantity_units'=>$new,'estimated_unit_cost_cents'=>(int)$batch['unit_cost_cents'],'version'=>(int)$stock['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_batch_transfer_target_stock_changed');if(Db::name('inventory_batch')->where('id',(int)$batch['id'])->where('version',(int)$batch['version'])->update(['available_quantity_units'=>(int)$batch['available_quantity_units']+$units,'version'=>(int)$batch['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_batch_transfer_target_batch_changed'); }
    private function facts(array $scope,array $sourceStock,array $sourceBatch,array $targetStock,array $targetBatch,int $units,array $command,int $lineId): array { $cost=intdiv($units*(int)$sourceBatch['unit_cost_cents'],10**(int)$sourceStock['quantity_scale']);$common=['tenantId'=>$scope['tenantId'],'organizationId'=>$scope['organizationId'],'organizationPath'=>$scope['organizationPath'],'storeId'=>$scope['storeId'],'quantityUnits'=>$units,'unitCostCents'=>(int)$sourceBatch['unit_cost_cents'],'costAmountCents'=>$cost,'sourceId'=>$command['key'],'sourceDetailId'=>(string)$lineId,'reversalOf'=>0,'businessDate'=>$command['date'],'occurredAt'=>$command['now'],'settledAt'=>$command['now'],'recordedAt'=>$command['now']];$facts=new InventoryBatchMovementFactServices();return [$facts->append($common+['factKey'=>'transfer-out:'.hash('sha256',$command['key'].':'.$lineId),'stockId'=>(int)$sourceStock['id'],'batchId'=>(int)$sourceBatch['id'],'direction'=>-1,'sourceType'=>'batch_transfer_out']),$facts->append($common+['factKey'=>'transfer-in:'.hash('sha256',$command['key'].':'.$lineId),'stockId'=>(int)$targetStock['id'],'batchId'=>(int)$targetBatch['id'],'direction'=>1,'sourceType'=>'batch_transfer_in'])]; }

    private function replay(array $document,array $from,array $to,array $command): array { if((string)$document['request_fingerprint']!==$command['fingerprint']||(int)$document['from_location_id']!==(int)$from['id']||(int)$document['to_location_id']!==(int)$to['id']||(string)$document['document_status']!=='CONFIRMED')throw new \RuntimeException('inventory_batch_transfer_idempotency_conflict');$lines=Db::name('inventory_batch_transfer_line')->where('document_id',(int)$document['id'])->order('line_no asc')->select()->toArray();if(!$lines)throw new \RuntimeException('inventory_batch_transfer_idempotency_conflict');$result=[];foreach($command['lines'] as $line){$matched=array_values(array_filter($lines,static fn(array $r):bool=>(int)$r['line_no']===$line['index']));if(!$matched)throw new \RuntimeException('inventory_batch_transfer_idempotency_conflict');$ids=[];foreach($matched as $record){$facts=Db::name('inventory_batch_movement_fact')->where('tenant_id',(string)$document['tenant_id'])->where('source_id',$command['key'])->where('source_detail_id',(string)$record['id'])->whereIn('source_type',['batch_transfer_out','batch_transfer_in'])->order('id asc')->column('id');if(count($facts)!==2)throw new \RuntimeException('inventory_batch_transfer_idempotency_conflict');foreach($facts as $id)$ids[]=(int)$id;}$result[]=['movement_fact_ids'=>$ids,'idempotent'=>true];}return ['transfer_id'=>(int)$document['id'],'transfer_no'=>(string)$document['transfer_no'],'idempotency_key'=>$command['key'],'lines'=>$result]; }
    private function date(string $value): string { $d=\DateTimeImmutable::createFromFormat('!Y-m-d',trim($value));$e=\DateTimeImmutable::getLastErrors();if(!$d||($e!==false&&($e['warning_count']||$e['error_count']))||$d->format('Y-m-d')!==trim($value))throw new \InvalidArgumentException('inventory_batch_transfer_date_invalid');return $d->format('Y-m-d'); }
}

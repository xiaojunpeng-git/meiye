<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;

/** Batch-authoritative salon consumable issue/void. Historical return documents remain read-only. */
final class InventorySalonUsageServices
{
    /** 作废只接受未退回的领用单；库存反向事实和状态在同一事务内落地，不能重复退库。 */
    public function voidForStore(int $storeId, int $operatorId, int $documentId, array $input): array
    {
        return Db::transaction(function () use ($storeId, $operatorId, $documentId, $input): array {
            $scope = $this->scope($storeId, $operatorId);
            return $this->voidAtScope($scope, $this->defaultLocation($scope), $documentId, $input);
        });
    }

    /** 平台端也只能作废已授权门店默认仓的领用单，不能跨门店退库。 */
    public function voidForPlatform(array $adminInfo, int $storeId, int $documentId, array $input): array
    {
        return Db::transaction(function () use ($adminInfo, $storeId, $documentId, $input): array {
            [$scope, $location] = $this->platformScope($adminInfo, $storeId);
            return $this->voidAtScope($scope, $location, $documentId, $input);
        });
    }

    private function voidAtScope(array $scope, array $location, int $documentId, array $input): array
    {
        if ($documentId <= 0 || array_keys($input) !== ['idempotency_key', 'reason']) {
            throw new \InvalidArgumentException('inventory_salon_usage_void_input_invalid');
        }
        $document = Db::name('inventory_salon_usage_document')->where('id', $documentId)
            ->where('tenant_id', $scope['tenantId'])->where('store_id', $scope['storeId'])
            ->where('location_id', (int)$location['id'])->lock(true)->find();
        if (!$document || (string)$document['operation_type'] !== 'ISSUE') {
            throw new \RuntimeException('inventory_salon_usage_void_document_missing');
        }
        if (!in_array((string)$document['document_status'], ['SETTLED', 'VOIDED'], true)) {
            throw new \RuntimeException('inventory_salon_usage_void_state_invalid');
        }
        $lineIds = Db::name('inventory_salon_usage_line')->where('document_id', $documentId)->column('id');
        if (!$lineIds) throw new \RuntimeException('inventory_salon_usage_void_document_missing');
        // 旧退回单仍然保留，但原领用已有退回时不能整单作废，否则同一数量会两次回库。
        if (Db::name('inventory_salon_usage_line')->alias('l')
            ->join('inventory_salon_usage_document d', 'd.id=l.document_id')
            ->whereIn('l.source_usage_line_id', array_map('intval', $lineIds))
            ->where('d.operation_type', 'RETURN')->find()) {
            throw new \RuntimeException('inventory_salon_usage_void_has_return');
        }
        $reversal = (new InventoryManualDocumentReversalServices())->reverseSalonAtLocation($scope, $location, [
            'source_id' => (string)$document['usage_no'],
            'idempotency_key' => (string)$input['idempotency_key'],
            'reason' => (string)$input['reason'],
        ]);
        if ((string)$document['document_status'] === 'SETTLED') {
            if (Db::name('inventory_salon_usage_document')->where('id', $documentId)
                ->where('document_status', 'SETTLED')->update(['document_status' => 'VOIDED']) !== 1) {
                throw new \RuntimeException('inventory_salon_usage_void_state_changed');
            }
        }
        return ['usage_document_id' => $documentId, 'usage_no' => (string)$document['usage_no'],
            'document_status' => 'VOIDED', 'reversal_id' => (int)$reversal['reversal_id'],
            'idempotent' => (bool)$reversal['idempotent']];
    }
    public function issue(int $storeId, int $operatorId, array $input): array
    {
        $command = $this->command($input, 'ISSUE');
        return Db::transaction(function () use ($storeId, $operatorId, $command): array {
            $scope = $this->scope($storeId, $operatorId);
            $command = $this->authorizeProject($scope, $command);
            $location = $this->defaultLocation($scope);
            return $this->writeIssue($scope, $location, (int)$scope['operatorId'], $command);
        });
    }

    /** Platform commands remain server-scoped to one authorized store warehouse. */
    public function issueForPlatform(array $adminInfo, int $storeId, array $input): array
    {
        $command = $this->command($input, 'ISSUE');
        return Db::transaction(function () use ($adminInfo, $storeId, $command): array {
            [$scope, $location, $operatorId] = $this->platformScope($adminInfo, $storeId);
            return $this->writeIssue($scope, $location, $operatorId, $this->authorizeProject($scope, $command));
        });
    }

    /** Page-side query is intentionally facts/documents only; caller scope is server-derived. */
    public function list(int $storeId, int $operatorId, int $projectId, string $from, string $to): array
    {
        $scope = $this->scope($storeId, $operatorId);
        return $this->listForScope($scope, $projectId, '', $from, $to);
    }

    public function listForPlatform(array $adminInfo, int $storeId, string $keyword, string $from, string $to): array
    {
        [$scope] = $this->platformScope($adminInfo, $storeId);
        return $this->listForScope($scope, 0, $keyword, $from, $to);
    }

    private function listForScope(array $scope, int $projectId, string $keyword, string $from, string $to): array
    {
        $from = $this->date($from); $to = $this->date($to); if ($from > $to) throw new \InvalidArgumentException('inventory_salon_usage_date_range_invalid');
        $query = Db::name('inventory_salon_usage_document')->alias('d')
            ->leftJoin('inventory_salon_usage_line l', 'l.document_id=d.id')
            ->where('d.tenant_id', $scope['tenantId'])->where('d.store_id', $scope['storeId'])->whereBetween('d.business_date', [$from, $to]);
        if ($projectId > 0) $query->where('project_id', $projectId);
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($inner) use ($like): void { $inner->whereLike('d.usage_no|d.project_name_snapshot|d.remark', $like); });
        }
        $items = $query->order('d.business_date desc')->order('d.id desc')
            ->field('d.id,d.usage_no,d.operation_type,d.project_id,d.project_name_snapshot,d.location_id,d.document_status,d.business_date,d.remark,d.settled_at,d.recorded_at operation_at,COUNT(l.id) detail_count')
            ->group('d.id')->select()->toArray();
        $issueIds = array_values(array_map('intval', array_column(array_filter($items,
            static fn (array $item): bool => (string)$item['operation_type'] === 'ISSUE'
                && (string)$item['document_status'] === 'SETTLED'), 'id')));
        $returned = [];
        if ($issueIds) {
            $returned = array_fill_keys(array_map('intval', Db::name('inventory_salon_usage_line')->alias('source')
                ->join('inventory_salon_usage_line returned', 'returned.source_usage_line_id=source.id')
                ->join('inventory_salon_usage_document rd', 'rd.id=returned.document_id')
                ->whereIn('source.document_id', $issueIds)->where('rd.operation_type', 'RETURN')
                ->group('source.document_id')->column('source.document_id')), true);
        }
        foreach ($items as &$item) {
            // 已退回的旧领用单只读；新作废入口仅对尚无退回的有效领用单开放。
            $item['can_void'] = (string)$item['operation_type'] === 'ISSUE'
                && (string)$item['document_status'] === 'SETTLED'
                && !isset($returned[(int)$item['id']]);
        }
        unset($item);
        return ['items' => $items, 'from' => $from, 'to' => $to];
    }

    /**
     * Read-only project picker. The authenticated store scope is derived here;
     * callers cannot query another store's project catalogue.
     */
    public function projects(int $storeId, int $operatorId, string $keyword, int $page = 1, int $limit = 50): array
    {
        $scope = $this->scope($storeId, $operatorId);
        return $this->projectsForScope($scope, $keyword, $page, $limit);
    }

    public function projectsForPlatform(array $adminInfo, int $storeId, string $keyword, int $page = 1, int $limit = 50): array
    {
        [$scope] = $this->platformScope($adminInfo, $storeId);
        return $this->projectsForScope($scope, $keyword, $page, $limit);
    }

    private function projectsForScope(array $scope, string $keyword, int $page = 1, int $limit = 50): array
    {
        $keyword = trim($keyword);
        if (mb_strlen($keyword) > 64) throw new \InvalidArgumentException('inventory_salon_usage_project_search_invalid');
        $page = max(1, $page);
        $limit = max(1, min($limit, 50));

        $query = Db::name('store_product')->alias('p')
            ->where('p.is_del', 0)
            ->where('p.is_show', 1)
            ->where('p.product_type', 6)
            ->whereIn('p.type', [0, 1])
            ->where(function ($scopeQuery) use ($scope) {
                $scopeQuery->where('p.type', 0)->whereOr('p.relation_id', $scope['storeId']);
            })
            ->when($keyword !== '', function ($keywordQuery) use ($keyword) {
                $like = '%' . $keyword . '%';
                $keywordQuery->whereLike('p.store_name|p.keyword|p.code|p.bar_code', $like);
            });

        $total = (int)(clone $query)->count();
        $list = $query
            ->field(['p.id' => 'id', 'p.store_name' => 'name', 'p.code' => 'code'])
            ->order('p.sort desc,p.id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return compact('list', 'total', 'page', 'limit');
    }

    public function detail(int $storeId, int $operatorId, int $documentId): array
    {
        return $this->detailForScope($this->scope($storeId, $operatorId), $documentId);
    }

    public function detailForPlatform(array $adminInfo, int $storeId, int $documentId): array
    {
        [$scope] = $this->platformScope($adminInfo, $storeId);
        return $this->detailForScope($scope, $documentId);
    }

    private function detailForScope(array $scope, int $documentId): array
    {
        if ($documentId <= 0) throw new \InvalidArgumentException('inventory_salon_usage_detail_invalid');
        $document = Db::name('inventory_salon_usage_document')
            ->where('tenant_id', $scope['tenantId'])->where('store_id', $scope['storeId'])->where('id', $documentId)->find();
        if (!$document) throw new \InvalidArgumentException('inventory_salon_usage_detail_missing');
        $lines = Db::name('inventory_salon_usage_line')->alias('l')->leftJoin('inventory_batch b', 'b.id=l.batch_id')
            ->leftJoin('inventory_stock s', 's.id=l.stock_id')->where('l.document_id', $documentId)
            ->field('l.id line_id,l.source_usage_line_id,l.product_id,l.sku_id,l.sku_unique,l.quantity_scale,l.quantity_units,l.unit_cost_cents,b.product_name_snapshot product_name,b.sku_name_snapshot sku_name,b.barcode_snapshot barcode,s.stock_unit')
            ->order('l.line_no asc')->select()->toArray();
        foreach ($lines as &$line) {
            $returned = (string)$document['operation_type'] === 'ISSUE'
                ? (int)Db::name('inventory_salon_usage_line')->alias('r')->join('inventory_salon_usage_document d', 'd.id=r.document_id')
                    ->where('r.source_usage_line_id', (int)$line['line_id'])->where('d.operation_type', 'RETURN')->sum('r.quantity_units')
                : 0;
            $line['quantity'] = $this->quantity((int)$line['quantity_units'], (int)$line['quantity_scale']);
            $line['returned_quantity'] = $this->quantity($returned, (int)$line['quantity_scale']);
            $line['returnable_quantity'] = $this->quantity(max(0, (int)$line['quantity_units'] - $returned), (int)$line['quantity_scale']);
        }
        unset($line);
        $voidReceipt = (string)$document['document_status'] === 'VOIDED'
            ? Db::name('inventory_manual_document_reversal')->where('tenant_id', $scope['tenantId'])
                ->where('source_type', InventoryManualDocumentReversalServices::SALON_ISSUE)
                ->where('source_id', (string)$document['usage_no'])->where('reversal_status', 'SETTLED')->find()
            : null;
        $hasReturn = (string)$document['operation_type'] === 'ISSUE'
            && array_reduce($lines, static fn (bool $found, array $line): bool => $found || (float)$line['returned_quantity'] > 0, false);
        return ['document' => [
            'id' => (int)$document['id'], 'usage_no' => (string)$document['usage_no'], 'operation_type' => (string)$document['operation_type'],
            'project_id' => (int)$document['project_id'], 'project_name_snapshot' => (string)$document['project_name_snapshot'],
            'location_id' => (int)$document['location_id'], 'business_date' => (string)$document['business_date'],
            'recorded_at' => (int)$document['recorded_at'], 'remark' => (string)$document['remark'], 'document_status' => (string)$document['document_status'],
            'can_void' => (string)$document['operation_type'] === 'ISSUE' && (string)$document['document_status'] === 'SETTLED' && !$hasReturn,
            'void_reason' => $voidReceipt ? (string)$voidReceipt['reason'] : '',
            'voided_at' => $voidReceipt ? (int)$voidReceipt['settled_at'] : 0,
        ], 'lines' => $lines];
    }

    private function writeIssue(array $scope, array $location, int $operatorId, array $command): array
    {
        if ($existing = $this->existing($scope, $command, $location)) return $existing;
        $doc = $this->document($scope, $location, $operatorId, $command);
        $ordered = $command['lines']; usort($ordered, static fn($a,$b) => [$a['productId'],$a['skuId'],$a['unique'],$a['index']] <=> [$b['productId'],$b['skuId'],$b['unique'],$b['index']]);
        $written=[];
        foreach ($ordered as $line) {
            $stock=$this->lockStock($location,$line); $units=InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'],(int)$stock['quantity_scale']);
            $allocations=$this->fefo((int)$stock['id'],$units); $this->decrease($stock,$allocations,$units,$command['now']);
            foreach ($allocations as $allocationIndex => $allocation) {
                $batch=$allocation['batch']; $fact=$this->fact($scope,$stock,$batch,-1,$allocation['units'],'salon_usage_issue',(string)$doc['usage_no'],$line['index'].':'.$allocationIndex,$command);
                // 只有退回行才引用原领用明细；领用行用 NULL，允许同单多商品或多批次，
                // 同时保留 (document_id, source_usage_line_id) 对退回行的唯一约束。
                $id=(int)Db::name('inventory_salon_usage_line')->insertGetId(['document_id'=>(int)$doc['id'],'line_no'=>$line['index']*1000+$allocationIndex,'source_usage_line_id'=>null,'movement_fact_id'=>$fact,'stock_id'=>(int)$stock['id'],'batch_id'=>(int)$batch['id'],'origin_batch_id'=>(int)$batch['origin_batch_id'],'product_id'=>$line['productId'],'sku_id'=>$line['skuId'],'sku_unique'=>$line['unique'],'quantity_scale'=>(int)$stock['quantity_scale'],'quantity_units'=>$allocation['units'],'unit_cost_cents'=>(int)$batch['unit_cost_cents'],'created_at'=>$command['now']]);
                $written[]=$id;
            }
        }
        return ['usage_document_id'=>(int)$doc['id'],'usage_no'=>(string)$doc['usage_no'],'line_ids'=>$written,'idempotent'=>false];
    }

    private function existing(array $scope,array $command,array $location): ?array { $doc=Db::name('inventory_salon_usage_document')->where('tenant_id',$scope['tenantId'])->where('idempotency_key',$command['key'])->lock(true)->find(); if(!$doc)return null; if((string)$doc['request_fingerprint']!==$command['fingerprint']||(int)$doc['location_id']!==(int)$location['id']||(string)$doc['operation_type']!==$command['operation'])throw new \RuntimeException('inventory_salon_usage_idempotency_conflict'); return ['usage_document_id'=>(int)$doc['id'],'usage_no'=>(string)$doc['usage_no'],'line_ids'=>Db::name('inventory_salon_usage_line')->where('document_id',(int)$doc['id'])->column('id'),'idempotent'=>true]; }
    private function document(array $scope,array $location,int $operatorId,array $c): array { $id=(int)Db::name('inventory_salon_usage_document')->insertGetId(['usage_no'=>(new InventoryBusinessDocumentNumberServices())->next($scope['tenantId'],InventoryBusinessDocumentNumberServices::SALON_ISSUE,$c['businessDate'],$c['now']),'idempotency_key'=>$c['key'],'request_fingerprint'=>$c['fingerprint'],'operation_type'=>'ISSUE','tenant_id'=>$scope['tenantId'],'organization_id'=>$scope['organizationId'],'organization_path'=>$scope['organizationPath'],'store_id'=>$scope['storeId'],'project_id'=>$c['projectId'],'project_name_snapshot'=>$c['projectName'],'location_id'=>(int)$location['id'],'operator_id'=>$operatorId,'operator_name_snapshot'=>(string)($scope['operatorName']??''),'document_status'=>'SETTLED','remark'=>$c['remark'],'business_date'=>$c['businessDate'],'settled_at'=>$c['now'],'recorded_at'=>$c['now']]); return Db::name('inventory_salon_usage_document')->where('id',$id)->lock(true)->find(); }
    private function lockStock(array $location,array $line): array { $s=Db::name('inventory_stock')->where('tenant_id',$location['tenant_id'])->where('location_id',(int)$location['id'])->where('consumable_product_id',$line['productId'])->where('sku_id',$line['skuId'])->where('product_unique',$line['unique'])->where('stock_status',InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)->lock(true)->find(); if(!$s||(int)$s['available_quantity_units']<=0)throw new \RuntimeException('inventory_salon_usage_stock_insufficient'); return $s; }
    private function fefo(int $stockId,int $needed): array { $b=Db::name('inventory_batch')->where('stock_id',$stockId)->where('batch_status','ACTIVE')->where('available_quantity_units','>',0)->orderRaw('expire_date IS NULL ASC, expire_date ASC, received_business_date IS NULL ASC, received_business_date ASC, id ASC')->lock(true)->select()->toArray();$a=[];$left=$needed;foreach($b as $x){$n=min((int)$x['available_quantity_units'],$left);if($n)$a[]=['batch'=>$x,'units'=>$n];$left-=$n;if(!$left)break;}if($left)throw new \RuntimeException('inventory_salon_usage_stock_insufficient');return $a; }
    private function decrease(array $stock,array $allocations,int $units,int $now):void { if(Db::name('inventory_stock')->where('id',(int)$stock['id'])->where('version',(int)$stock['version'])->update(['available_quantity_units'=>(int)$stock['available_quantity_units']-$units,'version'=>(int)$stock['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_salon_usage_stock_changed');foreach($allocations as $a){$b=$a['batch'];if(Db::name('inventory_batch')->where('id',(int)$b['id'])->where('version',(int)$b['version'])->where('available_quantity_units','>=',$a['units'])->update(['available_quantity_units'=>(int)$b['available_quantity_units']-$a['units'],'version'=>(int)$b['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_salon_usage_batch_changed');} }
    private function fact(array $scope,array $stock,array $batch,int $direction,int $units,string $type,string $source,string $detail,array $c):int{return(new InventoryBatchMovementFactServices())->append(['factKey'=>$type.':'.hash('sha256',$source.':'.$detail),'tenantId'=>$scope['tenantId'],'organizationId'=>$scope['organizationId'],'organizationPath'=>$scope['organizationPath'],'storeId'=>$scope['storeId'],'stockId'=>(int)$stock['id'],'batchId'=>(int)$batch['id'],'direction'=>$direction,'quantityUnits'=>$units,'unitCostCents'=>(int)$batch['unit_cost_cents'],'costAmountCents'=>intdiv($units*(int)$batch['unit_cost_cents'],10**(int)$stock['quantity_scale']),'sourceType'=>$type,'sourceId'=>$source,'sourceDetailId'=>$detail,'reversalOf'=>0,'businessDate'=>$c['businessDate'],'occurredAt'=>$c['now'],'settledAt'=>$c['now'],'recordedAt'=>$c['now'],'operatorType'=>(string)($scope['operatorType']??''),'operatorId'=>(int)($scope['operatorId']??0),'operatorName'=>(string)($scope['operatorName']??'')]);}
    private function platformScope(array $adminInfo, int $storeId): array
    {
        $access = (new InventoryPlatformAccessPolicy())->resolve($adminInfo);
        if ($storeId <= 0 || (empty($access['is_super_admin']) && !in_array($storeId, (array)$access['store_ids'], true))) {
            throw new \RuntimeException('inventory_salon_usage_platform_store_denied');
        }
        $location = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->lock(true)->find();
        if (!$location) throw new \RuntimeException('inventory_salon_usage_default_location_invalid');
        $scope = ['tenantId' => (string)$location['tenant_id'], 'organizationId' => (string)$location['organization_id'], 'organizationPath' => (string)$location['organization_path'], 'storeId' => $storeId, 'operatorId' => (int)$access['admin_id'], 'operatorType' => 'PLATFORM_ADMIN', 'operatorName' => mb_substr(trim((string)($adminInfo['real_name'] ?? $adminInfo['name'] ?? $adminInfo['account'] ?? '')), 0, 128)];
        if ($scope['organizationId'] === '' || $scope['organizationPath'] === '') throw new \RuntimeException('inventory_salon_usage_scope_denied');
        return [$scope, $location, (int)$access['admin_id']];
    }

    private function quantity(int $units, int $scale): string
    {
        $scale = max(0, min(4, $scale));
        if ($scale === 0) return (string)$units;
        $digits = str_pad((string)abs($units), $scale + 1, '0', STR_PAD_LEFT);
        return rtrim(rtrim(substr($digits, 0, -$scale) . '.' . substr($digits, -$scale), '0'), '.');
    }

    /** 院装读写复用同一可信门店与岗位边界，组织选店会话的审计身份必须是稳定员工 ID。 */
    private function scope(int $storeId, int $operatorId): array
    {
        try {
            $actor = (new InventoryStoreAccessPolicy())->writableActor($storeId, $operatorId, 'cashier.v3.inventory.usage');
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException('inventory_salon_usage_scope_denied', 0, $exception);
        }
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->where('is_show', 1)->lock(true)->find();
        $bind = Db::name('organization_store')->where('store_id', $storeId)->lock(true)->find();
        $org = (int)($bind['org_id'] ?? 0);
        if (!$store || $org <= 0) throw new \RuntimeException('inventory_salon_usage_scope_denied');
        $path = []; $seen = []; $cur = $org;
        for ($i = 0; $i < 64; $i++) {
            if (isset($seen[$cur])) throw new \RuntimeException('inventory_salon_usage_scope_denied');
            $seen[$cur] = true;
            $node = Db::name('organization')->where('id', $cur)->where('is_del', 0)->lock(true)->find();
            if (!$node) throw new \RuntimeException('inventory_salon_usage_scope_denied');
            $path[] = (int)$node['id'];
            $parent = (int)$node['pid'];
            if ($parent === 0) break;
            if ($parent < 0) throw new \RuntimeException('inventory_salon_usage_scope_denied');
            $cur = $parent;
        }
        if (!$path || (int)end($path) !== $cur) throw new \RuntimeException('inventory_salon_usage_scope_denied');
        return ['tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'organizationId' => (string)$org,
            'organizationPath' => '/' . implode('/', array_reverse($path)) . '/', 'storeId' => $storeId,
            'operatorId' => $actor['id'], 'operatorType' => $actor['type'], 'operatorName' => $actor['name']];
    }
    private function defaultLocation(array $scope):array{$rows=Db::name('inventory_location')->where('tenant_id',$scope['tenantId'])->where('store_id',$scope['storeId'])->where('location_type','STORE')->where('is_default',1)->where('location_status','ACTIVE')->limit(2)->lock(true)->select()->toArray();if(count($rows)!==1||(string)$rows[0]['organization_id']!==$scope['organizationId']||(string)$rows[0]['organization_path']!==$scope['organizationPath'])throw new \RuntimeException('inventory_salon_usage_default_location_invalid');return$rows[0];}
    /** 项目 0 是明确的“未关联项目”；有项目仍按当前门店校验并取服务端名称快照。 */
    private function authorizeProject(array $scope, array $command): array
    {
        if ($command['projectId'] > 0) {
            $project = Db::name('store_product')->where('id', $command['projectId'])
                ->where('is_del', 0)->where('is_show', 1)->where('product_type', 6)
                ->lock(true)->field('id,type,relation_id,store_name')->find();
            if (!$project || !in_array((int)$project['type'], [0, 1], true)
                || ((int)$project['type'] === 1 && (int)$project['relation_id'] !== $scope['storeId'])
                || trim((string)$project['store_name']) === '') {
                throw new \RuntimeException('inventory_salon_usage_project_not_found');
            }
            $command['projectName'] = mb_substr(trim((string)$project['store_name']), 0, 120);
        }
        $command['fingerprintPayload']['project_name'] = $command['projectName'];
        $command['fingerprint'] = hash('sha256', json_encode($command['fingerprintPayload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        unset($command['fingerprintPayload']);
        return $command;
    }

    /** 院装领用允许项目 0；历史退回单只读，不再接受新的退回命令。 */
    private function command(array $input, string $operation): array
    {
        if ($operation !== 'ISSUE') throw new \InvalidArgumentException('inventory_salon_usage_operation_invalid');
        $keys = ['idempotency_key', 'business_date', 'project_id', 'project_name', 'remark', 'lines'];
        if (array_keys($input) !== $keys || !is_array($input['lines']) || !$input['lines'] || count($input['lines']) > 100) {
            throw new \InvalidArgumentException('inventory_salon_usage_input_invalid');
        }
        $key = trim((string)$input['idempotency_key']);
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1
            || !is_int($input['project_id']) || $input['project_id'] < 0) {
            throw new \InvalidArgumentException('inventory_salon_usage_identifier_invalid');
        }
        $lines = [];
        foreach (array_values($input['lines']) as $i => $line) {
            $lineKeys = ['product_id', 'sku_id', 'sku_unique', 'quantity'];
            if (!is_array($line) || array_keys($line) !== $lineKeys
                || !preg_match('/^\d+(?:\.\d{1,4})?$/D', trim((string)$line['quantity']))) {
                throw new \InvalidArgumentException('inventory_salon_usage_line_invalid');
            }
            if (!is_int($line['product_id']) || !is_int($line['sku_id'])
                || $line['product_id'] <= 0 || $line['sku_id'] <= 0
                || trim((string)$line['sku_unique']) === '') {
                throw new \InvalidArgumentException('inventory_salon_usage_line_invalid');
            }
            $lines[] = ['index' => $i, 'productId' => $line['product_id'], 'skuId' => $line['sku_id'],
                'unique' => trim((string)$line['sku_unique']), 'quantity' => trim((string)$line['quantity'])];
        }
        $date = $this->date((string)$input['business_date']);
        $payload = ['operation' => $operation, 'business_date' => $date, 'project_id' => $input['project_id'],
            'project_name' => '', 'remark' => mb_substr(trim((string)$input['remark']), 0, 500),
            'return_location_id' => 0, 'lines' => $lines];
        return ['key' => $key, 'operation' => $operation, 'businessDate' => $date,
            'projectId' => $input['project_id'], 'projectName' => '', 'remark' => $payload['remark'],
            'lines' => $lines,
            'fingerprintPayload' => $payload, 'fingerprint' => '', 'now' => time()];
    }
    private function date(string $v):string{$d=\DateTimeImmutable::createFromFormat('!Y-m-d',trim($v));if(!$d||$d->format('Y-m-d')!==trim($v))throw new \InvalidArgumentException('inventory_salon_usage_date_invalid');return$d->format('Y-m-d');}
}

<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;

/** Batch-authoritative salon consumable issue/return. No legacy salon stock counter is used. */
final class InventorySalonUsageServices
{
    public function issue(int $storeId, int $operatorId, array $input): array
    {
        $command = $this->command($input, 'ISSUE');
        return Db::transaction(function () use ($storeId, $operatorId, $command): array {
            $scope = $this->scope($storeId, $operatorId);
            $command = $this->authorizeProject($scope, $command);
            $location = $this->defaultLocation($scope);
            return $this->writeIssue($scope, $location, $operatorId, $command);
        });
    }

    public function returnToDefault(int $storeId, int $operatorId, array $input): array
    {
        $command = $this->command($input, 'RETURN');
        return Db::transaction(function () use ($storeId, $operatorId, $command): array {
            $scope = $this->scope($storeId, $operatorId);
            $command = $this->authorizeProject($scope, $command);
            $location = $this->selectedDefaultLocation($scope, $command['returnLocationId']);
            return $this->writeReturn($scope, $location, $operatorId, $command);
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

    /** Platform returns can only restore the selected store's default warehouse. */
    public function returnForPlatform(array $adminInfo, int $storeId, array $input): array
    {
        $command = $this->command($input, 'RETURN');
        return Db::transaction(function () use ($adminInfo, $storeId, $command): array {
            [$scope, $location, $operatorId] = $this->platformScope($adminInfo, $storeId);
            if ($command['returnLocationId'] !== (int)$location['id']) throw new \RuntimeException('inventory_salon_usage_return_location_denied');
            return $this->writeReturn($scope, $location, $operatorId, $this->authorizeProject($scope, $command));
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
        return ['document' => [
            'id' => (int)$document['id'], 'usage_no' => (string)$document['usage_no'], 'operation_type' => (string)$document['operation_type'],
            'project_id' => (int)$document['project_id'], 'project_name_snapshot' => (string)$document['project_name_snapshot'],
            'location_id' => (int)$document['location_id'], 'business_date' => (string)$document['business_date'],
            'recorded_at' => (int)$document['recorded_at'], 'remark' => (string)$document['remark'], 'document_status' => (string)$document['document_status'],
            'can_return' => (string)$document['operation_type'] === 'ISSUE' && array_reduce($lines, static fn(bool $can, array $line): bool => $can || (float)$line['returnable_quantity'] > 0, false),
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
                $id=(int)Db::name('inventory_salon_usage_line')->insertGetId(['document_id'=>(int)$doc['id'],'line_no'=>$line['index']*1000+$allocationIndex,'source_usage_line_id'=>0,'movement_fact_id'=>$fact,'stock_id'=>(int)$stock['id'],'batch_id'=>(int)$batch['id'],'origin_batch_id'=>(int)$batch['origin_batch_id'],'product_id'=>$line['productId'],'sku_id'=>$line['skuId'],'sku_unique'=>$line['unique'],'quantity_scale'=>(int)$stock['quantity_scale'],'quantity_units'=>$allocation['units'],'unit_cost_cents'=>(int)$batch['unit_cost_cents'],'created_at'=>$command['now']]);
                $written[]=$id;
            }
        }
        return ['usage_document_id'=>(int)$doc['id'],'usage_no'=>(string)$doc['usage_no'],'line_ids'=>$written,'idempotent'=>false];
    }

    private function writeReturn(array $scope, array $location, int $operatorId, array $command): array
    {
        if ($existing = $this->existing($scope, $command, $location)) return $existing;
        $doc=$this->document($scope,$location,$operatorId,$command); $written=[];
        foreach ($command['lines'] as $line) {
            $source=Db::name('inventory_salon_usage_line')->alias('l')->join('inventory_salon_usage_document d','d.id=l.document_id')->where('l.id',$line['sourceLineId'])->where('d.tenant_id',$scope['tenantId'])->where('d.store_id',$scope['storeId'])->where('d.operation_type','ISSUE')->where('d.project_id',$command['projectId'])->lock(true)->field('l.*,d.project_id,d.usage_no')->find();
            if (!$source) throw new \RuntimeException('inventory_salon_usage_return_source_not_found');
            $returned=(int)Db::name('inventory_salon_usage_line')->alias('r')->join('inventory_salon_usage_document d','d.id=r.document_id')->where('r.source_usage_line_id',(int)$source['id'])->where('d.operation_type','RETURN')->sum('r.quantity_units');
            $units=InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'],(int)$source['quantity_scale']);
            if ($units > (int)$source['quantity_units']-$returned) throw new \RuntimeException('inventory_salon_usage_return_exceeds_issue');
            $origin=Db::name('inventory_batch')->where('id',(int)$source['batch_id'])->lock(true)->find(); if (!$origin) throw new \RuntimeException('inventory_salon_usage_return_batch_not_found');
            $stock=$this->lockOrCreateReturnStock($scope,$location,$source,$command['now']);
            $batch=$this->lockOrCreateReturnBatch($stock,$origin,$source,$doc,$command);
            $this->increase($stock,$batch,$units,(int)$source['unit_cost_cents'],$command['now']);
            $fact=$this->fact($scope,$stock,$batch,1,$units,'salon_usage_return',(string)$doc['usage_no'],(string)$line['index'],$command);
            $written[]=(int)Db::name('inventory_salon_usage_line')->insertGetId(['document_id'=>(int)$doc['id'],'line_no'=>$line['index'],'source_usage_line_id'=>(int)$source['id'],'movement_fact_id'=>$fact,'stock_id'=>(int)$stock['id'],'batch_id'=>(int)$batch['id'],'origin_batch_id'=>(int)$origin['origin_batch_id'],'product_id'=>(int)$source['product_id'],'sku_id'=>(int)$source['sku_id'],'sku_unique'=>(string)$source['sku_unique'],'quantity_scale'=>(int)$source['quantity_scale'],'quantity_units'=>$units,'unit_cost_cents'=>(int)$source['unit_cost_cents'],'created_at'=>$command['now']]);
        }
        return ['usage_document_id'=>(int)$doc['id'],'usage_no'=>(string)$doc['usage_no'],'line_ids'=>$written,'idempotent'=>false];
    }

    private function existing(array $scope,array $command,array $location): ?array { $doc=Db::name('inventory_salon_usage_document')->where('tenant_id',$scope['tenantId'])->where('idempotency_key',$command['key'])->lock(true)->find(); if(!$doc)return null; if((string)$doc['request_fingerprint']!==$command['fingerprint']||(int)$doc['location_id']!==(int)$location['id']||(string)$doc['operation_type']!==$command['operation'])throw new \RuntimeException('inventory_salon_usage_idempotency_conflict'); return ['usage_document_id'=>(int)$doc['id'],'usage_no'=>(string)$doc['usage_no'],'line_ids'=>Db::name('inventory_salon_usage_line')->where('document_id',(int)$doc['id'])->column('id'),'idempotent'=>true]; }
    private function document(array $scope,array $location,int $operatorId,array $c): array { $documentType=$c['operation']==='RETURN'?InventoryBusinessDocumentNumberServices::SALON_RETURN:InventoryBusinessDocumentNumberServices::SALON_ISSUE; $id=(int)Db::name('inventory_salon_usage_document')->insertGetId(['usage_no'=>(new InventoryBusinessDocumentNumberServices())->next($scope['tenantId'],$documentType,$c['businessDate'],$c['now']),'idempotency_key'=>$c['key'],'request_fingerprint'=>$c['fingerprint'],'operation_type'=>$c['operation'],'tenant_id'=>$scope['tenantId'],'organization_id'=>$scope['organizationId'],'organization_path'=>$scope['organizationPath'],'store_id'=>$scope['storeId'],'project_id'=>$c['projectId'],'project_name_snapshot'=>$c['projectName'],'location_id'=>(int)$location['id'],'operator_id'=>$operatorId,'document_status'=>'SETTLED','remark'=>$c['remark'],'business_date'=>$c['businessDate'],'settled_at'=>$c['now'],'recorded_at'=>$c['now']]); return Db::name('inventory_salon_usage_document')->where('id',$id)->lock(true)->find(); }
    private function lockStock(array $location,array $line): array { $s=Db::name('inventory_stock')->where('tenant_id',$location['tenant_id'])->where('location_id',(int)$location['id'])->where('consumable_product_id',$line['productId'])->where('sku_id',$line['skuId'])->where('product_unique',$line['unique'])->where('stock_status',InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)->lock(true)->find(); if(!$s||(int)$s['available_quantity_units']<=0)throw new \RuntimeException('inventory_salon_usage_stock_insufficient'); return $s; }
    private function fefo(int $stockId,int $needed): array { $b=Db::name('inventory_batch')->where('stock_id',$stockId)->where('batch_status','ACTIVE')->where('available_quantity_units','>',0)->orderRaw('expire_date IS NULL ASC, expire_date ASC, received_business_date IS NULL ASC, received_business_date ASC, id ASC')->lock(true)->select()->toArray();$a=[];$left=$needed;foreach($b as $x){$n=min((int)$x['available_quantity_units'],$left);if($n)$a[]=['batch'=>$x,'units'=>$n];$left-=$n;if(!$left)break;}if($left)throw new \RuntimeException('inventory_salon_usage_stock_insufficient');return $a; }
    private function decrease(array $stock,array $allocations,int $units,int $now):void { if(Db::name('inventory_stock')->where('id',(int)$stock['id'])->where('version',(int)$stock['version'])->update(['available_quantity_units'=>(int)$stock['available_quantity_units']-$units,'version'=>(int)$stock['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_salon_usage_stock_changed');foreach($allocations as $a){$b=$a['batch'];if(Db::name('inventory_batch')->where('id',(int)$b['id'])->where('version',(int)$b['version'])->where('available_quantity_units','>=',$a['units'])->update(['available_quantity_units'=>(int)$b['available_quantity_units']-$a['units'],'version'=>(int)$b['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_salon_usage_batch_changed');} }
    private function increase(array $stock,array $batch,int $units,int $cost,int $now):void{$old=(int)$stock['available_quantity_units'];$new=$old+$units;$avg=intdiv($old*(int)$stock['estimated_unit_cost_cents']+$units*$cost,$new);if(Db::name('inventory_stock')->where('id',(int)$stock['id'])->where('version',(int)$stock['version'])->update(['available_quantity_units'=>$new,'estimated_unit_cost_cents'=>$avg,'version'=>(int)$stock['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_salon_usage_return_stock_changed');if(Db::name('inventory_batch')->where('id',(int)$batch['id'])->where('version',(int)$batch['version'])->update(['available_quantity_units'=>(int)$batch['available_quantity_units']+$units,'version'=>(int)$batch['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_salon_usage_return_batch_changed');}
    private function lockOrCreateReturnStock(array $scope,array $loc,array $source,int $now):array{$q=Db::name('inventory_stock')->where('tenant_id',$scope['tenantId'])->where('location_id',(int)$loc['id'])->where('consumable_product_id',(int)$source['product_id'])->where('sku_id',(int)$source['sku_id'])->where('stock_status',InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD);if($s=$q->lock(true)->find())return $s;$id=Db::name('inventory_stock')->insertGetId(['tenant_id'=>$scope['tenantId'],'organization_id'=>$scope['organizationId'],'organization_path'=>$scope['organizationPath'],'location_id'=>(int)$loc['id'],'store_id'=>$scope['storeId'],'consumable_product_id'=>(int)$source['product_id'],'sku_id'=>(int)$source['sku_id'],'product_unique'=>(string)$source['sku_unique'],'stock_status'=>InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD,'stock_unit'=>'','quantity_scale'=>(int)$source['quantity_scale'],'available_quantity_units'=>0,'estimated_unit_cost_cents'=>(int)$source['unit_cost_cents'],'version'=>1,'created_at'=>$now,'updated_at'=>$now]);return Db::name('inventory_stock')->where('id',$id)->lock(true)->find();}
    private function lockOrCreateReturnBatch(array $stock,array $origin,array $source,array $doc,array $c):array{$q=Db::name('inventory_batch')->where('stock_id',(int)$stock['id'])->where('batch_no',(string)$origin['batch_no']);if($b=$q->lock(true)->find()){if((int)$b['unit_cost_cents']!==(int)$source['unit_cost_cents'])throw new \RuntimeException('inventory_salon_usage_return_batch_conflict');return $b;}$id=Db::name('inventory_batch')->insertGetId(['stock_id'=>(int)$stock['id'],'origin_batch_id'=>(int)$origin['origin_batch_id'],'source_batch_id'=>(int)$origin['id'],'batch_no'=>(string)$origin['batch_no'],'manufactured_date'=>$origin['manufactured_date'],'expire_date'=>$origin['expire_date'],'received_at'=>$c['now'],'received_business_date'=>$c['businessDate'],'available_quantity_units'=>0,'unit_cost_cents'=>(int)$source['unit_cost_cents'],'cost_allocated_quantity_units'=>0,'batch_status'=>'ACTIVE','version'=>1,'product_name_snapshot'=>(string)$origin['product_name_snapshot'],'sku_name_snapshot'=>(string)$origin['sku_name_snapshot'],'product_code_snapshot'=>(string)$origin['product_code_snapshot'],'barcode_snapshot'=>(string)$origin['barcode_snapshot'],'brand_name_snapshot'=>(string)$origin['brand_name_snapshot'],'category_name_snapshot'=>(string)$origin['category_name_snapshot'],'source_order_no_snapshot'=>(string)$doc['usage_no'],'data_quality'=>(string)$origin['data_quality'],'created_at'=>$c['now'],'updated_at'=>$c['now']]);return Db::name('inventory_batch')->where('id',$id)->lock(true)->find();}
    private function fact(array $scope,array $stock,array $batch,int $direction,int $units,string $type,string $source,string $detail,array $c):int{return(new InventoryBatchMovementFactServices())->append(['factKey'=>$type.':'.hash('sha256',$source.':'.$detail),'tenantId'=>$scope['tenantId'],'organizationId'=>$scope['organizationId'],'organizationPath'=>$scope['organizationPath'],'storeId'=>$scope['storeId'],'stockId'=>(int)$stock['id'],'batchId'=>(int)$batch['id'],'direction'=>$direction,'quantityUnits'=>$units,'unitCostCents'=>(int)$batch['unit_cost_cents'],'costAmountCents'=>intdiv($units*(int)$batch['unit_cost_cents'],10**(int)$stock['quantity_scale']),'sourceType'=>$type,'sourceId'=>$source,'sourceDetailId'=>$detail,'reversalOf'=>0,'businessDate'=>$c['businessDate'],'occurredAt'=>$c['now'],'settledAt'=>$c['now'],'recordedAt'=>$c['now']]);}
    private function platformScope(array $adminInfo, int $storeId): array
    {
        $access = (new InventoryPlatformAccessPolicy())->resolve($adminInfo);
        if ($storeId <= 0 || (empty($access['is_super_admin']) && !in_array($storeId, (array)$access['store_ids'], true))) {
            throw new \RuntimeException('inventory_salon_usage_platform_store_denied');
        }
        $location = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->lock(true)->find();
        if (!$location) throw new \RuntimeException('inventory_salon_usage_default_location_invalid');
        $scope = ['tenantId' => (string)$location['tenant_id'], 'organizationId' => (string)$location['organization_id'], 'organizationPath' => (string)$location['organization_path'], 'storeId' => $storeId];
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

    private function scope(int $storeId,int $operatorId):array{$store=Db::name('system_store')->where('id',$storeId)->where('is_del',0)->where('is_show',1)->lock(true)->find();$staff=Db::name('system_store_staff')->where('id',$operatorId)->where('store_id',$storeId)->where('status',1)->where('is_del',0)->lock(true)->find();$bind=Db::name('organization_store')->where('store_id',$storeId)->lock(true)->find();$org=(int)($bind['org_id']??0);if(!$store||!$staff||$org<=0)throw new \RuntimeException('inventory_salon_usage_scope_denied');$path=[];$seen=[];$cur=$org;for($i=0;$i<64;$i++){if(isset($seen[$cur]))throw new \RuntimeException('inventory_salon_usage_scope_denied');$seen[$cur]=true;$n=Db::name('organization')->where('id',$cur)->where('is_del',0)->lock(true)->find();if(!$n)throw new \RuntimeException('inventory_salon_usage_scope_denied');$path[]=(int)$n['id'];$p=(int)$n['pid'];if($p===0)break;if($p<0)throw new \RuntimeException('inventory_salon_usage_scope_denied');$cur=$p;}if(!$path||(int)end($path)!==$cur)throw new \RuntimeException('inventory_salon_usage_scope_denied');return['tenantId'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'organizationId'=>(string)$org,'organizationPath'=>'/'.implode('/',array_reverse($path)).'/','storeId'=>$storeId];}
    private function defaultLocation(array $scope):array{$rows=Db::name('inventory_location')->where('tenant_id',$scope['tenantId'])->where('store_id',$scope['storeId'])->where('location_type','STORE')->where('is_default',1)->where('location_status','ACTIVE')->limit(2)->lock(true)->select()->toArray();if(count($rows)!==1||(string)$rows[0]['organization_id']!==$scope['organizationId']||(string)$rows[0]['organization_path']!==$scope['organizationPath'])throw new \RuntimeException('inventory_salon_usage_default_location_invalid');return$rows[0];}
    private function selectedDefaultLocation(array $scope,int $id):array{$l=$this->defaultLocation($scope);if((int)$l['id']!==$id)throw new \RuntimeException('inventory_salon_usage_return_location_denied');return$l;}
    private function command(array $input,string $operation):array{$keys=$operation==='ISSUE'?['idempotency_key','business_date','project_id','project_name','remark','lines']:['idempotency_key','business_date','project_id','project_name','remark','return_location_id','lines'];if(array_keys($input)!==$keys||!is_array($input['lines'])||!$input['lines']||count($input['lines'])>100)throw new \InvalidArgumentException('inventory_salon_usage_input_invalid');$key=trim((string)$input['idempotency_key']);if(preg_match('/^[A-Za-z0-9:._-]{8,96}$/D',$key)!==1||!is_int($input['project_id'])||$input['project_id']<=0)throw new \InvalidArgumentException('inventory_salon_usage_identifier_invalid');$lines=[];foreach(array_values($input['lines'])as$i=>$line){$ks=$operation==='ISSUE'?['product_id','sku_id','sku_unique','quantity']:['source_usage_line_id','quantity'];if(!is_array($line)||array_keys($line)!==$ks||!preg_match('/^\d+(?:\.\d{1,4})?$/D',trim((string)$line['quantity'])))throw new \InvalidArgumentException('inventory_salon_usage_line_invalid');if($operation==='ISSUE'){if(!is_int($line['product_id'])||!is_int($line['sku_id'])||$line['product_id']<=0||$line['sku_id']<=0||trim((string)$line['sku_unique'])==='')throw new \InvalidArgumentException('inventory_salon_usage_line_invalid');$lines[]=['index'=>$i,'productId'=>$line['product_id'],'skuId'=>$line['sku_id'],'unique'=>trim((string)$line['sku_unique']),'quantity'=>trim((string)$line['quantity'])];}else{if(!is_int($line['source_usage_line_id'])||$line['source_usage_line_id']<=0)throw new \InvalidArgumentException('inventory_salon_usage_line_invalid');$lines[]=['index'=>$i,'sourceLineId'=>$line['source_usage_line_id'],'quantity'=>trim((string)$line['quantity'])];}}$date=$this->date((string)$input['business_date']);$payload=['operation'=>$operation,'business_date'=>$date,'project_id'=>$input['project_id'],'project_name'=>'','remark'=>mb_substr(trim((string)$input['remark']),0,500),'return_location_id'=>$operation==='RETURN'?$input['return_location_id']:0,'lines'=>$lines];if($operation==='RETURN'&&(!is_int($input['return_location_id'])||$input['return_location_id']<=0))throw new \InvalidArgumentException('inventory_salon_usage_input_invalid');return['key'=>$key,'operation'=>$operation,'businessDate'=>$date,'projectId'=>$input['project_id'],'projectName'=>'','remark'=>$payload['remark'],'returnLocationId'=>(int)$payload['return_location_id'],'lines'=>$lines,'fingerprintPayload'=>$payload,'fingerprint'=>'','now'=>time()];}
    private function authorizeProject(array $scope,array $command):array{$project=Db::name('store_product')->where('id',$command['projectId'])->where('is_del',0)->where('is_show',1)->where('product_type',6)->lock(true)->field('id,type,relation_id,store_name')->find();if(!$project||!in_array((int)$project['type'],[0,1],true)||((int)$project['type']===1&&(int)$project['relation_id']!==$scope['storeId'])||trim((string)$project['store_name'])==='')throw new \RuntimeException('inventory_salon_usage_project_not_found');$command['projectName']=mb_substr(trim((string)$project['store_name']),0,120);$command['fingerprintPayload']['project_name']=$command['projectName'];$command['fingerprint']=hash('sha256',json_encode($command['fingerprintPayload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));unset($command['fingerprintPayload']);return$command;}
    private function date(string $v):string{$d=\DateTimeImmutable::createFromFormat('!Y-m-d',trim($v));if(!$d||$d->format('Y-m-d')!==trim($v))throw new \InvalidArgumentException('inventory_salon_usage_date_invalid');return$d->format('Y-m-d');}
}

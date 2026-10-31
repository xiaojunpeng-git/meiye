<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

/** 007 项目消耗分析表：统一 V3 与受控历史服务来源，每条原服务只计一次数量和金额。 */
final class ItemConsumption007ReadServices
{
    private $quantityReader;
    private $amountReader;
    private $snapshotReader;
    private $coverageReader;
    private $saleProjectAmountReader;
    private $historicalEnabled;

    /** 测试可注入只读事实，生产使用统一注册指标及受控历史核销适配器，禁止各报表自行补算。 */
    public function __construct(?callable $quantityReader = null, ?callable $amountReader = null,
        ?callable $snapshotReader = null, ?callable $coverageReader = null,
        ?callable $saleProjectAmountReader = null)
    {
        $this->historicalEnabled = $quantityReader === null;
        $this->quantityReader = $quantityReader ?: static function (array $stores, array $range, int $employee): array {
            $reader = RegisteredMetricReadServices::withCurrentReportProductCategory();
            return $employee > 0
                ? $reader->categoryRowsForParticipant('completed_service_item_count', '0', $stores, $range, [], [], $employee)
                : $reader->categoryRows('completed_service_item_count', '0', $stores, $range);
        };
        $this->amountReader = $amountReader ?: static function (array $stores, array $range, int $employee): array {
            // 金额只按服务结账键与数量事实配对；现行分类由数量端解析。
            // 金额端再次解析 5 万多条分类既不改变金额也会拖慢全门店月报。
            $reader = new RegisteredMetricReadServices();
            return $employee > 0
                ? $reader->categoryRowsForParticipant('consume_amount', '0', $stores, $range, [], [], $employee)
                : $reader->categoryRows('consume_amount', '0', $stores, $range);
        };
        $this->snapshotReader = $snapshotReader ?: [$this, 'snapshots'];
        $this->coverageReader = $coverageReader ?: [new ItemConsumption007SourceCoverageServices(),'read'];
        $this->saleProjectAmountReader=$saleProjectAmountReader
            ?: [new SaleProjectConsumption007AmountReadServices(),'read'];
    }

    /** 门店与本人参与人只由 Host 认证后注入；列表、合计和导出须使用本次完整结果。 */
    public function read(array $stores, array $range, array $employeeIds = []): array
    {
        $stores = $this->stores($stores); $range = $this->range($range);
        $employee = $this->employee($employeeIds);
        if (!$stores) return $this->project([], [], [], $stores, $range);
        return Db::transaction(function () use ($stores, $range, $employee): array {
            $coverage = ($this->coverageReader)($stores, $range);
            $historical=['services'=>[],'amounts'=>[],'sources'=>['phones'=>[],'store_names'=>[],'ordering_store_ids'=>[]],'gaps'=>[]];
            if($this->historicalEnabled){
                $adapter=new HistoricalCardService007ReadServices();$old=$adapter->readServices($stores,$range);
                if($old['complete']){
                    $coverage['gaps']=array_values(array_filter($coverage['gaps']??[],static function($g){return($g['code']??'')!=='LEGACY_WRITEOFF_V3_UNMAPPED';}));
                    $historical=$adapter->reportProjection($old,$employee);
                }
                $coverage['gaps']=array_merge($coverage['gaps']??[],$old['gaps'],$historical['gaps']);$coverage['complete']=!$coverage['gaps'];
            }
            if(!($coverage['complete']??false))return $this->failClosed($stores,$range,$coverage);
            $result=$this->project([],[],[],$stores,$range);
            $seen=[];$amountGap=false;
            // A full all-store month can exceed 60,000 immutable service rows.
            // Partition by non-overlapping authorized stores before registered
            // category resolution; one service/amount can never cross batches.
            foreach($this->storeBatches($stores,$range) as $batch){
                $quantity=($this->quantityReader)($batch,$range,$employee);$this->cap($quantity);
                $amount=($this->amountReader)($batch,$range,$employee);$this->cap($amount);
                $snapshots=($this->snapshotReader)($batch,array_column($quantity,'service_fact_id'));
                $immediate=($this->saleProjectAmountReader)($batch,array_values($snapshots),$amount);
                $amount=array_merge($amount,$immediate['rows']);$this->cap($amount);
                // Feed both authorities into one aggregation, preserving item grouping and avoiding double totals.
                foreach($historical['services']as$old)if(in_array($old['store_id'],$batch,true)){$quantity[]=$old;$snapshots[$old['service_fact_id']]=$old;}
                foreach($historical['amounts']as$old)if(in_array($old['store_id'],$batch,true))$amount[]=$old;
                $part=$this->project($quantity,$amount,$snapshots,$batch,$range);
                foreach($part['rows'] as $row){
                    if(isset($seen[$row['row_id']]))throw new \LogicException('项目消耗跨批行键重复');
                    $seen[$row['row_id']]=true;$result['rows'][]=$row;
                }
                $this->cap($result['rows']);
                $result['gaps']=array_merge($result['gaps'],$part['gaps'],$immediate['gaps']);
                if(!in_array('consumption_amount',$part['sum_fields'],true)||$immediate['gaps']!==[])
                    $amountGap=true;
                unset($quantity,$amount,$snapshots,$immediate,$part);
            }
            usort($result['rows'],static fn(array $a,array $b):int=>strcmp($a['row_id'],$b['row_id']));
            $result['complete']=$result['gaps']===[];
            if($amountGap)$result['sum_fields']=['consumption_quantity'];
            $result['source_coverage']=$coverage;
            return $result;
        });
    }

    /**
     * Raw fact counts only plan memory-safe batches; all business numbers
     * still come from registered metrics. Overestimated voided rows merely
     * create smaller batches and cannot remove a result row.
     */
    private function storeBatches(array $stores,array $range):array
    {
        $weights=array_fill_keys($stores,0);
        foreach([['cashier_v3_entitlement_service_fact','service_status','completed'],
            ['cashier_v3_performance_fact','performance_type','consumption_performance_recorded']] as [$table,$field,$value]){
            $query=Db::name($table)->where('tenant_id','0')->whereIn('store_id',$stores)
                ->whereBetween('business_date',[$range['start'],$range['end']])->where($field,$value);
            if($table==='cashier_v3_performance_fact')$query->where('status','effective');
            foreach($query->fieldRaw('store_id,COUNT(*) AS fact_count')->group('store_id')->select()->toArray() as $row){
                $id=(int)$row['store_id'];
                if(!array_key_exists($id,$weights))throw new \LogicException('项目消耗分批越过授权门店');
                $weights[$id]=max($weights[$id],(int)$row['fact_count']);
            }
        }
        // Below the 10,000-source fail-closed boundary, while leaving room
        // for multiple monetary facts and immediate services in one batch.
        return self::partitionStores($stores,$weights,8000);
    }

    /** The same store cannot be split across batches because it owns the grouping row key. */
    private static function partitionStores(array $stores,array $weights,int $maxFacts):array
    {
        if($maxFacts<1)throw new \InvalidArgumentException('项目消耗分批上限无效');
        $out=[];$batch=[];$used=0;
        foreach($stores as $store){
            $weight=max(1,(int)($weights[$store]??0));
            if($weight>10000)throw new \RuntimeException('单店项目消耗事实超过10000条，请缩小日期范围');
            if($batch!==[]&&$used+$weight>$maxFacts){$out[]=$batch;$batch=[];$used=0;}
            $batch[]=$store;$used+=$weight;
        }
        if($batch!==[])$out[]=$batch;
        return $out;
    }

    /** 缺旧服务映射时，部分 V3 行、金额合计和导出全部撤回。 */
    private function failClosed(array $stores,array $range,array $coverage): array
    {
        $result=$this->project([],[],[],$stores,$range);
        $result['rows']=[];$result['sum_fields']=[];
        $result['gaps']=$coverage['gaps']??[['code'=>'LEGACY_WRITEOFF_SOURCE_UNVERIFIED',
            'message'=>'旧核销覆盖未认证。']];
        $result['complete']=false;$result['source_coverage']=$coverage;
        return $result;
    }

    /** 仅补齐已授权服务事实的结账键，不能从快照引入新服务或金额。 */
    private function snapshots(array $stores, array $ids): array
    {
        $out = [];
        // 同一只读批最多 8,000 个服务；扩大键批可减少整月数百次往返，
        // 每次仍受 10,001 行上限与已授权门店双重约束。
        foreach (array_chunk(array_values(array_unique(array_filter($ids))), 8000) as $chunk) {
            $rows = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', '0')
                ->whereIn('store_id', $stores)->whereIn('service_fact_id', $chunk)
                ->field('service_fact_id,store_id,member_id,checkout_request_id,source_line_id,source_document_type,'
                    .'source_document_id,document_id,project_id,quantity')->limit(10001)->select()->toArray();
            $this->cap($rows);
            foreach ($rows as $row) $out[(string)$row['service_fact_id']] = $row;
        }
        return $out;
    }

    /** 原核销金额按完整服务行归属；缺少消费事实不写零并停用金额合计。 */
    private function project(array $quantity, array $amount, array $snapshots, array $stores, array $range): array
    {
        $authorized = array_fill_keys($stores, true); $money = []; $moneySeen = [];
        foreach ($amount as $fact) {
            $key = json_encode([(int)($fact['store_id'] ?? 0), (string)($fact['checkout_request_id'] ?? ''),
                (string)($fact['source_line_id'] ?? '')]);
            if (!isset($authorized[(int)($fact['store_id'] ?? 0)])) throw new \LogicException('消耗金额越过授权门店');
            $factId = (string)($fact['fact_id'] ?? '');
            if ($factId === '' || isset($moneySeen[$factId])) throw new \LogicException('消耗金额事实身份异常');
            $moneySeen[$factId] = true;
            $money[$key] = ($money[$key] ?? 0) + (int)($fact['amount_cents'] ?? 0);
        }
        $rows = []; $gaps = []; $serviceSeen = [];
        foreach ($quantity as $fact) {
            $id = (string)($fact['service_fact_id'] ?? '');
            $storeId = (int)($fact['store_id'] ?? 0);
            if ($id === '' || isset($serviceSeen[$id]) || !isset($authorized[$storeId])) {
                throw new \LogicException('服务数量事实身份或权限异常');
            }
            $serviceSeen[$id] = true;
            $snapshot = $snapshots[$id] ?? null;
            if (!$snapshot || (int)$snapshot['store_id'] !== $storeId) throw new \LogicException('服务事实结账键缺失');
            $line = json_encode([$storeId, (string)$snapshot['checkout_request_id'], (string)$fact['source_line_id']]);
            $itemId = (int)($fact['item_id'] ?? $fact['project_id'] ?? 0);
            $categoryId = (int)($fact['category_id'] ?? 0);
            $name = (string)($fact['project_name_snapshot'] ?? $fact['item_name'] ?? '');
            if ($itemId <= 0 || ($categoryId <= 0&&!($fact['category_unclassified']??false)) || trim($name) === '') {
                $gaps[] = ['service_fact_id'=>$id,'code'=>'PROJECT_IDENTITY_UNRESOLVED',
                    'message'=>'服务项目或当前分类无法可靠定位，保留事实但不猜分类或名称。'];
            }
            $identity = $itemId > 0 && ($categoryId > 0||($fact['category_unclassified']??false)) && trim($name) !== ''
                ? [$storeId,$categoryId,$itemId,$name] : [$storeId,'unresolved',$id];
            $rowKey = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE));
            if (!isset($rows[$rowKey])) $rows[$rowKey] = [
                'row_id'=>'item_consumption_007:'.$rowKey, 'store_id'=>$storeId,
                'store_name'=>(string)($fact['store_name_snapshot'] ?? ''),
                'category_id'=>$categoryId, 'category_path'=>(string)($fact['category_path'] ?? ''),
                'item_id'=>$itemId, 'item_name'=>$name, 'consumption_quantity'=>0.0,
                '_amount_cents'=>0, '_amount_missing'=>false];
            if (!is_numeric($fact['quantity'] ?? null) || (float)$fact['quantity'] < 0) {
                throw new \LogicException('服务数量无效');
            }
            $rows[$rowKey]['consumption_quantity'] += (float)$fact['quantity'];
            if (!array_key_exists($line, $money)) {
                $rows[$rowKey]['_amount_missing'] = true;
                $gaps[] = ['service_fact_id'=>$id,'code'=>'CONSUMPTION_AMOUNT_FACT_MISSING',
                    'message'=>'已完成服务缺少对应消费金额事实；金额不显示为0。'];
            } else {
                $rows[$rowKey]['_amount_cents'] += $money[$line];
                unset($money[$line]); // 一条完整服务行的消费金额只归属一次。
            }
        }
        // A registered consumption fact without a matching completed service
        // would otherwise disappear from the result while totals looked complete.
        // Keep its identity as evidence and withhold the money total.
        foreach ($money as $line => $amountCents) {
            $gaps[] = ['source_line_key'=>$line,'code'=>'CONSUMPTION_ORPHAN_AMOUNT',
                'amount_cents'=>$amountCents,
                'message'=>'消费金额事实没有匹配的已完成服务行，不能静默漏计或归入其他项目。'];
        }
        $this->cap($rows);
        $resultRows = [];
        foreach ($rows as $row) {
            $row['consumption_quantity'] = round($row['consumption_quantity'], 2, PHP_ROUND_HALF_UP);
            $row['consumption_amount'] = $row['_amount_missing'] ? null : $row['_amount_cents'] / 100;
            unset($row['_amount_cents'], $row['_amount_missing']);
            $resultRows[] = $row;
        }
        usort($resultRows, static fn(array $a,array $b): int => strcmp($a['row_id'],$b['row_id']));
        $hasAmountGap = count(array_filter($gaps, static fn(array $gap): bool => in_array($gap['code'],
            ['CONSUMPTION_AMOUNT_FACT_MISSING','CONSUMPTION_ORPHAN_AMOUNT'],true))) > 0;
        return ['rows'=>$resultRows,'fields'=>$this->fields(), 'field_sources'=>[
            'store_name'=>'核销服务事实的执行门店名称快照。',
            'category_path'=>'服务项目关联的平台商品当前分类。',
            'item_name'=>'服务完成时冻结的项目名称。',
            'consumption_amount'=>'权益核销取有效消费事实；整行即买即用项目核同一已结算销售行后取冻结行成交额，保留分精度。',
            'consumption_quantity'=>'已完成服务事实的原服务数量，每条服务行只计一次。',
            'store_id'=>'执行门店ID，供授权与稳定行键使用。',
            'category_id'=>'当前平台分类ID，供精确筛选和稳定行键使用。',
            'item_id'=>'服务事实保存的项目ID，供精确筛选和稳定行键使用。',
        ],'complete'=>$gaps===[],'gaps'=>$gaps,
            'sum_fields'=>$hasAmountGap?['consumption_quantity']:['consumption_amount','consumption_quantity'],
            'metric_version'=>'item-consumption-007-v3-candidate-1',
            'coverage_start'=>StoreUnifiedReportServices::COVERAGE_START,'data_as_of'=>date('c'),
            'scope'=>['store_ids'=>$stores,'date_range'=>$range],
            'fact_completeness'=>'V3已完成服务与有效消费事实；旧有效核销若缺原ID映射则整表拒绝展示。',
            'limitations'=>['007 旧SQL按门店名、分类名、项目名汇总并向下取整金额；本表按稳定ID归组，金额在分精度汇总后交由统一金额展示规则。']];
    }

    /** 不查库的五列契约由专用读取器维护，统一入口复用同一顺序。 */
    public static function definitions(): array { return self::fields(); }

    public static function fields(): array
    {
        $spec = [['store_name','门店','text',false],['category_path','分类','text',false],
            ['item_name','名称','text',false],['consumption_amount','消耗金额','decimal',true],
            ['consumption_quantity','消耗数量','decimal',false]];
        $fields = [];
        foreach ($spec as [$key,$label,$type,$money]) $fields[] = [
            'key'=>$key,'label'=>$label,'type'=>$type,'defaultVisible'=>true,
            'defaultQuick'=>in_array($key,['store_name','item_name'],true),'width'=>150,
            'fixed'=>$key==='store_name'?'left':'','money'=>$money,
            'summable'=>in_array($key,['consumption_amount','consumption_quantity'],true)];
        foreach (['store_id','category_id','item_id'] as $key) $fields[] = [
            'key'=>$key,'label'=>$key,'type'=>'integer','defaultVisible'=>false,'defaultQuick'=>false,
            'width'=>100,'fixed'=>'','money'=>false,'summable'=>false];
        return $fields;
    }

    private function stores(array $stores): array
    {
        $ids=[];
        foreach($stores as $id){if((!is_int($id)&&!(is_string($id)&&ctype_digit($id)))||(int)$id<=0)
            throw new \InvalidArgumentException('授权门店无效');$ids[(int)$id]=(int)$id;}
        return array_values($ids);
    }

    private function range(array $range): array
    {
        foreach(['start','end'] as $key){$value=$range[$key]??null;
            $day=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
            if(!$day||$day->format('Y-m-d')!==$value)throw new \InvalidArgumentException('日期须为有效YYYY-MM-DD');}
        if($range['start']>$range['end']||$range['start']<StoreUnifiedReportServices::COVERAGE_START)
            throw new \InvalidArgumentException('日期范围无效或早于V3覆盖期');
        return ['start'=>$range['start'],'end'=>$range['end']];
    }

    private function employee(array $ids): int
    {
        if($ids===[])return 0;
        if(count($ids)!==1||(!is_int($ids[0])&&!(is_string($ids[0])&&ctype_digit($ids[0])))||(int)$ids[0]<=0)
            throw new \InvalidArgumentException('本人参与权限身份无效');
        return (int)$ids[0];
    }

    private function cap(array $rows): void
    {
        if(count($rows)>10000)throw new \InvalidArgumentException('基础报表源数据超过10000行，请缩小范围');
    }
}

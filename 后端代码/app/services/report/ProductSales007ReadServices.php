<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/** 007 产品销售表的 V3 只读候选：原商品行 × 原销售人 × 有效支付方式。 */
final class ProductSales007ReadServices
{
    private $saleReader;
    private $personReader;
    private $paymentReader;
    private $coverageReader;

    /** 测试可注入事实，生产只查询已认证门店内的正式销售、人员和收款分摊。 */
    public function __construct(?callable $saleReader = null, ?callable $personReader = null,
        ?callable $paymentReader = null, ?callable $coverageReader = null)
    {
        $this->saleReader = $saleReader ?: [$this,'sales'];
        $this->personReader = $personReader ?: [$this,'people'];
        $this->paymentReader = $paymentReader ?: [$this,'payments'];
        $this->coverageReader = $coverageReader ?: [new ProductSales007SourceCoverageServices(),'read'];
    }

    /** 本人参与范围只读该员工的原销售人事实，筛选不能重算其他员工金额。 */
    public function read(array $stores,array $range,array $employeeIds=[]):array
    {
        $stores=$this->stores($stores);$range=$this->range($range);$employee=$this->employee($employeeIds);
        if(!$stores)return $this->project([],[],['payments'=>[],'allocations'=>[],'balances'=>[]],$stores,$range,$employee);
        return Db::transaction(function()use($stores,$range,$employee):array{
            $sales=($this->saleReader)($stores,$range);$this->cap($sales);
            // The original 007 query can have eligible old product cart lines
            // absent from V3. An empty V3 list alone never proves zero sales.
            $coverage=($this->coverageReader)($stores,$range,$sales);
            if(!($coverage['complete']??false)){
                return $this->failClosed($stores,$range,$employee,$coverage);
            }
            // 007 only lists qualified old product carts. A V3 import may
            // contain other product facts from the same order, so source
            // coverage must also act as the row eligibility boundary.
            $sales=$this->eligibleSales($sales,$coverage);
            $people=($this->personReader)($stores,$range,$sales,$employee);$this->cap($people);
            $payment=($this->paymentReader)($stores,$sales);
            $this->cap($payment['payments']??[]);$this->cap($payment['allocations']??[]);
            $result=$this->project($sales,$people,$payment,$stores,$range,$employee);
            foreach($coverage['gaps']??[] as $gap)$result['gaps'][]=$gap;
            $result['complete']=$result['gaps']===[];
            $result['source_coverage']=$coverage;
            return $result;
        });
    }

    /** Keep new V3 facts, but admit imported old product lines only when the original 007 eligibility query proves them. */
    private function eligibleSales(array $sales,array $coverage):array
    {
        $eligible=array_fill_keys($coverage['eligible_legacy_fact_ids']??[],true);
        return array_values(array_filter($sales,static function(array $sale)use($eligible):bool{
            return (string)($sale['source_document_type']??'')!=='legacy_store_order'
                ||isset($eligible[(string)($sale['fact_id']??'')]);
        }));
    }

    /** Partial source coverage makes every visible row and export misleading. */
    private function failClosed(array $stores,array $range,int $employee,array $coverage):array
    {
        $result=$this->project([],[],['payments'=>[],'allocations'=>[],'balances'=>[]],$stores,$range,$employee);
        $result['rows']=[];$result['sum_fields']=[];
        $result['gaps']=$coverage['gaps']??[['code'=>'LEGACY_PRODUCT_SOURCE_UNVERIFIED',
            'message'=>'007 产品销售来源覆盖未认证。']];
        $result['complete']=false;$result['source_coverage']=$coverage;
        return $result;
    }

    /** 只取未冲销、未作废的正式产品原销售行，日期属于成交而非后补收款。 */
    private function sales(array $stores,array $range):array
    {
        $query=Db::name('cashier_v3_sale_fact')->alias('s')
            ->leftJoin('system_store st','st.id=s.store_id')
            ->where('s.tenant_id','0')->whereIn('s.store_id',$stores)
            ->whereBetween('s.business_date',[$range['start'],$range['end']])
            ->where('s.status','effective')->where('s.fact_direction','forward')
            ->whereIn('s.source_type',['product','goods'])
            ->whereNotExists(function($reversal):void{
                $reversal->name('cashier_v3_sale_fact')->alias('product_sale_reversal')
                    ->whereRaw('product_sale_reversal.tenant_id=s.tenant_id')
                    ->whereRaw('product_sale_reversal.reversal_of=s.fact_id')
                    ->where('product_sale_reversal.fact_direction','reversal')
                    ->where('product_sale_reversal.status','effective');
            });
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query,'s.tenant_id','s.order_id');
        return $query->fieldRaw('s.fact_id,s.store_id,s.order_id,s.source_line_id,s.source_document_type,'
            .'s.business_date,COALESCE(st.name,s.store_name_snapshot) store_name,'
            .'s.member_name_snapshot,s.item_name_snapshot,s.quantity,s.sale_amount_cents')
            ->limit(10001)->select()->toArray();
    }

    /** 同一原销售明细可能多名销售人；被冲销的旧分配事实不能重复入报表。 */
    private function people(array $stores,array $range,array $sales,int $employee):array
    {
        $orders=array_values(array_unique(array_filter(array_map('strval',array_column($sales,'order_id')))));
        $lines=array_values(array_unique(array_filter(array_map('strval',array_column($sales,'source_line_id')))));
        if(!$orders||!$lines)return [];
        $out=[];
        foreach(array_chunk($orders,500) as $orderChunk){
            foreach(array_chunk($lines,500) as $lineChunk){
                $query=Db::name('cashier_v3_performance_fact')->alias('p')
                    ->where('p.tenant_id','0')->whereIn('p.store_id',$stores)
                    ->whereIn('p.order_id',$orderChunk)->whereIn('p.source_line_id',$lineChunk)
                    ->where('p.performance_type','sales_performance_allocated')
                    // Imported 007 orders preserve their original salesperson
                    // allocation as a legacy_store_order performance fact.
                    ->whereIn('p.source_document_type',['cashier_snapshot','sales_order','legacy_store_order'])
                    ->where('p.fact_direction','forward')->where('p.status','effective')
                    ->where('p.employee_id','>',0)
                    ->whereNotExists(function($reversal):void{
                        $reversal->name('cashier_v3_performance_fact')->alias('product_person_reversal')
                            ->whereRaw('product_person_reversal.tenant_id=p.tenant_id')
                            ->whereRaw('product_person_reversal.reversal_of=p.fact_id')
                            ->where('product_person_reversal.fact_direction','reversal')
                            ->where('product_person_reversal.status','effective');
                    });
                if($employee>0)$query->where('p.employee_id',$employee);
                (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query,'p.tenant_id','p.order_id');
                $batch=$query->field('p.fact_id,p.store_id,p.order_id,p.source_line_id,p.source_document_type,p.employee_id,'
                    .'p.employee_name_snapshot,p.amount_cents')->limit(10001)->select()->toArray();
                $this->cap($batch);$out=array_merge($out,$batch);$this->cap($out);
            }
        }
        return $out;
    }

    /** 原单有效现金与余额分别证明支付方式；欠款后补不制造原单现金行。 */
    private function payments(array $stores,array $sales):array
    {
        $orders=array_values(array_unique(array_filter(array_map('strval',array_column($sales,'order_id')))));
        $saleIds=array_values(array_unique(array_filter(array_map('strval',array_column($sales,'fact_id')))));
        $lines=array_values(array_unique(array_filter(array_map('strval',array_column($sales,'source_line_id')))));
        $receipts=[];$allocations=[];$balances=[];
        foreach(array_chunk($orders,500) as $chunk){
            $batch=Db::name('cashier_v3_payment_fact')->where('tenant_id','0')
                ->whereIn('store_id',$stores)->whereIn('order_id',$chunk)
                ->whereIn('source_document_type',['cashier_snapshot','sales_order','legacy_store_order'])
                ->where('fact_type','payment_collected')->where('fact_direction','forward')
                ->where('status','effective')->where('amount_cents','>',0)
                ->whereNotIn('payment_method',['other_collection','old_card_entry'])
                ->field('fact_id,store_id,order_id,source_document_type,amount_cents')
                ->limit(10001)->select()->toArray();
            $this->cap($batch);$receipts=array_merge($receipts,$batch);$this->cap($receipts);
        }
        foreach(array_chunk(array_column($receipts,'fact_id'),500) as $paymentChunk){
            foreach(array_chunk($saleIds,500) as $saleChunk){
                $batch=Db::name('cashier_v3_payment_sale_allocation_fact')->where('tenant_id','0')
                    ->whereIn('store_id',$stores)->whereIn('payment_fact_id',$paymentChunk)
                    ->whereIn('sale_fact_id',$saleChunk)->where('status','effective')
                    ->where('fact_direction','forward')
                    ->field('allocation_fact_id,payment_fact_id,sale_fact_id,store_id,amount_cents')
                    ->limit(10001)->select()->toArray();
                $this->cap($batch);$allocations=array_merge($allocations,$batch);$this->cap($allocations);
            }
        }
        foreach(array_chunk($lines,500) as $chunk){
            $batch=Db::name('cashier_v3_order_balance_allocation')->where('tenant_id','0')
                ->whereIn('store_id',$stores)->whereIn('order_line_id',$chunk)
                ->field('store_id,order_id,order_line_id,amount_cents')->limit(10001)->select()->toArray();
            $this->cap($batch);$balances=array_merge($balances,$batch);$this->cap($balances);
        }
        return ['payments'=>$receipts,'allocations'=>$allocations,'balances'=>$balances];
    }

    /** 逐商品销售行、销售人、现金/余额拆行；逐人余额业绩无权威事实，金额留空。 */
    private function project(array $sales,array $people,array $payment,array $stores,array $range,int $employee=0):array
    {
        $authorized=array_fill_keys($stores,true);$saleById=[];$saleByLine=[];$gaps=[];$rows=[];$seen=[];
        foreach($sales as $sale){
            $id=(string)($sale['fact_id']??'');$storeId=(int)($sale['store_id']??0);
            if($id===''||isset($saleById[$id])||!isset($authorized[$storeId]))
                throw new \LogicException('产品原销售事实身份或权限异常');
            $saleById[$id]=$sale;
            $line=json_encode([$storeId,(string)$sale['order_id'],(string)$sale['source_line_id']]);
            if(isset($saleByLine[$line]))throw new \LogicException('产品原销售行重复');
            $saleByLine[$line]=$id;
        }
        $persons=[];
        foreach($people as $person){
            $id=(string)($person['fact_id']??'');$storeId=(int)($person['store_id']??0);
            if($id===''||isset($seen[$id])||!isset($authorized[$storeId]))
                throw new \LogicException('产品销售人事实身份或权限异常');
            $seen[$id]=true;
            $line=json_encode([$storeId,(string)($person['order_id']??''),(string)($person['source_line_id']??'')]);
            if(!isset($saleByLine[$line]))continue;
            $saleId=$saleByLine[$line];$employeeId=(int)($person['employee_id']??0);
            // A migrated order can reuse an opaque line key. Bind salesperson
            // identity to the same source document as the sale when both exist.
            $saleSource=(string)($saleById[$saleId]['source_document_type']??'');
            $personSource=(string)($person['source_document_type']??'');
            if($saleSource!==''&&$personSource!==''&&$saleSource!==$personSource)
                throw new \LogicException('产品销售人原单来源不一致');
            if($employeeId<=0)throw new \LogicException('产品销售人身份无效');
            if(!isset($persons[$saleId][$employeeId]))$persons[$saleId][$employeeId]=[
                'employee_id'=>$employeeId,'name'=>(string)($person['employee_name_snapshot']??''),'amount_cents'=>0];
            $persons[$saleId][$employeeId]['amount_cents']+=(int)($person['amount_cents']??0);
        }
        $receipts=[];
        foreach($payment['payments']??[] as $receipt){
            $id=(string)($receipt['fact_id']??'');$storeId=(int)($receipt['store_id']??0);
            if($id===''||isset($receipts[$id])||!isset($authorized[$storeId]))
                throw new \LogicException('产品原结账收款身份或权限异常');
            $receipts[$id]=$receipt;
        }
        $cash=[];$allocationSeen=[];
        foreach($payment['allocations']??[] as $allocation){
            $saleId=(string)($allocation['sale_fact_id']??'');$paymentId=(string)($allocation['payment_fact_id']??'');
            if(!isset($saleById[$saleId])||!isset($receipts[$paymentId])
                ||(int)($allocation['store_id']??0)!==(int)$saleById[$saleId]['store_id']
                ||(string)$receipts[$paymentId]['order_id']!==(string)$saleById[$saleId]['order_id']
                ||((string)($saleById[$saleId]['source_document_type']??'')!==''
                    &&(string)($receipts[$paymentId]['source_document_type']??'')
                        !==(string)$saleById[$saleId]['source_document_type']))
                throw new \LogicException('产品原结账分摊归属异常');
            if(isset($allocationSeen[$paymentId][$saleId]))throw new \LogicException('产品原结账分摊重复');
            $allocationSeen[$paymentId][$saleId]=true;
            $cents=(int)($allocation['amount_cents']??0);
            if($cents<0)throw new \LogicException('产品原结账正向分摊金额无效');
            $cash[$saleId]=($cash[$saleId]??0)+$cents;
        }
        $balance=[];
        foreach($payment['balances']??[] as $allocation){
            $line=json_encode([(int)($allocation['store_id']??0),(string)($allocation['order_id']??''),
                (string)($allocation['order_line_id']??'')]);
            if(!isset($saleByLine[$line]))continue;
            $cents=(int)($allocation['amount_cents']??0);
            if($cents<0)throw new \LogicException('产品原余额分摊金额无效');
            $id=$saleByLine[$line];$balance[$id]=($balance[$id]??0)+$cents;
        }
        foreach($saleById as $saleId=>$sale){
            if(!isset($persons[$saleId])){
                if($employee===0)$gaps[]=['sale_fact_id'=>$saleId,'code'=>'SALESPERSON_FACT_MISSING',
                    'message'=>'原产品成交行缺少有效销售人事实，不能造一名销售人。'];
                continue;
            }
            $cashPositive=($cash[$saleId]??0)>0;
            $balancePositive=($balance[$saleId]??0)>0;
            foreach($persons[$saleId] as $person){
                if(!$cashPositive&&$person['amount_cents']!==0)$gaps[]=[
                    'sale_fact_id'=>$saleId,'code'=>'CASH_ALLOCATION_MISSING',
                    'message'=>'销售人存在现金业绩但原商品行缺少可证实现金收款分摊。'];
                foreach([['cash',$cashPositive],['balance',$balancePositive]] as [$method,$visible]){
                    if(!$visible)continue;
                    $rowKey=hash('sha256',json_encode([$saleId,$person['employee_id'],$method]));
                    $rows[]=['row_id'=>'product_sales_007:'.$rowKey,
                        'business_date'=>(string)$sale['business_date'],
                        'store_name'=>(string)$sale['store_name'],
                        'member_name'=>(string)$sale['member_name_snapshot'],
                        'product_name'=>(string)$sale['item_name_snapshot'],
                        'payment_method'=>$method==='cash'?'现金':'余额',
                        'amount'=>$method==='cash'?$person['amount_cents']/100:null,
                        'salesperson'=>(string)$person['name'],
                        'store_id'=>(int)$sale['store_id'],'sale_fact_id'=>$saleId,
                        'employee_id'=>$person['employee_id'],'payment_method_code'=>$method];
                    if($method==='balance')$gaps[]=['sale_fact_id'=>$saleId,'code'=>'PERSON_BALANCE_PERFORMANCE_UNAVAILABLE',
                        'message'=>'V3 没有逐销售人的原余额业绩事实；不能按分配比例编造金额。'];
                }
            }
        }
        $this->cap($rows);
        usort($rows,static fn(array $a,array $b):int=>strcmp($b['business_date'],$a['business_date'])
            ?:strcmp($a['row_id'],$b['row_id']));
        return ['rows'=>$rows,'fields'=>$this->fields(),'field_sources'=>$this->fieldSources(),
            'complete'=>$gaps===[],'gaps'=>$gaps,'sum_fields'=>[],
            'metric_version'=>'product-sales-007-v3-candidate-1',
            'coverage_start'=>StoreUnifiedReportServices::COVERAGE_START,'data_as_of'=>date('c'),
            'scope'=>['store_ids'=>$stores,'date_range'=>$range],
            'fact_completeness'=>'V3正式原商品成交、原结账收款、余额及有效销售人事实；007有效旧商品行须逐原cart_id核实迁移覆盖。',
            'limitations'=>['007 现金行金额取旧逐人 yeji，V3 现金行取正式销售人业绩事实；人工改派与旧 SQL 仍需逐单对账。',
                '007 余额行金额取旧逐人 deduct_card_yeji；V3 缺同义逐人事实，余额行金额为空并标缺口。']];
    }

    /** 007 七列纯静态契约，供统一入口提前展示。 */
    public static function definitions():array { return self::fields(); }

    public static function fields():array
    {
        $spec=[['business_date','日期','date',false],['store_name','门店','text',false],
            ['member_name','客户名称','text',false],['product_name','商品名称','text',false],
            ['payment_method','支付方式','text',false],['amount','金额','decimal',true],
            ['salesperson','销售人','text',false]];
        $out=[];foreach($spec as [$key,$label,$type,$money])$out[]=['key'=>$key,'label'=>$label,'type'=>$type,
            'defaultVisible'=>true,'defaultQuick'=>in_array($key,['business_date','store_name','product_name','salesperson'],true),
            'width'=>150,'fixed'=>$key==='business_date'?'left':'','money'=>$money,'summable'=>false];
        foreach(['store_id','sale_fact_id','employee_id','payment_method_code'] as $key)$out[]=[
            'key'=>$key,'label'=>$key,'type'=>in_array($key,['store_id','employee_id'],true)?'integer':'text',
            'defaultVisible'=>false,'defaultQuick'=>false,'width'=>100,'fixed'=>'','money'=>false,'summable'=>false];
        return $out;
    }
    private function fieldSources():array
    {
        return ['business_date'=>'原商品成交事实的业务日期。','store_name'=>'原商品成交门店当前名称。',
            'member_name'=>'原成交时会员名称快照。','product_name'=>'原成交时商品名称快照。',
            'payment_method'=>'原单有效现金收款分摊或余额扣款分摊，按方式拆行。',
            'amount'=>'现金行取原单有效逐人销售业绩事实；余额行无逐人权威事实时为null。',
            'salesperson'=>'原销售人业绩事实的姓名快照。','store_id'=>'授权门店ID。',
            'sale_fact_id'=>'不可变原商品销售事实ID。','employee_id'=>'原销售人身份ID。',
            'payment_method_code'=>'原现金或余额支付方式的稳定代码。'];
    }
    private function stores(array $stores):array
    {
        $out=[];foreach($stores as $id){if((!is_int($id)&&!(is_string($id)&&ctype_digit($id)))||(int)$id<=0)
            throw new \InvalidArgumentException('授权门店无效');$out[(int)$id]=(int)$id;}return array_values($out);
    }
    private function range(array $range):array
    {
        foreach(['start','end'] as $key){$value=$range[$key]??null;$date=is_string($value)
            ?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
            if(!$date||$date->format('Y-m-d')!==$value)throw new \InvalidArgumentException('日期须为有效YYYY-MM-DD');}
        if($range['start']>$range['end']||$range['start']<StoreUnifiedReportServices::COVERAGE_START)
            throw new \InvalidArgumentException('日期范围无效或早于V3覆盖期');
        return ['start'=>$range['start'],'end'=>$range['end']];
    }
    private function employee(array $ids):int
    {
        if($ids===[])return 0;
        if(count($ids)!==1||(!is_int($ids[0])&&!(is_string($ids[0])&&ctype_digit($ids[0])))||(int)$ids[0]<=0)
            throw new \InvalidArgumentException('本人参与权限身份无效');return (int)$ids[0];
    }
    private function cap(array $rows):void
    {
        if(count($rows)>10000)throw new \InvalidArgumentException('基础报表源数据超过10000行，请缩小范围');
    }
}

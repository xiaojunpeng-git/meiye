<?php

declare(strict_types=1);

/** 007 跨店结算按门店净额展示；来源覆盖缺口使列表与合计一起撤回。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\CrossStoreSettlement007ReadServices;

$dimensions=static fn(array $stores):array=>[['id'=>9,'name'=>'甲店'],['id'=>10,'name'=>'乙店']];
$pairs=['rows'=>[['source_store_id'=>9,'target_store_id'=>10,
    'stored_value_payable'=>12.34,'stored_value_receivable'=>12.34,
    'card_payable'=>5.0,'card_receivable'=>5.0]],'complete'=>true,'data_gaps'=>[]];
$pairReader=static fn(array $stores,array $range):array=>$pairs;
$range=['start'=>'2026-10-20','end'=>'2026-10-20'];
$unverified=(new CrossStoreSettlement007ReadServices($pairReader,$dimensions,
    static fn(array $stores,array $range):bool=>false))->read([9,10],$range);
if($unverified['complete']||$unverified['sum_fields']!==[]
    ||$unverified['rows']!==[]
    ||!in_array('CROSS_STORE_SOURCE_COVERAGE_UNVERIFIED',array_column($unverified['gaps'],'code'),true))
    throw new RuntimeException('unverified source snapshot must withhold amounts');
// 单店既可能作为执行店应收，也可能作为原购买店应付；只读一端时两种方向都不能显示零。
foreach([[9,10],[10,9]] as [$origin,$target]){
    $oneStore=(new CrossStoreSettlement007ReadServices(
        static function() use($origin,$target):array {
            throw new RuntimeException('open-ended single-store pair reader must not run');
        },static fn(array $stores):array=>[['id'=>9,'name'=>'甲店']],
        static fn(array $stores,array $range):bool=>false))->read([9],$range);
    if($oneStore['rows']!==[]||$oneStore['complete']
        ||!in_array('CROSS_STORE_SOURCE_COVERAGE_UNVERIFIED',array_column($oneStore['gaps'],'code'),true))
        throw new RuntimeException('single-store cross direction must fail closed');
}
$singleStore=static fn(array $stores):array=>[['id'=>9,'name'=>'甲店']];
$outgoing=(new CrossStoreSettlement007ReadServices(static fn():array=>$pairs,$singleStore,
    static fn():bool=>true))->read([9],$range);
if(!$outgoing['complete']||count($outgoing['rows'])!==1
    ||(int)round($outgoing['rows'][0]['total_amount']*100)!==-1734)
    throw new RuntimeException('single store origin must receive signed payable');
$incoming=$pairs;$incoming['rows'][0]['source_store_id']=10;$incoming['rows'][0]['target_store_id']=9;
$receivable=(new CrossStoreSettlement007ReadServices(static fn():array=>$incoming,$singleStore,
    static fn():bool=>true))->read([9],$range);
if(!$receivable['complete']||count($receivable['rows'])!==1
    ||(int)round($receivable['rows'][0]['total_amount']*100)!==1734)
    throw new RuntimeException('single store execution must receive signed receivable');
$verified=(new CrossStoreSettlement007ReadServices($pairReader,$dimensions,
    static fn(array $stores,array $range):bool=>true))->read([9,10],$range);
if(!$verified['complete']||count($verified['rows'])!==2
    ||(int)round($verified['rows'][0]['stored_value_amount']*100)!==-1234
    ||(int)round($verified['rows'][0]['card_amount']*100)!==-500
    ||(int)round($verified['rows'][0]['total_amount']*100)!==-1734
    ||(int)round($verified['rows'][1]['total_amount']*100)!==1734)
    throw new RuntimeException('signed store-level settlement projection');
if(array_slice(array_column($verified['fields'],'label'),0,4)!==
    ['门店','储值对账金额','次卡对账金额','合计'])
    throw new RuntimeException('007 cross-store exact field labels');
if(count($verified['field_sources'])!==count($verified['fields']))
    throw new RuntimeException('field source completeness');
$orderPayProject=new ReflectionMethod(CrossStoreSettlement007ReadServices::class,'projectOrderPay');
$orderPayProject->setAccessible(true);
$fact=['fact_id'=>'balance-old-1','store_id'=>10,'member_id'=>18,'order_id'=>'IMP007-501-sale',
    'source_document_type'=>'legacy_store_order',
    'principal_delta_cents'=>-1200,'bonus_delta_cents'=>-300];
$old=[501=>['id'=>501,'order_id'=>'old-order-18','uid'=>18,'store_id'=>10,
    'kua_store'=>9,'paid'=>1,'order_type'=>0]];
$headers=['IMP007-501-sale'=>['source_document_id'=>'501','source_document_no_snapshot'=>'old-order-18',
    'order_id'=>'IMP007-501-sale','member_id'=>18,'store_id'=>10]];
$supplement=$orderPayProject->invoke(new CrossStoreSettlement007ReadServices(),[$fact],$old,$headers,[9,10]);
if($supplement['data_gaps']!==[]||count($supplement['rows'])!==1
    ||(int)round($supplement['rows'][0]['stored_value_payable']*100)!==1500
    ||(int)round($supplement['rows'][0]['stored_value_receivable']*100)!==1500
    ||$supplement['rows'][0]['source_store_id']!==9)
    throw new RuntimeException('imported order_pay original debit must produce conserved pair');
$wrongHeader=$headers;$wrongHeader['IMP007-501-sale']['member_id']=19;
$unbound=$orderPayProject->invoke(new CrossStoreSettlement007ReadServices(),[$fact],$old,$wrongHeader,[9,10]);
if($unbound['rows']!==[]||$unbound['data_gaps'][0]['code']!=='CROSS_STORE_ORDER_PAY_SOURCE_UNVERIFIED')
    throw new RuntimeException('cross order_pay source identity must fail closed');
$unknownDirection=$old;unset($unknownDirection[501]['kua_store']);
$direction=$orderPayProject->invoke(new CrossStoreSettlement007ReadServices(),[$fact],$unknownDirection,$headers,[9,10]);
if($direction['rows']!==[]||$direction['data_gaps'][0]['code']!=='CROSS_STORE_ORDER_PAY_DIRECTION_MISSING')
    throw new RuntimeException('cross order_pay direction must be explicit');
$bad=$pairs;$bad['data_gaps']=[['code'=>'SOURCE_MISSING','message'=>'missing']];$bad['complete']=false;
$incomplete=(new CrossStoreSettlement007ReadServices(static fn(array $stores,array $range):array=>$bad,
    $dimensions,static fn(array $stores,array $range):bool=>true))->read([9,10],$range);
if($incomplete['complete']||$incomplete['rows']!==[])
    throw new RuntimeException('missing pair fact must withhold totals');
try{(new CrossStoreSettlement007ReadServices($pairReader,$dimensions,
    static fn(array $stores,array $range):bool=>true))->read([8],$range);
    throw new RuntimeException('scope failed');}
catch(LogicException $expected){}
echo "cross-store-settlement-007-read: PASS\n";

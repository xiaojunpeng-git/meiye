<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../后端代码/vendor/autoload.php';
require_once __DIR__ . '/../../../后端代码/app/services/report/Sidebar007ParityPlanServices.php';
require_once __DIR__ . '/../../../后端代码/app/services/report/Sidebar007PartnerAllocationReadServices.php';
require_once __DIR__ . '/../../../后端代码/app/services/report/Sidebar007SalesV3ReadServices.php';

use app\services\report\Sidebar007SalesV3ReadServices;

/** 独立合同只检验授权、007 字段结构和缺口传播；不把模拟事实当成线上业务验收。 */
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function reject(callable $fn, string $message): void
{
    try { $fn(); } catch (InvalidArgumentException|LogicException $e) { return; }
    throw new RuntimeException($message);
}

$reader = new Sidebar007SalesV3ReadServices();
$stores = [['id'=>7,'name'=>'万象城店'], ['id'=>8,'name'=>'滨北店']];
$coverage = static fn(array $ids, string $start, string $end): array => [
    ['store_id'=>7,'sale_fact_rows'=>'12','sale_orders'=>'4'],
];
$partner = static fn(array $ids, array $period): array => [
    ['store_id'=>7,'partner_amount_cents'=>'5000','outsourced_amount_cents'=>'8000',
        'refund_deduction_cents'=>'-1000','unclassified_rows'=>'0','unsigned_reversal_rows'=>'0'],
];
$sourceMap = static fn(): array => ['complete'=>true,'by_007_id'=>[],
    'by_v3_id'=>[], 'applied_at'=>1720000000];
$sourceCategories = static fn():array => ['by_store'=>[], 'gaps'=>[], 'complete'=>true];
$serviceGap=static fn():array=>['complete'=>false,'by_store'=>[],
    'gaps'=>[['code'=>'LEGACY_SERVICE_ORDER_QUALIFICATION_UNVERIFIED']]];
$serviceFull=static fn():array=>['complete'=>true,'gaps'=>[],'by_store'=>[
    7=>['consumption_amount_cents'=>62300,'service_customer_count'=>63],
    8=>['consumption_amount_cents'=>0,'service_customer_count'=>0]]];
$designatedGap=static fn():array=>['complete'=>false,'by_store'=>[7=>null,8=>null],
    'gaps'=>[['code'=>'DESIGNATED_POINT_LABOR_FACT_MISSING']]];
$designatedFull=static fn():array=>['complete'=>true,'by_store'=>[7=>31,8=>0],'gaps'=>[]];

$sales = $reader->read('sales_data', [7,8], $stores, '2026-10-01', '2026-10-30',
    [7=>[0=>2,1=>12300]], $coverage, $partner, $sourceMap, $sourceCategories,$serviceGap);
check(array_column($sales['columns'], 'label') === ['万象城店','滨北店'], '动态门店列顺序错误');
check(count($sales['rows']) === 20 && $sales['rows'][0]['metric'] === '散客'
    && $sales['rows'][19]['metric'] === '门店总业绩', '007 销售数据纵向字段不完整');
check($sales['rows'][0]['cells']['store_7'] === 2 && $sales['rows'][1]['cells']['store_7'] === 12300,
    '认证事实未按序号投影');
check($sales['rows'][17]['cells']['store_7'] === 5000 && $sales['rows'][17]['cells']['store_8'] === 0,
    '合作方业绩须从人员类型快照分配聚合，不能由外部认证输入覆盖');
check($sales['rows'][0]['cells']['store_8'] === null && !$sales['complete'] && count($sales['gaps']) === 36,
    '未经认证的格子不可伪造为零');
check($sales['coverage'][0]['sale_fact_rows'] === 12 && $sales['coverage'][1]['sale_fact_rows'] === 0,
    '事实覆盖统计只作诊断且空店补零');
check($sales['rows'][1]['unit'] === 'money_cents' && $sales['rows'][0]['unit'] === 'count', '行单位错误');
check($sales['row_sources'][17]['row_id']==='sales_data:17'
    && $sales['row_sources'][17]['description']!=='' && $sales['source_mapping']['complete']===true,
    '每行应有业务来源及冻结映射诊断');
$allMoney=[1=>100,3=>200,5=>300,7=>400,9=>500,11=>600,13=>700,15=>800];
$all=$reader->read('sales_data',[7,8],$stores,'2026-10-01','2026-10-30',
    [7=>$allMoney],$coverage,$partner,$sourceMap,$sourceCategories,$serviceFull);
check($all['rows'][18]['cells']['store_7']===63
    &&$all['rows'][19]['cells']['store_7']===8600,
    '总客数接服务子单；门店总业绩仅在各来源业绩齐全时按007加总');
$afterCategories=static fn():array=>['complete'=>true,'gaps'=>[],
    'by_store'=>[7=>['sales_data'=>[15=>400]]],
    'after_sales_entries'=>[7=>[['store_id'=>7,'business_date'=>'2026-10-10',
        'member_id'=>5,'friend'=>false,'order_id'=>'sale:A']]]];
$afterServices=static fn():array=>['complete'=>true,'gaps'=>[],
    'by_store'=>[7=>['consumption_amount_cents'=>1000,'service_customer_count'=>3],
        8=>['consumption_amount_cents'=>0,'service_customer_count'=>0]],
    'qualified_entries'=>[
        ['store_id'=>7,'business_date'=>'2026-10-10','member_id'=>5,'friend'=>false,
            'order_id'=>'service:1','aftercare_cash_eligible'=>true],
        ['store_id'=>7,'business_date'=>'2026-10-10','member_id'=>5,'friend'=>true,
            'order_id'=>'service:2','aftercare_cash_eligible'=>true],
        ['store_id'=>7,'business_date'=>'2026-10-10','member_id'=>5,'friend'=>true,
            'order_id'=>'service:3','aftercare_cash_eligible'=>true],
        ['store_id'=>7,'business_date'=>'2026-10-10','member_id'=>6,'friend'=>false,
            'order_id'=>'service:4','aftercare_cash_eligible'=>false]]];
$after=$reader->read('sales_data',[7,8],$stores,'2026-10-01','2026-10-30',[],
    $coverage,$partner,$sourceMap,$afterCategories,$afterServices);
check($after['rows'][14]['cells']['store_7']===3
    &&$after['rows'][15]['cells']['store_7']===400,
    '售后客合并来源11现金销售及合格服务子单，会员同日去重、朋友逐笔');

$analysis = $reader->read('sales_analysis', [7,8], $stores, '2026-10-01', '2026-10-30',
    [7=>['new_customer_count'=>12,'casual_customer_count'=>49]],
    $coverage, null, $sourceMap, $sourceCategories,$serviceFull,$designatedGap);
check(count($analysis['rows']) === 7 && $analysis['rows'][2]['cells']['store_7'] === 19,
    '007 销售分析纵向字段或整数百分比错误');
check($analysis['rows'][4]['cells']['store_7'] === null && !$analysis['complete'],
    '未证明的指定客数不可借相近指标填入');
check($analysis['rows'][5]['cells']['store_7']===63
    &&$analysis['rows'][5]['cells']['store_8']===0,
    '销售分析总客数须复用同一服务子单桥');
$analysisFull=$reader->read('sales_analysis',[7,8],$stores,'2026-10-01','2026-10-30',
    [7=>['new_customer_count'=>12,'casual_customer_count'=>49]],
    $coverage,null,$sourceMap,$sourceCategories,$serviceFull,$designatedFull);
check($analysisFull['rows'][4]['cells']['store_7']===31
    &&$analysisFull['rows'][6]['cells']['store_7']===49,
    '已认证点客劳务数与服务客数同源派生指定率');

reject(static fn() => $reader->read('sales_data', [7], $stores, '2026-10-01', '2026-10-30', [], $coverage, $partner),
    '越权门店列必须被拒绝');
reject(static fn() => $reader->read('sales_data', [7,8], $stores, '2026-10-01', '2026-10-30', [],
    static fn(): array => [['store_id'=>9,'sale_fact_rows'=>1,'sale_orders'=>1]]), '越权覆盖结果必须被拒绝');
reject(static fn() => $reader->read('sales_data', [7,8], $stores, '2026-10-01', '2026-10-30', [7=>[0=>1.5]], $coverage),
    '认证指标不得使用浮点数');
reject(static fn() => $reader->read('sales_data', [7,8], $stores, '2026-10-01', '2026-10-30', [7=>[20=>1]], $coverage),
    '未知指标序号不得被静默忽略');
reject(static fn() => $reader->read('sales_data', [7,8], $stores, '2026-10-01', '2026-10-30', [7=>[17=>1]], $coverage),
    '合作方业绩不得由外部注入覆盖快照事实');
reject(static fn() => $reader->read('sales_data', [7,8], $stores, '2026-10-30', '2026-10-01', [], $coverage),
    '逆序日期必须被拒绝');

echo "sidebar-007-sales-v3-read-contract PASS\n";

<?php
declare(strict_types=1);

require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\Sidebar007OriginalCashReadServices;

$day=['start'=>'2026-10-30','end'=>'2026-10-30'];
$paid=(new DateTimeImmutable('2026-10-30 09:00:00',new DateTimeZone('Asia/Shanghai')))->getTimestamp();
if(Sidebar007OriginalCashReadServices::legacyDateState(77,'2026-10-30',$paid-86400,$day)!=='inside'
    ||Sidebar007OriginalCashReadServices::legacyDateState(77,'2026-10-29',$paid,$day)!=='outside'
    ||Sidebar007OriginalCashReadServices::legacyDateState(null,null,$paid,$day)!=='inside'
    ||Sidebar007OriginalCashReadServices::legacyDateState(null,null,$paid-86400,$day)!=='outside'
    ||Sidebar007OriginalCashReadServices::legacyDateState(null,null,null,$day)!=='unresolved')
    throw new RuntimeException('跨日原单必须使用 V3 业务日，未迁只按支付日诊断');

/** 旧金额只作对账，成功计算的钱必须来自 V3 原销售/原充值收款。 */
$facts=static fn():array=>[
    ['fact_id'=>'p1','store_id'=>2,'order_id'=>'IMP007-10-sale','source_line_id'=>'pay-1',
        'source_document_type'=>'legacy_store_order','source_order_id'=>'10',
        'amount_cents'=>'9000','eligible'=>true],
    ['fact_id'=>'p2','store_id'=>2,'order_id'=>'RCH:11','source_line_id'=>'pay-2',
        'source_document_type'=>'recharge','amount_cents'=>'3000','eligible'=>true],
    ['fact_id'=>'p3','store_id'=>3,'order_id'=>'SALE-3','source_line_id'=>'pay-3',
        'source_document_type'=>'sales_order','amount_cents'=>'1200','eligible'=>true],
];
$legacy=static fn():array=>[['store_id'=>2,'source_order_id'=>'10','expected_cents'=>9000]];
$result=(new Sidebar007OriginalCashReadServices($facts,$legacy))->read([2,3],
    ['start'=>'2026-10-01','end'=>'2026-10-30']);
if(!$result['complete']||$result['by_store'][2]['amount_cents']!==12000
    ||$result['by_store'][3]['amount_cents']!==1200)
    throw new RuntimeException('V3 原单现金与旧身份覆盖核验失败');
$missing=(new Sidebar007OriginalCashReadServices(static fn():array=>[], $legacy))->read([2],
    ['start'=>'2026-10-01','end'=>'2026-10-30']);
if($missing['complete']||$missing['by_store'][2]['amount_cents']!==null
    ||$missing['by_store'][2]['gaps'][0]['code']!=='ORIGINAL_CASH_LEGACY_PAYMENT_MISSING')
    throw new RuntimeException('旧订单缺 V3 原收款必须保留缺口');
$different=(new Sidebar007OriginalCashReadServices($facts,
    static fn():array=>[['store_id'=>2,'source_order_id'=>'10','expected_cents'=>9500]]))
    ->read([2,3],['start'=>'2026-10-01','end'=>'2026-10-30']);
if($different['by_store'][2]['amount_cents']!==null
    ||$different['by_store'][2]['v3_candidate_cents']!==12000
    ||$different['by_store'][2]['gaps'][0]['code']!=='ORIGINAL_CASH_LEGACY_AMOUNT_DIFF')
    throw new RuntimeException('旧 pay_price 与 V3 实收差异须显式标注');
$unknown=(new Sidebar007OriginalCashReadServices(static fn():array=>[[
    'fact_id'=>'u','store_id'=>2,'order_id'=>'SALE-U','source_document_type'=>'sales_order',
    'source_line_id'=>'pay-u','amount_cents'=>1000,'eligible'=>false]],static fn():array=>[]))
    ->read([2],['start'=>'2026-10-01','end'=>'2026-10-30']);
if($unknown['complete']||$unknown['by_store'][2]['amount_cents']!==null
    ||$unknown['by_store'][2]['gaps'][0]['code']!=='ORIGINAL_CASH_IDENTITY_UNVERIFIED')
    throw new RuntimeException('未认证支付方式或来源身份不能当作零现金');
$undated=(new Sidebar007OriginalCashReadServices(static fn():array=>[],
    static fn():array=>[['store_id'=>2,'source_order_id'=>'88','expected_cents'=>1000,
        'business_date_unresolved'=>true]]))->read([2],$day);
if($undated['complete']||$undated['by_store'][2]['gaps'][0]['code']
    !=='ORIGINAL_CASH_BUSINESS_DATE_UNRESOLVED')
    throw new RuntimeException('无头且无支付日的旧原单应给全店业务日缺口');
$duplicate=(new Sidebar007OriginalCashReadServices(static fn():array=>[
    ['fact_id'=>'a','store_id'=>2,'order_id'=>'SALE-D','collection_reference'=>'same',
        'source_document_type'=>'sales_order','amount_cents'=>1000,'eligible'=>true],
    ['fact_id'=>'b','store_id'=>2,'order_id'=>'SALE-D','collection_reference'=>'same',
        'source_document_type'=>'sales_order','amount_cents'=>1000,'eligible'=>true]],
    static fn():array=>[]))->read([2],$day);
if($duplicate['complete']||$duplicate['by_store'][2]['amount_cents']!==null
    ||$duplicate['by_store'][2]['gaps'][0]['code']!=='ORIGINAL_CASH_PAYMENT_DUPLICATE')
    throw new RuntimeException('同一冻结支付键的两种事实类型不能重复计算');

/** 拆查询须保留原 LEFT JOIN 的同店、原主键字符串、归档和重复头语义。 */
$migrated = new ReflectionMethod(Sidebar007OriginalCashReadServices::class,'migratedCandidateRows');
$migrated->setAccessible(true);
$unmigrated = new ReflectionMethod(Sidebar007OriginalCashReadServices::class,'unmigratedCandidateRows');
$unmigrated->setAccessible(true);
$old=['id'=>10,'store_id'=>2,'is_system_del'=>0,'pay_time'=>$paid,'pay_type'=>'cash',
    'pid'=>0,'cash_choose'=>0,'pay_price'=>'90'];
$header=['id'=>71,'store_id'=>2,'source_document_id'=>'10','business_date'=>'2026-10-30',
    'order_status'=>'settled','order_direction'=>'forward'];
$selected=$migrated->invoke(null,[$header,array_replace($header,['id'=>72]),
    array_replace($header,['source_document_id'=>'010']),
    array_replace($header,['store_id'=>3])],[10=>$old]);
if(count($selected)!==2||$selected[0]['migrated_header_id']!==71
    ||$selected[1]['migrated_header_id']!==72)
    throw new RuntimeException('拆查询丢失重复头或混入跨店、非精确原ID');
$archived=$old; $archived['is_system_del']=1;
if(count($migrated->invoke(null,[$header],[10=>$archived]))!==1)
    throw new RuntimeException('已迁有效归档单被错误排除');
$void=$header; $void['order_direction']='reversal';
if($migrated->invoke(null,[$void],[10=>$archived])!==[])
    throw new RuntimeException('归档作废单被错误纳入');
$other=$old; $other['id']=11;
if(count($unmigrated->invoke(null,[$old,$other],['2|10'=>true]))!==1)
    throw new RuntimeException('已有跨月V3头的旧单不能回落到旧支付日');
try {
    (new Sidebar007OriginalCashReadServices(static fn():array=>[],static fn():array=>[
        ['store_id'=>2,'source_order_id'=>'10','expected_cents'=>9000],
        ['store_id'=>2,'source_order_id'=>'10','expected_cents'=>9000],
    ]))->read([2],$day);
    throw new RuntimeException('重复原单头未拒绝');
} catch (LogicException $expected) {
    if ($expected->getMessage() !== '旧原单身份缺失或重复') throw $expected;
}
echo "sidebar-007-original-cash-read-contract PASS\n";

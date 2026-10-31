<?php
declare(strict_types=1);

require __DIR__ . '/../../../后端代码/vendor/autoload.php';

use app\services\report\Sidebar007ServiceOrderReadServices;

function checkOrder(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$range=['start'=>'2026-10-01','end'=>'2026-10-30'];
$facts=[
    ['service_fact_id'=>'W1','source_document_type'=>'legacy_writeoff','source_document_id'=>'91',
        'store_id'=>8,'business_date'=>'2026-10-10','amount_cents'=>1000,'member_id'=>5,
        'service_object'=>'friend','document_id'=>'VW1','legacy_origin_order_id'=>501,'quantity'=>'2'],
    ['service_fact_id'=>'D1','source_document_type'=>'direct','source_document_id'=>'0',
        'store_id'=>7,'business_date'=>'2026-10-10','amount_cents'=>500,'member_id'=>6,
        'service_object'=>'self','document_id'=>'VD1','quantity'=>'1.5'],
    ['service_fact_id'=>'D2','source_document_type'=>'direct','source_document_id'=>'0',
        'store_id'=>7,'business_date'=>'2026-10-10','amount_cents'=>600,'member_id'=>6,
        'service_object'=>'self','document_id'=>'VD2','quantity'=>'1'],
];
$services=static fn():array=>['complete'=>true,'gaps'=>[],'rows'=>$facts];
$legacy=static fn():array=>[
    ['id'=>101,'store_id'=>7,'uid'=>5,'link_id'=>91,'link_order'=>501,'service_object'=>'朋友','pay_price'=>'10.00','aftercare_cash_eligible'=>true],
    ['id'=>102,'store_id'=>7,'uid'=>5,'link_id'=>91,'link_order'=>501,'service_object'=>'朋友','pay_price'=>'10.00','aftercare_cash_eligible'=>false],
];
$cross=static fn():int=>0;
$result=(new Sidebar007ServiceOrderReadServices($services,$legacy,$cross))->read([7,8],$range);
checkOrder($result['complete']===true&&$result['by_store'][7]['consumption_amount_cents']===3100
    &&$result['by_store'][7]['service_customer_count']===3
    &&$result['by_store'][8]['consumption_amount_cents']===0
    &&$result['by_store'][8]['consumed_project_count']===2
    &&$result['by_store'][7]['consumed_project_count']===2,
    '跨店归属按服务子单店，重复 link 两笔均计；会员同日去重');
checkOrder(count($result['qualified_entries'])===4
    &&$result['qualified_entries'][2]['aftercare_cash_eligible']===true
    &&$result['qualified_entries'][3]['aftercare_cash_eligible']===false,
    '售后客保留每张原服务子单身份及旧卡现金资格');
$blocked=(new Sidebar007ServiceOrderReadServices($services,$legacy,static fn():int=>1))->read([7,8],$range);
checkOrder(!$blocked['complete']&&$blocked['by_store'][7]['consumption_amount_cents']===null,
    '未授权执行店的跨店子单不能静默漏计');
$amountDiff=static fn():array=>[['id'=>101,'store_id'=>7,'uid'=>5,'link_id'=>91,'link_order'=>501,
    'service_object'=>'朋友','pay_price'=>'11.00']];
$diff=(new Sidebar007ServiceOrderReadServices($services,$amountDiff,$cross))->read([7,8],$range);
checkOrder(!$diff['complete']&&$diff['gaps'][0]['code']==='LEGACY_SERVICE_ORDER_IDENTITY_OR_AMOUNT_DIFF',
    '旧金额只作 V3 同身份来源核对，差异须缺口');
$wrongOrigin=static fn():array=>[['id'=>101,'store_id'=>7,'uid'=>5,'link_id'=>91,
    'link_order'=>502,'service_object'=>'朋友','pay_price'=>'10.00']];
$wrong=(new Sidebar007ServiceOrderReadServices($services,$wrongOrigin,$cross))->read([7,8],$range);
checkOrder(!$wrong['complete']&&$wrong['gaps'][0]['code']==='LEGACY_SERVICE_ORDER_IDENTITY_OR_AMOUNT_DIFF',
    '旧服务子单须精确归属同一原购买单');
$missing=(new Sidebar007ServiceOrderReadServices($services,static fn():array=>[],$cross))->read([7,8],$range);
checkOrder(!$missing['complete']&&$missing['gaps'][0]['code']==='LEGACY_SERVICE_ORDER_QUALIFICATION_UNVERIFIED',
    '有旧核销事实却无可核有效子单不能被当成零笔');
$incomplete=static fn():array=>['complete'=>false,'gaps'=>[['code'=>'SOURCE_GAP']],'rows'=>[]];
$gap=(new Sidebar007ServiceOrderReadServices($incomplete,$legacy,$cross))->read([7,8],$range);
checkOrder(!$gap['complete']&&$gap['by_store'][8]['service_customer_count']===null,
    '逐服务金额不完整时所有指标回收');

// A native completed reservation counts once by formal service document and
// participates in the same member/day deduplication as direct completed service.
$bookingFacts=$facts;$bookingFacts[1]['source_document_type']='reservation';
$bookingFacts[1]['source_document_id']='10';
$bookingReader=static fn():array=>['complete'=>true,'gaps'=>[],'rows'=>$bookingFacts];
$booking=(new Sidebar007ServiceOrderReadServices($bookingReader,$legacy,$cross))->read([7,8],$range);
checkOrder($booking['complete']&&$booking['by_store']===$result['by_store'],
    '预约已完成服务须与直接核销同金额同去重，不因来源入口漏计');
// Only the explicitly certified historical adapter uses original writeoff price.
$historic=$facts;$historic[0]['source_kind']='historical_007_card_service';
$histReader=static fn():array=>['complete'=>true,'gaps'=>[],'rows'=>$historic];
$h=(new Sidebar007ServiceOrderReadServices($histReader,$amountDiff,$cross))->read([7,8],$range);
checkOrder($h['complete']&&$h['by_store'][7]['consumption_amount_cents']===2100
    &&count($h['historical_services'])===1,'已批准旧核销金额不被旧子单不同精度的pay_price覆盖');
$h=(new Sidebar007ServiceOrderReadServices($histReader,$wrongOrigin,$cross))->read([7,8],$range);
checkOrder(!$h['complete'],'历史金额口径豁免不能绕过原单/客户身份验证');
echo "sidebar-007-service-order-contract PASS\n";

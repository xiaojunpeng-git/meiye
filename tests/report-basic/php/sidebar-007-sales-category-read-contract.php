<?php
declare(strict_types=1);

require __DIR__ . '/../../../后端代码/vendor/autoload.php';

use app\services\report\Sidebar007SalesCategoryReadServices;

function checkCategory(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$mapping=['complete'=>true,'by_v3_id'=>[18=>1,19=>2,20=>3,23=>6,24=>11]];
$period=['start'=>'2026-10-01','end'=>'2026-10-30'];
$amounts=['A'=>20000,'B'=>10000,'C'=>30000,'D'=>5000,'E'=>30000,'F'=>10000,'G'=>40000,'I'=>20000];
$cash=static fn():array=>['by_store'=>[
    7=>['complete'=>true],8=>['complete'=>true]],
    'by_order'=>[7=>$amounts,8=>['H'=>20000]]];
$orders=static function():array {
    $rows=[
    ['store_id'=>7,'order_id'=>'A','source_id'=>20,'business_date'=>'2026-10-10','member_id'=>1,'friend'=>false],
    ['store_id'=>7,'order_id'=>'B','source_id'=>20,'business_date'=>'2026-10-10','member_id'=>1,'friend'=>false],
    ['store_id'=>7,'order_id'=>'C','source_id'=>20,'business_date'=>'2026-10-10','member_id'=>2,'friend'=>false],
    ['store_id'=>7,'order_id'=>'D','source_id'=>18,'business_date'=>'2026-10-10','member_id'=>0,'friend'=>false],
    ['store_id'=>7,'order_id'=>'E','source_id'=>18,'business_date'=>'2026-10-10','member_id'=>3,'friend'=>false],
    ['store_id'=>7,'order_id'=>'F','source_id'=>23,'business_date'=>'2026-10-10','member_id'=>3,'friend'=>false],
    ['store_id'=>7,'order_id'=>'G','source_id'=>20,'business_date'=>'2026-10-10','member_id'=>4,'friend'=>false],
    ['store_id'=>7,'order_id'=>'I','source_id'=>24,'business_date'=>'2026-10-10','member_id'=>6,'friend'=>false],
    ['store_id'=>8,'order_id'=>'H','source_id'=>1,'business_date'=>'2026-10-10','member_id'=>5,'friend'=>false],
    ];
    foreach($rows as &$row)$row['cash_identity_verified']=true;
    unset($row);
    return $rows;
};
$partner=static fn():array=>[['store_id'=>7,'order_id'=>'G']];
$result=(new Sidebar007SalesCategoryReadServices($cash,$orders,$partner))->read([7,8],$period,$mapping);
$sales=$result['by_store'][7]['sales_data'];
checkCategory($sales[0]===1&&$sales[1]===30000&&$sales[2]===1&&$sales[3]===30000,
    '默认来源按会员同日去重并按 298 元分客类');
checkCategory($sales[4]===1&&$sales[5]===5000&&$sales[6]===1&&$sales[7]===30000,
    '大众来源的客数与原单实收');
checkCategory($sales[12]===1&&$sales[13]===10000,
    '续卡只取已认证有效原单');
checkCategory($sales[16]===1,'合作方客数只取确有 partner 实配的成交原单');
checkCategory($sales[15]===20000
    &&$result['after_sales_entries'][7][0]['order_id']==='sale:I',
    '售后现金业绩只取来源11有效会员原销售，客数保留原单身份');
checkCategory($result['by_store'][7]['sales_analysis']['new_customer_count']===2
    &&$result['by_store'][7]['sales_analysis']['casual_customer_count']===2,
    '销售分析跨来源先合并后按会员同日去重');
checkCategory(!isset($result['by_store'][8])&&!$result['complete']
    &&$result['gaps'][0]['code']==='SOURCE_007_ORDER_IDENTITY_UNVERIFIED',
    '旧字典同号不能冒充已迁入的 007 客源');

$extra=static function()use($orders):array {
    $rows=$orders();
    $rows[]=['store_id'=>7,'order_id'=>'ZERO','cash_identity_verified'=>false];
    return $rows;
};
$withZero=(new Sidebar007SalesCategoryReadServices($cash,$extra,$partner))->read([7,8],$period,$mapping);
checkCategory(!isset($withZero['by_store'][7]),'无外部现金的结算订单不能从客数中静默漏掉');
$certifiedZero=static function()use($orders):array {
    $rows=$orders();
    $rows[]=['store_id'=>7,'order_id'=>'ZERO','source_id'=>20,
        'business_date'=>'2026-10-10','member_id'=>5,'friend'=>false,
        'cash_identity_verified'=>true,'zero_cash_certified'=>true];
    return $rows;
};
$withCertifiedZero=(new Sidebar007SalesCategoryReadServices($cash,$certifiedZero,$partner))->read([7,8],$period,$mapping);
checkCategory($withCertifiedZero['by_store'][7]['sales_data'][0]===2
    &&$withCertifiedZero['by_store'][7]['sales_data'][1]===30000,
    '已证明纯余额或全额优惠的零现金销售须计客、不增现金');

$oldNoSource=static function()use($orders):array{
    $rows=$orders();$rows[]=['store_id'=>7,'order_id'=>'OLD-NO-SOURCE','source_id'=>0,
        'legacy_source_id'=>0,'cash_identity_verified'=>false];return $rows;
};
$excluded=(new Sidebar007SalesCategoryReadServices($cash,$oldNoSource,$partner))->read([7,8],$period,$mapping);
checkCategory($excluded['by_store'][7]===$result['by_store'][7],
    '007旧source0不属于普通来源人口，不因此要求零现金证明或改变独立现金总额');
$historicCash=$cash();$historicCash['by_order'][7]['historical:order:10']=10000;
$historicCash['historical_orders']=[['source_kind'=>'historical_007_original_receipt','store_id'=>7,
    'order_id'=>'historical:order:10','business_date'=>'2026-10-10','member_id'=>9,'friend'=>false,
    'legacy_source_id'=>6,'cash_identity_verified'=>true]];
$historic=(new Sidebar007SalesCategoryReadServices(static fn():array=>$historicCash,$orders,$partner))->read([7,8],$period,$mapping);
checkCategory($historic['by_store'][7]['sales_data'][12]===2&&$historic['by_store'][7]['sales_data'][13]===20000,
    '原充值通过已执行字典映射计续卡，不伪造V3销售头');
$oldCard=static function()use($orders):array{
    $rows=$orders();$rows[]=['store_id'=>7,'order_id'=>'OLD-CARD','source_id'=>25,'legacy_source_id'=>7,
        'old_card_entry_excluded'=>true,'cash_identity_verified'=>false];return $rows;
};
$excluded=(new Sidebar007SalesCategoryReadServices($cash,$oldCard,$partner))->read([7,8],$period,$mapping);
checkCategory($excluded['by_store'][7]===$result['by_store'][7],'旧卡录入资格排除，不冒充缺现金成交');
echo "sidebar-007-sales-category-read-contract PASS\n";

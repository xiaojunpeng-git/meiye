<?php

declare(strict_types=1);

/** 独立 Reader 的业务合同：销售数量取原行，现金组件守恒，缺身份不伪造。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\StoreItemSales007ReadServices;

function checkStoreItem(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rejectsStoreItem(callable $call, string $message): void
{
    try { $call(); } catch (InvalidArgumentException|LogicException $e) { return; }
    throw new RuntimeException($message);
}

$reader = new StoreItemSales007ReadServices();
$fieldsMethod = new ReflectionMethod($reader, 'fields'); $fieldsMethod->setAccessible(true);
$resultMethod = new ReflectionMethod($reader, 'result'); $resultMethod->setAccessible(true);
$rangeMethod = new ReflectionMethod($reader, 'range'); $rangeMethod->setAccessible(true);
$storesMethod = new ReflectionMethod($reader, 'stores'); $storesMethod->setAccessible(true);
$fields = $fieldsMethod->invoke($reader);
$range = ['start'=>'2026-10-01','end'=>'2026-10-30'];
$sales = [
    ['fact_id'=>'s-card','store_id'=>9,'order_id'=>'order-a','store_name'=>'甲店','item_id'=>101,'item_name'=>'组合卡','quantity'=>'2.00'],
    ['fact_id'=>'s-project','store_id'=>9,'order_id'=>'order-a','store_name'=>'甲店','item_id'=>101,'item_name'=>'组合卡','quantity'=>'0.50'],
    ['fact_id'=>'s-same-name','store_id'=>9,'order_id'=>'order-a','store_name'=>'甲店','item_id'=>102,'item_name'=>'组合卡','quantity'=>'1.00'],
];
$cash = ['payments'=>[
    ['fact_id'=>'pay-a','store_id'=>9,'order_id'=>'order-a','amount_cents'=>400],
    ['fact_id'=>'pay-b','store_id'=>9,'order_id'=>'order-a','amount_cents'=>200],
], 'allocations'=>[
    // 两种原结账收款可分摊同一销售行；全零分摊也有正式事实。
    ['payment_fact_id'=>'pay-a','sale_fact_id'=>'s-card','store_id'=>9,'amount_cents'=>301],
    ['payment_fact_id'=>'pay-b','sale_fact_id'=>'s-card','store_id'=>9,'amount_cents'=>200],
    ['payment_fact_id'=>'pay-a','sale_fact_id'=>'s-project','store_id'=>9,'amount_cents'=>99],
    ['payment_fact_id'=>'pay-b','sale_fact_id'=>'s-project','store_id'=>9,'amount_cents'=>0],
    ['payment_fact_id'=>'pay-a','sale_fact_id'=>'s-same-name','store_id'=>9,'amount_cents'=>0],
    ['payment_fact_id'=>'pay-b','sale_fact_id'=>'s-same-name','store_id'=>9,'amount_cents'=>0],
]];
$categories = [0=>['id'=>7,'path'=>'项目/养护'],1=>['id'=>7,'path'=>'项目/养护'],2=>['id'=>7,'path'=>'项目/养护']];
$result = $resultMethod->invoke($reader, $sales, $fields, [9], $range, $cash, $categories);
checkStoreItem($result['complete'] && count($result['rows']) === 2, '同一品项归组且异品项不按同名合并');
$byItem = array_column($result['rows'], null, 'item_id');
checkStoreItem($byItem[101]['sales_quantity'] === 2.5 && $byItem[101]['sales_performance'] === 6,
    '原销售行数量只算一次，组件收款以分守恒');
checkStoreItem($byItem[102]['sales_quantity'] === 1.0 && $byItem[102]['sales_performance'] === 0,
    '正式零分收款分摊与缺失事实区分');
checkStoreItem(array_column($fields,'label') === ['门店','分类','名称','销售数量','销售业绩','store_id','category_id','item_id'],
    '007 可见五列及顺序');
checkStoreItem($result['sum_fields'] === ['sales_quantity','sales_performance'], '合计只含可加总指标');
checkStoreItem(count($result['field_sources']) === count($fields), '所有字段都有业务来源说明');
checkStoreItem($result['rows'] === $resultMethod->invoke($reader,$sales,$fields,[9],$range,$cash,$categories)['rows'],
    '同一事实集合的稳定行键和排序');
$coverageMethod=new ReflectionMethod($reader,'failClosed');$coverageMethod->setAccessible(true);
$blocked=$coverageMethod->invoke($reader,$fields,[9],$range,['complete'=>false,'gaps'=>[
    ['code'=>'STORE_ITEM_007_SOURCE_COVERAGE_UNVERIFIED']]]);
checkStoreItem(!$blocked['complete']&&$blocked['rows']===[]&&$blocked['sum_fields']===[]
    &&$blocked['gaps'][0]['code']==='STORE_ITEM_007_SOURCE_COVERAGE_UNVERIFIED',
    '旧销售人口未认证时不展示局部行与合计');
$legacySale=$sales[0];$legacySale['source_document_type']='legacy_store_order';
$legacyPayment=$cash['payments'][0];$legacyPayment['source_document_type']='legacy_store_order';
$legacyResult=$resultMethod->invoke($reader,[$legacySale],$fields,[9],$range,
    ['payments'=>[$legacyPayment],'allocations'=>[$cash['allocations'][0]]],[0=>$categories[0]]);
checkStoreItem($legacyResult['complete']&&count($legacyResult['rows'])===1,
    '迁入007原单只匹配同来源的原收款分摊');

$unresolved = $resultMethod->invoke($reader, [
    ['fact_id'=>'unknown-a','store_id'=>9,'order_id'=>'order-b','store_name'=>'甲店','item_id'=>0,'item_name'=>'','quantity'=>1],
    ['fact_id'=>'unknown-b','store_id'=>9,'order_id'=>'order-b','store_name'=>'甲店','item_id'=>0,'item_name'=>'','quantity'=>2],
], $fields, [9], $range, ['payments'=>[
    ['fact_id'=>'pay-c','store_id'=>9,'order_id'=>'order-b','amount_cents'=>100],
], 'allocations'=>[
    ['payment_fact_id'=>'pay-c','sale_fact_id'=>'unknown-a','store_id'=>9,'amount_cents'=>50],
    ['payment_fact_id'=>'pay-c','sale_fact_id'=>'unknown-b','store_id'=>9,'amount_cents'=>50],
]], []);
checkStoreItem(!$unresolved['complete'] && count($unresolved['rows']) === 2 && count($unresolved['gaps']) === 2,
    '身份缺失保留原销售事实，并且不把未知品项错误合并');
rejectsStoreItem(static fn() => $resultMethod->invoke($reader, $sales, $fields, [10], $range, $cash, $categories),
    '越权门店事实必须拒绝');
rejectsStoreItem(static fn() => $resultMethod->invoke($reader, $sales, $fields, [9], $range,
    ['payments'=>$cash['payments'],'allocations'=>[['payment_fact_id'=>'pay-a','sale_fact_id'=>'s-card','store_id'=>10,'amount_cents'=>1]]], $categories), '跨门店收款必须拒绝');
rejectsStoreItem(static fn() => $resultMethod->invoke($reader, $sales, $fields, [9], $range,
    ['payments'=>$cash['payments'],'allocations'=>[['payment_fact_id'=>'pay-a','sale_fact_id'=>'s-card','store_id'=>9,'amount_cents'=>-1]]], $categories), '负向收款不得混进正向销售业绩');
$missing = $cash;
array_pop($missing['allocations']);
$incomplete = $resultMethod->invoke($reader, $sales, $fields, [9], $range, $missing, $categories);
checkStoreItem(!$incomplete['complete'] && $incomplete['rows'][array_search(102,array_column($incomplete['rows'],'item_id'),true)]['sales_performance'] === null
    && !in_array('sales_performance',$incomplete['sum_fields'],true), '缺少原收款分摊时金额为null且不发布合计');
$balanceSales = array_map(static function (array $sale): array {
    $sale['sale_amount_cents'] = 1000; $sale['debt_amount_cents'] = 0;
    return $sale;
}, $sales);
$balanceOnly = $resultMethod->invoke($reader, $balanceSales, $fields, [9], $range,
    ['payments'=>[],'allocations'=>[],'balances'=>['s-card'=>1000,'s-project'=>1000,'s-same-name'=>1000]], $categories);
checkStoreItem(count($balanceOnly['rows'])===2 && $balanceOnly['complete']
    && array_sum(array_column($balanceOnly['rows'],'sales_quantity'))===3.5
    && array_sum(array_column($balanceOnly['rows'],'sales_performance'))==0.0,
    '007 合格非旧卡原单保留零现金销量');
$unknownPayment = $resultMethod->invoke($reader, $balanceSales, $fields, [9], $range,
    ['payments'=>[],'allocations'=>[],'balances'=>[]], $categories);
checkStoreItem(count($unknownPayment['rows'])===2 && !$unknownPayment['complete']
    && $unknownPayment['rows'][0]['sales_performance']===null
    && !in_array('sales_performance',$unknownPayment['sum_fields'],true),
    '缺原结账收款且余额/欠款无法覆盖销售额时必须报来源缺口');
rejectsStoreItem(static fn() => $reader->read([9], $range, [11]), '本人参与范围没有专用过滤时必须拒绝');
rejectsStoreItem(static fn() => $rangeMethod->invoke($reader, ['start'=>'2026-02-30','end'=>'2026-10-30']),
    '非法日期必须拒绝');
rejectsStoreItem(static fn() => $storesMethod->invoke($reader, [0]), '非法授权门店必须拒绝');

echo "store-item-sales-007-read: PASS\n";

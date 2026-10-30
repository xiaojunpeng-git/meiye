<?php

declare(strict_types=1);

/** 五张 007 可见列的静态合同：无需连接数据库即可向统一报表 Host 声明。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\CrossStoreSettlement007ReadServices;
use app\services\report\StoreItemSales007ReadServices;
use app\services\report\ItemConsumption007ReadServices;
use app\services\report\WriteoffDetail007ReadServices;
use app\services\report\ProductSales007ReadServices;

$expected=[
    CrossStoreSettlement007ReadServices::class=>['门店','储值对账金额','次卡对账金额','合计'],
    StoreItemSales007ReadServices::class=>['门店','分类','名称','销售数量','销售业绩'],
    ItemConsumption007ReadServices::class=>['门店','分类','名称','消耗金额','消耗数量'],
    WriteoffDetail007ReadServices::class=>['ID','订单号','客户名称','手机号','商品分类','商品名称',
        '手艺人','核销数量','核销金额','下单门店','核销门店','核销人员','核销时间'],
    ProductSales007ReadServices::class=>['日期','门店','客户名称','商品名称','支付方式','金额','销售人'],
];
foreach($expected as $class=>$labels){
    $fields=$class::definitions();
    $visible=array_values(array_map(static fn(array $field):string=>$field['label'],
        array_filter($fields,static fn(array $field):bool=>$field['defaultVisible']===true)));
    if($visible!==$labels||$fields!==$class::fields())
        throw new RuntimeException('007 static fields drift: '.$class);
}
echo "store-five-007-definitions: PASS\n";

<?php

declare(strict_types=1);

/** 007 原购物车主键与 V3 导入行键逐行匹配，旧时间不作业务日期。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\StoreItemSales007SourceCoverageServices;

$range=['start'=>'2026-10-01','end'=>'2026-10-30'];
$line=['store_id'=>9,'old_order_id'=>52,'old_cart_detail_id'=>71];
$fact=['store_id'=>9,'order_id'=>'IMP007-52-sale',
    'source_line_id'=>'IMP007-52-line-71','source_document_type'=>'legacy_store_order',
    'fact_id'=>'IMP007-52-line-71-fact'];
$reader=new StoreItemSales007SourceCoverageServices(static fn(array $stores,array $days):array=>[
    'verified'=>true,'lines'=>[$line],'unknown_date_candidate'=>false]);
$ok=$reader->read([9],$range,[$fact]);
if(!$ok['complete']||$ok['matched_lines']!==1||$ok['eligible_legacy_fact_ids']!==[$fact['fact_id']])
    throw new RuntimeException('old cart primary keys should map to one V3 sale');
$miss=$reader->read([9],$range,[]);
if($miss['complete']||$miss['gaps'][0]['code']!=='STORE_ITEM_007_SALE_FACT_MISSING')
    throw new RuntimeException('missing old cart must not become zero sale');
$unknown=(new StoreItemSales007SourceCoverageServices(static fn(array $stores,array $days):array=>[
    'verified'=>true,'lines'=>[],'unknown_date_candidate'=>true]))->read([9],$range,[]);
if($unknown['complete']||$unknown['gaps'][0]['code']!=='STORE_ITEM_007_BUSINESS_DATE_UNRESOLVED')
    throw new RuntimeException('unknown V3 business day cannot use old add_time');
$duplicate=$reader->read([9],$range,[$fact,$fact]);
if($duplicate['complete']||$duplicate['gaps'][0]['code']!=='STORE_ITEM_007_SALE_FACT_AMBIGUOUS')
    throw new RuntimeException('duplicate V3 source line must stop result');
try{$reader->read([10],$range,[$fact]);throw new RuntimeException('unauthorized source accepted');}
catch(LogicException $expected){}
echo "store-item-sales-007-source-coverage: PASS\n";

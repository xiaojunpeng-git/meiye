<?php

declare(strict_types=1);

/** 旧007有效产品行必须一对一出现在 V3；空 V3 不能冒充原报表零销售。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\ProductSales007SourceCoverageServices;

$range=['start'=>'2026-10-01','end'=>'2026-10-30'];
$legacy=['store_id'=>9,'old_order_id'=>52,'old_cart_detail_id'=>71,'business_date'=>'2026-10-20'];
$source=static fn(array $stores,array $range):array=>['verified'=>true,'lines'=>[$legacy]];
$audit=new ProductSales007SourceCoverageServices($source);
$missing=$audit->read([9],$range,[]);
if($missing['complete']||$missing['legacy_eligible_lines']!==1||$missing['matched_lines']!==0
    ||!in_array('LEGACY_PRODUCT_SALE_FACT_MISSING',array_column($missing['gaps'],'code'),true))
    throw new RuntimeException('legacy product candidate cannot become a complete zero-row report');
$sale=['fact_id'=>'sale-1','store_id'=>9,'order_id'=>'IMP007-52-sale',
    'source_line_id'=>'IMP007-52-line-71','source_document_type'=>'legacy_store_order'];
$matched=$audit->read([9],$range,[$sale]);
if(!$matched['complete']||$matched['matched_lines']!==1||$matched['legacy_eligible_lines']!==1
    ||$matched['eligible_legacy_fact_ids']!==['sale-1'])
    throw new RuntimeException('exact old-cart V3 match');
$wrongType=$sale;$wrongType['source_document_type']='cashier_snapshot';
if($audit->read([9],$range,[$wrongType])['complete'])
    throw new RuntimeException('new cashier fact cannot cover old 007 cart');
$duplicate=$sale;$duplicate['fact_id']='sale-2';
$ambiguous=$audit->read([9],$range,[$sale,$duplicate]);
if($ambiguous['complete']||!in_array('LEGACY_PRODUCT_SALE_FACT_AMBIGUOUS',
    array_column($ambiguous['gaps'],'code'),true))
    throw new RuntimeException('duplicate V3 migration must fail closed');
$unavailable=(new ProductSales007SourceCoverageServices(static fn(array $stores,array $range):array=>[
    'verified'=>false,'gap'=>['code'=>'LEGACY_PRODUCT_SOURCE_UNAVAILABLE','message'=>'unavailable']]))
    ->read([9],$range,[]);
if($unavailable['complete']||$unavailable['legacy_eligible_lines']!==null)
    throw new RuntimeException('unreadable old source must not return complete zero');
$withoutBusinessDate=$legacy;unset($withoutBusinessDate['business_date']);
$dateGap=(new ProductSales007SourceCoverageServices(static fn(array $stores,array $range):array=>[
    'verified'=>true,'lines'=>[$withoutBusinessDate]]))->read([9],$range,[]);
if($dateGap['complete']||$dateGap['date_unresolved_lines']!==1
    ||!in_array('LEGACY_PRODUCT_BUSINESS_DATE_UNRESOLVED',array_column($dateGap['gaps'],'code'),true))
    throw new RuntimeException('legacy add_time cannot substitute for V3 business date');
$unknownProbe=(new ProductSales007SourceCoverageServices(static fn(array $stores,array $range):array=>[
    'verified'=>true,'lines'=>[],'unknown_date_candidate'=>true]))->read([9],$range,[]);
if($unknownProbe['complete']||$unknownProbe['legacy_eligible_lines']!==0
    ||$unknownProbe['date_unresolved_lines']!==1)
    throw new RuntimeException('bounded old-date probe must report unresolved source only');
echo "product-sales-007-source-coverage: PASS\n";

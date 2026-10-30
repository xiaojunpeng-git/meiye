<?php

declare(strict_types=1);

/** 显式旧核销 ID 映射缺失或重复时，消费与核销明细都拒绝局部展示。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\ItemConsumption007SourceCoverageServices;
use app\services\report\ItemConsumption007ReadServices;
use app\services\report\WriteoffDetail007ReadServices;

$range=['start'=>'2026-10-20','end'=>'2026-10-20'];
$sqlMethod=new ReflectionMethod(ItemConsumption007SourceCoverageServices::class,'coverageSql');
$sqlMethod->setAccessible(true);
$sql=$sqlMethod->invoke(new ItemConsumption007SourceCoverageServices(),
    'eb_store_order_writeoff','eb_cashier_v3_entitlement_service_fact','9');
if(strpos($sql,"('legacy_writeoff','legacy_paid_project')")===false
    ||strpos($sql,'w.type')!==false||strpos($sql,'GROUP BY s.source_document_id')===false
    ||strpos($sql,') m ON m.source_document_id=w.id')===false
    ||strpos($sql,'m.mapped_project_id<>w.product_id')===false
    ||strpos($sql,'m.mapped_member_id<>w.uid')===false)
    throw new RuntimeException('old type cannot hide paid-project facts with the same original writeoff ID');
$missing=(new ItemConsumption007SourceCoverageServices(static fn(array $stores,array $range):array=>[
    'verified'=>true,'count'=>3,'unmapped'=>1,'ambiguous'=>0,'mismatched'=>0]))->read([9],$range);
if($missing['complete']||$missing['legacy_candidates']!==3
    ||$missing['gaps'][0]['code']!=='LEGACY_WRITEOFF_V3_UNMAPPED')
    throw new RuntimeException('old completed writeoffs need stable V3 mapping');
$empty=(new ItemConsumption007SourceCoverageServices(static fn(array $stores,array $range):array=>[
    'verified'=>true,'count'=>0,'unmapped'=>0,'ambiguous'=>0,'mismatched'=>0]))->read([9],$range);
if(!$empty['complete']||$empty['legacy_candidates']!==0)
    throw new RuntimeException('zero old diagnostic rows');
$mapped=(new ItemConsumption007SourceCoverageServices(static fn(array $stores,array $range):array=>[
    'verified'=>true,'count'=>3,'unmapped'=>0,'ambiguous'=>0,'mismatched'=>0]))->read([9],$range);
if(!$mapped['complete']||$mapped['mapped_candidates']!==3)
    throw new RuntimeException('explicit unique ID mapping allows V3 business-date result');
$ambiguous=(new ItemConsumption007SourceCoverageServices(static fn(array $stores,array $range):array=>[
    'verified'=>true,'count'=>3,'unmapped'=>0,'ambiguous'=>1,'mismatched'=>0]))->read([9],$range);
if($ambiguous['complete']||$ambiguous['gaps'][0]['code']!=='LEGACY_WRITEOFF_V3_AMBIGUOUS')
    throw new RuntimeException('duplicate source identity must stop result');
$mismatched=(new ItemConsumption007SourceCoverageServices(static fn(array $stores,array $range):array=>[
    'verified'=>true,'count'=>3,'unmapped'=>0,'ambiguous'=>0,'mismatched'=>1]))->read([9],$range);
if($mismatched['complete']||$mismatched['mapped_candidates']!==2
    ||$mismatched['gaps'][0]['code']!=='LEGACY_WRITEOFF_V3_IDENTITY_MISMATCH')
    throw new RuntimeException('source ID alone cannot certify store/project/member/status');
$unavailable=(new ItemConsumption007SourceCoverageServices(static fn(array $stores,array $range):array=>[
    'verified'=>false,'gap'=>['code'=>'LEGACY_WRITEOFF_SOURCE_UNAVAILABLE']]))->read([9],$range);
if($unavailable['complete']||$unavailable['legacy_candidates']!==null)
    throw new RuntimeException('unavailable legacy source must fail closed');
foreach([new ItemConsumption007ReadServices(),new WriteoffDetail007ReadServices()] as $reader){
    $method=new ReflectionMethod($reader,'failClosed'); $method->setAccessible(true);
    $blocked=$method->invoke($reader,[9],$range,$missing);
    if($blocked['complete']||$blocked['rows']!==[]||$blocked['sum_fields']!==[]
        ||$blocked['gaps'][0]['code']!=='LEGACY_WRITEOFF_V3_UNMAPPED')
        throw new RuntimeException('old writeoff gap must withhold all rows');
}
echo "item-consumption-007-source-coverage: PASS\n";

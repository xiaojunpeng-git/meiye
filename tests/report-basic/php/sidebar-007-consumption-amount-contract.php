<?php
declare(strict_types=1);

require __DIR__ . '/../../../后端代码/vendor/autoload.php';

use app\services\report\Sidebar007ConsumptionAmountReadServices;

function checkConsumption(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$range=['start'=>'2026-10-01','end'=>'2026-10-30'];
$reader=new Sidebar007ConsumptionAmountReadServices(static fn():array=>[
    'complete'=>true,'gaps'=>[],'rows'=>[
        ['store_id'=>7,'consumption_amount'=>11.25],
        ['store_id'=>7,'consumption_amount'=>3.5],
        ['store_id'=>8,'consumption_amount'=>0],
    ]]);
$result=$reader->read([7,8],$range);
checkConsumption($result['complete']===true&&$result['by_store'][7]===1475
    &&$result['by_store'][8]===0,'核准项目消耗须按门店精确汇总整数分');
$gap=(new Sidebar007ConsumptionAmountReadServices(static fn():array=>[
    'complete'=>false,'gaps'=>[['code'=>'LEGACY_WRITEOFF_SOURCE_UNVERIFIED']],
    'rows'=>[['store_id'=>7,'consumption_amount'=>100]],
]))->read([7,8],$range);
checkConsumption($gap['by_store'][7]===null&&$gap['by_store'][8]===null,
    '旧核销覆盖不全时不能展示局部金额或伪零');
try{
    (new Sidebar007ConsumptionAmountReadServices(static fn():array=>[
        'complete'=>true,'gaps'=>[],'rows'=>[['store_id'=>9,'consumption_amount'=>1]],
    ]))->read([7],$range);
    throw new RuntimeException('越权应拒绝');
}catch(LogicException $expected){}

echo "sidebar-007-consumption-amount-contract PASS\n";

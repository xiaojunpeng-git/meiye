<?php
declare(strict_types=1);

require __DIR__.'/../../../后端代码/vendor/autoload.php';

use app\services\report\Sidebar007DesignatedCustomerReadServices;

function checkPoint(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$scope=['start'=>'2026-10-01','end'=>'2026-10-30'];
$services=static fn():array=>['complete'=>true,'gaps'=>[]];
$coverage=static fn():array=>['complete'=>true,'gaps'=>[],'legacy_rows'=>2];
$metric=static fn():array=>['detail_context_by_labor_key'=>[
    'a'=>['store_id'=>7,'business_date'=>'2026-10-10','subject'=>'member:5','point'=>true],
    'b'=>['store_id'=>7,'business_date'=>'2026-10-10','subject'=>'member:5','point'=>true],
    'c'=>['store_id'=>7,'business_date'=>'2026-10-10','subject'=>'record:S1','point'=>true],
    'd'=>['store_id'=>7,'business_date'=>'2026-10-10','subject'=>'record:S1','point'=>true],
    'e'=>['store_id'=>7,'business_date'=>'2026-10-10','subject'=>'member:6','point'=>false],
    'f'=>['store_id'=>8,'business_date'=>'2026-10-10','subject'=>'member:7','point'=>true],
]];
$friendExtra=static fn():array=>['complete'=>true,'by_store'=>[7=>0,8=>0],'gaps'=>[]];
$reader=new Sidebar007DesignatedCustomerReadServices($services,$metric,$coverage,$friendExtra);
$result=$reader->read([7,8],$scope);
checkPoint($result['complete']&&$result['by_store']===[7=>3,8=>1],
    '会员同日合并多人点客，游客/朋友每条劳务记录完整计数');
$missing=new Sidebar007DesignatedCustomerReadServices($services,$metric,
    static fn():array=>['complete'=>false,'gaps'=>[['code'=>'DESIGNATED_POINT_LABOR_FACT_MISSING']]],
    $friendExtra);
$gap=$missing->read([7,8],$scope);
checkPoint(!$gap['complete']&&$gap['by_store'][7]===null,
    '旧点客劳务缺V3同人同服务事实不能报零');
$outside=new Sidebar007DesignatedCustomerReadServices($services,
    static fn():array=>['detail_context_by_labor_key'=>[
        'x'=>['store_id'=>9,'business_date'=>'2026-10-10','subject'=>'member:1','point'=>true]]],
    $coverage,$friendExtra);
try{$outside->read([7,8],$scope);throw new RuntimeException('指定客越权未拒绝');}
catch(LogicException $expected){}
$friendMismatch=(new Sidebar007DesignatedCustomerReadServices($services,$metric,$coverage,
    static fn():array=>['complete'=>true,'by_store'=>[7=>2,8=>0],'gaps'=>[]]))->read([7,8],$scope);
checkPoint($friendMismatch['complete']&&$friendMismatch['by_store'][7]===5,
    '被通用员工指标跳过的朋友服务须按有效点客劳务逐条补算');

// Historical personnel are mutually exclusive with migrated service facts but share
// member/day deduplication; friend records keep their separate original labor identity.
$historical=(new Sidebar007DesignatedCustomerReadServices($services,$metric,
    static fn():array=>['complete'=>true,'gaps'=>[],'historical_contexts'=>[
        ['store_id'=>7,'business_date'=>'2026-10-10','subject'=>'member:5','point'=>true],
        ['store_id'=>7,'business_date'=>'2026-10-10','subject'=>'member:8','point'=>true],
        ['store_id'=>7,'business_date'=>'2026-10-10','subject'=>'record:historical-labor:11','point'=>true],
    ]],$friendExtra))->read([7,8],$scope);
checkPoint($historical['complete']&&$historical['by_store'][7]===5,
    '历史指定会员与V3同日去重，原朋友劳务保留逐行计数');

echo "sidebar-007-designated-customer-contract PASS\n";

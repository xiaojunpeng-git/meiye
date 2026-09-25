<?php
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\contract\AiContractException;
use app\services\ai\contract\AiIntentUnderstandingContract;
use app\services\ai\context\MemberDetailContinuationResolver;
use app\services\ai\presentation\AiMemberDetailAnswerRenderer;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void {if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;};
$question=['schema_version'=>'sanitized-question-v2','question'=>'这个会员的权益明细给我看一下','has_unresolved_conditions'=>false,
    'server_resolved_fields'=>[],'reference_date'=>'2026-09-24','recent_questions'=>[],
    'evidence_messages'=>[['id'=>'current','text'=>'这个会员的权益明细给我看一下']],
    'prior_query'=>['operation'=>'condition_list']];
$understanding=AiIntentUnderstandingContract::normalize([
    'goal'=>'查看当前会员权益明细','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>'查看这个会员的权益明细','fields'=>['object_kind','object_relation','member_detail'],
        'values'=>['object_kind'=>'member','object_relation'=>'analysis',
            'member_detail'=>['view'=>'rights','target'=>'single','ordinal'=>null]],
        'evidence'=>[['message_id'=>'current','quote'=>'这个会员的权益明细给我看一下']],
    ]],
],$question);
$check(($understanding['requirements'][0]['values']['member_detail']['view']??null)==='rights',
    'the language boundary carries a typed member-rights continuation without mapping a phrase in PHP');

$query=['query_shape'=>'condition_list','condition_set'=>['subject'=>'member','relation'=>'all','conditions'=>[]]];
$view=['query'=>$query,'results'=>[['rows'=>[['member_id'=>101,'member_name'=>'测试会员','metrics'=>[]]]]]];
$resolved=(new MemberDetailContinuationResolver())->resolve($query,$view,['view'=>'rights','target'=>'single','ordinal'=>null]);
$check($resolved===['selection_ref'=>'member:101','label'=>'测试会员','view'=>'rights'],
    'a singleton verified member set resolves to its opaque reference');

$many=$view;$many['results'][0]['rows'][]=['member_id'=>102,'member_name'=>'另一会员','metrics'=>[]];
try {(new MemberDetailContinuationResolver())->resolve($query,$many,['view'=>'rights','target'=>'single','ordinal'=>null]);
    throw new RuntimeException('FAIL ambiguous current member accepted');
} catch (RuntimeException $error) {$check($error->getMessage()==='AI_MEMBER_DETAIL_SELECTION_REQUIRED','ambiguous current member asks for an ordinal without selecting anybody');}
$set=(new MemberDetailContinuationResolver())->resolve($query,$many,['view'=>'rights','target'=>'set','ordinal'=>null]);
$check(array_column($set['members'],'selection_ref')===['member:101','member:102'],
    'batch detail retains the complete verified population in display order');
$truncated=$many;$truncated['results'][0]['has_more']=true;$truncated['results'][0]['count']=101;
try {(new MemberDetailContinuationResolver())->resolve($query,$truncated,['view'=>'rights','target'=>'set','ordinal'=>null]);
    throw new RuntimeException('FAIL truncated detail set accepted');
} catch (RuntimeException $error) {$check($error->getMessage()==='AI_MEMBER_DETAIL_SET_NOT_READY','a truncated page must not be claimed as the complete member set');}
$second=(new MemberDetailContinuationResolver())->resolve($query,$many,['view'=>'summary','target'=>'single','ordinal'=>2]);
$check($second['selection_ref']==='member:102','an explicit ordinal stays inside the verified member result');

$rankingQuery=['query_shape'=>'ranking','business_filters'=>[]];
$rankingView=['query'=>$rankingQuery,'results'=>[['object_kind'=>'member','rows'=>[
    'top'=>[['entity_id'=>101,'entity_name'=>'测试会员','amount_cents'=>128860]],'bottom'=>[],
]]]];
$rankingMember=(new MemberDetailContinuationResolver())->resolve(
    $rankingQuery,$rankingView,['view'=>'rights','target'=>'single','ordinal'=>null]
);
$check($rankingMember['selection_ref']==='member:101',
    'a singleton member ranking can be continued as this member without a name lookup');
$rankingView['results'][0]['rows']['top'][]=[
    'entity_id'=>102,'entity_name'=>'另一会员','amount_cents'=>100000,
];
$rankingSet=(new MemberDetailContinuationResolver())->resolve(
    $rankingQuery,$rankingView,['view'=>'summary','target'=>'set','ordinal'=>null]
);
$check(array_column($rankingSet['members'],'selection_ref')===['member:101','member:102'],
    'a ranked member set can read details for those exact verified members in display order');

$detail=['projectionContractVersion'=>'cashier-v3-member-detail-v3','dataAsOf'=>'2026-09-24T20:00:00+08:00',
    'member'=>['memberId'=>101,'name'=>'测试会员'],'summary'=>[
        'accountBalance'=>'1288.60','principalBalance'=>'1000.00','giftBalance'=>'288.60',
        'activeCardCount'=>2,'remainingProjectTimes'=>6,'remainingProjectAmount'=>'1800.49',
    ],'cards'=>[[
        'cardName'=>'护理卡','statusLabel'=>'有效','remainingTimes'=>6,'remainingAmount'=>'1800.49','expiresAt'=>'2027-09-24',
    ]]];
$answer=(new AiMemberDetailAnswerRenderer())->render($detail,'测试会员','rights');
$check(($answer['presentation']['facts'][0]['value']??null)==='1,289'
    &&($answer['table']['rows'][0]['card']??null)==='护理卡'
    &&strpos(json_encode($answer,JSON_UNESCAPED_UNICODE),'memberId')===false,
    'the renderer applies integer-yuan display and excludes internal member identity');
$batch=(new AiMemberDetailAnswerRenderer())->renderSet([
    ['detail'=>$detail,'label'=>'测试会员'],['detail'=>$detail,'label'=>'测试会员'],
],'rights');
$check(count($batch['sections'])===2&&count($batch['sections'][1]['answer']['table']['rows'])===2
    &&$batch['sections'][0]['answer']['table']['rows'][0]['f0']==='1,289',
    'member batches reuse the two-section cross-terminal contract and exact money formatting');
$projection=app\services\ai\presentation\AiMemberRightsExportProjection::class;
$members=[['detail'=>$detail,'label'=>'测试会员','selection_ref'=>'member:101']];
$assets=$projection::capture($members,'rights');
$check(count($assets['rows'])===8&&$assets['rows'][0]['metric_value']==='1288.60'
    &&$assets['rows'][7]['metric_value']==='1800.49','asset export preserves cents instead of rounded screen values');
$check(strpos($assets['rows'][6]['metric_name'],'护理卡')!==false
    &&strpos($assets['rows'][6]['metric_name'],'2027-09-24')!==false,
    'rights export retains card name, status and expiry with its own values');
$check(count($projection::capture($members,'summary')['rows'])===6,'summary exports do not add unrequested card details');
$populationRow=array_replace($assets['rows'][0],['metric_name'=>'实际收款销售额','period_name'=>'本期',
    'start_date'=>'2026-08-26','end_date'=>'2026-09-24','metric_value'=>'15678.91']);
$combined=$projection::capture($members,'rights',[$populationRow]);
$check(count($combined['rows'])===9&&$combined['rows'][0]['metric_name']==='筛选依据：实际收款销售额'
    &&$combined['rows'][0]['metric_value']==='15678.91'&&$combined['rows'][1]['row_id']==='2',
    'combined answer exports exact selection evidence before the matching assets with stable row IDs');
$check(strpos(json_encode($assets['rows']),'member:')===false,'export cells exclude private member selection references');
$composite=app\services\query\metric\MetricReadViewExportProvider::withMemberRights(['result_hash'=>'context-hash'],$assets);
$check($composite['result_hash']!=='context-hash'
    &&app\services\query\metric\MetricReadViewExportProvider::project($composite)===$assets['rows'],
    'asset file hash and projection cannot be confused with the retained context ranking');
$tampered=$assets;$tampered['rows'][0]['metric_value']='0.00';
foreach ([null,$tampered] as $bad) {
    try {$projection::validate($bad);throw new LogicException('accepted missing or altered assets');}
    catch(RuntimeException $e){$check($e->getMessage()==='AI_EXPORT_SOURCE_MISMATCH','missing or altered immutable assets reject');}
}
try {$projection::capture(array_merge($members,$members),'rights');throw new LogicException('accepted duplicate member');}
catch(RuntimeException $e){$check($e->getMessage()==='AI_EXPORT_SOURCE_MISMATCH','duplicate members cannot double-count exported assets');}
$exportClass=new ReflectionClass(app\services\ai\execution\AiExportRuntime::class);
$exportSourceGuard=$exportClass->getMethod('assertMetricExportSource');
$exportWithoutDependencies=$exportClass->newInstanceWithoutConstructor();
$exportSourceGuard->invoke($exportWithoutDependencies,['workflow_code'=>'wf_performance_condition_list']);
$check(true,'ordinary registered member-list exports retain their source contract');
try {$exportSourceGuard->invoke($exportWithoutDependencies,['workflow_code'=>'wf_member_detail_read']);
    throw new LogicException('rights answer exported as filter list');
} catch (RuntimeException $e) {$check($e->getMessage()==='AI_EXPORT_SOURCE_MISMATCH',
    'rights answers cannot export their context query as if it were asset evidence');}

// Business boundaries must remain actionable at the UI and must not inflate
// technical-failure diagnostics merely because a user asks for several people.
$gatewayClass=new ReflectionClass(app\services\ai\AiGatewayServices::class);
$gateway=$gatewayClass->newInstanceWithoutConstructor();
$progress=$gatewayClass->getMethod('progressText');
$outcome=(new ReflectionClass(app\services\ai\execution\AiRunStore::class))->getMethod('outcomeClass');
foreach (['AI_MEMBER_DETAIL_SELECTION_REQUIRED','AI_MEMBER_DETAIL_SET_NOT_READY'] as $reason) {
    $run=['status'=>'FAILED','reason'=>$reason];
    $check(strpos($progress->invoke($gateway,$run),'第几位')!==false
        &&$outcome->invoke(null,$run)==='neutral','member boundary offers an ordinal and is not a technical outage');
}

echo "member-detail-continuation: {$checks} checks passed\n";

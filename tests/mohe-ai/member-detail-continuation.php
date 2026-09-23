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
} catch (RuntimeException $error) {$check($error->getMessage()==='AI_RESULT_REFERENCE_UNAVAILABLE','ambiguous current member fails closed');}
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

echo "member-detail-continuation: {$checks} checks passed\n";

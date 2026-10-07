<?php
// R63 customer-facing failure projection needs no business database or model.
require __DIR__.'/fixture-autoload.php';

$gateway=(new ReflectionClass(\app\services\ai\AiGatewayServices::class))->newInstanceWithoutConstructor();
$guidance=new ReflectionMethod($gateway,'failureGuidance');
$progress=new ReflectionMethod($gateway,'progressText');
foreach (['AI_MODEL_INTENT_CONTRACT_INVALID','AI_MODEL_RESULT_UNKNOWN','AI_INTENT_PARTIAL','AI_AUTHORIZATION_CHANGED','AI_UNSUPPORTED_CONDITION'] as $reason) {
    $card=$guidance->invoke($gateway,$reason);
    $actions=array_column($card['options'],'value');
    if ($actions!==['retry_original','edit_original','new_conversation']
        || ($card['supplement_label']??null)!=='我来补充描述'
        || $progress->invoke($gateway,['status'=>'FAILED','reason'=>$reason])!==$card['question']
        || preg_match('/AI_[A-Z_]+|请联系管理员|尚未查询数据/',$card['question'])) {
        throw new RuntimeException('R63 failure card contract: '.$reason);
    }
}
foreach (['AI_INTENT_UNRESOLVED','AI_CAPABILITY_CHANGED'] as $reason) {
    $help=$guidance->invoke($gateway,$reason);
    if (($help['kind']??null)!=='capability_help' || isset($help['options'])
        || strpos($help['question'],'今天的现金业绩')===false
        || $progress->invoke($gateway,['status'=>'FAILED','reason'=>$reason])!==$help['question']) {
        throw new RuntimeException('R63 capability help must not render a retry card: '.$reason);
    }
}
// Private-member discovery can legitimately find a nickname inside ordinary
// prose. Calendar spans must survive masking intact, while an exact private
// label colliding with a calendar word is still kept away from the model.
$projector=new \app\services\ai\model\AiSafeQuestionProjector();
$config=['enabled'=>true,'external_processing_authorized'=>true,'external_scope_supported'=>true,
    'external_scope_version'=>\app\services\ai\config\AiConfigStore::QUESTION_SCOPE];
foreach (['今天天气如何','昨天天气如何','明天天气如何'] as $question) {
    $dates=\app\services\ai\semantic\AiSemanticIntentParser::calendarEvidence($question)['matched_texts'];
    $safe=$projector->project($question,$config,['天天'],$dates);
    if ($safe['outbound']['question']!==$question || $safe['local_conditions']!==[]) {
        throw new RuntimeException('R63 public calendar span broken by private nickname');
    }
}
$namedMember='会员天天的权益';
$safe=$projector->project($namedMember,$config,['天天'],
    \app\services\ai\semantic\AiSemanticIntentParser::calendarEvidence($namedMember)['matched_texts']);
if (strpos($safe['outbound']['question'],'[local_condition_1]')===false
    || strpos($safe['outbound']['question'],'天天')!==false) {
    throw new RuntimeException('R63 actual member nickname must remain private');
}
$privateDate='会员今天的权益';
$dates=\app\services\ai\semantic\AiSemanticIntentParser::calendarEvidence($privateDate)['matched_texts'];
$safe=$projector->project($privateDate,$config,['今天'],array_values(array_diff($dates,['今天'])));
if (strpos($safe['outbound']['question'],'[local_condition_1]')===false
    || strpos($safe['outbound']['question'],'今天')!==false) {
    throw new RuntimeException('R63 exact private label leaked by calendar shielding');
}
echo "R63 failure guidance: PASS (recovery cards and plain capability help)\n";

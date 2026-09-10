<?php
/** Source-contract regression checks. No database, business facts or model call. */
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\config\AiConfigStore;
use app\services\ai\execution\AiSemanticObjectContractRegistry;
use app\services\ai\execution\AiDimensionGuidancePlanner;
use app\services\ai\execution\AiSkillGuidancePlanner;
use app\services\ai\model\AiSafeQuestionProjector;
use app\services\ai\registry\AiBusinessRegistry;
use app\services\ai\registry\AiSkillDocument;
use app\services\ai\execution\AiCapabilityGuidanceCatalog;
use app\services\query\metric\MetricReadViewServices;

$checks=0;
$check=static function(bool $condition,string $label)use(&$checks):void { if(!$condition)throw new RuntimeException('FAIL '.$label);++$checks; };
$skill=AiSkillDocument::storeOperations();
$projection=$skill['semantic_projection'];
$check($skill['version']===12&&count($projection['objects'])===9&&isset($projection['actions'],$projection['capability_groups'])&&!isset($projection['intents'],$projection['qualifier_words'],$projection['result_grammar']),'published store Skill exposes business meanings without phrase or result-shape grammar');
$check((new AiBusinessRegistry())->modelSkill('store_operations')['semantic_projection']===$projection,'registry carries the exact validated Skill vocabulary');
$skills=(new AiBusinessRegistry())->modelSkills('store_operations');
$check($skills['intent_understanding']['skill_code']==='skill_intent_understanding'&&$skills['business']['skill_code']==='skill_store_operations','model receives separate language and business Skills');
$person=array_values(array_filter($projection['objects'],static function(array $object):bool{return $object['code']==='person';}))[0];
$position=array_values(array_filter($projection['objects'],static function(array $object):bool{return $object['code']==='position';}))[0];
$check(!in_array('岗位',$person['aliases'],true)&&$position['contract_ref']==='metric_read_view_person_v1','position is independent from person aliases but reuses the registered personnel contract');
$config=['enabled'=>true,'external_processing_authorized'=>true,'external_scope_supported'=>true,'external_scope_version'=>AiConfigStore::QUESTION_SCOPE];
$safe=(new AiSafeQuestionProjector())->project('这个月卖得最好的项目',$config,[]);
$wire=json_encode($safe['outbound'],JSON_UNESCAPED_UNICODE);
$check(!$safe['outbound']['has_unresolved_conditions']&&$safe['outbound']['server_resolved_fields']===[]&&str_contains($wire,'卖得')&&str_contains($wire,'项目'),'published public words reach model intent input intact without inventing server-resolved fields');
$role=(new AiSafeQuestionProjector())->project('美容师岗的同事业绩最好',$config,['美容师岗']);
$check($role['outbound']['has_unresolved_conditions']&&strpos(json_encode($role['outbound'],JSON_UNESCAPED_UNICODE),'美容师岗')===false,'authorized position labels remain local before the model stage');
$unknown=(new AiSafeQuestionProjector())->project('哪个魔法项目卖得好',$config,[]);
$check(!$unknown['outbound']['has_unresolved_conditions']&&$unknown['outbound']['question']==='哪个魔法项目卖得好','unseen natural-language wording reaches the model intact instead of becoming a whitelist miss');
$planner=new AiSkillGuidancePlanner();
$missing=AiSemanticObjectContractRegistry::missingObjects($projection,[['kind'=>'object','code'=>'project','label'=>'项目','text'=>'项目']]);
$check($missing[0]['contract_ref']==='metric_dimension_project_v1'&&$missing[0]['missing_reason']==='AI_OBJECT_CONTRACT_NOT_READY','project remains unavailable without a runtime registered dimension contract');
$runtimeCapabilities=['metric_codes'=>array_keys(MetricReadViewServices::metricCapabilities()),'metric_readiness'=>MetricReadViewServices::metricCapabilities()];
$check((AiSemanticObjectContractRegistry::statuses($projection,$runtimeCapabilities)['project']['state']??null)==='registered','project becomes executable only after the registered metric dimension contract is present');
$projectCandidates=AiCapabilityGuidanceCatalog::discover($runtimeCapabilities,'project','ranking');
$check(array_keys($projectCandidates)===['completed_service_item_count'],'project candidate is discovered from the registered dimension, not a Skill metric list');
$check(($projectCandidates['completed_service_item_count']['action_codes']??[])===['service'],'project candidate exposes its registered business action rather than a report-specific rule');
$memberCandidates=AiCapabilityGuidanceCatalog::discover($runtimeCapabilities,'member','ranking');
$check(($memberCandidates['cash_performance']['action_codes']??[])===['payment','revenue'],'member cash ranking exposes only its registry-declared payment and collection actions');
$personCandidates=AiCapabilityGuidanceCatalog::discover($runtimeCapabilities,'person','ranking');
$check(($personCandidates['staff_labor_yeji']['action_codes']??[])===['service']
    && ($personCandidates['staff_sales_yeji']['action_codes']??[])===['sales'],'personnel actions come from registered metric dimension contracts, not AI phrase rules');
$check((AiSemanticObjectContractRegistry::statuses($projection)['position']['state']??null)==='registered','position is registered through the personnel query contract');
$guide=$planner->start($projection,$missing);
$check($guide['kind']==='clarification'&&$guide['schema_version']==='mohe-skill-guidance-v1'&&$guide['fields'][0]['key']==='skill_evaluation_metric','unregistered project enters a server-owned single-choice guide');
$check(array_column($guide['fields'][0]['options'],'value')===['sales_amount','sales_quantity','completed_service_count','consume_amount'],'choice list originates from the published Skill slot');
$stop=$planner->choose($guide,['skill_evaluation_metric'=>'sales_amount']);
$check($stop['kind']==='capability_unavailable'&&$stop['reason']==='AI_OBJECT_CONTRACT_NOT_READY','choice never compiles an unregistered fact query');
try {$planner->choose($guide,['skill_evaluation_metric'=>'invented']);throw new RuntimeException('missing choice rejection');}
catch(RuntimeException $error){$check($error->getMessage()==='AI_CLARIFICATION_INVALID','server rejects non-Skill choice');}
$member=(new AiSafeQuestionProjector())->project('消费能力最强的会员有哪些',$config,[]);
$check(!$member['outbound']['has_unresolved_conditions']&&str_contains(json_encode($member['outbound'],JSON_UNESCAPED_UNICODE),'消费能力'),'member payment semantics remain available to the model stage');
$memberPlural=(new AiSafeQuestionProjector())->project('本月消费能力最强的会员有哪一些？',$config,[]);
$check(!$memberPlural['outbound']['has_unresolved_conditions']&&str_contains($memberPlural['outbound']['question'],'哪一些'),'generic plural wording is not misclassified as an unknown business condition');
$rankedMember=(new AiSafeQuestionProjector())->project('本月消费能力最强的会员前五名',$config,[]);
$check(!$rankedMember['outbound']['has_unresolved_conditions']&&$rankedMember['outbound']['question']==='本月消费能力最强的会员前五名','ordinary result wording needs no code or Skill grammar list');
$check((AiSemanticObjectContractRegistry::statuses($projection)['member']['state']??null)==='registered','member is registered through the metric read-view dimension contract');
$memberPlan=(new AiDimensionGuidancePlanner())->start('member',[
    'object_kind'=>'member','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['payment'],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>null],
],['signals'=>['cash_performance'],'date_terms'=>[['code'=>'THIS_MONTH']],'date_grouping_ambiguous'=>false,'semantic_intent'=>['rank_limit'=>null]],[
    'cash_performance'=>['name'=>'现金业绩','summary'=>'统计期内实际付款金额','action_codes'=>['payment','revenue']],
],'screen','2026-09-10');
$check($memberPlan['kind']==='plan'&&$memberPlan['plan']['query']['business_filters']===['object_kind'=>'member']&&$memberPlan['plan']['query']['ranking']===['direction'=>'top','limit'=>5],'registered member dimension compiles a generic payment ranking without a privacy side gate');
$dimensionPlanner=new AiDimensionGuidancePlanner();
$memberService=$dimensionPlanner->start('member',[
    'object_kind'=>'member','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['service'],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>null],
],['signals'=>[],'date_terms'=>[['code'=>'THIS_MONTH']],'date_grouping_ambiguous'=>false,'semantic_intent'=>['rank_limit'=>null]],[
    'cash_performance'=>['name'=>'现金业绩','summary'=>'统计期内实际付款金额','action_codes'=>['payment']],
],'screen','2026-09-10');
$check($memberService['kind']==='capability_unavailable'&&$memberService['reason']==='AI_DIMENSION_ACTION_CONTRACT_NOT_READY','a published but unsupported member action never falls back to payment');
$dimensionIntent=['operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>5]];
$dimensionProjection=['signals'=>['cash_performance'],'date_terms'=>[],'semantic_intent'=>['rank_limit'=>null]];
$dimensionCandidates=['cash_performance'=>['name'=>'现金业绩','summary'=>'统计期内实际付款金额']];
$dateGuide=$dimensionPlanner->start('member',$dimensionIntent,$dimensionProjection,$dimensionCandidates,'screen','2026-09-10');
$dated=$dimensionPlanner->choose($dateGuide,['start_date'=>'2026-09-01','end_date'=>'2026-09-08']);
$check($dated['kind']==='plan'&&$dated['plan']['query']['start_date']==='2026-09-01'&&$dated['plan']['query']['end_date']==='2026-09-08','date pair clarification submits successfully and preserves both dates');
$both=$dimensionProjection;$both['date_terms']=[['code'=>'THIS_MONTH']];
$bothIntent=$dimensionIntent;$bothIntent['ranking']=['direction'=>'top_and_bottom','limit'=>5];
$bothPlan=$dimensionPlanner->start('member',$bothIntent,$both,$dimensionCandidates,'screen','2026-09-10');
$check($bothPlan['plan']['query']['ranking']['direction']==='top_and_bottom','top and bottom request preserves both ranking directions');
foreach ([
    'mixed metrics'=>array_replace($both,['signals'=>['cash_performance','consume_amount']]),
] as $label=>$invalidProjection) {
    try {$dimensionPlanner->start('member',$bothIntent,$invalidProjection,$dimensionCandidates,'screen','2026-09-10');throw new RuntimeException('missing rejection');}
    catch(RuntimeException $error){$check($error->getMessage()==='AI_ANALYSIS_COMBINATION_UNAVAILABLE',$label.' must not be reduced to a supported partial query');}
}
$multiIntent=$dimensionIntent;$multiIntent['metric_codes']=['cash_performance','consume_amount'];
try {$dimensionPlanner->start('member',$multiIntent,['signals'=>[]],$dimensionCandidates,'screen','2026-09-10');throw new RuntimeException('missing rejection');}
catch(RuntimeException $error){$check($error->getMessage()==='AI_ANALYSIS_COMBINATION_UNAVAILABLE','multiple model-selected metrics cannot be truncated to the first');}
$ambiguous=$dimensionIntent;$ambiguous['needs_metric_choice']=true;
$explicit=$dimensionPlanner->start('member',$ambiguous,$both,$dimensionCandidates,'screen','2026-09-10');
$check($explicit['kind']==='plan','explicit dictionary criterion does not need redundant metric clarification');
$inventory=(new AiSafeQuestionProjector())->project('上个季度库存还剩多少',$config,[]);
$inventoryGuide=$planner->start($projection,AiSemanticObjectContractRegistry::missingObjects($projection,[['kind'=>'object','code'=>'inventory','label'=>'库存与耗用','text'=>'库存']]));
$check(!$inventory['outbound']['has_unresolved_conditions']&&$inventoryGuide['fields'][0]['key']==='skill_usage_fact_type','inventory intent keeps the period phrase and asks the inventory fact slot');
echo 'PASS skill semantic projection: '.$checks." checks (source vocabulary / no fact reads)\n";

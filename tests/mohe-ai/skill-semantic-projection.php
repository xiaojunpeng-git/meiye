<?php
/** Published Skills are prose guidance; executable choices are discovered from
 * the metric registry.  No database, model, queue or HTTP access. */
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\config\AiConfigStore;
use app\services\ai\execution\AiCapabilityGuidanceCatalog;
use app\services\ai\execution\AiDimensionGuidancePlanner;
use app\services\ai\model\AiSafeQuestionProjector;
use app\services\ai\registry\AiBusinessRegistry;
use app\services\ai\registry\AiSkillDocument;
use app\services\query\metric\MetricDefinitionRegistry;

$backend=dirname(__DIR__,2).'/后端代码/app/services/';
require_once $backend.'query/metric/MetricDefinitionRegistry.php';
require_once $backend.'query/metric/MetricReadViewServices.php';
require_once $backend.'metric/MetricDictionaryServices.php';
require_once $backend.'ai/config/AiConfigStore.php';
require_once $backend.'ai/model/AiSafeQuestionProjector.php';
require_once $backend.'ai/registry/AiRegistryValue.php';
require_once $backend.'ai/registry/AiSkillDocument.php';
require_once $backend.'ai/registry/AiBusinessManifest.php';
require_once $backend.'ai/registry/AiBusinessRegistry.php';
require_once $backend.'ai/execution/AiCapabilityGuidanceCatalog.php';
require_once $backend.'ai/execution/AiWorkflowPlanner.php';
require_once $backend.'ai/execution/AiDimensionGuidancePlanner.php';

$checks=0;
$check=static function(bool $condition,string $label)use(&$checks):void { if(!$condition) throw new RuntimeException('FAIL '.$label); ++$checks; };
$store=AiSkillDocument::storeOperations();
$intent=AiSkillDocument::intentUnderstanding();
$check($store['skill_code']==='skill_store_operations'&&is_int($store['version'])&&$store['version']>=1&&$intent['skill_code']==='skill_intent_understanding'&&is_int($intent['version'])&&$intent['version']>=1,'published Skill identities are source-owned');
$check(strpos($store['markdown'],'```')===false&&strpos($intent['markdown'],'```')===false,'published Skills contain prose guidance rather than embedded executable contracts');
$check(strpos($store['markdown'],'商品分类')!==false&&strpos($store['markdown'],'合作方')!==false&&strpos($intent['markdown'],'完整阅读')!==false,'business and language guidance remains available to the model');
$check(substr_count($intent['markdown'],'## 第')===5&&substr_count($store['markdown'],'## 第')===5,'both source Skills explain a stepwise reasoning process');
$check(strpos($intent['markdown'],'没有匹配的指标编码而被判为听不懂')!==false&&strpos($store['markdown'],'分开判断歧义与能力缺口')!==false,'understood business meaning is kept separate from executable capability availability');
$skills=(new AiBusinessRegistry())->modelSkills('store_operations');
$check($skills['business']['instructions']===$store['markdown']&&$skills['intent_understanding']['instructions']===$intent['markdown'],'model receives the complete Markdown sources');

$config=['enabled'=>true,'external_processing_authorized'=>true,'external_scope_supported'=>true,'external_scope_version'=>AiConfigStore::QUESTION_SCOPE];
$safe=(new AiSafeQuestionProjector())->project('这个月卖得最好的项目',$config,[]);
$check($safe['outbound']['question']==='这个月卖得最好的项目'&&!$safe['outbound']['has_unresolved_conditions'],'unfamiliar but meaningful wording reaches the model unchanged');
$safe=(new AiSafeQuestionProjector())->project('消费能力最强的会员有哪一些？',$config,[]);
$check(strpos($safe['outbound']['question'],'消费能力')!==false,'member intent is not rejected by a local phrase whitelist');
$history=[];
for($index=1;$index<=20;$index++) $history[]=['question'=>'第'.$index.'轮问题'];
$conversation=(new AiSafeQuestionProjector())->projectConversation('继续看刚才的结果',$history,$config,[]);
$check($conversation['outbound']['recent_questions']===['第15轮问题','第16轮问题','第17轮问题','第18轮问题','第19轮问题','第20轮问题']
    && count($conversation['outbound']['evidence_messages'])===7,'external context keeps only the latest six de-identified questions while the local transcript remains untouched');

$capabilities=['metric_codes'=>array_keys(MetricDefinitionRegistry::capabilities()),'metric_readiness'=>MetricDefinitionRegistry::capabilities()];
$project=AiCapabilityGuidanceCatalog::discover($capabilities,'project','ranking');
$projectSummary=AiCapabilityGuidanceCatalog::discover($capabilities,'project','summary');
$member=AiCapabilityGuidanceCatalog::discover($capabilities,'member','ranking');
$person=AiCapabilityGuidanceCatalog::discover($capabilities,'person','ranking');
$check(isset($project['sales_amount'])&&isset($project['completed_service_item_count'])&&isset($member['cash_performance'])
    && ($project['sales_amount']['action_codes']??[])===['sales'],'objects become available only when the metric registry declares an executable dimension contract');
$check(array_keys($projectSummary)===['completed_service_item_count','sales_amount','sales_quantity'],
    'an object summary exposes exactly the metrics opted into its registry overview profile');
$projectSummaryCodes=array_keys($projectSummary);
$projectSummaryPlan=(new \app\services\ai\execution\AiWorkflowPlanner())->compile(
    ['dates'=>[],'date_terms'=>[['code'=>'EXPLICIT','start'=>'2026-09-01','end'=>'2026-09-18']],
        'signals'=>array_merge($projectSummaryCodes,['summary']),'blocking_reason'=>null,'unresolved_condition'=>false,
        'semantic_intent'=>['constraints'=>[]]],
    ['decision'=>'query','query_shape'=>'summary','metric_codes'=>$projectSummaryCodes,
        'ranking'=>['direction'=>'unspecified','limit'=>null],'object_kind'=>'project'],
    $capabilities+['query_shapes'=>['summary','trend','ranking','comparison','threshold_count'],'output_formats'=>['screen']],
    'screen','2026-09-18'
);
$compiledProjectCodes=$projectSummaryPlan['plan']['query']['metric_codes']??[];
sort($compiledProjectCodes,SORT_STRING); sort($projectSummaryCodes,SORT_STRING);
$check(($projectSummaryPlan['kind']??null)==='plan'
    && ($projectSummaryPlan['plan']['query']['business_filters']??null)===['object_kind'=>'project']
    && $compiledProjectCodes===$projectSummaryCodes,
    'the generic workflow compiles the complete registry-backed object summary without a question-specific branch');
$check(isset($person['staff_sales_yeji'])&&!isset($person['cash_performance'])
    && ($person['staff_sales_yeji']['default_selection_ref']??null)==='role:salesperson'
    && strpos((string)($person['staff_sales_yeji']['summary']??''),'人员现金业绩')!==false,
    'the model receives sales-person allocation as the registered person cash-performance meaning, not store collection totals');
$planner=new AiDimensionGuidancePlanner();
$plan=$planner->start('member',['object_kind'=>'member','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['payment'],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>3]],['date_terms'=>[['code'=>'EXPLICIT','start'=>'2026-09-01','end'=>'2026-09-09']]],['cash_performance'=>$member['cash_performance']],'screen','2026-09-10');
$check($plan['kind']==='plan'&&$plan['plan']['query']['ranking']['limit']===3,'model-supplied natural count survives registry compilation');
$missing=$planner->start('project',['object_kind'=>'project','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'top','limit'=>1]],['date_terms'=>[['code'=>'EXPLICIT','start'=>'2026-09-01','end'=>'2026-09-09']]],$project,'screen','2026-09-10');
$check($missing['kind']==='clarification'&&$missing['fields'][0]['key']==='dimension_metric','an ambiguous evaluation is guided from registered candidates, not a fixed report scene');

echo 'PASS skill prose and dynamic registry: '.$checks." checks\n";

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
$check($store['skill_code']==='skill_store_operations'&&$store['version']===13&&$intent['skill_code']==='skill_intent_understanding','published Skill identities are source-owned');
$check(strpos($store['markdown'],'```')===false&&strpos($intent['markdown'],'```')===false,'published Skills contain prose guidance rather than embedded executable contracts');
$check(strpos($store['markdown'],'商品分类')!==false&&strpos($store['markdown'],'合作方')!==false&&strpos($intent['markdown'],'完整阅读')!==false,'business and language guidance remains available to the model');
$skills=(new AiBusinessRegistry())->modelSkills('store_operations');
$check($skills['business']['instructions']===$store['markdown']&&$skills['intent_understanding']['instructions']===$intent['markdown'],'model receives the complete Markdown sources');

$config=['enabled'=>true,'external_processing_authorized'=>true,'external_scope_supported'=>true,'external_scope_version'=>AiConfigStore::QUESTION_SCOPE];
$safe=(new AiSafeQuestionProjector())->project('这个月卖得最好的项目',$config,[]);
$check($safe['outbound']['question']==='这个月卖得最好的项目'&&!$safe['outbound']['has_unresolved_conditions'],'unfamiliar but meaningful wording reaches the model unchanged');
$safe=(new AiSafeQuestionProjector())->project('消费能力最强的会员有哪一些？',$config,[]);
$check(strpos($safe['outbound']['question'],'消费能力')!==false,'member intent is not rejected by a local phrase whitelist');

$capabilities=['metric_codes'=>array_keys(MetricDefinitionRegistry::capabilities()),'metric_readiness'=>MetricDefinitionRegistry::capabilities()];
$project=AiCapabilityGuidanceCatalog::discover($capabilities,'project','ranking');
$member=AiCapabilityGuidanceCatalog::discover($capabilities,'member','ranking');
$check(isset($project['completed_service_item_count'])&&isset($member['cash_performance']),'objects become available only when the metric registry declares a dimension contract');
$planner=new AiDimensionGuidancePlanner();
$plan=$planner->start('member',['object_kind'=>'member','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['payment'],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>3]],['date_terms'=>[['code'=>'EXPLICIT','start'=>'2026-09-01','end'=>'2026-09-09']]],['cash_performance'=>$member['cash_performance']],'screen','2026-09-10');
$check($plan['kind']==='plan'&&$plan['plan']['query']['ranking']['limit']===3,'model-supplied natural count survives registry compilation');
$missing=$planner->start('project',['object_kind'=>'project','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'top','limit'=>1]],['date_terms'=>[['code'=>'EXPLICIT','start'=>'2026-09-01','end'=>'2026-09-09']]],$project,'screen','2026-09-10');
$check($missing['kind']==='clarification'&&$missing['fields'][0]['key']==='dimension_metric','an ambiguous evaluation is guided from registered candidates, not a fixed report scene');

echo 'PASS skill prose and dynamic registry: '.$checks." checks\n";

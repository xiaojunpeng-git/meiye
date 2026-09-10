<?php
/** Source-contract regression checks. No database, business facts or model call. */
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\config\AiConfigStore;
use app\services\ai\execution\AiSkillGuidancePlanner;
use app\services\ai\model\AiSafeQuestionProjector;
use app\services\ai\registry\AiBusinessRegistry;
use app\services\ai\registry\AiSkillDocument;

$checks=0;
$check=static function(bool $condition,string $label)use(&$checks):void { if(!$condition)throw new RuntimeException('FAIL '.$label);++$checks; };
$skill=AiSkillDocument::storeOperations();
$projection=$skill['semantic_projection'];
$check($skill['version']===3&&count($projection['objects'])===8,'published store Skill exposes its bounded semantic projection');
$check((new AiBusinessRegistry())->modelSkill('store_operations')['semantic_projection']===$projection,'registry carries the exact validated Skill vocabulary');
$config=['enabled'=>true,'external_processing_authorized'=>true,'external_scope_supported'=>true,'external_scope_version'=>AiConfigStore::QUESTION_SCOPE];
$safe=(new AiSafeQuestionProjector())->project('这个月卖得最好的项目',$config,[],$projection);
$wire=json_encode($safe['outbound'],JSON_UNESCAPED_UNICODE);
$check(!$safe['outbound']['has_unresolved_conditions']&&str_contains($wire,'卖得')&&str_contains($wire,'项目'),'published public words reach model intent input intact');
$check($safe['recognized_terms']===[['kind'=>'object','code'=>'project','label'=>'项目','text'=>'项目']],'project object comes from the Skill contract, not a page rule');
$unknown=(new AiSafeQuestionProjector())->project('哪个魔法项目卖得好',$config,[],$projection);
$check($unknown['outbound']['has_unresolved_conditions']&&in_array('魔法',$unknown['local_conditions'],true),'unknown qualifier remains local and cannot bypass the gateway');
$planner=new AiSkillGuidancePlanner();
$guide=$planner->start($projection,$safe['recognized_terms']);
$check($guide['kind']==='clarification'&&$guide['schema_version']==='mohe-skill-guidance-v1'&&$guide['fields'][0]['key']==='skill_evaluation_metric','unregistered project enters a server-owned single-choice guide');
$check(array_column($guide['fields'][0]['options'],'value')===['sales_amount','sales_quantity','completed_service_count','consume_amount'],'choice list originates from the published Skill slot');
$stop=$planner->choose($guide,['skill_evaluation_metric'=>'sales_amount']);
$check($stop['kind']==='capability_unavailable'&&$stop['reason']==='AI_PROJECT_OBJECT_NOT_READY','choice never compiles an unregistered fact query');
try {$planner->choose($guide,['skill_evaluation_metric'=>'invented']);throw new RuntimeException('missing choice rejection');}
catch(RuntimeException $error){$check($error->getMessage()==='AI_CLARIFICATION_INVALID','server rejects non-Skill choice');}
echo 'PASS skill semantic projection: '.$checks." checks (source vocabulary / no fact reads)\n";

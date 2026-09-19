<?php
require_once __DIR__.'/fixture-autoload.php';
use app\services\ai\execution\AiAnalysisGuidancePlanner;
use app\services\ai\execution\AiRegisteredPlanCompiler;
use app\services\query\metric\PersonnelPerformanceReadServices;
use app\services\query\metric\PersonnelAnalysisObjectServices;
use app\services\query\metric\AnalysisObjectCatalog;
use app\services\query\metric\MetricReadViewServices;
use app\services\query\metric\MetricReadViewStore;
use app\services\query\metric\GroupPerformanceMetricReadServices;
$checks=0;
function paCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function paReject(callable $call,$expected){try{$call();}catch(Throwable $e){$code=method_exists($e,'getErrorCode')?$e->getErrorCode():$e->getMessage();paCheck($code===$expected,'expected '.$expected.' got '.$code);return;}throw new RuntimeException('expected '.$expected);}
$planner=new AiAnalysisGuidancePlanner();
$outcome=new ReflectionMethod(app\services\ai\execution\AiRunStore::class,'outcomeClass');if(PHP_VERSION_ID<80100)$outcome->setAccessible(true);
foreach(['AI_ANALYSIS_COMBINATION_UNAVAILABLE','AI_OBJECT_BINDING_UNAVAILABLE','AI_OBJECT_SCOPE_TOO_LARGE','AI_PERSONNEL_PERMISSION_REQUIRED','AI_EXTERNAL_SCOPE_REQUIRED','AI_LOCAL_CONDITION_REQUIRED'] as $reason)
 paCheck($outcome->invoke(null,['status'=>'FAILED','reason'=>$reason])==='neutral','capability/authority refusal never counts as user technical failure');
$intent=['object_kind'=>'person','object_term'=>'技师','operation'=>'ranking','metric_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'top','limit'=>1]];
$projection=['date_terms'=>[['code'=>'TODAY']],'signals'=>['rank_top'],'analysis_singular_person'=>true];
$candidates=['staff_labor_yeji'=>['name'=>'劳动业绩','summary'=>'按实际手艺人分配','query_shapes'=>['summary','ranking']],
 'staff_sales_yeji'=>['name'=>'销售人业绩','summary'=>'按销售人分配','query_shapes'=>['summary','ranking']],
 'summary_only'=>['name'=>'仅汇总合同','summary'=>'不能用于排行','query_shapes'=>['summary']]];
$objects=['status'=>'choose','objects'=>[['ref'=>'position:2','label'=>'护理师'],['ref'=>'role:craftsman','label'=>'有手艺人资格的在职人员']]];
$step=$planner->start($intent,$projection,$candidates,$objects,'screen','2026-09-09');
$reordered=$intent;$reordered['ranking']=['limit'=>1,'direction'=>'top'];
paCheck($planner->start($reordered,$projection,$candidates,$objects,'screen','2026-09-09')['kind']==='clarification','model JSON member order never changes personnel interpretation');
paCheck($step['fields'][0]['key']==='analysis_object','actual object choice first, no technician scene');
foreach ([
 ['object_kind'=>'store','operation'=>'ranking','metric_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'top','limit'=>1]],
 ['object_kind'=>'person','operation'=>'trend','metric_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'top','limit'=>1]],
 ['object_kind'=>'person','operation'=>'ranking','metric_codes'=>['staff_labor_yeji','staff_sales_yeji'],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>1]],
] as $invalidIntent) {
 paReject(fn()=>$planner->start($invalidIntent,$projection,$candidates,$objects,'screen','2026-09-09'),
  $invalidIntent['object_kind']!=='person'?'AI_ANALYSIS_PERSON_OBJECT_UNAVAILABLE':($invalidIntent['operation']!=='ranking'?'AI_ANALYSIS_PERSON_OPERATION_UNAVAILABLE':'AI_ANALYSIS_PERSON_MULTIPLE_METRICS'));
}
$next=$planner->choose($step,['analysis_object'=>'position:2']);
paCheck($next['fields'][0]['key']==='analysis_metric' && count($next['fields'][0]['options'])===2,'best does not guess cash or labor; unsupported operation is not offered');
$plan=$planner->choose($next,['analysis_metric'=>'staff_labor_yeji'])['plan'];
// Follow-up language is deliberately not parsed in a PHP fixture. The
// model-produced delta is covered by query-context.php; this planner remains
// responsible only for already-normalized, registered values.
paCheck($plan['query']['business_filters']===['object_kind'=>'person','selection_ref'=>'position:2'],'selection survives into executable query');
paCheck($plan['query']['ranking']===['direction'=>'top','limit'=>1] && $plan['query']['start_date']==='2026-09-09','singular who preserves today and one result');
$defaultCandidates=$candidates;
$defaultCandidates['staff_labor_yeji']['default_selection_ref']='role:craftsman';
$unselectedObjects=['status'=>'choose','objects'=>[['ref'=>'position:2','label'=>'护理师'],['ref'=>'role:craftsman','label'=>'有手艺人资格的在职人员']]];
$defaultIntent=$intent;$defaultIntent['metric_codes']=['staff_labor_yeji'];$defaultIntent['needs_metric_choice']=false;
$defaultPlan=$planner->start($defaultIntent,$projection,$defaultCandidates,$unselectedObjects,'screen','2026-09-09');
paCheck($defaultPlan['kind']==='plan' && $defaultPlan['plan']['query']['business_filters']['selection_ref']==='role:craftsman','registered metric cohort supplies a labelled first answer without a role picker');
$multiIntent=['object_kind'=>'person','object_term'=>'同名员工','operation'=>'summary',
 'metric_codes'=>['staff_sales_yeji','staff_labor_yeji'],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null]];
$multiObjects=['status'=>'choose','objects'=>[['ref'=>'person:7','label'=>'同名员工（合成一店）'],['ref'=>'person:8','label'=>'同名员工（合成二店）']]];
$multiStep=$planner->start($multiIntent,$projection,$candidates,$multiObjects,'screen','2026-09-09');
$multiPlan=$planner->choose($multiStep,['analysis_object'=>'person:7']);
paCheck(($multiPlan['kind']??null)==='plan'
 && ($multiPlan['plan']['query']['metric_codes']??null)===['staff_sales_yeji','staff_labor_yeji']
 && ($multiPlan['plan']['query']['business_filters']['selection_ref']??null)==='person:7',
 'choosing one duplicate-name person preserves every explicitly requested summary metric');
paReject(function()use($planner,$step){$planner->choose($step,['analysis_object'=>'position:999']);},'AI_CLARIFICATION_INVALID');
$caps=['metric_codes'=>array_keys($candidates),'metric_readiness'=>PersonnelPerformanceReadServices::capabilities(),'query_shapes'=>['summary','ranking'],'output_formats'=>['screen']];
$compiler=new AiRegisteredPlanCompiler();$compiled=$compiler->compile($plan,$caps);$compiler->assertCompiled($compiled);
paCheck($compiled['workflow_code']==='wf_performance_ranking','existing reusable ranking workflow, no per-question workflow');
$selfCapabilities=\app\services\ai\execution\AiAuthority::capabilities(false,[
 'scope_mode'=>'self_participant','store_ids'=>[1],'analysis_personnel_ready'=>true,
]);
paCheck($selfCapabilities['metric_codes']===['staff_sales_yeji','staff_labor_yeji','staff_project_num','staff_service_num','service_people'],
 'self-participant capability projection contains only Reader-executable person metrics');
paCheck(\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($selfCapabilities,'store','summary')===[],
 'self-participant never receives a store-total candidate that Reader must reject');
paCheck(\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($selfCapabilities,'person','summary')!==[],
 'self-participant retains its registered person-grain capabilities');
$bad=$plan;$bad['query']['business_filters']=[];
paReject(function()use($compiler,$bad,$caps){$compiler->compile($bad,$caps);},'AI_UNSUPPORTED_CONDITION');
$forged=$caps;$forged['metric_readiness']['staff_labor_yeji']['filter_grain']='store';$forged['metric_readiness']['staff_labor_yeji']['business_filters']=[];
paReject(function()use($compiler,$plan,$forged){$compiler->compile($plan,$forged);},'AI_METRIC_CONTRACT_INCOMPLETE');

class PaQuery {
 public static $calls=[]; public static $amount='10001'; public $field='';
 public function __call($name,$args){self::$calls[]=[$name,$args];if(isset($args[0])&&is_callable($args[0]))$args[0]($this);if(in_array($name,['field','fieldRaw'],true))$this->field=$args[0];return $this;}
 public function find(){return ['amount_cents'=>self::$amount];}
 public function select(){return $this;}
 public function toArray(){
  if(strpos($this->field,'SUM(')!==false)return [['employee_id'=>'7','business_date'=>'2026-09-09','metric_value'=>self::$amount,'fact_count'=>'2']];
  if(strpos($this->field,'position_name')!==false)return [['store_id'=>1,'employee_id'=>7,'employee_name'=>'合成人员甲','store_name'=>'合成一店','cashier_craftsman_enabled'=>1,'cashier_salesperson_enabled'=>1,'position_id'=>2,'position_name'=>'护理师']];
  return [['store_id'=>1,'employee_id'=>7,'employee_name'=>'合成人员甲']];
 }
}
$scope=['personnel_authorized'=>true,'store_ids'=>[1],'employee_id'=>0,'permission_version'=>'fixture-v1'];
$factory=static function($table){PaQuery::$calls[]=['table',$table];return new PaQuery();};
$authorize=function($metric)use(&$scope){return $scope;};
$objectService=new PersonnelAnalysisObjectServices($factory,$authorize);
$selection=$objectService->selection('staff_labor_yeji','position:2');
paCheck($selection['pairs']===[['store_id'=>1,'employee_id'=>7]],'selection uses store/person pairs');
paCheck($objectService->selection('staff_labor_yeji','person:7')['pairs']===$selection['pairs'],'named person uses the same authorized store/person pairs');
$roleCatalog=new AnalysisObjectCatalog($objectService->catalog('staff_labor_yeji')['objects'],static function(){return true;});
paCheck(($roleCatalog->resolve('技师','position','staff_labor_yeji')['objects'][0]['ref']??null)==='role:craftsman','built-in role aliases resolve a technician to the qualification-backed craftsman object');
$sameNameObjects=[
 ['ref'=>'person:71','kind'=>'person','label'=>'同名员工（合成一店）','aliases'=>['同名员工'],'version'=>'1','relations'=>['staff_labor_yeji']],
 ['ref'=>'person:72','kind'=>'person','label'=>'同名员工（合成二店）','aliases'=>['同名员工'],'version'=>'1','relations'=>['staff_labor_yeji']],
];
$sameNameResolution=(new AnalysisObjectCatalog($sameNameObjects,static function(){return true;}))->resolve('同名员工','person','staff_labor_yeji');
paCheck($sameNameResolution['status']==='choose' && array_column($sameNameResolution['objects'],'label')===['同名员工（合成一店）','同名员工（合成二店）'],
 'same-name employees remain a customer choice with distinct authoritative store labels');
paCheck($objectService->selection('staff_labor_yeji','role:craftsman')['pairs']===$selection['pairs'],'the resolved craftsman role uses the same authorized store/person fact pairs');
$single=$plan;$single['query']['business_filters']['selection_ref']='person:7';
paCheck($compiler->compile($single,$caps)['workflow_code']==='wf_performance_ranking','named person reuses the same registered workflow');
$multiIntent=['object_kind'=>'person','object_term'=>'合成人员甲','operation'=>'summary','metric_codes'=>['staff_labor_yeji','staff_sales_yeji'],
 'needs_metric_choice'=>false,'action_codes'=>['service','sales'],'ranking'=>['direction'=>'unspecified','limit'=>null]];
$multiObjects=['status'=>'resolved','objects'=>[['ref'=>'person:7','label'=>'合成人员甲']]];
$multi=$planner->start($multiIntent,['date_terms'=>[['code'=>'TODAY']]],$candidates,$multiObjects,'screen','2026-09-09');
paCheck($multi['kind']==='plan'&&$multi['plan']['query']['metric_codes']===['staff_labor_yeji','staff_sales_yeji'],'one named person can retain two compatible registered summary metrics');
paCheck($compiler->compile($multi['plan'],$caps)['workflow_code']==='wf_performance_summary','multi-metric personnel summary remains in the shared registered workflow');
$reader=new GroupPerformanceMetricReadServices($factory,static function($q,$tenant,$order){$q->normalScope($tenant,$order);});
$range=['start'=>'2026-09-09','end'=>'2026-09-09'];
$points=$reader->personnelTotals('0',[1],$range,'staff_labor_yeji',$selection['pairs']);
paCheck($points[0]['amount_cents']===10001,'exact cents');
$record=json_encode(PaQuery::$calls);
foreach (['labor_performance_allocated','normalScope','p.tenant_id','p.employee_id','p.store_id','SUM(amount_cents)','p.business_date'] as $needle)paCheck(strpos($record,$needle)!==false,'query contract '.$needle);
PaQuery::$amount='-200';paCheck($reader->personnelTotals('0',[1],$range,'staff_labor_yeji',$selection['pairs'])[0]['amount_cents']===-200,'reversals keep negative sign');PaQuery::$amount='10001';
paReject(function()use($reader,$range){$reader->personnelTotals('0',[1],$range,'staff_labor_yeji',[['store_id'=>2,'employee_id'=>7]]);},'METRIC_PERSONNEL_SCOPE_INVALID');
$binding=['instance_id'=>'fixture','subject_ref'=>'fixture','terminal'=>'platform','tenant_id'=>'0','permission_version'=>'fixture-v1','report_capability_code'=>'group_management_dashboard','scope_provider_code'=>'current_report_scope_v1','scope_mode'=>'stores','store_ids'=>[1]];
$temp=sys_get_temp_dir().'/mohe-personnel-test-'.bin2hex(random_bytes(8));
$store=new MetricReadViewStore($temp,str_repeat('fixture',8));
$views=new MetricReadViewServices($store,static function()use(&$binding){return $binding;},static function($call)use($reader){return $call($reader);},null,$objectService);
try {
 $view=$views->create([],$plan['query']);
 paCheck($view['results'][0]['rows']['top'][0]['employee_name']==='合成人员甲','shared immutable view contains validated object label');
 paCheck($views->replay([],$plan['query'],$view['read_consistency_ref'])['result_hash']===$view['result_hash'],'exact replay');
 $multiView=$views->create([],$multi['plan']['query']);
 paCheck(array_column($multiView['results'],'metric_code')===['staff_labor_yeji','staff_sales_yeji'],'one immutable personnel view keeps the same selection across both registered metrics');
 $binding['scope_mode']='self_participant';$binding['employee_id']=7;$binding['store_report_authorized']=false;$scope['employee_id']=7;
 $selfQuery=$plan['query'];$selfQuery['query_shape']='summary';$selfQuery['ranking']=null;$selfQuery['business_filters']['selection_ref']='person:7';
 foreach (['platform','store','merchant'] as $terminal) {
  $binding['terminal']=$terminal;
  paCheck($views->create([],$selfQuery)['results'][0]['amount_cents']===10001,'self personnel works through '.$terminal);
 }
 $cash=$selfQuery;$cash['metric_codes']=['cash_performance'];$cash['business_filters']=[];
 paReject(fn()=>$views->create([],$cash),'METRIC_PERMISSION_GRAIN_UNAVAILABLE');
 $scope['employee_id']=0;paReject(fn()=>$views->create([],$selfQuery),'METRIC_PERMISSION_DENIED');$scope['employee_id']=7;
 $binding['employee_id']=8;paReject(fn()=>$views->create([],$selfQuery),'METRIC_PERMISSION_DENIED');$binding['employee_id']=7;
 $binding['scope_mode']='stores';paCheck(is_int($views->create([],$cash)['results'][0]['amount_cents']),'page report visibility never changes registered Reader scope');
 $binding=['instance_id'=>'fixture','subject_ref'=>'fixture','terminal'=>'platform','tenant_id'=>'0','permission_version'=>'fixture-v1','report_capability_code'=>'group_management_dashboard','scope_provider_code'=>'current_report_scope_v1','scope_mode'=>'stores','store_ids'=>[1]];$scope['employee_id']=0;
 $scope['personnel_authorized']=false;
 paReject(function()use($views,$plan,$view){$views->replay([],$plan['query'],$view['read_consistency_ref']);},'AI_PERSONNEL_PERMISSION_REQUIRED');
} finally {foreach(new DirectoryIterator($temp) as $file)if($file->isFile()&&!$file->isLink())unlink($file->getPathname());rmdir($temp);}
echo "PASS personnel analysis: $checks checks (synthetic data only)\n";

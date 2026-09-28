<?php
require_once __DIR__.'/fixture-autoload.php';
require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiAuthority.php';
require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiRuntimeMonitor.php';
// Real SQLite state/config + real encrypted object/read-view stores. Only model and
// fact aggregation adapter are fixtures. No external account, network or business DB.
$source = dirname(__DIR__,2).'/后端代码/app/services/';
require_once $source.'cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';
require_once $source.'BaseServices.php';
require_once $source.'metric/MetricDictionaryServices.php';
require_once $source.'query/metric/MetricMoneyFormatter.php';
foreach (['query/UnifiedQueryException.php','query/UnifiedQueryJson.php','query/metric/MetricQueryContractException.php','query/metric/GroupPerformanceMetricReadServices.php','query/metric/MetricReadViewStore.php','query/metric/MetricGroupedProjection.php','query/metric/MetricReadViewServices.php','ai/contract/AiContractException.php','ai/contract/AiStrictJson.php','ai/model/AiModelInputProjector.php','ai/model/SiliconFlowClient.php','ai/config/AiPrivateStorage.php','ai/config/AiConfigStore.php','ai/execution/AiRunStore.php','ai/execution/AiWorkflowPlanner.php','ai/AiGatewayServices.php'] as $file) require_once $source.$file;
use app\services\ai\AiGatewayServices;
use app\services\ai\config\AiConfigStore;
use app\services\ai\config\AiPrivateStorage;
use app\services\ai\execution\AiRunStore;
use app\services\query\metric\MetricReadViewStore;
use app\services\query\metric\GroupPerformanceMetricReadServices;
$checks=0;
function verifyGateway($value,$label){global $checks;if(!$value)throw new RuntimeException('FAIL '.$label);$checks++;}
function rejectGateway(callable $call,$code){try{$call();}catch(Throwable $e){verifyGateway($e->getMessage()===$code,'expected '.$code.' got '.$e->getMessage());return;}throw new RuntimeException('Missing error '.$code);}
class GatewayFactFixture { private $day;private $table;public function __construct($table){$this->table=$table;}public function __call($name,$args){if($name==='whereExists')$args[0]($this);if($name==='whereBetween')$this->day=$args[1][0];return $this;}public function find(){return ['amount_cents'=>$this->table==='cashier_v3_payment_fact'?'10000':'12345'];}public function column(){return [1=>'测试门店'];}public function toArray(){return [['store_id'=>'1','business_date'=>$this->day,'amount_cents'=>$this->table==='cashier_v3_payment_fact'?'10000':'12345']];} }
$db=new PDO('sqlite::memory:');
foreach ([
 'CREATE TABLE mohe_ai_mutex (instance_id TEXT PRIMARY KEY,quarantined_slots INTEGER NOT NULL DEFAULT 0)',
 'CREATE TABLE mohe_ai_receipt (instance_id TEXT,receipt_key TEXT,request_hash TEXT,window_id TEXT,run_id TEXT,reason TEXT,expires_at INTEGER,PRIMARY KEY(instance_id,receipt_key))',
 'CREATE TABLE mohe_ai_attempt (instance_id TEXT,run_id TEXT,attempt_code TEXT,kind TEXT,target_code TEXT,payload_hash TEXT,state TEXT,input_tokens INTEGER,output_tokens INTEGER,created_at INTEGER,expires_at INTEGER,PRIMARY KEY(instance_id,run_id,attempt_code))',
 'CREATE TABLE mohe_ai_run (instance_id TEXT,run_id TEXT,account_id INTEGER,terminal TEXT,conversation_id TEXT,window_id TEXT,generation INTEGER,status TEXT,reason TEXT,progress_code TEXT,clarification_ref TEXT,version INTEGER,created_at INTEGER,expires_at INTEGER,deadline_at INTEGER,last_clock_at INTEGER,remaining_ms INTEGER,pause_at INTEGER,clarification_count INTEGER,slot_held INTEGER,worker_token TEXT,evidence_ref TEXT,answer_ref TEXT,snapshot_json TEXT,counters_json TEXT,PRIMARY KEY(instance_id,run_id))',
 'CREATE TABLE mohe_ai_config (instance_id TEXT PRIMARY KEY,enabled INTEGER,model TEXT,encrypted_key TEXT,external_authorized INTEGER,external_scope_version TEXT,version INTEGER)'
] as $sql)$db->exec($sql);
$temp=sys_get_temp_dir().'/mohe-gateway-integration-'.bin2hex(random_bytes(8));mkdir($temp,0700);
try {
 $private=new AiPrivateStorage($temp);$views=new MetricReadViewStore($temp.'/views',$private->signingKey());
 $config=new AiConfigStore($db,'','fixture.instance',$private);
 $config->save(['enabled'=>true,'external_processing_authorized'=>true,'external_scope_version'=>AiConfigStore::QUESTION_SCOPE,'model'=>'fixture/model','api_key'=>'fixture-only-key','version'=>0]);
 $runs=new AiRunStore($db,'','fixture.instance');$models=0;$queries=0;$unknownBindingFailure=false;$unknownUnderstandingFailures=0;
 $model=function($view,$candidates,$configuration,$checkpoint,$repairPredicate=null,$phase='binding',$understanding=null)use(&$models,&$unknownBindingFailure,&$unknownUnderstandingFailures){$models++;$checkpoint();
   if($phase==='binding_verification') return ['review'=>['decision'=>'accept','rejected_requirement_ids'=>[]],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if($phase==='rank_metric_selection') {$clarifyRank=in_array($view['question'],['哪些门店业绩好（注册默认）','按现金业绩或消耗业绩，哪些门店排名更好（需要澄清）'],true);return ['selection'=>['decision'=>$clarifyRank?'clarify':'select','metric_code'=>$clarifyRank?null:'cash_performance'],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];}
   if($phase==='understanding' && $unknownUnderstandingFailures>0) {$unknownUnderstandingFailures--;throw new \app\services\ai\contract\AiContractException('AI_MODEL_RESULT_UNKNOWN',['stage'=>'transport','predicate'=>'timeout','transport_errno'=>28,'http_status'=>0,'elapsed_ms'=>30000]);}
   if($phase==='binding' && $unknownBindingFailure) {$unknownBindingFailure=false;throw new \app\services\ai\contract\AiContractException('AI_MODEL_RESULT_UNKNOWN',['stage'=>'transport','predicate'=>'timeout','transport_errno'=>28,'http_status'=>0,'elapsed_ms'=>30000]);}
   if($phase==='understanding' && $view['question']==='这个月经营情况如何（确定性概览）') return ['understanding'=>[
       'goal'=>'查看本月门店整体经营概览','request_kind'=>'open_overview','status'=>'understood','requirements'=>[[
           'id'=>'r1','meaning'=>$view['question'],'fields'=>['metric_codes','object_kind','object_relation','operation','periods'],
           'values'=>['metric_terms'=>['经营情况'],'object_kind'=>'store','object_relation'=>'analysis','operation'=>'summary',
               'periods'=>[['kind'=>'month_offset','offset_months'=>0]]],
           'evidence'=>[['message_id'=>'current','quote'=>$view['question']]],
       ]],
   ],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   // A typed broad comparison should retain both explicit periods and use
   // the registry profile without a second model binding/review round trip.
   if($phase==='understanding' && $view['question']==='9月8日经营情况对比9月7日（确定性比较）') return ['understanding'=>[
       'goal'=>'比较两个日期的门店经营概览','request_kind'=>'overview_comparison','status'=>'understood','requirements'=>[[
           'id'=>'r1','meaning'=>$view['question'],'fields'=>['metric_codes','object_kind','object_relation','operation','periods'],
           'values'=>['metric_terms'=>['经营情况'],'object_kind'=>'store','object_relation'=>'analysis','operation'=>'comparison',
               'periods'=>[['kind'=>'date_range','start'=>'2026-09-08','end'=>'2026-09-08'],['kind'=>'date_range','start'=>'2026-09-07','end'=>'2026-09-07']]],
           'evidence'=>[['message_id'=>'current','quote'=>$view['question']]],
       ]],
   ],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if($phase==='understanding' && $view['question']==='9月10日经营情况对比9月9日（拆分语义）') return ['understanding'=>[
       'goal'=>'比较两个日期的经营情况','request_kind'=>'overview_comparison','status'=>'understood','requirements'=>[
           ['id'=>'r1','meaning'=>'本期','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'date_range','start'=>'2026-09-10','end'=>'2026-09-10']]],
               'evidence'=>[['message_id'=>'current','quote'=>$view['question']]]],
           ['id'=>'r2','meaning'=>'对比期','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'date_range','start'=>'2026-09-09','end'=>'2026-09-09']]],
               'evidence'=>[['message_id'=>'current','quote'=>$view['question']]]],
       ],
   ],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if($phase==='understanding' && in_array($view['question'],['做得最好的门店是哪家（专业首答）','哪个门店业绩最高（统一默认入口）'],true)) return ['understanding'=>['goal'=>$view['question'],'requirements'=>[['id'=>'r1','meaning'=>$view['question'],'fields'=>['object_kind','operation','periods','ranking'], 'values'=>['object_kind'=>'store','operation'=>'ranking','periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'ranking'=>['direction'=>'top','limit'=>1]],'evidence'=>[['message_id'=>'current','quote'=>$view['question']]]]],'status'=>'understood'],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if($phase==='understanding' && $view['question']==='哪个门店消耗业绩最高（明确指标）') return ['understanding'=>['goal'=>$view['question'],'requirements'=>[['id'=>'r1','meaning'=>$view['question'],'fields'=>['metric_codes','object_kind','operation','periods','ranking'],'values'=>['metric_terms'=>['消耗业绩'],'object_kind'=>'store','operation'=>'ranking','periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'ranking'=>['direction'=>'top','limit'=>1]],'evidence'=>[['message_id'=>'current','quote'=>$view['question']]]]],'status'=>'understood'],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if($phase==='understanding' && $view['question']==='这个月的销售额最高是哪天（日期极值）') return ['understanding'=>['goal'=>'查看本月销售额最高的日期','requirements'=>[['id'=>'r1','meaning'=>$view['question'],'fields'=>['metric_codes','object_kind','object_relation','operation','periods','ranking'],'values'=>['metric_terms'=>['销售额'],'object_kind'=>'business_date','object_relation'=>'analysis','operation'=>'ranking','periods'=>[['kind'=>'month_offset','offset_months'=>0]],'ranking'=>['direction'=>'top','limit'=>1]],'evidence'=>[['message_id'=>'current','quote'=>$view['question']]]]],'status'=>'understood'],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if($phase==='understanding' && in_array($view['question'],['哪些门店业绩好（候选恢复）','哪些门店业绩好（注册默认）','按现金业绩或消耗业绩，哪些门店排名更好（需要澄清）'],true)) {$values=['metric_terms'=>['业绩'],'object_kind'=>'store','operation'=>'ranking','periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'ranking'=>['direction'=>'top','limit'=>null]];if($view['question']==='按现金业绩或消耗业绩，哪些门店排名更好（需要澄清）')$values['metric_terms']=['现金业绩','消耗业绩'];return ['understanding'=>['goal'=>$view['question'],'requirements'=>[['id'=>'r1','meaning'=>$view['question'],'fields'=>['metric_codes','object_kind','operation','periods','ranking'],'values'=>$values,'evidence'=>[['message_id'=>'current','quote'=>$view['question']]]]],'status'=>'understood'],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];}
   if($phase==='understanding' && $view['question']==='今天消耗业绩理解修复') return ['understanding'=>['goal'=>$view['question'],'requirements'=>[['id'=>'r1','meaning'=>$view['question'],'fields'=>['metric_codes','periods'],'values'=>$repairPredicate===null?[]:['metric_terms'=>['消耗业绩'],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>$view['question']]]]],'status'=>'understood'],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if($phase==='understanding') { $legacy=(new \app\services\ai\model\AiModelInputProjector())->modelView(['question'=>$view['question'],'history'=>[]]);$signals=$legacy['current']['signals'];$shape='summary';foreach(['trend','ranking','comparison'] as $candidate)if(in_array($candidate,$signals,true))$shape=$candidate;if(in_array('top_5',$signals,true)||in_array('bottom_5',$signals,true))$shape='ranking';$metrics=array_values(array_intersect(['cash_performance','consume_amount'],$signals));$dates=[];if(in_array('TODAY',$signals,true))$dates=[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]];$values=[];$fields=[];$terms=[];foreach((new \app\services\query\metric\MetricSemanticCatalog())->entries() as $code=>$entry)if(in_array($code,$metrics,true))foreach($entry['terms'] as $term)if(mb_strpos($view['question'],$term,0,'UTF-8')!==false){$terms[]=$term;break;}if($terms){$fields[]='metric_codes';$values['metric_terms']=$terms;}if($dates){$fields[]='periods';$values['periods']=$dates;}if($shape!=='summary'){$fields[]='operation';$values['operation']=$shape;}if($shape==='ranking'){$fields[]='ranking';$values['ranking']=['direction'=>'top','limit'=>5];}if(preg_match('/门店|店/u',$view['question'])){$fields[]='object_kind';$values['object_kind']='store';}if(!$fields&&$view['question']==='查看尚未登记的经营目标')$fields=['unbound'];$requirements=$fields?[['id'=>'r1','meaning'=>$view['question'],'fields'=>$fields,'values'=>$values,'evidence'=>[['message_id'=>'current','quote'=>$view['question']]]]]:[];return ['understanding'=>['goal'=>$view['question'],'requirements'=>$requirements,'status'=>$fields?'understood':'needs_clarification'],'usage'=>['input_tokens'=>20,'output_tokens'=>10]]; }
   $complete=function(array $intent)use($understanding,$view): array {$metrics=(array)($intent['metric_codes']??[]);$periods=(array)($intent['periods']??[]);$ranking=(array)($intent['ranking']??[]);if(!isset($intent['requirement_bindings'])){$bound=$metrics;if(($intent['context_delta']['metric_codes']??null)==='inherit')$bound=(array)($view['prior_query']['metric_codes']??[]);$ids=[];foreach((array)($understanding['requirements']??[])as$requirement)if(in_array('metric_codes',(array)($requirement['fields']??[]),true))$ids[]=$requirement['id'];$state=$bound===[]?($intent['needs_metric_choice']?'pending':'unavailable'):'satisfied';$intent['requirement_bindings']=array_map(static function($id)use($state,$bound){return['requirement_id'=>$id,'status'=>$state,'metric_codes'=>$state==='satisfied'?$bound:[]];},$ids);}$intent['provenance']=['object_kind'=>['source'=>(($intent['object_kind']??'store')==='store'&&($intent['object_term']??'')==='')?'system':'customer','requirements'=>(($intent['object_kind']??'store')==='store'&&($intent['object_term']??'')==='')?[]:['r1']],'metric_codes'=>['source'=>$metrics===[]?'system':'customer','requirements'=>$metrics===[]?[]:['r1']],'operation'=>['source'=>in_array(($intent['operation']??'summary'),['summary','unknown'],true)?'system':'customer','requirements'=>in_array(($intent['operation']??'summary'),['summary','unknown'],true)?[]:['r1']],'periods'=>['source'=>$periods===[]?'system':'customer','requirements'=>$periods===[]?[]:['r1']],'ranking'=>['source'=>(($ranking['direction']??'unspecified')==='unspecified'&&($ranking['limit']??null)===null)?'system':'customer','requirements'=>(($ranking['direction']??'unspecified')==='unspecified'&&($ranking['limit']??null)===null)?[]:['r1']],'scope'=>['source'=>'system','requirements'=>[]]];return $intent;};
   if ($view['question']==='查看尚未登记的经营目标') return ['intent'=>$complete(['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'authorized','unresolved_fragments'=>[]]),'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if ($view['question']==='今天消耗业绩格式修复') {
       $intent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['consume_amount'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized','unresolved_fragments'=>[]];
       if ($repairPredicate===null) unset($intent['unresolved_fragments']);
       return ['intent'=>$complete($intent),'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   }
   if ($view['question']==='今天消耗业绩对象载体修复') {
       $intent=['object_kind'=>$repairPredicate===null?'overall':'store','object_term'=>'','operation'=>'summary','metric_codes'=>['consume_amount'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized','unresolved_fragments'=>[]];
       return ['intent'=>$complete($intent),'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   }
   if ($view['question']==='做得最好的门店是哪家（专业首答）') return ['intent'=>$complete(['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'recommended_initial_answer'=>true,'initial_observation'=>false,'ranking'=>['direction'=>'top','limit'=>1],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized','requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]],'unresolved_fragments'=>[]]),'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if ($view['question']==='哪个门店业绩最高（统一默认入口）') return ['intent'=>$complete(['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'recommended_initial_answer'=>false,'initial_observation'=>false,'ranking'=>['direction'=>'top','limit'=>1],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized','requirement_bindings'=>[],'unresolved_fragments'=>[]]),'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if ($view['question']==='哪个门店消耗业绩最高（明确指标）') return ['intent'=>$complete(['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>['consume_amount'],'action_codes'=>[],'needs_metric_choice'=>false,'recommended_initial_answer'=>false,'initial_observation'=>false,'ranking'=>['direction'=>'top','limit'=>1],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized','requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['consume_amount']]],'unresolved_fragments'=>[]]),'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if ($view['question']==='这个月的销售额最高是哪天（日期极值）') return ['intent'=>$complete(['object_kind'=>'business_date','object_term'=>'','object_relation'=>'analysis','operation'=>'ranking','metric_codes'=>['sales_amount'],'action_codes'=>[],'needs_metric_choice'=>false,'recommended_initial_answer'=>false,'initial_observation'=>false,'ranking'=>['direction'=>'top','limit'=>1],'periods'=>[['kind'=>'month_offset','offset_months'=>0]],'scope'=>'authorized','requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['sales_amount']]],'unresolved_fragments'=>[]]),'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if (in_array($view['question'],['哪些门店业绩好（候选恢复）','哪些门店业绩好（注册默认）','按现金业绩或消耗业绩，哪些门店排名更好（需要澄清）'],true)) return ['intent'=>$complete(['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>['cash_performance','consume_amount'],'action_codes'=>[],'needs_metric_choice'=>true,'recommended_initial_answer'=>false,'initial_observation'=>false,'ranking'=>['direction'=>'top','limit'=>null],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized','unresolved_fragments'=>[]]),'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   if ($view['question']==='今天整体经营怎么样（概览恢复）') {
       if ($repairPredicate==='open_overview_candidate') return ['intent'=>$complete(['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['cash_performance','actual_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'initial_observation'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized','requirement_bindings'=>[],'unresolved_fragments'=>[]]),'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
       return ['intent'=>$complete(['object_kind'=>'unknown','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized','unresolved_fragments'=>[]]),'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
   }
   $legacy=(new \app\services\ai\model\AiModelInputProjector())->modelView(['question'=>$view['question'],'history'=>[]]);$signals=$legacy['current']['signals'];$shape='summary';foreach(['trend','ranking','comparison'] as $candidate)if(in_array($candidate,$signals,true))$shape=$candidate;if(in_array('top_5',$signals,true)||in_array('bottom_5',$signals,true))$shape='ranking';$metrics=array_values(array_intersect(['cash_performance','consume_amount'],$signals));$dates=[];if(in_array('TODAY',$signals,true))$dates=[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]];return ['intent'=>$complete(['object_kind'=>'store','object_term'=>'','operation'=>$shape,'metric_codes'=>$metrics,'action_codes'=>[],'needs_metric_choice'=>$metrics===[],'ranking'=>$shape==='ranking'?['direction'=>'top','limit'=>5]:['direction'=>'unspecified','limit'=>null],'periods'=>$dates,'scope'=>'authorized','unresolved_fragments'=>[]]),'usage'=>['input_tokens'=>20,'output_tokens'=>10]];};
 $transaction=function($callback)use(&$queries){$queries++;return $callback(new GroupPerformanceMetricReadServices(function($table){return new GatewayFactFixture($table);},function(){}));};
 $gateway=new AiGatewayServices($runs,$config,$private,'fixture.instance',$views,$model,$transaction);
 $auth=['terminal'=>'store','account_id'=>7,'tenant_id'=>'0','can_use'=>true,'can_configure'=>true,'permission_version'=>'v1','scope_mode'=>'stores','store_ids'=>[1],'personnel_data_authorized'=>false,'report_capability_code'=>'group_management_dashboard'];
 $context=$auth;$context['_refresh']=function()use(&$auth){return $auth;};
 $boot=$gateway->handle('bootstrap',$context,['client_session_id'=>'device1']);
 verifyGateway($boot['enabled'] && $boot['history_round_limit']===20,'bootstrap enabled +20');
 $storeMetrics=[];foreach(\app\services\query\metric\MetricReadViewServices::metricCapabilities() as $code=>$contract)if(($contract['filter_grain']??null)!=='person'&&($contract['ai_query_ready']??false)===true)$storeMetrics[]=$code;
 verifyGateway($boot['capabilities']['metric_codes']===$storeMetrics,'all query-ready non-person metrics exposed from the shared catalog');
 verifyGateway(!isset($boot['api_key']),'bootstrap never key');
 // A dedicated conversation lets boundary scenarios coexist without making
 // this integration harness itself exceed the product's per-conversation cap.
 $make=function($request,$question,$history=[],$conversation='conversation1')use($gateway,$context,$boot){$input=['client_request_id'=>$request,'conversation_id'=>$conversation,'client_session_id'=>'device1','window_token'=>$boot['window_token'],'question'=>$question,'history'=>$history,'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2'];return [$gateway->handle('create',$context,$input),$input];};
 $binding=function($run){return ['client_session_id'=>'device1','generation'=>$run['generation'],'run_delivery_token'=>$run['run_delivery_token']];};
 $clarify=function($run,$waiting,$choices,$id)use($gateway,$context,$binding){$c=$waiting['clarification'];return $gateway->handle('clarify',$context,$binding($run)+['schema_version'=>'mohe-clarification-v2','clarification_id'=>$c['id'],'step_revision'=>$c['step_revision'],'intent_revision'=>$c['intent_revision'],'client_submission_id'=>$id,'choices'=>$choices],$run['run_id']);};
 [$run,$input]=$make('request1','今天消耗业绩多少？',[['question'=>'历史测试问题','answer'=>'TRANSCRIPT_SECRET_DO_NOT_STORE']]);
 verifyGateway($run['status']==='RECEIVED','create reserved');
 $result=$gateway->handle('execute',$context,$binding($run)+$input,$run['run_id']);
 verifyGateway($result['status']==='COMPLETED','create execute complete reason='.($result['reason']??''));
 verifyGateway(($result['answer']['cards']??null)===[] && strpos((string)$result['answer']['summary'],'消耗业绩为123元')===0,
     'deterministic cents display appears once in the verified conclusion');
 $tooltip=(new app\services\metric\MetricDictionaryServices())->getTooltip('consume_amount');
 verifyGateway(is_array($tooltip) && isset($tooltip['summary'],$tooltip['include'],$tooltip['exclude'],$tooltip['timing'],$tooltip['note']),
     'complete natural language metric tooltip remains available from the dictionary');
 verifyGateway($models===0 && $queries===1,'complete registered summary reaches the shared Reader without model stages');
 verifyGateway((int)$db->query('SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id='.$db->quote($run['run_id'])." AND kind='model'")->fetchColumn()===0,
     'deterministic summary records no hidden model attempt');
 $repeat=$gateway->handle('execute',$context,$binding($run)+$input,$run['run_id']);
 verifyGateway($repeat['status']==='COMPLETED' && $models===0 && $queries===1,'idempotent execute no duplicates');
 $delivered=$gateway->handle('status',$context,$binding($result),$result['run_id']);
 verifyGateway($delivered['status']==='COMPLETED' && strpos((string)$delivered['answer']['summary'],'消耗业绩为123元')===0,
     'completed answer delivery validates the immutable signed view without starting another query');
 $deliveryScope=$auth['store_ids'];$auth['store_ids']=[2];
 rejectGateway(function()use($gateway,$context,$binding,$result){$gateway->handle('status',$context,$binding($result),$result['run_id']);},'AI_AUTHORIZATION_CHANGED');
 $auth['store_ids']=$deliveryScope;
 [$professionalRank,$professionalRankInput]=$make('professional-ranking','做得最好的门店是哪家（专业首答）');$modelsBeforeProfessionalRank=$models;$queriesBeforeProfessionalRank=$queries;
 $professionalRankResult=$gateway->handle('execute',$context,$binding($professionalRank)+$professionalRankInput,$professionalRank['run_id']);
 verifyGateway($professionalRankResult['status']==='COMPLETED' && $models===$modelsBeforeProfessionalRank+3 && $queries===$queriesBeforeProfessionalRank+1,
     'a broad ranking completes with one reviewed professional first metric instead of failing on redundant non-metric audit bookkeeping');
 // Keep the two convergence regressions on an isolated synthetic principal;
 // they must not consume the primary fixture's real per-account run budget.
 $defaultAuth=$auth;$defaultAuth['account_id']=9;$defaultContext=$defaultAuth;
 $defaultContext['_refresh']=function()use(&$defaultAuth){return $defaultAuth;};
 $defaultBoot=$gateway->handle('bootstrap',$defaultContext,['client_session_id'=>'device-rank-default']);
 $makeDefault=function(string $request,string $question)use($gateway,$defaultContext,$defaultBoot):array {
     $input=['client_request_id'=>$request,'conversation_id'=>'conversation-rank-default','client_session_id'=>'device-rank-default',
         'window_token'=>$defaultBoot['window_token'],'question'=>$question,'history'=>[],'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2'];
     return [$gateway->handle('create',$defaultContext,$input),$input];
 };
 [$unmarkedRank,$unmarkedRankInput]=$makeDefault('unmarked-professional-ranking','哪个门店业绩最高（统一默认入口）');$queriesBeforeUnmarkedRank=$queries;
 $unmarkedRankBinding=['client_session_id'=>'device-rank-default','generation'=>$unmarkedRank['generation'],'run_delivery_token'=>$unmarkedRank['run_delivery_token']];
 $unmarkedRankResult=$gateway->handle('execute',$defaultContext,$unmarkedRankBinding+$unmarkedRankInput,$unmarkedRank['run_id']);
 verifyGateway($unmarkedRankResult['status']==='COMPLETED' && $queries===$queriesBeforeUnmarkedRank+1,
     'a direct broad ranking converges on the registry default even when the first binding omitted its recommendation marker');
 verifyGateway(strpos((string)$db->query('SELECT counters_json FROM mohe_ai_run WHERE run_id='.$db->quote($unmarkedRank['run_id']))->fetchColumn(),'registered_rank_default_applied')!==false,
     'the common post-merge default boundary is auditable for direct bindings');
 [$explicitRank,$explicitRankInput]=$makeDefault('explicit-ranking-metric','哪个门店消耗业绩最高（明确指标）');$queriesBeforeExplicitRank=$queries;
 $explicitRankBinding=['client_session_id'=>'device-rank-default','generation'=>$explicitRank['generation'],'run_delivery_token'=>$explicitRank['run_delivery_token']];
 $explicitRankResult=$gateway->handle('execute',$defaultContext,$explicitRankBinding+$explicitRankInput,$explicitRank['run_id']);
 verifyGateway($explicitRankResult['status']==='COMPLETED' && $queries===$queriesBeforeExplicitRank+1,
     'an explicit registered ranking metric still executes without being replaced by the broad default');
 verifyGateway(strpos((string)$db->query('SELECT counters_json FROM mohe_ai_run WHERE run_id='.$db->quote($explicitRank['run_id']))->fetchColumn(),'registered_rank_default_applied')===false,
     'the broad default leaves exact registered customer measurements untouched');
 [$dateRank,$dateRankInput]=$makeDefault('business-date-ranking','这个月的销售额最高是哪天（日期极值）');$modelsBeforeDateRank=$models;$queriesBeforeDateRank=$queries;
 $dateRankBinding=['client_session_id'=>'device-rank-default','generation'=>$dateRank['generation'],'run_delivery_token'=>$dateRank['run_delivery_token']];
 $dateRankResult=$gateway->handle('execute',$defaultContext,$dateRankBinding+$dateRankInput,$dateRank['run_id']);
 verifyGateway($dateRankResult['status']==='COMPLETED' && $queries===$queriesBeforeDateRank+1 && $models===$modelsBeforeDateRank+2,
     'a model-understood business-date extremum uses one understanding, one exact binding and one Reader call');
 verifyGateway(($dateRankResult['answer']['table']['columns'][0]['label']??null)==='日期'
     && preg_match('/^2026-09-[0-9]{2}$/D',(string)($dateRankResult['answer']['table']['rows'][0]['label']??''))===1,
     'the complete question returns a date rather than a store ranking');
 [$overviewRecovery,$overviewRecoveryInput]=$make('overview-recovery','今天整体经营怎么样（概览恢复）');$modelsBeforeOverviewRecovery=$models;$queriesBeforeOverviewRecovery=$queries;
 $overviewRecoveryResult=$gateway->handle('execute',$context,$binding($overviewRecovery)+$overviewRecoveryInput,$overviewRecovery['run_id']);
 verifyGateway($overviewRecoveryResult['status']==='COMPLETED' && $models===$modelsBeforeOverviewRecovery+4 && $queries===$queriesBeforeOverviewRecovery+1,
     'an understood broad store summary repairs a stale metric selector into one reviewed registry overview');
verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($overviewRecovery['run_id'])." AND attempt_code='bind_candidate_repair' AND state='SUCCEEDED'")->fetchColumn()===1,
    'open overview recovery is one bounded, auditable model correction');
 // Keep this added path on its own synthetic principal so it does not alter
 // the original fixture's account-level admission budget.
 $typedOverviewAuth=$auth;$typedOverviewAuth['account_id']=9;$typedOverviewContext=$typedOverviewAuth;
 $typedOverviewContext['_refresh']=function()use(&$typedOverviewAuth){return $typedOverviewAuth;};
 $typedOverviewBoot=$gateway->handle('bootstrap',$typedOverviewContext,['client_session_id'=>'device-typed-overview']);
 $typedOverviewInput=['client_request_id'=>'typed-overview-fast-path','conversation_id'=>'conversation-typed-overview',
     'client_session_id'=>'device-typed-overview','window_token'=>$typedOverviewBoot['window_token'],
     'question'=>'这个月经营情况如何（确定性概览）','history'=>[],'output_format'=>'screen',
     'guidance_schema_version'=>'mohe-clarification-v2'];
 $typedOverviewRun=$gateway->handle('create',$typedOverviewContext,$typedOverviewInput);
 $typedOverviewBinding=['client_session_id'=>'device-typed-overview','generation'=>$typedOverviewRun['generation'],
     'run_delivery_token'=>$typedOverviewRun['run_delivery_token']];
 $modelsBeforeTypedOverview=$models;$queriesBeforeTypedOverview=$queries;
 $typedOverviewResult=$gateway->handle('execute',$typedOverviewContext,$typedOverviewBinding+$typedOverviewInput,$typedOverviewRun['run_id']);
 verifyGateway($typedOverviewResult['status']==='COMPLETED'
     &&$models===$modelsBeforeTypedOverview+1&&$queries===$queriesBeforeTypedOverview+1,
     'a fully typed open overview uses one understanding model call and one registered Reader query');
 verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($typedOverviewRun['run_id'])." AND attempt_code IN ('bind_intent','review_binding')")->fetchColumn()===0,
     'the strict overview admission does not repeat binding or semantic review');
 verifyGateway(strpos((string)$db->query('SELECT counters_json FROM mohe_ai_run WHERE run_id='.$db->quote($typedOverviewRun['run_id']))->fetchColumn(),'registered_open_overview_admitted')!==false,
     'the typed overview shortcut is observable without storing customer wording');
 $comparisonInput=$typedOverviewInput;
 $comparisonInput['client_request_id']='typed-overview-comparison';
 $comparisonInput['conversation_id']='conversation-typed-overview-comparison';
 $comparisonInput['question']='9月8日经营情况对比9月7日（确定性比较）';
 $comparisonRun=$gateway->handle('create',$typedOverviewContext,$comparisonInput);
 $comparisonBinding=['client_session_id'=>'device-typed-overview','generation'=>$comparisonRun['generation'],
     'run_delivery_token'=>$comparisonRun['run_delivery_token']];
 $modelsBeforeComparison=$models;$queriesBeforeComparison=$queries;
 $comparisonResult=$gateway->handle('execute',$typedOverviewContext,$comparisonBinding+$comparisonInput,$comparisonRun['run_id']);
 verifyGateway($comparisonResult['status']==='COMPLETED'
     &&$models===$modelsBeforeComparison+1&&$queries===$queriesBeforeComparison+1,
     'a typed multi-indicator comparison uses one understanding call and one registered Reader query');
 verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($comparisonRun['run_id'])." AND attempt_code IN ('bind_intent','review_binding')")->fetchColumn()===0,
     'typed overview comparison does not repeat model binding or review');
 verifyGateway(strpos((string)$db->query('SELECT counters_json FROM mohe_ai_run WHERE run_id='.$db->quote($comparisonRun['run_id']))->fetchColumn(),'registered_overview_comparison_admitted')!==false,
     'typed comparison admission is observable without retaining customer wording');
 $splitComparisonInput=$comparisonInput;
 $splitComparisonInput['client_request_id']='split-overview-comparison';
 $splitComparisonInput['conversation_id']='conversation-split-overview-comparison';
 $splitComparisonInput['question']='9月10日经营情况对比9月9日（拆分语义）';
 $splitComparisonRun=$gateway->handle('create',$typedOverviewContext,$splitComparisonInput);
 $splitComparisonBinding=['client_session_id'=>'device-typed-overview','generation'=>$splitComparisonRun['generation'],
     'run_delivery_token'=>$splitComparisonRun['run_delivery_token']];
 $modelsBeforeSplit=$models;$queriesBeforeSplit=$queries;
 $splitComparisonResult=$gateway->handle('execute',$typedOverviewContext,$splitComparisonBinding+$splitComparisonInput,$splitComparisonRun['run_id']);
 verifyGateway($splitComparisonResult['status']==='COMPLETED'
     &&$models===$modelsBeforeSplit+1&&$queries===$queriesBeforeSplit+1,
     'a typed comparison with separately grounded periods also avoids redundant binding');
 // The existing local semantic slots prove this complete broad comparison;
 // real model variability must not send it through three repair stages.
 $directAuth=$auth;$directAuth['account_id']=11;$directContext=$directAuth;
 $directContext['_refresh']=function()use(&$directAuth){return $directAuth;};
 $directBoot=$gateway->handle('bootstrap',$directContext,['client_session_id'=>'device-direct-comparison']);
 $directInput=['client_request_id'=>'direct-overview-comparison','conversation_id'=>'conversation-direct-comparison',
     'client_session_id'=>'device-direct-comparison','window_token'=>$directBoot['window_token'],
     'question'=>'9月8日经营情况对比9月7日','history'=>[],'output_format'=>'screen',
     'guidance_schema_version'=>'mohe-clarification-v2'];
 $directRun=$gateway->handle('create',$directContext,$directInput);
 $directBinding=['client_session_id'=>'device-direct-comparison','generation'=>$directRun['generation'],
     'run_delivery_token'=>$directRun['run_delivery_token']];
 $modelsBeforeDirect=$models;$queriesBeforeDirect=$queries;
 $directResult=$gateway->handle('execute',$directContext,$directBinding+$directInput,$directRun['run_id']);
 verifyGateway($directResult['status']==='COMPLETED'
     &&$models===$modelsBeforeDirect&&$queries===$queriesBeforeDirect+1,
     'a structurally complete broad comparison uses the registered profile without a model call');
 verifyGateway(strpos((string)$db->query('SELECT counters_json FROM mohe_ai_run WHERE run_id='.$db->quote($directRun['run_id']))->fetchColumn(),'deterministic_registered_comparison_admitted')!==false,
     'deterministic comparison remains auditable without storing customer wording');
 $directMethod=new ReflectionMethod(AiGatewayServices::class,'compileRegisteredComparison');
 $directCaps=(new ReflectionMethod(AiGatewayServices::class,'capabilities'))->invoke($gateway,$directContext);
 // Exact named measurements can use the same safe path, including a single
 // metric and mixed money/count metrics; no broad profile is invented.
 foreach (['9月8日现金业绩对比9月7日'=>1,'9月8日现金业绩和销售数量对比9月7日'=>2] as $namedQuestion=>$expectedCount) {
     $namedPlan=$directMethod->invoke($gateway,$namedQuestion,$directCaps,'screen','2026-09-08');
     verifyGateway(($namedPlan['kind']??null)==='plan'&&count((array)($namedPlan['plan']['query']['metric_codes']??[]))===$expectedCount,
         'exact named comparison compiles the requested metric count through the registered planner');
 }
 foreach (['9月8日经营情况和销售额对比9月7日','9月8日员工业绩对比9月7日','9月8日经营情况对比9月7日，排除退款'] as $unsafeQuestion) {
     verifyGateway($directMethod->invoke($gateway,$unsafeQuestion,$directCaps,'screen','2026-09-08')===null,
         'mixed broad-and-named wording, personnel and exclusions cannot enter deterministic comparison');
 }
 [$rankRecovery,$rankRecoveryInput]=$make('rank-candidate-recovery','哪些门店业绩好（候选恢复）');$modelsBeforeRankRecovery=$models;$queriesBeforeRankRecovery=$queries;
 $rankRecoveryResult=$gateway->handle('execute',$context,$binding($rankRecovery)+$rankRecoveryInput,$rankRecovery['run_id']);
 verifyGateway($rankRecoveryResult['status']==='COMPLETED' && $models===$modelsBeforeRankRecovery+4 && $queries===$queriesBeforeRankRecovery+1,'multiple model candidates for one ranking receive one named professional metric-selection recovery before the Reader query');
 verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($rankRecovery['run_id'])." AND attempt_code='bind_intent' AND state='FAILED'")->fetchColumn()===1,'non-executable multi-metric binding remains auditable as failed rather than silently overwritten');
 verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($rankRecovery['run_id'])." AND attempt_code='bind_rank_metric_choice' AND state='SUCCEEDED'")->fetchColumn()===1,'model-selected ranking metric is recorded as an independent bounded recovery attempt');
 [$rankDefault,$rankDefaultInput]=$make('rank-candidate-default','哪些门店业绩好（注册默认）');$queriesBeforeRankDefault=$queries;
 $rankDefaultResult=$gateway->handle('execute',$context,$binding($rankDefault)+$rankDefaultInput,$rankDefault['run_id']);
 verifyGateway($rankDefaultResult['status']==='COMPLETED' && $queries===$queriesBeforeRankDefault+1,
     'a registered broad-ranking default completes when the bounded model asks to clarify');
 verifyGateway(strpos((string)$db->query('SELECT counters_json FROM mohe_ai_run WHERE run_id='.$db->quote($rankDefault['run_id']))->fetchColumn(),'registered_rank_default_applied')!==false,
     'the registry default is auditable without retaining customer text');
 // Use an isolated synthetic principal as well as a separate conversation so
 // this safety boundary does not consume the primary fixture's real 20-run
 // admission budget and accidentally weaken the rate-limit regression below.
 $clarifyAuth=$auth;$clarifyAuth['account_id']=8;$clarifyContext=$clarifyAuth;
 $clarifyContext['_refresh']=function()use(&$clarifyAuth){return $clarifyAuth;};
 $clarifyBoot=$gateway->handle('bootstrap',$clarifyContext,['client_session_id'=>'device-rank-clarify']);
 $rankClarifyInput=['client_request_id'=>'rank-candidate-clarify','conversation_id'=>'conversation-rank-clarify','client_session_id'=>'device-rank-clarify',
     'window_token'=>$clarifyBoot['window_token'],'question'=>'按现金业绩或消耗业绩，哪些门店排名更好（需要澄清）','history'=>[],
     'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2'];
 $rankClarify=$gateway->handle('create',$clarifyContext,$rankClarifyInput);$queriesBeforeRankClarify=$queries;
 $rankClarifyBinding=['client_session_id'=>'device-rank-clarify','generation'=>$rankClarify['generation'],'run_delivery_token'=>$rankClarify['run_delivery_token']];
 $rankClarifyResult=$gateway->handle('execute',$clarifyContext,$rankClarifyBinding+$rankClarifyInput,$rankClarify['run_id']);
 verifyGateway($rankClarifyResult['status']==='WAITING_CLARIFICATION' && $queries===$queriesBeforeRankClarify,
     'explicit ranking alternatives remain customer-visible and never execute a registered default status='.
     ($rankClarifyResult['status']??'missing').' reason='.($rankClarifyResult['reason']??'').' query_delta='.($queries-$queriesBeforeRankClarify));
 verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($rankClarify['run_id'])." AND attempt_code='bind_rank_metric_choice' AND state='SUCCEEDED'")->fetchColumn()===1,
     'the genuine ranking clarification remains one bounded and auditable model decision');
 verifyGateway($gateway->handle('cancel',$clarifyContext,$rankClarifyBinding,$rankClarify['run_id'])['status']==='CANCELLED',
     'the isolated clarification fixture releases its active conversation before later capacity checks');
 // Additional business wording deliberately falls through the narrow direct
 // admission, preserving the model transport's unknown-result safety test.
 $unknownBindingFailure=true;[$unknownBinding,$unknownBindingInput]=$make('binding-result-unknown','今天消耗业绩多少？请解释一下');$modelsBeforeUnknownBinding=$models;$queriesBeforeUnknownBinding=$queries;
 $unknownBindingResult=$gateway->handle('execute',$context,$binding($unknownBinding)+$unknownBindingInput,$unknownBinding['run_id']);
 verifyGateway($unknownBindingResult['status']==='FAILED' && $unknownBindingResult['reason']==='AI_MODEL_RESULT_UNKNOWN'
     && $unknownBindingResult['progress']==='模型响应超时，本次尚未执行数据查询。您可以直接重试，无需重新描述问题。','an unknown binding result stops after one full model attempt with an accurate no-query explanation');
 verifyGateway($models===$modelsBeforeUnknownBinding+2 && $queries===$queriesBeforeUnknownBinding,'an unknown binding result is never automatically replayed or sent to the Reader');
 verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($unknownBinding['run_id'])." AND attempt_code='bind_intent' AND state='UNKNOWN'")->fetchColumn()===1,'unknown binding remains auditable');
 verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($unknownBinding['run_id'])." AND attempt_code='bind_transport_recovery'")->fetchColumn()===0,'unknown binding creates no second transport recovery attempt');
 $unknownUnderstandingFailures=1;[$unknownUnderstanding,$unknownUnderstandingInput]=$make('understand-result-unknown','今天消耗业绩多少？请解释一下');$queriesBeforeUnknownUnderstanding=$queries;
 $unknownUnderstandingResult=$gateway->handle('execute',$context,$binding($unknownUnderstanding)+$unknownUnderstandingInput,$unknownUnderstanding['run_id']);
 verifyGateway($unknownUnderstandingResult['status']==='FAILED' && $unknownUnderstandingResult['reason']==='AI_MODEL_RESULT_UNKNOWN'
     && $queries===$queriesBeforeUnknownUnderstanding,
     'an unknown understanding result stops before the fact query without a second short transport attempt');
 verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($unknownUnderstanding['run_id'])." AND attempt_code='understand_meaning' AND state='UNKNOWN' AND input_tokens IS NULL AND output_tokens IS NULL")->fetchColumn()===1,
     'the unusable read-only response preserves unknown usage and its timeout diagnostic');
 verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($unknownUnderstanding['run_id'])." AND attempt_code='understand_transport_retry'")->fetchColumn()===0,
     'the large understanding prompt is not replayed inside a shorter timeout window');
 verifyGateway(is_string($repeat['progress']),'frontend progress string');
 [$repairRun,$repairInput]=$make('repair-current','今天消耗业绩格式修复');$modelsBeforeRepair=$models;$queriesBeforeRepair=$queries;
 $repairResult=$gateway->handle('execute',$context,$binding($repairRun)+$repairInput,$repairRun['run_id']);
    verifyGateway($repairResult['status']==='COMPLETED' && $models===$modelsBeforeRepair+2 && $queries===$queriesBeforeRepair+1,'an omitted empty bookkeeping list completes without a model retry or a changed business binding');
    verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($repairRun['run_id'])." AND attempt_code='bind_repair' AND state='SUCCEEDED'")->fetchColumn()===0,'empty bookkeeping omission does not consume the bounded binding-recovery budget');
    [$objectCarrierRepairRun,$objectCarrierRepairInput]=$make('repair-object-carrier','今天消耗业绩对象载体修复');$modelsBeforeObjectCarrierRepair=$models;$queriesBeforeObjectCarrierRepair=$queries;
    $objectCarrierRepairResult=$gateway->handle('execute',$context,$binding($objectCarrierRepairRun)+$objectCarrierRepairInput,$objectCarrierRepairRun['run_id']);
    verifyGateway($objectCarrierRepairResult['status']==='COMPLETED' && $models===$modelsBeforeObjectCarrierRepair+3 && $queries===$queriesBeforeObjectCarrierRepair+1,'an invalid model enum carrier receives one model-owned binding repair without server-side semantic substitution');
    verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($objectCarrierRepairRun['run_id'])." AND attempt_code='bind_repair' AND state='SUCCEEDED'")->fetchColumn()===1,'enum carrier repair is separately recorded and bounded');
    [$meaningRepairRun,$meaningRepairInput]=$make('repair-meaning','今天消耗业绩理解修复');$modelsBeforeMeaningRepair=$models;$queriesBeforeMeaningRepair=$queries;
    $meaningRepairResult=$gateway->handle('execute',$context,$binding($meaningRepairRun)+$meaningRepairInput,$meaningRepairRun['run_id']);
    verifyGateway($meaningRepairResult['status']==='COMPLETED' && $models===$modelsBeforeMeaningRepair+3 && $queries===$queriesBeforeMeaningRepair+1,'a missing typed meaning receives one model-owned repair before binding');
    verifyGateway((int)$db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$db->quote($meaningRepairRun['run_id'])." AND attempt_code='understand_repair' AND state='SUCCEEDED'")->fetchColumn()===1,'typed semantic carrier repair is auditable and bounded');
 [$unbound,$unboundInput]=$make('unbound-goal','查看尚未登记的经营目标');$queriesBefore=$queries;
 $unboundResult=$gateway->handle('execute',$context,$binding($unbound)+$unboundInput,$unbound['run_id']);
 verifyGateway($unboundResult['status']==='FAILED' && $unboundResult['reason']==='AI_ANALYSIS_COMBINATION_UNAVAILABLE','understood but unregistered goal is a capability gap');
 verifyGateway($queries===$queriesBefore,'unregistered goal never substitutes a nearby metric or reads facts');
 [$ask,$askInput]=$make('request2','业绩多少？');
 $waiting=$gateway->handle('execute',$context,$binding($ask)+$askInput,$ask['run_id']);
 verifyGateway($waiting['status']==='WAITING_CLARIFICATION' && count($waiting['clarification']['fields'])===1,'v2 asks metric only');
 // The trusted current period is already available, so the only material
 // ambiguity is the metric. Resolving it must complete without a redundant
 // second date question.
 $answered=$clarify($ask,$waiting,['metric_code'=>'consume_amount'],'metric-answer');
 verifyGateway($answered['status']==='COMPLETED','metric clarification resumes complete status='.($answered['status']??'').' reason='.($answered['reason']??''));
 verifyGateway((int)$db->query("SELECT clarification_count FROM mohe_ai_run WHERE run_id=".$db->quote($ask['run_id']))->fetchColumn()===1,'one material semantic question budget');
 [$cancel,$cancelInput]=$make('request3','今天消耗业绩多少？');$before=$models;
 verifyGateway($gateway->handle('cancel',$context,$binding($cancel),$cancel['run_id'])['status']==='CANCELLED','cancel received');
 verifyGateway($gateway->handle('execute',$context,$binding($cancel)+$cancelInput,$cancel['run_id'])['status']==='CANCELLED' && $models===$before,'cancel before execute no model');
 [$bad,$badInput]=$make('request4','今天消耗业绩多少？');$changed=$badInput;$changed['question']='昨天消耗业绩多少？';
 rejectGateway(function()use($gateway,$context,$binding,$bad,$changed){$gateway->handle('execute',$context,$binding($bad)+$changed,$bad['run_id']);},'AI_IDEMPOTENCY_CONFLICT');
 $gateway->handle('cancel',$context,$binding($bad),$bad['run_id']);
 foreach (['trend'=>'今天消耗业绩趋势','ranking'=>'今天消耗业绩前五'] as $shape=>$question) {
   [$shapeRun,$shapeInput]=$make('shape-'.$shape,$question);
   $shapeResult=$gateway->handle('execute',$context,$binding($shapeRun)+$shapeInput,$shapeRun['run_id']);
   verifyGateway($shapeResult['status']==='COMPLETED' && count($shapeResult['answer']['table']['rows'])===1,$shape.' complete table reason='.($shapeResult['reason']??''));
 }
 [$compare,$compareInput]=$make('comparison','今天消耗业绩对比');
 $compareWait=$gateway->handle('execute',$context,$binding($compare)+$compareInput,$compare['run_id']);
 verifyGateway($compareWait['status']==='WAITING_CLARIFICATION' && count($compareWait['clarification']['fields'])===2,'comparison asks exact other range once');
 $compareDone=$clarify($compare,$compareWait,['compare_start'=>'2026-09-01','compare_end'=>'2026-09-02'],'comparison-answer');
 verifyGateway($compareDone['status']==='COMPLETED' && ($compareDone['answer']['cards']??null)===[]
     && strpos((string)$compareDone['answer']['summary'],'对比期间为')!==false,'comparison two periods');
 foreach (['Q001'=>'今天收了多少钱？','Q002'=>'今天现金业绩多少？'] as $case=>$question) {
   [$cashRun,$cashInput]=$make($case,$question);
   $cashResult=$gateway->handle('execute',$context,$binding($cashRun)+$cashInput,$cashRun['run_id']);
  verifyGateway($cashResult['status']==='COMPLETED' && ($cashResult['answer']['cards']??null)===[]
      && strpos((string)$cashResult['answer']['summary'],'现金业绩为323元')===0,
      $case.' gross includes every registered cash source and does not deduct refunds');
 }
 [$permission,$permissionInput]=$make('request5','今天消耗业绩多少？');$auth['permission_version']='v2';
 $permissionResult=$gateway->handle('execute',$context,$binding($permission)+$permissionInput,$permission['run_id']);
 verifyGateway($permissionResult['status']==='FAILED' && $permissionResult['reason']==='AI_AUTHORIZATION_CHANGED','permission change terminalizes');
 verifyGateway((int)$db->query('SELECT slot_held FROM mohe_ai_run WHERE run_id='.$db->quote($permission['run_id']))->fetchColumn()===0,'permission failure releases physical slot');
 $auth['can_use']=false;$revoked=$auth;$revoked['_refresh']=$context['_refresh'];
 verifyGateway($gateway->handle('cancel',$revoked,$binding($permission),$permission['run_id'])['status']==='FAILED','revoked user can stop own run without changing existing terminal');
 $wrong=$context;$wrong['account_id']=8;
 rejectGateway(function()use($gateway,$wrong,$binding,$run){$gateway->handle('status',$wrong,$binding($run),$run['run_id']);},'AI_DELIVERY_INVALID');
 foreach (['mohe_ai_run','mohe_ai_receipt','mohe_ai_attempt','mohe_ai_config'] as $table) { $rows=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);$text=json_encode($rows,JSON_UNESCAPED_UNICODE);verifyGateway(strpos($text,'TRANSCRIPT_SECRET_DO_NOT_STORE')===false && strpos($text,'今天消耗业绩多少')===false,'no chat DB '.$table); }
 foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS)) as $file) if($file->isFile())verifyGateway(strpos(file_get_contents($file->getPathname()),'TRANSCRIPT_SECRET_DO_NOT_STORE')===false,'no transcript file');
 $passiveModels=$models; $passiveQueries=$queries;
 // R6 configuration is a platform-only authority, independent of the store Run fixture.
 $adminContext=$context;$adminContext['terminal']='platform';
 $adminCurrent=$adminContext;unset($adminCurrent['_refresh']);
 $adminContext['_refresh']=function()use(&$adminCurrent){return $adminCurrent;};
 $adminStatus=$gateway->handle('config_get',$adminContext,[]);
 verifyGateway(isset($adminStatus['runtime_status']['success']) && !isset($adminStatus['api_key']),'administrator passive runtime diagnostics without key');
 verifyGateway($adminStatus['runtime_status']['monitoring']['status']==='not_ready','unregistered monitor is not a healthy-instance assertion');
 verifyGateway($models===$passiveModels && $queries===$passiveQueries,'passive diagnostics neither model nor business query');
 // Store context cannot configure even if an obsolete fixture says it can.
 rejectGateway(function()use($gateway,$context){$gateway->handle('config_get',$context,[]);},'AI_PERMISSION_DENIED');
 $ordinary=$adminContext; $ordinary['can_configure']=false;
 $ordinaryCurrent=$ordinary;unset($ordinaryCurrent['_refresh']);
 $ordinary['_refresh']=function()use($ordinaryCurrent){return $ordinaryCurrent;};
 rejectGateway(function()use($gateway,$ordinary){$gateway->handle('config_get',$ordinary,[]);},'AI_PERMISSION_DENIED');
 $adminCurrent['can_configure']=false;
 rejectGateway(function()use($gateway,$adminContext){$gateway->handle('config_get',$adminContext,[]);},'AI_PERMISSION_DENIED');
 $probe=$config->beginProbe(1);
 rejectGateway(function()use($config){$config->beginProbe(1);},'AI_CHECK_RATE_LIMITED');
 $config->finishProbe($probe,'UNKNOWN');
 $probeRow=$db->query('SELECT * FROM mohe_ai_attempt WHERE run_id='.$db->quote($probe))->fetch(PDO::FETCH_ASSOC);
 verifyGateway($probeRow['target_code']==='siliconflow_probe' && $probeRow['state']==='UNKNOWN' && $probeRow['input_tokens']===null,'probe usage audited without zero-cost assumption');
 verifyGateway((int)$probeRow['expires_at']-(int)$probeRow['created_at']===86400000,'probe metadata has original 24h expiry');
 echo 'Gateway integration: '.$checks." checks PASS (SQLite + model/fact fixtures)\n";
} finally { foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($temp); }

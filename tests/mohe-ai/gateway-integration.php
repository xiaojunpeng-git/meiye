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
 'CREATE TABLE mohe_ai_config (instance_id TEXT PRIMARY KEY,enabled INTEGER,model TEXT,encrypted_key TEXT,external_authorized INTEGER,version INTEGER)'
] as $sql)$db->exec($sql);
$temp=sys_get_temp_dir().'/mohe-gateway-integration-'.bin2hex(random_bytes(8));mkdir($temp,0700);
try {
 $private=new AiPrivateStorage($temp);$views=new MetricReadViewStore($temp.'/views',$private->signingKey());
 $config=new AiConfigStore($db,'','fixture.instance',$private);
 $config->save(['enabled'=>true,'external_processing_authorized'=>true,'model'=>'fixture/model','api_key'=>'fixture-only-key','version'=>0]);
 $runs=new AiRunStore($db,'','fixture.instance');$models=0;$queries=0;
 $model=function($view,$candidates,$configuration,$checkpoint)use(&$models){$models++;$checkpoint();$signals=$view['current']['signals'];$shape='summary';foreach(['trend','ranking','comparison'] as $candidate)if(in_array($candidate,$signals,true))$shape=$candidate;if(in_array('top_5',$signals,true)||in_array('bottom_5',$signals,true))$shape='ranking';return ['selection'=>['metric_codes'=>array_values(array_intersect(['cash_performance','consume_amount'],$signals)),'query_shape'=>$shape,'decision'=>'query','date_code'=>'TODAY'],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];};
 $transaction=function($callback)use(&$queries){$queries++;return $callback(new GroupPerformanceMetricReadServices(function($table){return new GatewayFactFixture($table);},function(){}));};
 $gateway=new AiGatewayServices($runs,$config,$private,'fixture.instance',$views,$model,$transaction);
 $auth=['terminal'=>'store','account_id'=>7,'tenant_id'=>'0','can_use'=>true,'can_configure'=>true,'permission_version'=>'v1','scope_mode'=>'stores','store_ids'=>[1],'report_capability_code'=>'group_management_dashboard'];
 $context=$auth;$context['_refresh']=function()use(&$auth){return $auth;};
 $boot=$gateway->handle('bootstrap',$context,['client_session_id'=>'device1']);
 verifyGateway($boot['enabled'] && $boot['history_round_limit']===20,'bootstrap enabled +20');
 verifyGateway($boot['capabilities']['metric_codes']===['cash_performance','refund_performance','actual_performance','consume_amount','sales_amount','balance_deduction_amount','recharge_amount'],'all registered amount metrics exposed from the shared catalog');
 verifyGateway(!isset($boot['api_key']),'bootstrap never key');
 $make=function($request,$question,$history=[])use($gateway,$context,$boot){$input=['client_request_id'=>$request,'conversation_id'=>'conversation1','client_session_id'=>'device1','window_token'=>$boot['window_token'],'question'=>$question,'history'=>$history,'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2'];return [$gateway->handle('create',$context,$input),$input];};
 $binding=function($run){return ['client_session_id'=>'device1','generation'=>$run['generation'],'run_delivery_token'=>$run['run_delivery_token']];};
 $clarify=function($run,$waiting,$choices,$id)use($gateway,$context,$binding){$c=$waiting['clarification'];return $gateway->handle('clarify',$context,$binding($run)+['schema_version'=>'mohe-clarification-v2','clarification_id'=>$c['id'],'step_revision'=>$c['step_revision'],'intent_revision'=>$c['intent_revision'],'client_submission_id'=>$id,'choices'=>$choices],$run['run_id']);};
 [$run,$input]=$make('request1','今天消耗业绩多少？',[['question'=>'历史测试问题','answer'=>'TRANSCRIPT_SECRET_DO_NOT_STORE']]);
 verifyGateway($run['status']==='RECEIVED','create reserved');
 $result=$gateway->handle('execute',$context,$binding($run)+$input,$run['run_id']);
 verifyGateway($result['status']==='COMPLETED','create execute complete reason='.($result['reason']??''));
 verifyGateway($result['answer']['cards'][0]['display_value']==='123','deterministic cents display');
 $tooltip=$result['answer']['cards'][0]['tooltip'];
 verifyGateway(is_array($tooltip) && isset($tooltip['summary'],$tooltip['include'],$tooltip['exclude'],$tooltip['timing'],$tooltip['note']),'complete natural language metric tooltip');
 verifyGateway($models===1 && $queries===1,'one model one query');
 $repeat=$gateway->handle('execute',$context,$binding($run)+$input,$run['run_id']);
 verifyGateway($repeat['status']==='COMPLETED' && $models===1 && $queries===1,'idempotent execute no duplicates');
 verifyGateway(is_string($repeat['progress']),'frontend progress string');
 [$ask,$askInput]=$make('request2','业绩多少？');
 $waiting=$gateway->handle('execute',$context,$binding($ask)+$askInput,$ask['run_id']);
 verifyGateway($waiting['status']==='WAITING_CLARIFICATION' && count($waiting['clarification']['fields'])===1,'v2 asks metric only');
 $dateWait=$clarify($ask,$waiting,['metric_code'=>'consume_amount'],'metric-answer');
 verifyGateway($dateWait['status']==='WAITING_CLARIFICATION' && count($dateWait['clarification']['fields'])===2,'v2 then asks one date interval');
 $answered=$clarify($ask,$dateWait,['start_date'=>'2026-09-01','end_date'=>'2026-09-08'],'date-answer');
 verifyGateway($answered['status']==='COMPLETED','clarification resumes complete status='.($answered['status']??'').' reason='.($answered['reason']??''));
 verifyGateway((int)$db->query("SELECT clarification_count FROM mohe_ai_run WHERE run_id=".$db->quote($ask['run_id']))->fetchColumn()===2,'two semantic questions budget');
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
 verifyGateway($compareDone['status']==='COMPLETED' && count($compareDone['answer']['cards'])===2,'comparison two periods');
 foreach (['Q001'=>'今天收了多少钱？','Q002'=>'今天现金业绩多少？'] as $case=>$question) {
   [$cashRun,$cashInput]=$make($case,$question);
   $cashResult=$gateway->handle('execute',$context,$binding($cashRun)+$cashInput,$cashRun['run_id']);
   verifyGateway($cashResult['status']==='COMPLETED' && $cashResult['answer']['cards'][0]['metric_name']==='现金业绩' && $cashResult['answer']['cards'][0]['display_value']==='223',$case.' gross includes recharge and does not deduct refund');
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

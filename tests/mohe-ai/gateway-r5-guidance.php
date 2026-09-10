<?php
// Real SQLite state, gateway, registry and encrypted evidence; synthetic facts/model only.
require_once __DIR__.'/fixture-autoload.php';
use app\services\ai\AiGatewayServices;
use app\services\ai\config\AiConfigStore;
use app\services\ai\config\AiPrivateStorage;
use app\services\ai\execution\AiRunStore;
use app\services\query\metric\MetricReadViewStore;
use app\services\query\metric\GroupPerformanceMetricReadServices;
$GLOBALS['r5_rounds']=3;
function config($key,$default=null){return $key==='mohe_ai.max_clarification_rounds'?$GLOBALS['r5_rounds']:$default;}
$checks=0;
function r5check($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;}
function r5reject($call,$reason){try{$call();}catch(Throwable $e){r5check($e->getMessage()===$reason,$reason.' got '.$e->getMessage());return;}throw new RuntimeException('Missing '.$reason);}
class R5Facts {
 private $day='2026-09-01'; public function __call($name,$args){if($name==='whereExists')$args[0]($this);if($name==='whereBetween')$this->day=$args[1][0];return $this;}
 public function find(){return ['amount_cents'=>'12345','gross_cents'=>'12345','refund_cents'=>'0'];}
 public function column(){return [1=>'合成门店'];}
 public function toArray(){return [['store_id'=>'1','business_date'=>$this->day,'amount_cents'=>'12345','entity_id'=>'101','entity_name'=>'合成会员','metric_value'=>'12345']];}
}
class R5Harness {
 public $db,$private,$gateway,$auth,$context,$temp,$boot,$models=0,$queries=0,$sequence=0,$semanticIntent=null;
 public function __construct($rounds,$sanitized=false){
  $GLOBALS['r5_rounds']=$rounds;$this->db=new PDO('sqlite::memory:');
  foreach([
   'CREATE TABLE mohe_ai_mutex(instance_id TEXT PRIMARY KEY,quarantined_slots INTEGER NOT NULL DEFAULT 0)',
   'CREATE TABLE mohe_ai_receipt(instance_id TEXT,receipt_key TEXT,request_hash TEXT,window_id TEXT,run_id TEXT,reason TEXT,expires_at INTEGER,PRIMARY KEY(instance_id,receipt_key))',
   'CREATE TABLE mohe_ai_attempt(instance_id TEXT,run_id TEXT,attempt_code TEXT,kind TEXT,target_code TEXT,payload_hash TEXT,state TEXT,input_tokens INTEGER,output_tokens INTEGER,created_at INTEGER,expires_at INTEGER,PRIMARY KEY(instance_id,run_id,attempt_code))',
   'CREATE TABLE mohe_ai_run(instance_id TEXT,run_id TEXT,account_id INTEGER,terminal TEXT,conversation_id TEXT,window_id TEXT,generation INTEGER,status TEXT,reason TEXT,progress_code TEXT,clarification_ref TEXT,version INTEGER,created_at INTEGER,expires_at INTEGER,deadline_at INTEGER,last_clock_at INTEGER,remaining_ms INTEGER,pause_at INTEGER,clarification_count INTEGER,slot_held INTEGER,worker_token TEXT,evidence_ref TEXT,answer_ref TEXT,snapshot_json TEXT,counters_json TEXT,PRIMARY KEY(instance_id,run_id))',
   'CREATE TABLE mohe_ai_config(instance_id TEXT PRIMARY KEY,enabled INTEGER,model TEXT,encrypted_key TEXT,external_authorized INTEGER,external_scope_version TEXT,version INTEGER)'
  ]as$sql)$this->db->exec($sql);
  $this->temp=sys_get_temp_dir().'/mohe-r5-guidance-'.bin2hex(random_bytes(8));mkdir($this->temp,0700);
  $this->private=new AiPrivateStorage($this->temp);$config=new AiConfigStore($this->db,'','fixture.r5',$this->private);
  $configInput=['enabled'=>true,'external_processing_authorized'=>true,'model'=>'fixture/model','api_key'=>'synthetic-fixture-key','version'=>0];if($sanitized)$configInput['external_scope_version']=AiConfigStore::QUESTION_SCOPE;$config->save($configInput);
  $model=function($view,$candidates,$configuration,$checkpoint){$this->models++;$checkpoint();if(($view['schema_version']??null)==='sanitized-question-v2'){ $intent=$this->semanticIntent??['object_kind'=>'project','object_term'=>'项目','operation'=>'ranking','metric_codes'=>[],'action_codes'=>['service'],'needs_metric_choice'=>true];$intent['ranking']=$intent['ranking']??['direction'=>'top','limit'=>null];$intent['action_codes']=$intent['action_codes']??[];$intent['unresolved_fragments']=$intent['unresolved_fragments']??[];return ['intent'=>$intent,'usage'=>['input_tokens'=>20,'output_tokens'=>10]];}$s=$view['current']['signals'];$shape='summary';foreach(['trend','ranking','comparison']as$v)if(in_array($v,$s,true))$shape=$v;if(in_array('top_5',$s,true)||in_array('bottom_5',$s,true))$shape='ranking';if(($view['current']['semantic_intent']['goal']??'')==='metric_definition')$shape='definition';return ['selection'=>['metric_codes'=>array_values(array_intersect(['cash_performance','consume_amount'],$s)),'query_shape'=>$shape,'decision'=>'query','date_code'=>'TODAY'],'usage'=>['input_tokens'=>20,'output_tokens'=>10]];};
  $transaction=function($call){$this->queries++;return $call(new GroupPerformanceMetricReadServices(function(){return new R5Facts();},function(){}));};
  $this->gateway=new AiGatewayServices(new AiRunStore($this->db,'','fixture.r5'),$config,$this->private,'fixture.r5',new MetricReadViewStore($this->temp.'/views',$this->private->signingKey()),$model,$transaction);
  $this->auth=['terminal'=>'store','account_id'=>7,'tenant_id'=>'0','can_use'=>true,'can_configure'=>true,'permission_version'=>'v1','scope_mode'=>'stores','store_ids'=>[1],'report_capability_code'=>'group_management_dashboard'];
  $this->context=$this->auth;$this->context['_refresh']=function(){return $this->auth;};
  $this->boot=$this->gateway->handle('bootstrap',$this->context,['client_session_id'=>'device1']);
  r5check($this->boot['max_clarification_rounds']===$rounds,'frozen configured profile '.$rounds);
 }
 public function start($question,$sourceRef=null){$input=['client_request_id'=>'request'.(++$this->sequence),'conversation_id'=>'conversation1','client_session_id'=>'device1','window_token'=>$this->boot['window_token'],'question'=>$question,'history'=>[],'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2'];if($sourceRef!==null)$input['context_ref']=$sourceRef;$run=$this->gateway->handle('create',$this->context,$input);return $this->gateway->handle('execute',$this->context,$this->binding($run)+$input,$run['run_id']);}
 public function binding($run){return ['client_session_id'=>'device1','generation'=>$run['generation'],'run_delivery_token'=>$run['run_delivery_token']];}
 public function input($run,$choices,$id=null,$revise=null){$c=$run['clarification'];$input=$this->binding($run)+['schema_version'=>'mohe-clarification-v2','clarification_id'=>$c['id'],'step_revision'=>$c['step_revision'],'intent_revision'=>$c['intent_revision'],'client_submission_id'=>$id??'answer'.(++$this->sequence),'choices'=>$choices];if($revise!==null)$input['revise_clarification_id']=$revise;return $input;}
 public function submit($run,$input){return $this->gateway->handle('clarify',$this->context,$input,$run['run_id']);}
 public function choose($run,$choices,$revise=null){return $this->submit($run,$this->input($run,$choices,null,$revise));}
 public function row($run){return $this->db->query('SELECT * FROM mohe_ai_run WHERE run_id='.$this->db->quote($run['run_id']))->fetch(PDO::FETCH_ASSOC);}
 public function step($run,$key,$round){r5check($run['status']==='WAITING_CLARIFICATION','waiting '.json_encode($run));r5check($run['clarification']['fields'][0]['key']===$key,'step '.$key);r5check($run['clarification']['round_no']===$round,'round '.$round);r5check(!isset($run['clarification']['pending_fields']),'private pending fields not exposed');}
 public function close(){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($this->temp);}
}
$h=null;
try{
 $h=new R5Harness(3);
 $direct=$h->start('这个月收了多少钱');r5check($direct['status']==='COMPLETED','cash colloquial direct');r5check($h->models===1&&$h->queries===1,'direct one model one query');
 $row=$h->row($direct);$e=$h->private->read($row['evidence_ref']);r5check(!empty($e['execution_trace'])&&!empty($e['compiled_run_hash']),'registered execution trace and plan hash');
 r5check(array_column($e['execution_trace'],'handler')===['unified_metric_query','all_evidence_guard','deterministic_answer'],'complete query guard render trace in registry order');
 r5check(array_unique(array_column($e['execution_trace'],'status'))===['SUCCEEDED'],'every trace node succeeded');
 $view=(new MetricReadViewStore($h->temp.'/views',$h->private->signingKey()))->get($e['view_ref']);
 r5check((string)$view['results'][0]['amount_cents']==='24690'&&$direct['answer']['cards'][0]['display_value']==='247','evidence exact cents and deterministic rounding agree');
 $definition=$h->start('解释现金业绩');r5check($definition['status']==='COMPLETED','definition completes '.($definition['reason']??''));r5check($h->queries===1,'definition never queries business facts');r5check($definition['answer']['cards']===[],'definition no invented number');
 $de=$h->private->read($h->row($definition)['evidence_ref']);r5check(array_column($de['execution_trace'],'handler')===['metric_catalog_read','metadata_guard','deterministic_definition'],'complete metadata-only trace');r5check(!isset($de['view_ref'])&&!isset($de['query']),'definition has no business snapshot');
 $joint=$h->start('今天现金业绩和消耗业绩多少');r5check($joint['status']==='COMPLETED'&&count($joint['answer']['cards'])===2,'explicit joint metrics survive end to end');r5check(array_column($joint['answer']['cards'],'display_value')===['247','123'],'joint metric numeric bindings remain separate');
 $run=$h->start('本月哪几家店需要关注');$h->step($run,'metric_code',1);
 $invalid=$h->choose($run,['metric_code'=>'unregistered_metric']);$h->step($invalid,'metric_code',1);r5check($invalid['clarification']['id']===$run['clarification']['id'],'invalid value does not consume another semantic question');
 $first=$run;$input=$h->input($run,['metric_code'=>'consume_amount'],'same-answer');$run=$h->submit($run,$input);$h->step($run,'rank_direction',2);
 $repeat=$h->submit($first,$input);r5check($repeat['clarification']['id']===$run['clarification']['id'],'identical retry replays without new step');
 $changed=$input;$changed['choices']['metric_code']='cash_performance';r5reject(function()use($h,$first,$changed){$h->submit($first,$changed);},'AI_IDEMPOTENCY_CONFLICT');
 $stale=$input;$stale['client_submission_id']='stale-answer';r5reject(function()use($h,$first,$stale){$h->submit($first,$stale);},'AI_CLARIFICATION_STALE');
 $run=$h->choose($run,['rank_direction'=>'top']);$h->step($run,'rank_limit',3);$run=$h->choose($run,['rank_limit'=>'5']);r5check($run['status']==='COMPLETED','last allowed third answer executes');r5check((int)$h->row($run)['clarification_count']===3,'three issued questions');
 r5check($h->models===4,'three questions and invalid input do not add model calls');
 $run=$h->start('哪几家店需要关注');$run=$h->choose($run,['metric_code'=>'consume_amount']);$run=$h->choose($run,['start_date'=>'2026-09-01','end_date'=>'2026-09-08']);$run=$h->choose($run,['rank_direction'=>'top']);r5check($run['status']==='FAILED'&&$run['reason']==='AI_CLARIFICATION_EXHAUSTED','fourth question blocked at profile3');
 foreach(['今天员工消耗业绩'=>'AI_CAPABILITY_NOT_READY','今天消耗业绩前十'=>'AI_RANK_LIMIT_NOT_READY','今天消耗业绩神秘条件'=>'AI_INTENT_UNRESOLVED']as$q=>$reason){$before=$h->models;$run=$h->start($q);r5check($run['status']==='FAILED'&&$run['reason']===$reason,'accurate stop '.$reason.' got '.($run['reason']??''));r5check($h->models===$before,'blocked before model');}
 $run=$h->start('业绩多少');$before=$h->queries;$h->auth['permission_version']='v2';$run=$h->choose($run,['metric_code'=>'consume_amount']);r5check($run['status']==='FAILED'&&$run['reason']==='AI_AUTHORIZATION_CHANGED','mid guidance revocation');r5check($h->queries===$before,'revocation no facts');
 $h->close();$h=null;
 foreach([4,5]as$rounds){$h=new R5Harness($rounds);$run=$h->start('哪几家店需要关注');$h->step($run,'metric_code',1);$original=$run['clarification']['id'];$run=$h->choose($run,['metric_code'=>'cash_performance']);$h->step($run,'start_date',2);$run=$h->choose($run,['start_date'=>'2026-09-01','end_date'=>'2026-09-08']);$h->step($run,'rank_direction',3);
  if($rounds===5){$run=$h->choose($run,['metric_code'=>'consume_amount'],$original);$h->step($run,'rank_direction',4);r5check(strpos(json_encode($run['clarification']['confirmed_summary'],JSON_UNESCAPED_UNICODE),'消耗')!==false,'revision replaces metric and preserves date');}
  $run=$h->choose($run,['rank_direction'=>'top']);$h->step($run,'rank_limit',$rounds);$run=$h->choose($run,['rank_limit'=>'5']);r5check($run['status']==='COMPLETED','last allowed '.$rounds.' answer completes');r5check($h->models===1&&$h->queries===1,'guidance no additional model or premature facts');r5check($run['answer']['table']['rows'][0]['value']===($rounds===5?'123':'247'),'registered numeric result remains authoritative');$h->close();$h=null;
 }
 $h=new R5Harness(3,true);
 $h->semanticIntent=['object_kind'=>'project','object_term'=>'项目','operation'=>'ranking','metric_codes'=>['completed_service_item_count'],'action_codes'=>['service'],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>null]];
 $before=$h->queries;$correctedProject=$h->start('这个月完成服务最多的项目有哪些');
 r5check($correctedProject['status']==='COMPLETED'&&$h->queries===$before+1,'natural-language model meaning and registered service action execute without an unrelated metric');
 r5check($correctedProject['answer']['table']['columns'][0]['label']==='项目','registered service action renders the project dimension through the shared reader');
 $h->semanticIntent=['object_kind'=>'project','object_term'=>'项目','operation'=>'ranking','metric_codes'=>[],'action_codes'=>['sales'],'needs_metric_choice'=>true];
 $before=$h->queries;$project=$h->start('这个月卖得最好的项目');
 r5check($project['status']==='FAILED'&&$project['reason']==='AI_DIMENSION_ACTION_CONTRACT_NOT_READY'&&$h->queries===$before,'sales wording never substitutes the only registered project service metric');
 $before=$h->queries;$soldProject=$h->start('本月卖出最多的项目');
 r5check($soldProject['status']==='FAILED'&&$soldProject['reason']==='AI_DIMENSION_ACTION_CONTRACT_NOT_READY'&&$h->queries===$before,'published sold wording never substitutes the only registered project service metric');
 $h->semanticIntent=['object_kind'=>'member','object_term'=>'会员','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['service'],'needs_metric_choice'=>false];
 $before=$h->queries;$unsupportedMemberAction=$h->start('本月服务最多的会员');
 r5check($unsupportedMemberAction['status']==='FAILED'&&$unsupportedMemberAction['reason']==='AI_DIMENSION_ACTION_CONTRACT_NOT_READY'&&$h->queries===$before,'recognized but unregistered member service action never falls back to payment');
 $before=$h->queries;$unsupportedMemberSales=$h->start('本月销量最高的会员');
 r5check($unsupportedMemberSales['status']==='FAILED'&&$unsupportedMemberSales['reason']==='AI_DIMENSION_ACTION_CONTRACT_NOT_READY'&&$h->queries===$before,'published sales wording remains an unmet member contract instead of falling back to payment');
 $h->semanticIntent['action_codes']=['revenue'];$before=$h->queries;$explicitCashReceipt=$h->start('本月现金业绩收款最多的会员');
 r5check($explicitCashReceipt['status']==='COMPLETED'&&$h->queries===$before+1&&$explicitCashReceipt['answer']['table']['columns'][0]['label']==='会员','explicit cash metric and registered collection wording execute the same member cash contract');
 $h->semanticIntent['action_codes']=['payment','revenue'];$before=$h->queries;$equivalentMemberActions=$h->start('本月按收款查询消费能力最强的会员');
 r5check($equivalentMemberActions['status']==='COMPLETED'&&$h->queries===$before+1&&$equivalentMemberActions['answer']['table']['columns'][0]['label']==='会员','multiple action words execute when one registered metric contract satisfies all of them');
 $rankH=new R5Harness(3,true);
 foreach ([[1,'top'],[2,'bottom'],[5,'top'],[10,'top'],[20,'top_and_bottom']] as [$limit,$direction]) {
  $rankH->semanticIntent=['object_kind'=>'member','object_term'=>'会员','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['payment'],'needs_metric_choice'=>false,'ranking'=>['direction'=>$direction,'limit'=>$limit]];
  $before=$rankH->queries;$ranked=$rankH->start('本月消费能力最强的会员');
  $evidence=$rankH->private->read($rankH->row($ranked)['evidence_ref']);
  r5check($ranked['status']==='COMPLETED'&&$rankH->queries===$before+1&&$evidence['query']['ranking']===['direction'=>$direction,'limit'=>$limit],"any in-contract model result shape executes unchanged: {$direction}/{$limit}");
 }
 $rankH->semanticIntent=['object_kind'=>'member','object_term'=>'会员','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['payment'],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>21]];
 $before=$rankH->queries;$tooManyMember=$rankH->start('本月消费能力最强的会员');
 r5check($tooManyMember['status']==='FAILED'&&$tooManyMember['reason']==='AI_DIMENSION_RANK_LIMIT_NOT_READY'&&$rankH->queries===$before,'out-of-contract model result count stops before business query');
 r5check(str_contains($tooManyMember['message'],'前20、后20'),'dimension limit message states the registered object limit rather than the store limit');
 $rankH->close();
 $h->semanticIntent=['object_kind'=>'member','object_term'=>'会员','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['service'],'needs_metric_choice'=>false];
 $before=$h->queries;$conflictingObject=$h->start('本月服务最多的会员');
 r5check($conflictingObject['status']==='FAILED'&&$conflictingObject['reason']==='AI_DIMENSION_ACTION_CONTRACT_NOT_READY'&&$h->queries===$before,'model-resolved member service request cannot fall back to a different dimension or metric');
 $h->semanticIntent=['object_kind'=>'member','object_term'=>'会员','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['payment'],'needs_metric_choice'=>false];
 $before=$h->queries;$correctedMember=$h->start('本月消费能力最强的会员');
 r5check($correctedMember['status']==='COMPLETED'&&$h->queries===$before+1&&$correctedMember['answer']['table']['columns'][0]['label']==='会员','model-resolved member payment meaning reaches the registered payment dimension');
 $h->semanticIntent=['object_kind'=>'member','object_term'=>'会员','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['payment'],'needs_metric_choice'=>false,'unresolved_fragments'=>['和门店']];
 $before=$h->queries;$multipleObjects=$h->start('本月消费能力最强的会员和门店');
 r5check($multipleObjects['status']==='FAILED'&&$multipleObjects['reason']==='AI_INTENT_UNRESOLVED'&&$h->queries===$before,'multiple requested analysis objects remain unresolved and never execute one object while discarding the other');
 $h->semanticIntent=['object_kind'=>'member','object_term'=>'会员','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['payment'],'needs_metric_choice'=>false];
 $before=$h->queries;$storeScopedMember=$h->start('本月本店消费能力最强的会员');
 r5check($storeScopedMember['status']==='COMPLETED'&&$h->queries===$before+1&&$storeScopedMember['answer']['table']['columns'][0]['label']==='会员','bound current-store scope is not misread as a second analysis object');
$h->semanticIntent['unresolved_fragments']=['和门店'];$before=$h->queries;$storeScopedMultipleObjects=$h->start('本月本店消费能力最强的会员和门店');
 r5check($storeScopedMultipleObjects['status']==='FAILED'&&$storeScopedMultipleObjects['reason']==='AI_INTENT_UNRESOLVED'&&$h->queries===$before,'current-store scope never hides an independently requested store analysis object');
 $h->semanticIntent=['object_kind'=>'project','object_term'=>'项目','operation'=>'ranking','metric_codes'=>[],'action_codes'=>['sales'],'needs_metric_choice'=>true,'unresolved_fragments'=>['不含销售']];
 $before=$h->queries;$excluded=$h->start('这个月不含销售的项目最多');
 r5check($excluded['status']==='FAILED'&&$excluded['reason']==='AI_INTENT_UNRESOLVED'&&$h->queries===$before,'unbound exclusion remains unresolved and never queries a reduced request');
 $h->semanticIntent=['object_kind'=>'project','object_term'=>'项目','operation'=>'ranking','metric_codes'=>[],'action_codes'=>['sales'],'needs_metric_choice'=>true,'unresolved_fragments'=>['魔法']];
 $unknown=$h->start('这个月魔法项目卖得最好');r5check($unknown['status']==='FAILED'&&$unknown['reason']==='AI_INTENT_UNRESOLVED','unresolved model meaning remains blocked and never becomes a project query');
 $h->semanticIntent=['object_kind'=>'member','object_term'=>'会员','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>['payment'],'needs_metric_choice'=>false];
 $member=$h->start('消费能力最强的会员有哪些');$h->step($member,'start_date',1);$before=$h->queries;
 $member=$h->choose($member,['start_date'=>'2026-09-01','end_date'=>'2026-09-08']);
 r5check($member['status']==='COMPLETED','member date guidance resumes registered execution '.($member['reason']??''));
 r5check($h->queries===$before+1&&$member['answer']['table']['rows'][0]['label']==='合成会员','member result passes real gateway state and registered reader facade');
 r5check(strpos($member['answer']['summary'],'2026-09-01 至 2026-09-08')!==false,'member date selection retained in final answer');
 $before=$h->queries;
 $mixed=$h->start('本月会员现金业绩和消耗业绩最高');
 r5check($mixed['status']==='FAILED'&&$h->queries===$before,'unsupported member metric is not dropped to query only cash');
 $h->semanticIntent['unresolved_fragments']=['并按天趋势'];$trend=$h->start('本月会员现金业绩最高并按天趋势');
 r5check($trend['status']==='FAILED'&&$h->queries===$before,'member ranking cannot silently discard requested trend');
 $h->context['terminal']='platform';$h->auth['terminal']='platform';
 $h->boot=$h->gateway->handle('bootstrap',$h->context,['client_session_id'=>'device1']);
 $beforeModels=$h->models;$scope=$h->start('本月本店消费能力最强的会员有哪些');
 r5check($scope['status']==='FAILED'&&$scope['reason']==='AI_UNSUPPORTED_CONDITION'&&$h->queries===$before&&$h->models===$beforeModels,
  'platform question with unbound current-store scope cannot become all authorized stores');
 $h->close();$h=null;
 $h=new R5Harness(3);
 $source=$h->start('9月1日到9月8日现金业绩合计多少');
 r5check($source['status']==='COMPLETED' && isset($source['answer']['context_ref']),'source answer signs conditions reference');
 $follow=$h->start('那就换成消耗业绩，其他条件别动。',$source['answer']['context_ref']);
 r5check($follow['status']==='COMPLETED','signed follow-up compiles without needless date question reason='.($follow['reason']??''));
 r5check($follow['answer']['cards'][0]['metric_name']==='消耗业绩' && $follow['answer']['cards'][0]['start_date']==='2026-09-01' && $follow['answer']['cards'][0]['end_date']==='2026-09-08','follow-up preserves dates and only replaces metric');
 r5check($h->queries===2,'follow-up queries new facts, never returns source amount');
 $explained=$h->start('现金业绩是什么意思');r5check($explained['status']==='COMPLETED' && $h->queries===2,'natural definition uses dictionary only');
 $tampered=$source['answer']['context_ref'];$tampered[strlen($tampered)-1]=$tampered[strlen($tampered)-1]==='a'?'b':'a';
 $bad=$h->start('那就换成消耗业绩，其他条件别动。',$tampered);
 r5check($bad['status']==='FAILED' && $h->queries===2,'forged source never creates business query');
 $missing=$h->start('那就换成消耗业绩，其他条件别动。');
 r5check($missing['status']==='WAITING_CLARIFICATION' && $missing['clarification']['fields'][0]['key']==='start_date','no signed source means ask missing dates, no trust in device answers');
 $h->gateway->handle('cancel',$h->context,$h->binding($missing),$missing['run_id']);
 $before=$h->queries;
 $future=$h->start('2099年9月1日到2099年9月8日现金业绩多少');
 r5check($future['status']==='FAILED' && $future['reason']==='AI_FUTURE_ACTUALS_UNAVAILABLE','explicit future actuals never silently clipped');
 r5check($h->queries===$before,'future actuals stopped before business query');
 $h->auth['permission_version']='revoked-source';
 $revoked=$h->start('那就换成消耗业绩，其他条件别动。',$source['answer']['context_ref']);
 r5check($revoked['status']==='FAILED' && $h->queries===$before,'source reference cannot carry prior authority after revocation');
 $h->close();$h=null;
 echo 'Gateway R5 guidance: '.$checks." checks PASS (SQLite + registered execution; fixture model/facts)\n";
}finally{if($h)$h->close();}

<?php
/** Explicit opt-in integration check. Real model cost + reversible local configuration changes.
 * Run only inside an authorized local backend container; never included in run-all.
 * No questions, answers, credentials or Run identifiers are printed/persisted by this script.
 */
if (getenv('MOHE_R6_LIVE_CONFIRM')!=='LOCAL_CONFIG_AND_MODEL_TEST') exit("Explicit local test authorization required\n");
require getcwd().'/vendor/autoload.php';
$app=new think\App();$app->initialize();
$db=(array)config('database.connections.'.config('database.default'));
$pdo=new PDO('mysql:host='.$db['hostname'].';port='.$db['hostport'].';dbname='.$db['database'],$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$identity=$pdo->query('SELECT @@server_uuid uuid,DATABASE() db')->fetch(PDO::FETCH_ASSOC);
if (!$identity || $identity['uuid']!==getenv('MOHE_R6_EXPECT_UUID') || $identity['db']!==getenv('MOHE_R6_EXPECT_DB') || $db['hostname']!=='mysql') exit("Target mismatch\n");
$rt=app\services\ai\execution\AiRuntimeFactory::make();
if ($rt['runs']->diagnostics()['active']!==0) exit("Active Runs exist; local test postponed\n");
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();$context=$resolver->authenticated($id);
$context['_refresh']=function()use($resolver,$id){return $resolver->authenticated($id);};
$gateway=new app\services\ai\AiGatewayServices();
$call=function($op,$data=[],$run='')use($gateway,$context){return $gateway->handle($op,$context,$data,$run);};
$state=$call('management_get');$baseline=$state;$ownedRevision=$state['revision'];$run=null;$client='r6-live-'.bin2hex(random_bytes(8));$checks=0;
$assert=function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException('LIVE_CHECK_FAILED_'.$label);$checks++;};
$binding=function($r)use($client){return ['client_session_id'=>$client,'generation'=>$r['generation'],'run_delivery_token'=>$r['run_delivery_token']];};
try {
    $draft=$state['draft'];$draft['guidance']['prompts']['metric_code']='请选择本次要了解的业绩类型';
    $state=$call('management_save',['expected_revision'=>$state['revision'],'document'=>$draft]);
    $ownedRevision=$state['revision'];
    $valid=$call('management_validate',['expected_revision'=>$state['revision']]);$assert($valid['valid']===true,'VALIDATE');
    $preview=$call('management_preview',['expected_revision'=>$state['revision'],'question'=>'今天业绩多少']);
    $assert($preview['kind']==='clarification'&&$preview['model_called']===false&&$preview['business_data_read']===false,'PREVIEW');
    $published=$call('management_publish',['expected_revision'=>$state['revision']]);
    $ownedRevision=$published['revision'];
    $assert($published['active_version']!=='source','PUBLISH');
    $boot=$call('bootstrap',['client_session_id'=>$client]);
    $input=['client_request_id'=>'request-'.bin2hex(random_bytes(8)),'conversation_id'=>'conversation-'.bin2hex(random_bytes(8)),
        'client_session_id'=>$client,'window_token'=>$boot['window_token'],'question'=>'今天业绩多少','history'=>[],
        'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2'];
    $run=$call('create',$input);$assert(($run['accepted']??true)!==false,'ADMITTED');
    $run=$call('execute',$input+$binding($run),$run['run_id']);
    $assert($run['status']==='WAITING_CLARIFICATION'&&$run['clarification']['question']===$draft['guidance']['prompts']['metric_code'],'REAL_GUIDANCE');
    $c=$run['clarification'];
    $run=$call('clarify',$binding($run)+['schema_version'=>'mohe-clarification-v2','clarification_id'=>$c['id'],'step_revision'=>$c['step_revision'],
        'intent_revision'=>$c['intent_revision'],'client_submission_id'=>'selection-'.bin2hex(random_bytes(8)),'choices'=>['metric_code'=>'cash_performance']],$run['run_id']);
    $assert($run['status']==='COMPLETED' && is_string($run['answer']['summary'] ?? null)
        && ($run['answer']['cards'] ?? null)===[],'REAL_QUERY');
    $assert(($run['management_trace']['management_version']??null)===$published['active_version'],'TRACE_VERSION');
    $assert(array_column($run['management_trace']['nodes'],'node_id')===['query','evidence','render'],'TRACE_NODES');
    echo 'PASS local real model/management/query integration: '.$checks." checks; figures and identifiers omitted\n";
} finally {
    if ($run && isset($run['run_id'])&&!in_array($run['status'],['COMPLETED','PARTIAL_SUCCEEDED','CANCELLED','FAILED'],true)) $call('cancel',$binding($run),$run['run_id']);
    $latest=$call('management_get');
    if ($latest['revision']!==$ownedRevision) throw new RuntimeException('Concurrent configuration change; restoration stopped without overwriting it');
    $restored=$call('management_rollback',['expected_revision'=>$latest['revision'],'target_version'=>$baseline['active_version']]);
    $call('management_save',['expected_revision'=>$restored['revision'],'document'=>$baseline['draft']]);
    echo "Original active policy and draft restored via versioned API; no business records altered\n";
}

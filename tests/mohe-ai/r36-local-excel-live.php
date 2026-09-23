<?php
/** Local-only real answer → independent Excel smoke test. Never touches remote instances. */
if (getenv('MOHE_R36_LOCAL_EXCEL_TEST') !== 'ruihao') exit("Explicit local ruihao opt-in required\n");
require getcwd().'/vendor/autoload.php';
$app=new think\App(); $app->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??'')!=='mysql' || ($db['database']??'')!=='ruihao') throw new RuntimeException('LOCAL_TARGET_MISMATCH');
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();
$context=$resolver->authenticated($id);
if (empty($context['can_use']) || empty($context['export_principal_ready'])) throw new RuntimeException('LOCAL_AUTHORITY_NOT_READY');
$context['_refresh']=function () use($resolver,$id) { return $resolver->authenticated($id); };
$gateway=new app\services\ai\AiGatewayServices();
$session='r36-'.bin2hex(random_bytes(8));
$bootstrap=$gateway->handle('bootstrap',$context,['client_session_id'=>$session]);
if (!in_array('screen_and_xlsx',$bootstrap['capabilities']['output_formats']??[],true)) throw new RuntimeException('LOCAL_EXPORT_NOT_READY');
$input=['client_request_id'=>'r36-'.bin2hex(random_bytes(8)),'conversation_id'=>'r36-'.bin2hex(random_bytes(8)),
    'client_session_id'=>$session,'window_token'=>$bootstrap['window_token'],'question'=>'这个月现金业绩多少',
    'history'=>[],'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2'];
$run=$gateway->handle('create',$context,$input);
if (!isset($run['run_id'],$run['run_delivery_token'])) throw new RuntimeException('LOCAL_ANSWER_NOT_ADMITTED');
$proof=['client_session_id'=>$session,'generation'=>$run['generation'],'run_delivery_token'=>$run['run_delivery_token']];
if (($run['execution_mode']??'')==='compatibility') $run=$gateway->handle('execute',$context,$input+$proof,$run['run_id']);
$answerStarted=microtime(true);
while (!in_array($run['status'],['COMPLETED','PARTIAL_SUCCEEDED','FAILED','CANCELLED'],true) && microtime(true)-$answerStarted<180) {
    usleep(500000); $run=$gateway->handle('status',$context,$proof,$run['run_id']);
}
if ($run['status']!=='COMPLETED' || empty($run['answer'])) throw new RuntimeException('LOCAL_ANSWER_NOT_COMPLETED_'.($run['status']??'unknown'));
// Record only timing/status. Actual business values remain in the private Run.
$answerSeconds=round(microtime(true)-$answerStarted,1);
$runtime=app\services\ai\execution\AiRuntimeFactory::make();
$owner=['account_id'=>$id,'terminal'=>'platform','conversation_id'=>$input['conversation_id'],'window_id'=>$session];
$answerRef=$runtime['runs']->get($owner,$run['run_id'],$run['generation'])['answer_ref'];
$receipt=$gateway->handle('export_create',$context,$proof,$run['run_id']);
$fileStarted=microtime(true);
while (in_array($receipt['status']??'', ['pending','running'],true) && microtime(true)-$fileStarted<180) {
    usleep(500000); $receipt=$gateway->handle('export_status',$context,$proof,$run['run_id']);
}
if (($receipt['status']??'')!=='succeeded') throw new RuntimeException('LOCAL_FILE_NOT_COMPLETED_'.($receipt['status']??'unknown'));
$download=$gateway->handle('export',$context,$proof,$run['run_id']);
if (!$download instanceof think\Response) throw new RuntimeException('LOCAL_DOWNLOAD_NOT_RESPONSE');
$current=$gateway->handle('status',$context,$proof,$run['run_id']);
if ($current['status']!=='COMPLETED' || $runtime['runs']->get($owner,$run['run_id'],$run['generation'])['answer_ref']!==$answerRef) throw new RuntimeException('LOCAL_ANSWER_MUTATED_BY_FILE');
echo json_encode(['result'=>'PASS','answer_seconds'=>$answerSeconds,
    'excel_seconds'=>round(microtime(true)-$fileStarted,1),'excel_status'=>$receipt['status'],
    'answer_unchanged'=>true,'download_response'=>true])."\n";

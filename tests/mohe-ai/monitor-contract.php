<?php
declare(strict_types=1);
require __DIR__.'/state-export-contract.php';
require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiRuntimeMonitor.php';
$dir=sys_get_temp_dir().'/mohe-monitor-'.bin2hex(random_bytes(8));
$clock=1000000;
$profile=['registered'=>true,'capacity_count'=>2,'export_min_samples'=>10,'export_failure_rate'=>0.5,'export_consecutive_failures'=>5,'security_count'=>1,'unknown_count'=>1,'technical_count'=>5,'cleanup_stale_seconds'=>60,'duration_ms'=>180000];
$monitor=new \app\services\ai\execution\AiRuntimeMonitor($profile,$dir,function()use(&$clock){return $clock;});
try {
    $monitor->recordCleanup(true);
    $stats=['capacity'=>0,'capacity_rejected'=>0,'technical'=>0,'security'=>0,'export_unknown'=>0,'usage'=>['unknown_attempts'=>0],'exports'=>['eligible'=>0,'failure_rate'=>null,'consecutive_failed'=>0],'duration'=>['max_ms'=>0]];
    checkState($monitor->evaluate($stats)['status']==='ok','registered clean monitor is healthy');
    $stats['export_unknown']=9; $stats['security']=1; $stats['capacity_rejected']=2;
    $codes=array_column($monitor->evaluate($stats)['alerts'],'code');
    checkState(in_array('EXECUTION_OUTCOME_UNKNOWN',$codes,true)&&in_array('SECURITY_INTEGRITY_FAILURES',$codes,true)&&in_array('CAPACITY_PRESSURE',$codes,true)&&!in_array('EXPORT_FILE_FAILURES',$codes,true),'unknown/security/capacity separate from file denominator');
    $stats['exports']=['eligible'=>5,'failure_rate'=>1,'consecutive_failed'=>5];
    checkState(in_array('EXPORT_FILE_FAILURES',array_column($monitor->evaluate($stats)['alerts'],'code'),true),'low-flow five consecutive file failures alert');
    $monitor->recordCleanup(false);
    checkState(in_array('CLEANUP_FAILED',array_column($monitor->evaluate($stats)['alerts'],'code'),true),'supervisor failure persists for same-permission admin');
    $unregistered=new \app\services\ai\execution\AiRuntimeMonitor([],$dir,function()use(&$clock){return $clock;});
    checkState($unregistered->evaluate($stats)['status']==='not_ready','missing explicit threshold registration is never healthy');
    $clock+=86400; $monitor->evaluate($stats);
    checkState(filesize($dir.'/cleanup-health.json')===0,'expired aggregate health physically erased on inspection');
} finally { if(is_file($dir.'/cleanup-health.json'))unlink($dir.'/cleanup-health.json'); if(is_dir($dir))rmdir($dir); }
// Probe Attempt has no business Run: still contributes known usage and unknown cleanup.
$db=new PDO('sqlite::memory:'); installAiStateFixture($db); $now=100000000;
$store=new \app\services\ai\execution\AiRunStore($db,'','monitor.fixture',function()use(&$now){return $now;});
$insert=$db->prepare('INSERT INTO mohe_ai_attempt (instance_id,run_id,attempt_code,kind,target_code,payload_hash,state,input_tokens,output_tokens,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute(['monitor.fixture','probe-only','a','model','siliconflow_probe',str_repeat('a',64),'SUCCEEDED',12,3,$now-1000,$now+86400000]);
$insert->execute(['monitor.fixture','probe-unknown','a','model','siliconflow_probe',str_repeat('a',64),'IN_FLIGHT',null,null,$now-21000,$now+86400000]);
$store->cleanup(); $stats=$store->diagnostics();
checkState($stats['usage']['input_tokens']===12&&$stats['usage']['output_tokens']===3&&$stats['usage']['unknown_attempts']===1&&$stats['usage']['usage_unknown_attempts']===1,'probe without business Run is counted, stale UNKNOWN never treated as zero');
$r=$store->create($owner,'duration',$hash,$snapshot)['run']; $store->claim($owner,$r['run_id'],$r['generation'],'worker'); $now+=1234;
$store->publish($owner,$r['run_id'],$r['generation'],'worker','evidence','answer','data_only_export_failed');
$stats=$store->diagnostics();
checkState($stats['duration']['mean_ms']===1234&&$stats['exports']['failed']===1&&$stats['exports']['eligible']===1,'actual terminal elapsed duration and safe partial file sample');
echo "PASS 8 monitor/real-store duration/token/probe/retention checks.\n";

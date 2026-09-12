<?php
// Disposable MySQL + real SQLite Run fencing + shared compiler/Task/Worker/Writer.
if (getenv('MOHE_QUERY_TEST_DISPOSABLE')!=='yes') throw new RuntimeException('Disposable fixture required');
function config($key,$default=null) { return $GLOBALS['aiExportFixtureConfig'][$key]??$default; }
function app() { return new class { public function getRuntimePath(){return $GLOBALS['aiExportFixturePath'].'/runtime/';} }; }
(function () {
    $temp=sys_get_temp_dir().'/mohe-export-e2e-'.bin2hex(random_bytes(8)); mkdir($temp,0700);
    $GLOBALS['aiExportFixturePath']=$temp;
    // Fixture gate only; does not attest a live instance's monitoring/release readiness.
    $GLOBALS['aiExportFixtureConfig']=['mohe_ai.execution_slots'=>4,'mohe_ai.export'=>['compatible_workers_ready'=>true,'reserved_slots_verified'=>true,'monitoring_ready'=>true,'reserved_slots'=>2,'queue_wait_budget_ms'=>10000,'publication_reserve_ms'=>5000]];
    $GLOBALS['aiExportFixtureConfig']['mohe_ai.monitoring']=['registered'=>true,'capacity_count'=>5,'export_min_samples'=>5,'export_consecutive_failures'=>5,'security_count'=>1,'unknown_count'=>1,'technical_count'=>5,'cleanup_stale_seconds'=>30,'duration_ms'=>180000,'export_failure_rate'=>0.5];
    $state=new PDO('sqlite::memory:');
    foreach ([
        'CREATE TABLE mohe_ai_mutex (instance_id TEXT PRIMARY KEY,quarantined_slots INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE mohe_ai_receipt (instance_id TEXT,receipt_key TEXT,request_hash TEXT,window_id TEXT,run_id TEXT,reason TEXT,expires_at INTEGER,PRIMARY KEY(instance_id,receipt_key))',
        'CREATE TABLE mohe_ai_attempt (instance_id TEXT,run_id TEXT,attempt_code TEXT,kind TEXT,target_code TEXT,payload_hash TEXT,state TEXT,input_tokens INTEGER,output_tokens INTEGER,created_at INTEGER,expires_at INTEGER,PRIMARY KEY(instance_id,run_id,attempt_code))',
        'CREATE TABLE mohe_ai_run (instance_id TEXT,run_id TEXT,account_id INTEGER,terminal TEXT,conversation_id TEXT,window_id TEXT,generation INTEGER,status TEXT,reason TEXT,progress_code TEXT,clarification_ref TEXT,version INTEGER,created_at INTEGER,expires_at INTEGER,deadline_at INTEGER,last_clock_at INTEGER,remaining_ms INTEGER,pause_at INTEGER,clarification_count INTEGER,slot_held INTEGER,worker_token TEXT,evidence_ref TEXT,answer_ref TEXT,snapshot_json TEXT,counters_json TEXT,PRIMARY KEY(instance_id,run_id))'
    ] as $sql) $state->exec($sql);
    try {
        $private=new app\services\ai\config\AiPrivateStorage($temp.'/objects');
        $viewStore=new app\services\query\metric\MetricReadViewStore($temp.'/views',$private->signingKey());
        $runs=new app\services\ai\execution\AiRunStore($state,'','fixture.instance');
        $context=['terminal'=>'store','account_id'=>1,'tenant_id'=>'0','can_use'=>true,'permission_version'=>'v1','scope_mode'=>'stores','store_ids'=>[1],'report_capability_code'=>'group_management_dashboard','export_principal_ready'=>true,'principal_kind'=>'staff','origin_store_id'=>1,'origin_organization_id'=>'1','staff_id'=>1];
        $runtime=['instance'=>'fixture.instance','private'=>$private,'views'=>$viewStore,'runs'=>$runs,'config'=>new class {public function read(){return ['version'=>1];}}];
        // The worker re-evaluates the capability projection immediately
        // before it publishes an export.  Build this fixture's signed run
        // snapshot from that same fully derived context, rather than relying
        // on an accidental default for a later-added capability field.
        $context['analysis_personnel_ready']=app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($runtime['config']->read());
        $dispatched=[];
        $export=new app\services\ai\execution\AiExportRuntime($runtime,function()use(&$context){return $context;},function($taskNo)use(&$dispatched){$dispatched[]=$taskNo;});
        $property=(new ReflectionClass($export))->getProperty('tasks'); $tasks=$property->getValue($export);
        foreach (['customFields'=>new class {public function listVisible(...$args):array{return [];}},'preferences'=>new class {public function load(...$args):array{return [];}},'references'=>new class {public function register(...$args):void{} public function release(...$args):void{}}] as $name=>$value) (new ReflectionClass($tasks))->getProperty($name)->setValue($tasks,$value);
        mysqlCheck($export->ready($context),'runtime real shared schema ready');
        $owner=['account_id'=>1,'terminal'=>'store','conversation_id'=>'e2e-conversation','window_id'=>'e2e-window'];
        $snapshot=['capability_snapshot_ref'=>'fixture','capability_snapshot_hash'=>app\services\ai\execution\AiAuthority::capabilityHash(true,false,$context),'budget_profile_version'=>'v1','authorization_version'=>app\services\ai\execution\AiAuthority::permissionHash($context),'model_config_version'=>'1'];
        $snapshot+=['guidance_schema_version'=>'mohe-clarification-v2','guidance_profile_version'=>'fixture-v2','max_clarification_rounds'=>'3'];
        $run=$runs->create($owner,'e2e-request',hash('sha256','fixture'),$snapshot)['run'];
        $token=bin2hex(random_bytes(24)); $runs->claim($owner,$run['run_id'],$run['generation'],$token);
        $run=$runs->get($owner,$run['run_id'],$run['generation']);
        $service=new app\services\query\metric\MetricReadViewServices($viewStore,function()use(&$context,$private){return app\services\ai\execution\AiAuthority::reportBinding($context,'fixture.instance',$private->signingKey());});
        $query=['query_shape'=>'summary','metric_codes'=>['consume_amount'],'start_date'=>'2026-09-08','end_date'=>'2026-09-08','store_ids'=>[],'business_filters'=>[],'compare_range'=>null,'ranking'=>null];
        $expiry=intdiv($run['expires_at'],1000); $view=$service->create([],$query,$expiry);
        $base=['owner'=>$owner,'run_id'=>$run['run_id'],'generation'=>$run['generation']];
        $evidence=$private->put('evidence',$base+['view_ref'=>$view['read_consistency_ref'],'query'=>$query],$expiry);
        $answer=$private->put('answer',$base+['answer'=>['summary'=>'fixture','cards'=>[]]],$expiry);
        $waiting=$export->queue($context,$owner,$run,$token,$evidence,$answer,$view);
        mysqlCheck($waiting['status']==='WAITING_EXPORT' && count($dispatched)===1,'queue commits waiting handoff before dispatch');
        mysqlCheck((int)$state->query('SELECT slot_held FROM mohe_ai_run')->fetchColumn()===0,'waiting export releases parent physical slot');
        $execute=(new ReflectionClass($export))->getMethod('execute'); $execute->invoke($export,$dispatched[0]);
        $done=$runs->get($owner,$run['run_id'],$run['generation']);
        mysqlCheck($done['status']==='COMPLETED','real worker publishes complete status='.$done['status'].' reason='.($done['reason']??''));
        $descriptor=$export->download($context,$owner,$done);
        mysqlCheck(!empty($descriptor['storageKey']),'download rechecks current permission and exact file');
        $execute->invoke($export,$dispatched[0]);
        mysqlCheck($runs->get($owner,$run['run_id'],$run['generation'])['answer_ref']===$done['answer_ref'],'duplicate job cannot republish');
        $context['permission_version']='revoked'; $denied=false;
        try {$export->download($context,$owner,$done);} catch(Throwable $e){$denied=true;}
        mysqlCheck($denied,'permission changed blocks original file download');
        $context['permission_version']='v1';
        foreach (['cancel','capacity'] as $case) {
            $next=$runs->create($owner,'e2e-'.$case,hash('sha256',$case),$snapshot)['run'];
            $nextToken=bin2hex(random_bytes(24)); $runs->claim($owner,$next['run_id'],$next['generation'],$nextToken);
            $next=$runs->get($owner,$next['run_id'],$next['generation']); $nextExpiry=intdiv($next['expires_at'],1000);
            $nextView=$service->create([],$query,$nextExpiry);
            $nextBase=['owner'=>$owner,'run_id'=>$next['run_id'],'generation'=>$next['generation']];
            $nextEvidence=$private->put('evidence',$nextBase+['view_ref'=>$nextView['read_consistency_ref'],'query'=>$query],$nextExpiry);
            $nextAnswer=$private->put('answer',$nextBase+['answer'=>['summary'=>'fixture','cards'=>[]]],$nextExpiry);
            $nextWait=$export->queue($context,$owner,$next,$nextToken,$nextEvidence,$nextAnswer,$nextView);
            $taskNo=end($dispatched);
            if ($case==='cancel') {
                $cancelled=$runs->cancel($owner,$next['run_id'],$next['generation']);
                mysqlCheck($export->cancel($context,$owner,$cancelled),'pending file task cancellation acknowledged');
                $execute->invoke($export,$taskNo);
                mysqlCheck($runs->get($owner,$next['run_id'],$next['generation'])['status']==='CANCELLED','late job cannot publish cancelled run');
                $row=think\facade\Db::name('unified_query_export_task')->where('task_no',$taskNo)->find();
                mysqlCheck($row['status']==='cancelled' && $row['storage_key']==='','cancelled pending task never writes file');
            } else {
                $held=[];
                try {
                    for($i=0;$i<2;$i++) {$handle=fopen($temp.'/runtime/private/mohe-ai-export-locks/'.hash('sha256','fixture.instance').'/slot-'.$i,'c+b'); flock($handle,LOCK_EX|LOCK_NB); $held[]=$handle;}
                    $execute->invoke($export,$taskNo);
                } finally {foreach($held as $handle){flock($handle,LOCK_UN);fclose($handle);}}
                $partial=$runs->get($owner,$next['run_id'],$next['generation']);
                mysqlCheck($partial['status']==='PARTIAL_SUCCEEDED','physical export capacity shortage preserves verified data');
                $partialObject=$private->read($partial['answer_ref']);
                mysqlCheck($partialObject['answer']['export_status']==='failed' && $partial['evidence_ref']===$nextEvidence,'partial keeps exact original evidence and failed file status');
            }
        }
    } finally {
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());} rmdir($temp);
    }
})();

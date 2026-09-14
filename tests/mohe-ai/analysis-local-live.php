<?php
/** Explicit local-only real-model/real-fact integration. Never part of offline suites.
 * Prints statuses/counts only, not credentials, object names, answers or Run IDs.
 */
$mode=getenv('MOHE_ANALYSIS_LOCAL_CONFIRM');
if (!in_array($mode,['PREFLIGHT','REBASE_SOURCE','ENABLE_AND_TEST','TEST_HISTORY','CLOCK_STRESS'],true)) exit("Explicit local opt-in required\n");
require getcwd().'/vendor/autoload.php';
$app=new think\App();$app->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if ($db['hostname']!=='mysql') exit("Target host mismatch\n");
$pdo=new PDO('mysql:host='.$db['hostname'].';port='.$db['hostport'].';dbname='.$db['database'].';charset=utf8mb4',$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$identity=$pdo->query('SELECT @@server_uuid uuid,DATABASE() db')->fetch(PDO::FETCH_ASSOC);
if ($identity['uuid']!==getenv('MOHE_ANALYSIS_EXPECT_UUID') || $identity['db']!==getenv('MOHE_ANALYSIS_EXPECT_DB')) exit("Target mismatch\n");
$rt=app\services\ai\execution\AiRuntimeFactory::make();
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();$context=$resolver->authenticated($id);
if (empty($context['can_configure']) || empty($context['can_use'])) exit("Admin authority unavailable\n");
$context['_refresh']=function()use($resolver,$id){return $resolver->authenticated($id);};
$active=$rt['runs']->diagnostics()['active'];
// A waiting clarification owns no execution slot and is deliberately kept for
// customer review. It must not prevent an independent acceptance conversation;
// only a currently executing/exporting run can conflict with this local probe.
$blocking=(int)think\facade\Db::name('mohe_ai_run')->whereIn('status',['WORKFLOW_EXECUTING','WAITING_EXPORT'])->count();$configuration=$rt['config']->read();
echo json_encode(['target_verified'=>true,'active_runs'=>$active,'blocking_runs'=>$blocking,'scope_column'=>$configuration['external_scope_supported'],'new_scope_authorized'=>app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($configuration),
    'active_ai_exports'=>(int)think\facade\Db::name('unified_query_export_task')->where('source_type','AI')->whereIn('status',['pending','running'])->count(),
    'available_metric_count'=>count(app\services\ai\execution\AiAuthority::capabilities(false,$context)['metric_codes'])])."\n";
if ($mode==='PREFLIGHT') exit(0);
if ($mode==='REBASE_SOURCE') {
    if($blocking!==0) exit("Executing Runs exist; source rebase postponed\n");
    $gateway=new app\services\ai\AiGatewayServices();
    $call=function($operation,$input=[])use($gateway,$context){return $gateway->handle($operation,$context,$input);};
    $state=$call('management_get');
    if(empty($state['source_changed'])) {echo "LOCAL_MANAGEMENT_SOURCE_ALREADY_CURRENT\n";exit(0);}
    $state=$call('management_rebase',['expected_revision'=>$state['revision']]);
    $valid=$call('management_validate',['expected_revision'=>$state['revision']]);
    if(($valid['valid']??false)!==true) throw new RuntimeException('LOCAL_MANAGEMENT_REBASE_INVALID');
    $state=$call('management_publish',['expected_revision'=>$state['revision']]);
    if(!empty($state['source_changed'])||!is_array($state['active_document']??null)) throw new RuntimeException('LOCAL_MANAGEMENT_REBASE_NOT_ACTIVE');
    echo "LOCAL_MANAGEMENT_SOURCE_REBASED_AND_PUBLISHED\n";exit(0);
}
if(getenv('MOHE_ANALYSIS_CLOCK_SCHEMA')==='1') {
    $s=$pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN (\'last_clock_at\',\'created_at\',\'deadline_at\')');$s->execute([$db['prefix'].'mohe_ai_run']);echo json_encode(['clock_schema'=>$s->fetchAll(PDO::FETCH_ASSOC)])."\n";exit(0);
}
if ($blocking!==0) exit("Executing Runs exist; stop before changes\n");
if($mode==='CLOCK_STRESS') {
    $owner=['terminal'=>'platform','account_id'=>$id,'conversation_id'=>'clock-probe-'.bin2hex(random_bytes(8)),'window_id'=>'clock-probe-'.bin2hex(random_bytes(8))];
    $snapshot=['capability_snapshot_ref'=>'clock-probe','capability_snapshot_hash'=>str_repeat('a',64),'budget_profile_version'=>'180000-clock-probe','authorization_version'=>'clock-probe','model_config_version'=>'clock-probe'];
    $created=$rt['runs']->create($owner,'clock-probe',str_repeat('b',64),$snapshot);if(!$created['accepted'])exit("Clock probe admission denied\n");$run=$created['run'];$token='clock-probe-worker';
    $probeFailed=false;
    try {
        $rt['runs']->claim($owner,$run['run_id'],$run['generation'],$token);
        for($i=0;$i<1000;$i++)$rt['runs']->checkpoint($owner,$run['run_id'],$run['generation'],$token);
        echo "CLOCK_PROBE_1000_CHECKPOINTS_PASS\n";
    } catch(Throwable $e) {
        $probeFailed=true;
        $values=[];foreach(['clockRegressionMs','minimumClockDelta'] as $key){$p=new ReflectionProperty($rt['runs'],$key);if(PHP_VERSION_ID<80100)$p->setAccessible(true);$values[$key]=$p->getValue($rt['runs']);}
        echo json_encode(['clock_probe_failure'=>$e->getMessage(),'diagnostics'=>$values])."\n";
    } finally {$rt['runs']->cancel($owner,$run['run_id'],$run['generation']);$rt['runs']->release($owner,$run['run_id'],$run['generation'],$token);}
    exit($probeFailed?1:0);
}
if (!$configuration['enabled'] || !$configuration['external_processing_authorized']) exit("Existing customer AI authorization missing\n");
try {
    if ($mode==='TEST_HISTORY' && !app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($configuration)) throw new RuntimeException('LOCAL_SCOPE_NOT_AUTHORIZED');
    if (!$configuration['external_scope_supported']) {
        $prefix=(string)$db['prefix'];if(!preg_match('/^[a-zA-Z0-9_]+$/D',$prefix))throw new RuntimeException('LOCAL_PREFIX_INVALID');
        $pdo->exec('ALTER TABLE `'.$prefix.'mohe_ai_config` ADD COLUMN `external_scope_version` varchar(64) NOT NULL DEFAULT \'\'');
        echo "LOCAL_ADDITIVE_SCOPE_COLUMN_APPLIED\n";
    }
    if (!app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($configuration)) {
        $configuration=$rt['config']->save(['version'=>$configuration['version'],'enabled'=>$configuration['enabled'],'model'=>$configuration['model'],
            'api_key'=>'','external_processing_authorized'=>true,'external_scope_version'=>app\services\ai\config\AiConfigStore::QUESTION_SCOPE]);
        echo "LOCAL_ADMIN_APPROVED_SCOPE_ENABLED\n";
    }
    // An explicit local-only question lets the same real-model harness cover
    // a newly discovered general-management request without changing the
    // production gateway or retaining customer wording.  It is deliberately
    // opt-in with the rest of this script's authorization guards.
    $question=(string)getenv('MOHE_ANALYSIS_QUESTION');
    if ($question==='' || strlen($question)>1024) $question='今天做的最好的技师是谁';
    $expectedRows=null;
    if ($mode==='TEST_HISTORY') {
        $scope=['personnel_authorized'=>true,'store_ids'=>$context['store_ids'],'employee_id'=>0,'permission_version'=>$context['permission_version']];
        $objects=new app\services\query\metric\PersonnelAnalysisObjectServices(static function($table){return think\facade\Db::name($table);},static function()use($scope){return $scope;});
        $selection=$objects->selection('staff_labor_yeji','role:craftsman');
        $query=think\facade\Db::name('cashier_v3_performance_fact')->alias('p')->where('p.tenant_id','0')->where('p.status','effective')
            ->where('p.performance_type','labor_performance_allocated')->where('p.business_date','>=',app\services\query\metric\MetricReadViewServices::COVERAGE_START)
            ->where('p.business_date','<=',date('Y-m-d'))->where(function($q)use($selection){foreach($selection['pairs'] as $pair)$q->whereOr(function($r)use($pair){$r->where('p.store_id',$pair['store_id'])->where('p.employee_id',$pair['employee_id']);});});
        if (!$selection['pairs']) throw new RuntimeException('LOCAL_POSITIVE_FIXTURE_UNAVAILABLE');
        (new app\services\report\StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query,'p.tenant_id','p.order_id');
        $day=(clone $query)->order('p.business_date','desc')->value('p.business_date');
        if (!$day) throw new RuntimeException('LOCAL_POSITIVE_FIXTURE_UNAVAILABLE');
        $expectedRows=(clone $query)->where('p.business_date',$day)->fieldRaw('p.employee_id,SUM(p.amount_cents) amount_cents')->group('p.employee_id')->order('amount_cents','desc')->order('p.employee_id','asc')->limit(1)->select()->toArray();
        $question=$day.'劳动业绩最高的技师是谁';
        if (getenv('MOHE_ANALYSIS_TEST_NAMED')==='1') {
            $employee=(int)$expectedRows[0]['employee_id'];
            $selection=$objects->selection('staff_labor_yeji','person:'.$employee);
            $namedQuery=think\facade\Db::name('cashier_v3_performance_fact')->alias('p')->where('p.tenant_id','0')->where('p.status','effective')
                ->where('p.performance_type','labor_performance_allocated')->where('p.business_date',$day)
                ->where(function($q)use($selection){foreach($selection['pairs'] as $pair)$q->whereOr(function($r)use($pair){$r->where('p.store_id',$pair['store_id'])->where('p.employee_id',$pair['employee_id']);});});
            (new app\services\report\StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($namedQuery,'p.tenant_id','p.order_id');
            $expectedRows=$namedQuery->fieldRaw('p.employee_id,SUM(p.amount_cents) amount_cents')->group('p.employee_id')->select()->toArray();
            $question=$day.$selection['names'][$employee].'劳动业绩多少';
        }
        echo json_encode(['historical_date'=>$day,'positive_fact_sample'=>count($expectedRows)>0])."\n";
    }
    $previousClock=0;$clockRegressions=[];
    $clock=function()use(&$previousClock,&$clockRegressions){$now=(int)floor(microtime(true)*1000);if($now<$previousClock)$clockRegressions[]=$previousClock-$now;$previousClock=$now;return $now;};
    $probeRuns=new app\services\ai\execution\AiRunStore($pdo,(string)$db['prefix'],$rt['instance'],$clock);
    $gateway=getenv('MOHE_ANALYSIS_PROBE_CLOCK')==='1'
        ?new app\services\ai\AiGatewayServices($probeRuns,$rt['config'],$rt['private'],$rt['instance'],$rt['views'],null,null,$rt['management'])
        :new app\services\ai\AiGatewayServices();
    $call=function($op,$data=[],$run='')use($gateway,$context){$start=microtime(true);$result=$gateway->handle($op,$context,$data,$run);if($op!=='status')echo json_encode(['operation'=>$op,'elapsed_ms'=>(int)((microtime(true)-$start)*1000)])."\n";return $result;};
    $client='analysis-live-'.bin2hex(random_bytes(8));$boot=$call('bootstrap',['client_session_id'=>$client]);
    $input=['client_request_id'=>'request-'.bin2hex(random_bytes(8)),'conversation_id'=>'conversation-'.bin2hex(random_bytes(8)),
        'client_session_id'=>$client,'window_token'=>$boot['window_token'],'question'=>$question,'history'=>[],
        'output_format'=>getenv('MOHE_ANALYSIS_TEST_XLSX')==='1'?'screen_and_xlsx':'screen','guidance_schema_version'=>'mohe-clarification-v2'];
    $run=$call('create',$input);
    // Admission may deliberately decline a fresh real-model run (for
    // example while the bounded technical-failure circuit is cooling down).
    // Do not turn that policy outcome into an undefined-index error in the
    // test harness, and do not attempt an execute call without a server run.
    if (!is_array($run) || !isset($run['run_id'],$run['generation'],$run['run_delivery_token'])) {
        echo json_encode(['result'=>'NOT_ADMITTED','reason'=>is_array($run)?($run['reason']??'unknown'): 'invalid_create_response'])."\n";
        exit(2);
    }
    $runsProperty=new ReflectionProperty($gateway,'runs');if(PHP_VERSION_ID<80100)$runsProperty->setAccessible(true);$probeRuns=$runsProperty->getValue($gateway);
    $binding=function($run)use($client){return ['client_session_id'=>$client,'generation'=>$run['generation'],'run_delivery_token'=>$run['run_delivery_token']];};
    $run=$call('execute',$input+$binding($run),$run['run_id']);
    echo json_encode(['stage'=>'understand','status'=>$run['status'],'reason'=>$run['reason']??null,'field'=>$run['clarification']['fields'][0]['key']??null])."\n";
    for($round=0;$round<5 && $run['status']==='WAITING_CLARIFICATION';$round++) {
        $c=$run['clarification'];$field=$c['fields'][0];$choices=[];
        if ($field['key']==='analysis_object') {
            $values=array_column($field['options'],'value');$choices['analysis_object']=in_array('role:craftsman',$values,true)?'role:craftsman':$values[0];
        } elseif ($field['key']==='analysis_metric') $choices['analysis_metric']='staff_labor_yeji';
        else throw new RuntimeException('LIVE_UNEXPECTED_GUIDANCE');
        $run=$call('clarify',$binding($run)+['schema_version'=>'mohe-clarification-v2','clarification_id'=>$c['id'],'step_revision'=>$c['step_revision'],
            'intent_revision'=>$c['intent_revision'],'client_submission_id'=>'selection-'.bin2hex(random_bytes(8)),'choices'=>$choices],$run['run_id']);
        echo json_encode(['stage'=>'guidance','round'=>$round+1,'status'=>$run['status'],'reason'=>$run['reason']??null,'next_field'=>$run['clarification']['fields'][0]['key']??null])."\n";
    }
    $pollUntil=microtime(true)+30;
    // The authorized local acceptance harness invokes the same dedicated
    // worker implementation immediately when an Excel task is queued.  It
    // does not fake an export or change business facts, and leaves the Task
    // and Run records intact for product review.  Production remains queued.
    if ($input['output_format']==='screen_and_xlsx' && $run['status']==='WAITING_EXPORT') {
        $task=think\facade\Db::name('unified_query_export_task')->where('source_type','AI')->where('status','pending')->order('id','desc')->field('task_no')->find();
        if (!$task || !is_string($task['task_no']??null)) throw new RuntimeException('LIVE_EXPORT_TASK_MISSING');
        app\services\ai\execution\AiExportRuntime::process($task['task_no']);
        $run=$call('status',$binding($run),$run['run_id']);
    }
    while(in_array($run['status'],['WAITING_EXPORT','WORKFLOW_EXECUTING'],true) && microtime(true)<$pollUntil){usleep(250000);$run=$call('status',$binding($run),$run['run_id']);}
    if ($run['status']!=='COMPLETED') {
        $clockRow=think\facade\Db::name('mohe_ai_run')->where('run_id',$run['run_id'])->field('created_at,last_clock_at,deadline_at,remaining_ms')->find();
        $clockDetail=new ReflectionProperty($probeRuns,'clockRegressionMs');if(PHP_VERSION_ID<80100)$clockDetail->setAccessible(true);
        $clockDelta=new ReflectionProperty($probeRuns,'minimumClockDelta');if(PHP_VERSION_ID<80100)$clockDelta->setAccessible(true);
        echo json_encode(['final_status'=>$run['status'],'reason'=>$run['reason']??null,'observed_clock_regressions_ms'=>$clockRegressions,'elapsed_since_create'=>(int)floor(microtime(true)*1000)-(int)$clockRow['created_at'],
            'persisted_clock_regression_ms'=>$clockDetail->getValue($probeRuns),
            'process_minimum_clock_delta_ms'=>$clockDelta->getValue($probeRuns),
            'deadline_delta'=>(int)$clockRow['deadline_at']-(int)floor(microtime(true)*1000),'clock_delta'=>(int)floor(microtime(true)*1000)-(int)$clockRow['last_clock_at']])."\n";
        throw new RuntimeException('LIVE_ANALYSIS_NOT_COMPLETED');
    }
    if ($input['output_format']==='screen_and_xlsx' && ($run['answer']['export_status']??null)!=='ready') throw new RuntimeException('LIVE_EXPORT_NOT_READY');
    if ($expectedRows!==null) {
        if(getenv('MOHE_ANALYSIS_TEST_NAMED')==='1') {
            $cards=$run['answer']['cards']??[];
            if(count($cards)!==1 || $cards[0]['display_value']!==app\services\query\metric\MetricMoneyFormatter::integerYuan((int)$expectedRows[0]['amount_cents'])) throw new RuntimeException('LIVE_NAMED_PARITY_MISMATCH');
            echo "LIVE_NAMED_PERSON_INDEPENDENT_AMOUNT_MATCH\n";
        } else {
        $rendered=$run['answer']['table']['rows']??[];
        echo json_encode(['historical_rendered_count'=>count($rendered),'expected_count'=>count($expectedRows),'row_field_names'=>isset($rendered[0])?array_keys($rendered[0]):[]])."\n";
        if (count($rendered)!==count($expectedRows)) throw new RuntimeException('LIVE_PARITY_COUNT_MISMATCH');
        foreach($expectedRows as $i=>$expected) {
            $name=$selection['names'][(int)$expected['employee_id']]??null;
            $value=app\services\query\metric\MetricMoneyFormatter::integerYuan((int)$expected['amount_cents']);
            if ($rendered[$i]['label']!==$name || $rendered[$i]['value']!==$value || $rendered[$i]['rank']!=='前'.($i+1)) throw new RuntimeException('LIVE_PARITY_VALUE_MISMATCH');
        }
        echo "LIVE_INDEPENDENT_SUM_NAME_RANK_AND_ROUNDED_AMOUNT_MATCH\n";
        }
    }
    if ($input['output_format']==='screen_and_xlsx') {
        $download=$call('export',$binding($run),$run['run_id']);
        if (!$download instanceof think\response\File || !is_file($download->getData())) throw new RuntimeException('LIVE_DOWNLOAD_INVALID');
        $book=PhpOffice\PhpSpreadsheet\IOFactory::load($download->getData());
        try {
            $sheet=$book->getActiveSheet();
            if ($expectedRows!==null) foreach($expectedRows as $i=>$expected) {
                $actual=$sheet->getCell('I'.($i+2))->getValue();$cents=(int)$expected['amount_cents'];
                $digits=str_pad(ltrim((string)$cents,'-'),3,'0',STR_PAD_LEFT);$wanted=($cents<0?'-':'').substr($digits,0,-2).'.'.substr($digits,-2);
                if (number_format((float)$actual,2,'.','')!==$wanted) throw new RuntimeException('LIVE_EXCEL_CENTS_MISMATCH');
            }
        } finally {$book->disconnectWorksheets();}
        echo "LIVE_DOWNLOAD_GATE_AND_EXCEL_CENTS_MATCH\n";
    }
    if(getenv('MOHE_ANALYSIS_TEST_FOLLOWUP')==='1') {
        if($mode!=='TEST_HISTORY' || getenv('MOHE_ANALYSIS_TEST_NAMED')==='1')throw new RuntimeException('LIVE_FOLLOWUP_FIXTURE_INVALID');
        $input['context_ref']=$run['answer']['context_ref']??'';
        $input['client_request_id']='followup-'.bin2hex(random_bytes(8));
        $input['question']='那本月做最好的呢';
        $input['history']=[['question'=>$question,'answer'=>$run['answer']['summary']]];
        $run=$call('create',$input);$run=$call('execute',$input+$binding($run),$run['run_id']);
        if($input['output_format']==='screen_and_xlsx'&&$run['status']==='WAITING_EXPORT') {
            $task=think\facade\Db::name('unified_query_export_task')->where('source_type','AI')->where('status','pending')->order('id','desc')->field('task_no')->find();
            if(!$task||!is_string($task['task_no']??null))throw new RuntimeException('LIVE_FOLLOWUP_EXPORT_TASK_MISSING');
            app\services\ai\execution\AiExportRuntime::process($task['task_no']);
            $run=$call('status',$binding($run),$run['run_id']);
        }
        $until=microtime(true)+30;
        while(in_array($run['status'],['WAITING_EXPORT','WORKFLOW_EXECUTING'],true)&&microtime(true)<$until){usleep(250000);$run=$call('status',$binding($run),$run['run_id']);}
        if($run['status']!=='COMPLETED')throw new RuntimeException('LIVE_FOLLOWUP_FAILED_'.($run['reason']??$run['status']));
        $start=date('Y-m-01');$end=date('Y-m-d');
        $monthly=(clone $query)->where('p.business_date','>=',$start)->where('p.business_date','<=',$end)
            ->fieldRaw('p.employee_id,SUM(p.amount_cents) amount_cents')->group('p.employee_id')->order('amount_cents','desc')->order('p.employee_id','asc')->limit(1)->select()->toArray();
        $rows=$run['answer']['table']['rows']??[];
        if(count($rows)!==count($monthly)||strpos($run['answer']['summary'],$start.' 至 '.$end)===false||strpos($run['answer']['summary'],'评价指标：劳动业绩')===false)
            throw new RuntimeException('LIVE_FOLLOWUP_CONDITIONS_MISMATCH');
        foreach($monthly as $i=>$expected)if($rows[$i]['label']!==$selection['names'][(int)$expected['employee_id']]||$rows[$i]['value']!==app\services\query\metric\MetricMoneyFormatter::integerYuan((int)$expected['amount_cents'])||$rows[$i]['rank']!=='前1')
            throw new RuntimeException('LIVE_FOLLOWUP_PARITY_MISMATCH');
        if($input['output_format']==='screen_and_xlsx') {
            $download=$call('export',$binding($run),$run['run_id']);
            if(!$download instanceof think\response\File)throw new RuntimeException('LIVE_FOLLOWUP_DOWNLOAD_INVALID');
            $book=PhpOffice\PhpSpreadsheet\IOFactory::load($download->getData());
            try {foreach($monthly as $i=>$expected)if((int)round((float)$book->getActiveSheet()->getCell('I'.($i+2))->getValue()*100)!==(int)$expected['amount_cents'])throw new RuntimeException('LIVE_FOLLOWUP_EXCEL_MISMATCH');}
            finally {$book->disconnectWorksheets();}
        }
        echo "LIVE_FOLLOWUP_MONTH_SCOPE_METRIC_RANK_AND_EXCEL_MATCH\n";
    }
    echo json_encode(['result'=>'PASS','real_model'=>true,'real_fact_query'=>true,'rendered_rows'=>count($run['answer']['table']['rows']??[]),
        'source'=>'local_authorized_instance','business_records_modified'=>false])."\n";
} catch(Throwable $e) {
    $code=$e->getMessage();if(!preg_match('/^[A-Z][A-Z0-9_]{2,80}$/D',$code))$code='LIVE_INTERNAL_ERROR';
    echo json_encode(['result'=>'FAILED','code'=>$code,'class'=>get_class($e),'line'=>$e->getLine()])."\n";exit(1);
}

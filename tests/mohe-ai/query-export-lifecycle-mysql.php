<?php
// Included only by the disposable MySQL fixture runner; no application boot or credentials.
if (getenv('MOHE_QUERY_TEST_DISPOSABLE') !== 'yes' || !isset($pdo,$db)) throw new RuntimeException('fixture required');
$pdo->exec("CREATE TABLE eb_unified_query_export_task (
 id INT PRIMARY KEY AUTO_INCREMENT, task_no VARCHAR(64), tenant_id VARCHAR(64), account_id INT DEFAULT 1,
 operator_id INT DEFAULT 1, origin_store_id INT DEFAULT 1, origin_organization_id INT DEFAULT 1,
 page_code VARCHAR(64) DEFAULT 'fixture', export_scope VARCHAR(16) DEFAULT 'query', status VARCHAR(32),
 query_payload LONGTEXT, field_snapshot LONGTEXT, alias_snapshot LONGTEXT, frozen_scope LONGTEXT,
 permission_fingerprint VARCHAR(128), permission_version VARCHAR(128), file_name VARCHAR(128), error_reason VARCHAR(255),
 storage_key VARCHAR(512) DEFAULT '', lease_token VARCHAR(128) DEFAULT '', lease_expires_at INT DEFAULT 0,
 result_count INT DEFAULT 0, query_cutoff_date DATE, data_as_of INT DEFAULT 0, include_summary INT DEFAULT 0,
 created_at INT, updated_at INT, completed_at INT DEFAULT 0, expires_at INT DEFAULT 0, started_at INT DEFAULT 0, attempt_count INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$makeTaskService = function () {
    $reflection = new ReflectionClass(app\services\query\UnifiedQueryExportTaskServices::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $property=$reflection->getProperty('references'); $property->setAccessible(true);
    $property->setValue($service,new class { public function release($tenant,$kind,$task):void {} public function register(...$args):void {} });
    return $service;
};
$legacy=$makeTaskService();
mysqlCheck(!$legacy->hasSourceContract(), 'legacy report schema remains available');
$legacy->assertExecutionPartition(['task_no'=>'old'], 'REPORT');
mysqlCheck($legacy->executablePlan(['query_payload'=>'{"page_code":"fixture"}'])===['page_code'=>'fixture'], 'legacy report original plan unchanged');
$pdo->exec("ALTER TABLE eb_unified_query_export_task ADD source_type VARCHAR(16) NOT NULL");
$db->connect()->getSchemaInfo('eb_unified_query_export_task', true);
$partialRejected=false; try {$makeTaskService()->hasSourceContract();} catch (RuntimeException $e) {$partialRejected=$e->getMessage()==='EXPORT_SOURCE_SCHEMA_INCOMPLETE';}
mysqlCheck($partialRejected,'partial source migration cannot masquerade as legacy REPORT');
$pdo->exec("ALTER TABLE eb_unified_query_export_task ADD ai_binding LONGTEXT NOT NULL");
$db->connect()->getSchemaInfo('eb_unified_query_export_task', true); // Models the required consumer restart after migration.
$service=$makeTaskService();
mysqlCheck($service->hasSourceContract(), 'explicit migrated source contract detected');
$now=time();
$binding=['instance_fingerprint'=>'fixture','terminal'=>'platform','conversation_id'=>'local-fixture','run_id'=>'fixture-run','account_id'=>1,'generation'=>1,
    'evidence_ref'=>'evidence','canonical_result_ref'=>'result','read_consistency_ref'=>'read','created_at'=>$now,'expires_at'=>$now+600,
    'execution_deadline_at'=>$now+60,'source_expires_at'=>[$now+600]];
$plan=['page_code'=>'fixture','query'=>['export'=>['scope'=>'query']],'plan'=>['page_code'=>'fixture']];
$row=['source_type'=>'AI','task_no'=>'uqe_'.str_repeat('a',32),'tenant_id'=>'fixture','status'=>'running','export_scope'=>'query',
    'ai_binding'=>json_encode($binding),'query_payload'=>json_encode($plan),'lease_token'=>'lease','lease_expires_at'=>$now+300,
    'created_at'=>$now,'updated_at'=>$now,'expires_at'=>$now+600];
$phases=[]; $allow=true;
$service->setAiFence(function ($actual,$phase,$action) use (&$phases,&$allow,$binding) {
    if (!$allow || $actual !== $binding) throw new RuntimeException('fixture runtime fence rejected');
    $phases[]=$phase; return $action();
});
mysqlCheck($service->executablePlan($row)===['page_code'=>'fixture'], 'AI envelope unwrap preserves shared compiler plan');
foreach ([['source_type'=>''],['export_scope'=>'page'],['query_payload'=>json_encode(['page_code'=>'fixture','query'=>['export'=>['scope'=>'page']],'plan'=>['page_code'=>'fixture']])]] as $mutation) {
    $rejected=false; try {$service->executablePlan(array_replace($row,$mutation));} catch(Throwable $e) {$rejected=true;}
    mysqlCheck($rejected,'missing source or either page scope is rejected');
}
$db->connect()->name('unified_query_export_task')->insert($row);
$workerPages=new app\services\query\UnifiedQueryPageRegistry();
$providers=new app\services\query\UnifiedQueryProviderRegistry($workerPages);
$resolvers=new app\services\query\UnifiedQueryWorkerContextResolverRegistry($workerPages);
$ordinaryWorker=new app\services\query\UnifiedQueryExportWorkerServices($service,$providers,$resolvers,'REPORT');
$wrongWorkerRejected=false;
try {$ordinaryWorker->processOne($row['task_no']);} catch (app\services\query\UnifiedQueryException $e) {$wrongWorkerRejected=$e->getErrorCode()==='UNIFIED_QUERY_EXPORT_PARTITION_MISMATCH';}
mysqlCheck($wrongWorkerRejected && $pdo->query('SELECT status FROM eb_unified_query_export_task')->fetchColumn()==='running','wrong real Worker rejects AI before catch and leaves state unchanged');
$service->renewLease('fixture',$row['task_no'],'lease');
mysqlCheck(in_array('heartbeat',$phases,true),'AI lease renewal crosses current Run fence');
$allow=false; $rejected=false;
try {$service->complete('fixture',$row['task_no'],1,'unified-query-exports/fixture/file.xlsx',$now+86400,'lease');} catch(Throwable $e) {$rejected=true;}
mysqlCheck($rejected && $pdo->query('SELECT status FROM eb_unified_query_export_task')->fetchColumn()==='running','cancel fence prevents late completion');
$allow=true;
$service->complete('fixture',$row['task_no'],1,'unified-query-exports/fixture/file.xlsx',$now+86400,'lease');
mysqlCheck((int)$pdo->query('SELECT expires_at FROM eb_unified_query_export_task')->fetchColumn()===$now+600,'completion does not extend source expiry');
$cancel=$row; $cancel['task_no']='uqe_'.str_repeat('b',32); $cancel['status']='pending';
$db->connect()->name('unified_query_export_task')->insert($cancel);
$ordinaryWorker->processPending(1,$cancel['task_no']);
mysqlCheck($db->connect()->name('unified_query_export_task')->where('task_no',$cancel['task_no'])->value('status')==='pending','ordinary Worker scan never selects pending AI');
$reportRow=$row; $reportRow['task_no']='uqe_'.str_repeat('c',32); $reportRow['source_type']='REPORT'; $reportRow['ai_binding']=''; $reportRow['status']='pending';
$db->connect()->name('unified_query_export_task')->insert($reportRow);
$aiWorker=new app\services\query\UnifiedQueryExportWorkerServices($service,$providers,$resolvers,'AI_EXPORT');
$aiWorker->processPending(1,$reportRow['task_no']);
mysqlCheck($db->connect()->name('unified_query_export_task')->where('task_no',$reportRow['task_no'])->value('status')==='pending','AI Worker scan never selects pending REPORT');
$db->connect()->name('unified_query_export_task')->where('task_no',$reportRow['task_no'])->delete();
mysqlCheck($service->cancelAiTask('fixture',$cancel['task_no']), 'trusted cancel fences pending AI task');
$rejected=false; try {$service->complete('fixture',$cancel['task_no'],1,'unified-query-exports/fixture/file.xlsx',$now+600,'lease');} catch(Throwable $e) {$rejected=true;}
mysqlCheck($rejected,'cancelled SQL state rejects late complete despite permissive fixture runtime');
foreach (['pending','running','failed'] as $i=>$status) {
    $expiredRow=$row; $expiredRow['task_no']='uqe_'.str_repeat((string)($i+1),32); $expiredRow['status']=$status;
    $db->connect()->name('unified_query_export_task')->insert($expiredRow);
}
$pdo->exec('UPDATE eb_unified_query_export_task SET expires_at='.($now-1));
$clean=$service->cleanupAiExpired(20,function ($task) {return false;});
mysqlCheck($clean['purged']===0 && $clean['retry']===5,'failed file erase never reports successful cleanup');
mysqlCheck((int)$pdo->query("SELECT COUNT(*) FROM eb_unified_query_export_task WHERE query_payload<>'' OR frozen_scope<>'' OR ai_binding<>'' OR account_id<>0")->fetchColumn()===0,'file failure still erases business and owner payloads');
$clean=$service->cleanupAiExpired(20,function ($task) {return true;});
mysqlCheck($clean['purged']===5 && (int)$pdo->query('SELECT COUNT(*) FROM eb_unified_query_export_task')->fetchColumn()===0,'all AI states fully removed at root expiry');
$createService=$makeTaskService();
$pages=app\services\query\UnifiedQueryPageRegistry::fromRegistrars([], [new app\services\query\metric\MetricReadViewExportRegistrar()]);
$compiler=new app\services\query\UnifiedQueryExecutionServices($pages,new app\services\query\StructuredExpressionValidator($pages),new app\services\query\StructuredExpressionEvaluator());
$properties=['registry'=>$pages,'execution'=>$compiler,'access'=>new app\services\query\UnifiedQueryAccessPolicy(),
    'customFields'=>new class { public function listVisible(...$args):array {return [];} }, 'preferences'=>new class {public function load(...$args):array {return [];} }];
foreach ($properties as $name=>$value) {$property=(new ReflectionClass($createService))->getProperty($name); $property->setAccessible(true); $property->setValue($createService,$value);}
$createService->setAiFence(function ($b,$phase,$action) {return $action();}); // Fixture only, current-owner fence behavior tested above.
$createBinding=$binding; $createBinding['read_consistency_ref']='mrv_'.str_repeat('a',48);
$createContext=['tenant_id'=>'fixture','account_id'=>1,'operator_id'=>1,'store_id'=>1,'organization_id'=>'1','page_code'=>'metric_read_view_export',
    'permissions'=>['policy:unified_query_page','mohe.ai.export'],'visible_store_ids'=>[1],'ancestor_organization_ids'=>['1'],
    'scope_dimensions'=>['metric_read_ref'=>[$createBinding['read_consistency_ref']]],'query_cutoff_date'=>'2026-09-08','data_as_of'=>$now,'permission_version'=>'fixture'];
$fields=array_keys(app\services\query\metric\MetricReadViewExportRegistrar::fields());
$created=$createService->createAi($createContext,['page_code'=>'metric_read_view_export','scope'=>'query','fields'=>$fields,'query'=>['visibleFields'=>$fields,'export'=>['scope'=>'query']]],$createBinding);
$createdTask=$db->connect()->name('unified_query_export_task')->where('task_no',$created['taskId'])->find();
mysqlCheck($createdTask['source_type']==='AI' && $createService->executablePlan($createdTask)['visible_fields']===$fields,'real createAi SQL freezes full fields through shared compiler');
$claim=$createService->claim($createContext,$created['taskId'],'AI_EXPORT');
mysqlCheck($claim['status']==='running' && $claim['export_scope']==='query','real AI claim validates frozen scope and explicit partition');
$db->connect()->name('unified_query_export_task')->where('task_no',$created['taskId'])->delete();

<?php
declare(strict_types=1);
require __DIR__.'/http-guard-contract.php';
require __DIR__.'/state-store-contract.php';
$fixtureRoot=sys_get_temp_dir().'/mohe-ai-runtime-'.bin2hex(random_bytes(8)); mkdir($fixtureRoot,0700);
$app->setRuntimePath($fixtureRoot.'/');
$private=new \app\services\ai\config\AiPrivateStorage($fixtureRoot.'/objects');
$views=new \app\services\query\metric\MetricReadViewStore($fixtureRoot.'/views',$private->signingKey());
$db=new PDO('sqlite::memory:'); installAiStateFixture($db);
$runs=new \app\services\ai\execution\AiRunStore($db,'','fixture.instance');
$runtime=new \app\services\ai\execution\AiExportRuntime(['instance'=>'fixture.instance','private'=>$private,'views'=>$views,'runs'=>$runs,'config'=>new class {public function read(){return ['version'=>1];}}]);
$check(!$runtime->ready(),'Default readiness is closed before touching shared schema');
$r=$runs->create($owner,'runtime-export',$hash,$snapshot)['run']; $runs->claim($owner,$r['run_id'],$r['generation'],'worker');
$object=['owner'=>$owner,'run_id'=>$r['run_id'],'generation'=>$r['generation'],'view_ref'=>'mrv_'.str_repeat('a',48),'query'=>[]];
$eref=$private->put('evidence',$object,time()+300);
$binding=['instance_fingerprint'=>'fixture.instance','terminal'=>$owner['terminal'],'account_id'=>$owner['account_id'],'conversation_id'=>$owner['conversation_id'],
    'window_id'=>$owner['window_id'],'run_id'=>$r['run_id'],'generation'=>$r['generation'],'evidence_ref'=>$eref,'read_consistency_ref'=>$object['view_ref'],
    'expires_at'=>time()+300,'execution_deadline_at'=>intdiv($r['deadline_at'],1000)];
$reflect=new ReflectionClass($runtime); $sign=$reflect->getMethod('signature'); $sign->setAccessible(true);
$validate=$reflect->getMethod('validateBinding'); $validate->setAccessible(true);
$binding['signature']=$sign->invoke($runtime,$binding);
$check($validate->invoke($runtime,$binding,'status')['run_id']===$r['run_id'],'Signed binding joins authoritative Run and private evidence');
$bad=$binding; $bad['generation']++;
try {$validate->invoke($runtime,$bad,'status'); throw new LogicException('expected rejection');} catch (RuntimeException $e) {$check($e->getMessage()==='AI_EXPORT_SIGNATURE_INVALID','Signed generation mutation rejected');}
$bad=$binding; $bad['instance_fingerprint']='other'; $bad['signature']=$sign->invoke($runtime,$bad);
try {$validate->invoke($runtime,$bad,'status'); throw new LogicException('expected rejection');} catch (RuntimeException $e) {$check($e->getMessage()==='AI_EXPORT_SIGNATURE_INVALID','Even signed foreign instance is rejected');}
$lock=$reflect->getMethod('lock'); $lock->setAccessible(true);
$slot=$lock->invoke($runtime,'slot-0'); $check(is_resource($slot),'Physical file slot acquired');
$check($lock->invoke($runtime,'slot-0')===null,'Occupied physical slot cannot be oversold'); flock($slot,LOCK_UN); fclose($slot);
$slot=$lock->invoke($runtime,'slot-0'); $check(is_resource($slot),'Physical slot releases only after actual unlock'); flock($slot,LOCK_UN); fclose($slot);
$workerReflection=new ReflectionClass(\app\services\query\UnifiedQueryExportWorkerServices::class); $worker=$workerReflection->newInstanceWithoutConstructor();
$write=$workerReflection->getMethod('writeXlsx'); $write->setAccessible(true);
$view=['query'=>['start_date'=>'2026-09-08','end_date'=>'2026-09-08','query_shape'=>'summary'],
    'results'=>[['metric_code'=>'consume_amount','storage_unit'=>'fen','period'=>'current','amount_cents'=>12345]]];
$fields=[]; foreach (\app\services\query\metric\MetricReadViewExportRegistrar::fields() as $key=>$label) $fields[]=['key'=>$key,'label'=>$label,'type'=>$key==='amount_yuan'?'amount':'text'];
$key=$write->invoke($worker,'uqe_'.str_repeat('e',32),str_repeat('f',64),$fields,\app\services\query\metric\MetricReadViewExportProvider::project($view),[],false,null);
$verify=$reflect->getMethod('verifyFile'); $verify->setAccessible(true); $verify->invoke($runtime,$fixtureRoot.'/'.$key,$view);
$check(true,'Actual shared XLSX passes exact original-result readback');
$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($fixtureRoot.'/'.$key); $book->getActiveSheet()->setCellValue('I2',999); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($fixtureRoot.'/'.$key); $book->disconnectWorksheets();
try {$verify->invoke($runtime,$fixtureRoot.'/'.$key,$view); throw new LogicException('expected mismatch');} catch (RuntimeException $e) {$check($e->getMessage()==='AI_EXPORT_CONTENT_MISMATCH','Tampered amount prevents publication');}
// Exact newly-created isolated fixture root; contains no application or user artifacts.
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); } rmdir($fixtureRoot);
echo "PASS 9 runtime registry/signature/physical-lock/real-XLSX checks; no production DB/model.\n";

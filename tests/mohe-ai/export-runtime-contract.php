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
$view=['query'=>['start_date'=>'2026-09-08','end_date'=>'2026-09-08','query_shape'=>'summary','compare_range'=>null],
    'results'=>[['metric_code'=>'consume_amount','storage_unit'=>'fen','period'=>'current','amount_cents'=>12345]]];
$fields=[]; foreach (\app\services\query\metric\MetricReadViewExportRegistrar::fields() as $key=>$label) $fields[]=['key'=>$key,'label'=>$label,'type'=>$key==='metric_value'?'decimal':'text'];
$key=$write->invoke($worker,'uqe_'.str_repeat('e',32),str_repeat('f',64),$fields,\app\services\query\metric\MetricReadViewExportProvider::project($view),[],false,null);
$verify=$reflect->getMethod('verifyFile'); $verify->setAccessible(true); $verify->invoke($runtime,$fixtureRoot.'/'.$key,$view);
$check(true,'Actual shared XLSX passes exact original-result readback');
// Both enabled metrics must use the same immutable projection for every shape.
// Synthetic values only: no business DB, model or real customer export.
$cash=['metric_code'=>'cash_performance','storage_unit'=>'fen','period'=>'current'];
$consume=['metric_code'=>'consume_amount','storage_unit'=>'fen','period'=>'current'];
$fixtures=[];
$fixtures['summary']=['query'=>$view['query'],'results'=>[
    $cash+['amount_cents'=>12345,'metric_name'=>'UNTRUSTED_LABEL'],
    $consume+['amount_cents'=>-1],
]];
$fixtures['comparison']=['query'=>array_replace($view['query'],['query_shape'=>'comparison','compare_range'=>['start'=>'2026-09-07','end'=>'2026-09-07']]),'results'=>[
    $cash+['amount_cents'=>12345],$consume+['amount_cents'=>0],
    array_replace($cash,['period'=>'comparison','amount_cents'=>99]),
    array_replace($consume,['period'=>'comparison','amount_cents'=>-101]),
]];
$fixtures['trend']=['query'=>array_replace($view['query'],['query_shape'=>'trend','start_date'=>'2026-09-07']),'results'=>[
    $cash+['rows'=>[['business_date'=>'2026-09-07','amount_cents'=>1],['business_date'=>'2026-09-08','amount_cents'=>10001]]],
    $consume+['rows'=>[['business_date'=>'2026-09-07','amount_cents'=>0],['business_date'=>'2026-09-08','amount_cents'=>-99]]],
]];
$fixtures['ranking']=['query'=>array_replace($view['query'],['query_shape'=>'ranking']),'results'=>[
    $cash+['rows'=>['top'=>[['store_name'=>'测试甲店','amount_cents'=>12345]],'bottom'=>[['store_name'=>'测试乙店','amount_cents'=>1]]]],
    $consume+['rows'=>['top'=>[['store_name'=>'测试乙店','amount_cents'=>9999]],'bottom'=>[['store_name'=>'测试甲店','amount_cents'=>-1]]]],
]];
$project=\app\services\query\metric\MetricReadViewExportProvider::class;
foreach ($fixtures as $shape=>$fixture) {
    $rows=$project::project($fixture);
    $check(array_values(array_unique(array_column($rows,'metric_name')))===['现金业绩','消耗业绩'], $shape.' preserves both registered names');
    $check(array_column($rows,'row_id')===array_map('strval',range(1,count($rows))), $shape.' has stable result IDs');
    $tables=$project::presentationTables($fixture);
    $fileKey=$write->invoke($worker,'uqe_'.md5('fixture-'.$shape),str_repeat('f',64),$fields,$rows,[],false,null,$tables);
    $verify->invoke($runtime,$fixtureRoot.'/'.$fileKey,$fixture);
    $check(true, $shape.' passes real writer and original-evidence readback');
    $sheetBook=\PhpOffice\PhpSpreadsheet\IOFactory::load($fixtureRoot.'/'.$fileKey);
    foreach ($rows as $i=>$row) {
        $sheet=$tables?$sheetBook->getSheet(1):$sheetBook->getActiveSheet();
        $check($sheet->getCell('B'.($i+2))->getValue()===$row['metric_name'], $shape.' XLSX correct metric label');
        $check(number_format((float)$sheet->getCell('I'.($i+2))->getValue(),2,'.','')===$row['metric_value'], $shape.' XLSX cents preserved');
    }
    if ($tables) {
        $visible=$sheetBook->getSheet(0);
        $check($visible->getCell('A1')->getValue()===$tables[0]['summary']
            &&$visible->getCell('A2')->getValue()===$tables[0]['columns'][0]['label'],
            $shape.' visible XLSX uses screen summary and column label');
        $check($sheetBook->getSheet(1)->getSheetState()===\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN,
            $shape.' precise source remains hidden, not a competing user-facing table');
    }
    $sheetBook->disconnectWorksheets();
}
$summaryRows=$project::project($fixtures['summary']);
$check(array_column($summaryRows,'metric_value')===['123.45','-0.01'], 'mixed summary is formatted without re-computing totals');
$countFixture=['query'=>$view['query'],'results'=>[[
    'metric_code'=>'completed_service_item_count','storage_unit'=>'count','period'=>'current','count'=>3,
], [
    'metric_code'=>'customer_active','storage_unit'=>'count','period'=>'current','count'=>2,
]]];
$countRows=$project::project($countFixture);
$check(array_column($countRows,'metric_value')===['3','2'] && array_column($countRows,'unit')===['项','人'],
    'registered counts export as counts with their own unit');
$countKey=$write->invoke($worker,'uqe_'.md5('fixture-count'),str_repeat('f',64),$fields,$countRows,[],false,null);
$verify->invoke($runtime,$fixtureRoot.'/'.$countKey,$countFixture);
$countBook=\PhpOffice\PhpSpreadsheet\IOFactory::load($fixtureRoot.'/'.$countKey);
$check((string)(int)$countBook->getActiveSheet()->getCell('I2')->getValue()==='3' && $countBook->getActiveSheet()->getCell('J2')->getValue()==='项',
    'count XLSX keeps numeric value and visible unit');
$countBook->disconnectWorksheets();
$large=$fixtures['summary'];
$large['results'][0]['amount_cents']=PHP_INT_MAX;
$large['results'][1]['amount_cents']=PHP_INT_MIN;
$largeRows=$project::project($large);
$check(array_column($largeRows,'metric_value')===['92233720368547758.07','-92233720368547758.08'], '64-bit cents boundaries never pass through float');
$largeKey=$write->invoke($worker,'uqe_'.md5('fixture-large'),str_repeat('f',64),$fields,$largeRows,[],false,null);
$verify->invoke($runtime,$fixtureRoot.'/'.$largeKey,$large);
$largeBook=\PhpOffice\PhpSpreadsheet\IOFactory::load($fixtureRoot.'/'.$largeKey);
$check($largeBook->getActiveSheet()->getCell('I2')->getValue()===$largeRows[0]['metric_value'] && $largeBook->getActiveSheet()->getCell('I3')->getValue()===$largeRows[1]['metric_value'], 'large exact amounts survive actual XLSX writer as precise text');
$largeBook->disconnectWorksheets();
$comparisonRows=$project::project($fixtures['comparison']);
$check(array_column($comparisonRows,'period_name')===['本期','本期','对比期','对比期'] && array_column($comparisonRows,'start_date')===['2026-09-08','2026-09-08','2026-09-07','2026-09-07'], 'comparison preserves exact periods and ranges');
$check(array_column($project::project($fixtures['trend']),'business_date')===['2026-09-07','2026-09-08','2026-09-07','2026-09-08'], 'both metric trends preserve dates');
$rankingRows=$project::project($fixtures['ranking']);
$check(array_column($rankingRows,'ranking_direction')===['前列','后列','前列','后列'] && array_column($rankingRows,'store_name')===['测试甲店','测试乙店','测试乙店','测试甲店'], 'ranking preserves direction and store identity per metric');
foreach (['cash_amount','custom_formula','',null] as $invalidMetric) {
    $badView=$fixtures['summary']; $badView['results'][0]['metric_code']=$invalidMetric;
    try {$project::project($badView); throw new LogicException('expected metric rejection');} catch (RuntimeException $e) {$check($e->getMessage()==='METRIC_EXPORT_METRIC_NOT_READY','unregistered/unready metric remains closed');}
}
foreach (['12345',123.45,null] as $invalidAmount) {
    $badView=$fixtures['summary']; $badView['results'][0]['amount_cents']=$invalidAmount;
    try {$project::project($badView); throw new LogicException('expected cents rejection');} catch (RuntimeException $e) {$check(in_array($e->getMessage(),['METRIC_EXPORT_AMOUNT_INVALID','METRIC_EXPORT_RESULT_INVALID'],true),'non-integer amounts cannot be exported');}
}
$badView=$fixtures['summary']; $badView['results'][0]['storage_unit']='yuan';
try {$project::project($badView); throw new LogicException('expected unit rejection');} catch (RuntimeException $e) {$check($e->getMessage()==='METRIC_EXPORT_METRIC_NOT_READY','unit cannot silently change');}
$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($fixtureRoot.'/'.$key); $book->getActiveSheet()->setCellValue('I2',999); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($fixtureRoot.'/'.$key); $book->disconnectWorksheets();
try {$verify->invoke($runtime,$fixtureRoot.'/'.$key,$view); throw new LogicException('expected mismatch');} catch (RuntimeException $e) {$check($e->getMessage()==='AI_EXPORT_CONTENT_MISMATCH','Tampered amount prevents publication');}
// Exercise the same production writer/readback fence for rights; these are
// synthetic assets, with cents chosen to differ from rounded screen amounts.
$assetDetail=['projectionContractVersion'=>'cashier-v3-member-detail-v3','dataAsOf'=>'2026-09-24T20:00:00+08:00',
    'member'=>['memberId'=>101,'name'=>'测试会员'],'summary'=>[
        'accountBalance'=>'1288.60','principalBalance'=>'1000.00','giftBalance'=>'288.60',
        'activeCardCount'=>1,'remainingProjectTimes'=>6,'remainingProjectAmount'=>'1800.49'],
    'cards'=>[['cardName'=>'护理卡','statusLabel'=>'有效','remainingTimes'=>6,'remainingAmount'=>'1800.49','expiresAt'=>'2027-09-24']]];
$assets=\app\services\ai\presentation\AiMemberRightsExportProjection::capture(
    [['detail'=>$assetDetail,'label'=>'测试会员','selection_ref'=>'member:101']],'rights');
// Retaining general AI permission is not enough to download private assets.
// This deny must happen before any member or card query is attempted.
$principalProperty=$reflect->getProperty('principalResolver');$principalProperty->setAccessible(true);
$principalProperty->setValue($runtime,static function(){return ['can_use'=>true,'member_data_authorized'=>false,
    'permission_version'=>'fixture','scope_mode'=>'stores','store_ids'=>[1]];});
$assetGuard=$reflect->getMethod('assertMetricExportSource');$assetGuard->setAccessible(true);
try {$assetGuard->invoke($runtime,['workflow_code'=>'wf_member_detail_read','member_rights_export'=>$assets]);
    throw new LogicException('accepted revoked member capability');}
catch(RuntimeException $e){$check($e->getMessage()==='AI_MEMBER_PERMISSION_REQUIRED','revoked member capability blocks asset export before DB access');}
$principalProperty->setValue($runtime,null);
$assetView=$project::withMemberRights($view+['result_hash'=>'fixture-context'],$assets);
$assetKey=$write->invoke($worker,'uqe_'.md5('fixture-rights'),str_repeat('f',64),$fields,$project::project($assetView),[],false,null);
$verify->invoke($runtime,$fixtureRoot.'/'.$assetKey,$assetView);
$assetBook=\PhpOffice\PhpSpreadsheet\IOFactory::load($fixtureRoot.'/'.$assetKey);
$check((string)$assetBook->getActiveSheet()->getCell('I2')->getValue()==='1288.6'
    &&(string)$assetBook->getActiveSheet()->getCell('I9')->getValue()==='1800.49',
    'rights XLSX is numeric, exact to cents and agrees with original asset evidence');
$assetBook->disconnectWorksheets();
try {$verify->invoke($runtime,$fixtureRoot.'/'.$assetKey,$view);throw new LogicException('accepted rights file as metrics');}
catch(RuntimeException $e){$check($e->getMessage()==='AI_EXPORT_CONTENT_MISMATCH','asset file cannot pass a metric-only evidence fence');}
// R57: a 21-person screen previews 20 rows, while the visible workbook keeps
// the identical two business tables and all 21 rows. The hidden source tab
// retains cents so rounded display values never overwrite exact evidence.
$members=[];$population=[];
for ($i=1;$i<=21;$i++) {
    $detail=$assetDetail;$detail['member']['memberId']=100+$i;$detail['member']['name']='测试会员'.$i;
    $members[]=['detail'=>$detail,'label'=>'测试会员'.$i,'selection_ref'=>'member:'.(100+$i)];
    $population[]=['member_id'=>100+$i,'member_name'=>'测试会员'.$i,'metrics'=>['sales_collected_amount'=>890001]];
}
$condition=['subject'=>'member','relation'=>'all','conditions'=>[['metric_code'=>'sales_collected_amount','operator'=>'gte','value'=>30000]]];
$populationView=['query'=>['start_date'=>'2026-09-01','end_date'=>'2026-09-30','query_shape'=>'condition_list',
    'compare_range'=>null,'business_filters'=>['object_kind'=>'member'],'metric_codes'=>['sales_collected_amount'],
    'condition_set'=>$condition], 'results'=>[['period'=>'current','metric_code'=>'sales_collected_amount',
    'storage_unit'=>'count','object_kind'=>'member','condition_set'=>$condition,'count'=>21,
    'list_limit'=>100,'has_more'=>false,'rows'=>$population]]];
$screen=(new \app\services\ai\presentation\AiAnswerRenderer())->render($populationView);
$check(count($screen['table']['rows'])===20 && $screen['table']['total_rows']===21,'screen previews exactly 20 people');
$rightsAnswer=(new \app\services\ai\presentation\AiMemberDetailAnswerRenderer())->renderSet($members,'summary');
$assetsV2=\app\services\ai\presentation\AiMemberRightsExportProjection::capture(
    $members,'summary',$project::project($populationView),$rightsAnswer);
$fullView=$project::withMemberRights($populationView+['result_hash'=>'fixture-combined'],$assetsV2);
$tables=$project::presentationTables($fullView);
$check(count($tables)===2 && $tables[0]['title']==='会员筛选依据'
    && $tables[1]['title']==='会员权益汇总' && count($tables[0]['rows'])===21
    && count($tables[1]['rows'])===21,'both full export tables retain screen hierarchy');
$check(array_column($tables[1]['columns'],'label')===['会员','所属门店','账户余额（元）','本金余额（元）',
    '赠送余额（元）','有效卡项（张）','剩余项目（次）','剩余项目金额（元）'],
    'rights workbook columns exactly equal the visible screen columns');
$fullKey=$write->invoke($worker,'uqe_'.md5('fixture-visible-rights'),str_repeat('f',64),$fields,
    $project::project($fullView),[],false,null,$tables);
$verify->invoke($runtime,$fixtureRoot.'/'.$fullKey,$fullView);
$fullBook=\PhpOffice\PhpSpreadsheet\IOFactory::load($fixtureRoot.'/'.$fullKey);
$check($fullBook->getSheet(0)->getCell('A1')->getValue()==='会员筛选依据'
    &&$fullBook->getSheet(0)->getCell('A26')->getValue()==='会员权益汇总'
    &&$fullBook->getSheet(0)->getCell('A49')->getValue()==='测试会员21',
    'actual XLSX first sheet has both complete business tables');
$check($fullBook->getSheet(0)->getCell('C29')->getValue()==='1,289'
    &&$fullBook->getSheet(1)->getCell('I2')->getValue()==8900.01
    &&$fullBook->getSheet(1)->getCell('I23')->getValue()==1288.6,
    'displayed rounded amount matches screen and hidden source retains cents');
$fullBook->disconnectWorksheets();
$tamperedBook=\PhpOffice\PhpSpreadsheet\IOFactory::load($fixtureRoot.'/'.$fullKey);
$tamperedBook->getSheet(0)->setCellValueExplicit('A1','错误标题',\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($tamperedBook))->save($fixtureRoot.'/'.$fullKey);
$tamperedBook->disconnectWorksheets();
try {$verify->invoke($runtime,$fixtureRoot.'/'.$fullKey,$fullView);throw new LogicException('accepted modified visible workbook');}
catch(RuntimeException $e){$check($e->getMessage()==='AI_EXPORT_CONTENT_MISMATCH','changed visible title is rejected before download');}
// Exact newly-created isolated fixture root; contains no application or user artifacts.
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); } rmdir($fixtureRoot);
echo "PASS runtime registry/signature/physical-lock plus both-metric summary/comparison/trend/ranking real-XLSX and rejection checks; no production DB/model.\n";

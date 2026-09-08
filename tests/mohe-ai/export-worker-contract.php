<?php
declare(strict_types=1);
require __DIR__.'/http-actions-contract.php';
$root=sys_get_temp_dir().'/mohe-ai-writer-'.bin2hex(random_bytes(8));
mkdir($root,0700); $app->setRuntimePath($root.'/');
$reflection=new ReflectionClass(\app\services\query\UnifiedQueryExportWorkerServices::class);
$worker=$reflection->newInstanceWithoutConstructor();
$write=$reflection->getMethod('writeXlsx'); $write->setAccessible(true);
$cleanup=$reflection->getMethod('deleteAiObjects'); $cleanup->setAccessible(true);
$taskNo='uqe_'.str_repeat('a',32); $token=str_repeat('b',64); $ticks=0;
$key=$write->invoke($worker,$taskNo,$token,[['key'=>'label','label'=>'指标','type'=>'text'],['key'=>'amount','label'=>'金额（元）','type'=>'amount']],
    [['label'=>'=HYPERLINK("fixture")','amount'=>'123.45']],[],false,function () use (&$ticks) { ++$ticks; });
$path=$root.'/'.$key;
$check(is_file($path),'Existing shared Writer creates real XLSX');
$xlsx=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);
$check($xlsx->getActiveSheet()->getCell('A2')->getDataType()==='s','Spreadsheet expression remains literal');
$check((string)$xlsx->getActiveSheet()->getCell('B2')->getValue()==='123.45','Shared writer preserves export precision');
$check($ticks>=3,'Writer checks lifecycle at multiple physical boundaries');
$xlsx->disconnectWorksheets(); unset($xlsx);
$cleanup->invoke($worker,['task_no'=>$taskNo]);
$check(!is_file($path),'Task cleanup removes its owned final object');
foreach ([dirname($path),dirname(dirname($path)),$root.'/unified-query-exports',$root] as $directory) { rmdir($directory); }
echo "PASS 5 actual XLSX writer/readback/cleanup checks; no DB/model or business export.\n";

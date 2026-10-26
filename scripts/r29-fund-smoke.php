<?php
/** 生产只读业务回归：沿用本实例已审核事实生成临时Excel并回读；不新增单据或修改金额。 */
$site=$argv[1]??'';$sites=['rh'=>['rh.cc3798.com','ruihao','3306'],'008'=>['008.cc3798.com','mohe_008','3307'],'010'=>['010.cc3798.com','mohe_010_app','3307']];if(!isset($sites[$site]))throw new RuntimeException('INSTANCE_REQUIRED');
[$domain,$database,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();if(config('database.connections.mysql.database')!==$database||(string)config('database.connections.mysql.hostport')!==$port)throw new RuntimeException('INSTANCE_MISMATCH');
set_exception_handler(static function(Throwable $e){file_put_contents('php://stderr',get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});
$service=new app\services\fund\StoreFundServices();$files=[];$rowCount=0;
think\facade\Db::execute('SET TRANSACTION READ ONLY');think\facade\Db::startTrans();
try{
 $sample=think\facade\Db::name('store_fund_document')->alias('d')->join('store_fund_document_line l','l.document_id=d.id')->where('d.document_status','APPROVED')->field('d.store_id,d.business_date')->order('d.id desc')->find();
 if($sample){
  $scope=['mode'=>'store','store_id'=>(int)$sample['store_id']];$filters=['date_from'=>$sample['business_date'],'date_to'=>$sample['business_date']];$all=$service->ledger($scope,$filters,false);$page=$service->ledger($scope,$filters);$rowCount=count($all['list']);if($page['count']!==$all['count']||count($page['list'])!==min(100,$rowCount))throw new RuntimeException('LEDGER_PAGINATION_REGRESSION');
  $export=$service->exportLedger($scope,$filters);$file=$service->exportFilePath($export['file_key']);$files[]=$file;$book=PhpOffice\PhpSpreadsheet\IOFactory::load($file);$sheet=$book->getActiveSheet();if($sheet->getCell('H3')->getValue()!=='摘要')throw new RuntimeException('SUMMARY_HEADER_MISSING');
  foreach($all['list']as $i=>$r){if((int)$r['store_id']!==$scope['store_id'])throw new RuntimeException('STORE_SCOPE_REGRESSION');$expected=(string)($r['summary']??'')!==''?(string)$r['summary']:(string)($r['document_summary']??'');if((string)$sheet->getCell('H'.($i+5))->getValue()!==$expected)throw new RuntimeException('EXPORTED_SUMMARY_MISMATCH');}
  if((string)$sheet->getCell('A'.($rowCount+5))->getValue()!=='合计'||(int)round((float)$sheet->getCell('G'.($rowCount+5))->getValue()*100)!==(int)$all['current_balance_cents'])throw new RuntimeException('RUNNING_BALANCE_REGRESSION');
  $service->report($scope,$filters);$documents=$service->listDocuments($scope,$filters);if(!isset($documents['list'],$documents['count']))throw new RuntimeException('DOCUMENT_LIST_REGRESSION');$book->disconnectWorksheets();
 }else{
  // 空实例只验证真实空范围，不伪造生产资金数据来填满验收路径。
  $empty=$service->ledger(['mode'=>'store','store_id'=>0],[],false);if($empty['list']!==[])throw new RuntimeException('EMPTY_SCOPE_REGRESSION');
 }
 echo json_encode(['site'=>$site,'result'=>'PASS','realLedgerRows'=>$rowCount,'xlsxSummariesReconciled'=>$rowCount,'existingDocumentAndReportRead'=>(bool)$sample,'businessWrites'=>0,'migration'=>'not required']),PHP_EOL;
}finally{think\facade\Db::rollback();foreach($files as $file)if(is_file($file))unlink($file);}

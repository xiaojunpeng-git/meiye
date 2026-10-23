<?php
declare(strict_types=1);

/** 本地真实XLSX回读：摘要原文、空值回退、跨页完整导出及门店隔离；夹具只在回滚事务内存在。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use app\services\fund\StoreFundServices;
use think\facade\Db;
use PhpOffice\PhpSpreadsheet\IOFactory;

if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_DATABASE_ONLY');
$assert=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;};
$subject=Db::name('store_fund_subject')->where('is_enabled',1)->where('subject_type','NORMAL')->find();if(!$subject)throw new RuntimeException('LOCAL_SUBJECT_REQUIRED');
$scope=['mode'=>'store','store_id'=>random_int(900000000,999999990)];$service=new StoreFundServices();$file=null;$ids=[];
$summaries=['0','打印费用明细',"第一行摘要\n第二行摘要",str_repeat('采购物品明细、用途及经办说明；',12),''];
Db::startTrans();
try{
 $now=date('Y-m-d H:i:s');
 // 101条本店明细证明导出复用完整授权结果，不止当前100条；邻店记录必须排除。
 for($i=0;$i<102;$i++){
  $document=(int)Db::name('store_fund_document')->insertGetId(['document_no'=>'R29-SUMMARY-'.$scope['store_id'].'-'.$i,'store_id'=>$scope['store_id']+($i===101?1:0),'business_date'=>'2099-12-31','direction'=>'EXPENSE','document_status'=>'APPROVED','summary'=>'单据摘要回退','remark'=>'','attachments_json'=>'[]','source_document_id'=>0,'created_by_type'=>'STORE_STAFF','created_by_id'=>1,'created_by_name_snapshot'=>'R29 local rollback','audited_by_type'=>'STORE_STAFF','audited_by_id'=>1,'audited_at'=>$now,'audit_revision'=>1,'created_at'=>$now,'updated_at'=>$now]);$ids[]=$document;
  Db::name('store_fund_document_line')->insert(['document_id'=>$document,'line_no'=>1,'subject_id'=>$subject['id'],'subject_code_snapshot'=>$subject['subject_code'],'subject_name_snapshot'=>$subject['subject_name'],'subject_type_snapshot'=>'NORMAL','amount_cents'=>10000,'summary'=>$summaries[$i%count($summaries)],'remark'=>'','created_at'=>$now,'updated_at'=>$now]);
 }
 $filters=['date_from'=>'2099-12-31','date_to'=>'2099-12-31','subject_type'=>'NORMAL','page'=>1];$page=$service->ledger($scope,$filters);$all=$service->ledger($scope,$filters,false);
 $assert(count($page['list'])===100&&count($all['list'])===101,'page has 100 rows; complete authorized export projection has 101');
 $result=$service->exportLedger($scope,$filters);$file=$service->exportFilePath($result['file_key']);$sheet=IOFactory::load($file)->getActiveSheet();
 $assert($sheet->getCell('H3')->getValue()==='摘要','real XLSX includes summary header');
 foreach($all['list']as $i=>$row){$expected=(string)$row['summary']!==''?(string)$row['summary']:(string)$row['document_summary'];if((string)$sheet->getCell('H'.($i+5))->getValue()!==$expected)throw new RuntimeException('XLSX_SUMMARY_MISMATCH_ROW_'.$i);}
 $assert((string)$all['list'][3]['summary']===$summaries[3],'long summary fixture fits stored field without truncation');
 $assert(true,'all 101 exported summaries match authority, including zero, Chinese, newline, long text and document fallback');
 $assert((string)$sheet->getCell('H5')->getValue()==='0','literal zero is not replaced by document summary');
 $assert((int)round((float)$sheet->getCell('G105')->getValue()*100)===(int)$all['list'][100]['balance_cents'],'last exported row retains original running balance');
 $assert((string)$sheet->getCell('H4')->getValue()==='-'&&(string)$sheet->getCell('H106')->getValue()==='-','opening and total rows retain summary placeholders');
 $assert($sheet->getCell('A106')->getValue()==='合计'&&$sheet->getCell('A107')->getValue()===null,'neighbor-store row is excluded from actual XLSX');
}finally{Db::rollback();if($file&&is_file($file))unlink($file);}
$assert(Db::name('store_fund_document')->whereIn('id',$ids)->count()===0,'test documents rolled back and generated XLSX removed');
echo "R29 LEDGER SUMMARY PASS\n";

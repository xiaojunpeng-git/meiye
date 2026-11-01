<?php
$root=getenv('R67_BACKEND') ?: dirname(__DIR__,2).'/后端代码';
require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
use think\facade\Db;use app\services\query\UnifiedQueryRuntime;use app\services\order\PlatformOrderCenterActionServices;use app\services\order\PlatformOrderCenterReadServices;
if(Db::query('SELECT DATABASE() db')[0]['db']!=='ruihao'||config('database.connections.mysql.hostname')!=='mysql')throw new RuntimeException('local only');
$a=Db::name('system_admin')->where('status',1)->where('is_del',0)->where('level',0)->find();$svc=app()->make(PlatformOrderCenterActionServices::class);
$base=['clientSessionId'=>'SESSION-9ec0ea15-9116-4e12-9a12-a8ea3b3c7f91','correlationId'=>'CORR-r67'];
$invoke=fn($action,$p=[])=>$svc->dispatch((int)$a['id'],$p+$base+['action'=>$action]);
$files=[];Db::startTrans();$start=microtime(true);
try{
$c=$invoke('query-unified-query-capabilities',['pageCode'=>'order_center_sales']);foreach($c['versions'] as $v)if($v['kind']==='query_preference')$version=$v['version'];
$p=['pageCode'=>'order_center_sales','scope'=>'query','query'=>['topFilters'=>[['field'=>'business_date','operator'=>'gte','value'=>'2026-10-01'],['field'=>'business_date','operator'=>'lte','value'=>'2026-10-31']]],'fields'=>['sales_order_no','business_date','store','payment_completed_at','receivable_amount','actual_received_amount'],'includeSummary'=>false,'fileName'=>'r67-large.xlsx',
'command'=>['action'=>'create-unified-query-export','idempotencyKey'=>'CMD-9ec0ea15-9116-4e12-9a12-a8ea3b3c7f92','contexts'=>[['kind'=>'query_preference','id'=>'order_center_sales','expectedVersion'=>$version]]]];
$r=$invoke('create-unified-query-export',$p);if(($r['result']['status']??'')!=='success')throw new RuntimeException(json_encode($r['result']));$task=$r['data']['exportTask']['taskId'];echo "created full-query task\n";
$rt=UnifiedQueryRuntime::runtime();$worker=new app\services\query\UnifiedQueryExportWorkerServices($rt['exports'],$rt['providers'],$rt['workerContextResolvers']);$done=$worker->processOne($task);
if(($done['status']??'')!=='succeeded')throw new RuntimeException(json_encode($done,JSON_UNESCAPED_UNICODE));
[,, $ctx]=app()->make(PlatformOrderCenterReadServices::class)->exportScopes((int)$a['id'],'order_center_sales');$d=$rt['exports']->resolveDownloadDescriptor($ctx,$task);$path=(new app\services\query\UnifiedQueryExportStorage())->absolutePath($d['storageKey']);$files[]=$path;
$workerSeconds=round(microtime(true)-$start,2);$workerPeak=memory_get_peak_usage(true);
echo json_encode(['stage'=>'worker_complete','status'=>$done['status'],'rows'=>$done['rowCount'],'seconds'=>$workerSeconds,'peak'=>$workerPeak])."\n";
$z=new ZipArchive();$z->open($path);$stream=$z->getStream('xl/worksheets/sheet1.xml');$tmp=tempnam('/tmp','r67-physical-');$out=fopen($tmp,'wb');stream_copy_to_stream($stream,$out);fclose($stream);fclose($out);$z->close();
$xml=new XMLReader();$xml->open($tmp);$physical=0;$seen=[];$cells=0;
while($xml->read())if($xml->nodeType===XMLReader::ELEMENT && $xml->localName==='row'){
 $dom=new DOMDocument();$dom->loadXML($xml->readOuterXml(),LIBXML_NONET);$values=$dom->getElementsByTagName('c');
 if($physical++){ $id=$values->item(0)->textContent;if(isset($seen[$id]))throw new RuntimeException('duplicate workbook order');$seen[$id]=1;$cells+=$values->length; }
}
$xml->close();unlink($tmp);
if($physical-1!==$done['rowCount'] || $done['rowCount']<40000)throw new RuntimeException('incomplete workbook');
echo json_encode(['status'=>'PASS','rows'=>$done['rowCount'],'cells'=>$cells,'seconds'=>$workerSeconds,'worker_peak'=>$workerPeak,'physical_rows'=>$physical,'file_sha256'=>hash_file('sha256',$path)])."\n";
}finally{Db::rollback();foreach($files as $f)if(is_file($f))unlink($f);}

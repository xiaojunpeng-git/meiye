<?php
/** 第3轮：同一筛选/排序/聚合口径，大数据外部归并与真实XLSX逐值验证。纯本地不写业务表。 */
require (getenv('R67_BACKEND') ?: dirname(__DIR__, 2).'/后端代码').'/vendor/autoload.php';
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryStreamingExecution;
use app\services\query\UnifiedQueryPageRegistry as Registry;
use app\services\query\StructuredExpressionValidator;
use app\services\query\StructuredExpressionEvaluator;
use app\services\query\UnifiedQueryStreamingWorkbook;
use app\services\query\UnifiedQueryExternalRows;
$registry = new Registry([['code'=>'reserved_total','name'=>'系统总额']]);
$registry->registerPage('stream_test', '流式测试', [
    Registry::field('record_id','编号','text',true,false,['filter','sort','export']),
    Registry::field('name','姓名','text',true,false,['filter','sort','group','export']),
    Registry::field('amount','金额','amount',true,false,['filter','sort','summary','export']),
    Registry::field('count','数量','integer',true,false,['filter','sort','summary','export']),
    Registry::field('day','日期','datetime',true,false,['filter','sort','export']),
], 'record_id');
$validator = new StructuredExpressionValidator($registry); $eval = new StructuredExpressionEvaluator();
$engine = new class($registry,$validator,$eval) extends UnifiedQueryExecutionServices { use UnifiedQueryStreamingExecution; };
$context = ['permissions'=>[],'query_cutoff_date'=>'2026-11-01','data_as_of'=>1793500000];
$rows = [];
for ($i=0; $i<4500; ++$i) $rows[] = ['record_id'=>sprintf('x%06d',$i),'name'=>$i%5===0 ? null : ['甲','乙','丙'][$i%3],
    'amount'=>$i%7===0 ? null : (($i%233)-70).'.'.sprintf('%02d',$i%100),'count'=>$i%17,
    'day'=>'2026-10-'.sprintf('%02d',1+$i%31).' 12:23:45'];
$queries = [
    ['page'=>1,'limit'=>20],
    ['page'=>100,'limit'=>20,'sorts'=>[['field'=>'amount','direction'=>'desc']]],
    ['page'=>999,'limit'=>20],
    ['filterRelation'=>'any','filters'=>[['field'=>'name','operator'=>'equal','value'=>'甲'],['field'=>'amount','operator'=>'greater_than','value'=>'100']],
     'topFilterConditions'=>[['field'=>'day','operator'=>'less_or_equal','value'=>'2026-10-15']],
     'quickFilters'=>[['field'=>'count','operator'=>'greater_or_equal','value'=>3]],
     'keywordFilters'=>[['field'=>'name','operator'=>'contains','value'=>'乙'],['field'=>'record_id','operator'=>'contains','value'=>'1']],
     'sorts'=>[['field'=>'amount','direction'=>'asc']], 'page'=>2,'limit'=>50],
    ['groupBy'=>['name'],'summaries'=>[
        ['field'=>'amount','operator'=>'sum'],['field'=>'amount','operator'=>'average'],['field'=>'amount','operator'=>'minimum'],
        ['field'=>'amount','operator'=>'maximum'],['field'=>'amount','operator'=>'count'],['field'=>'count','operator'=>'sum']],
     'sorts'=>[['field'=>'name','direction'=>'desc']]],
    ['export'=>['scope'=>'page','fields'=>['record_id','name','amount']],'page'=>9,'limit'=>20],
    ['export'=>['scope'=>'query','fields'=>['record_id','name','amount']]],
];
$allow = static function ($row) { return ((int)substr($row['record_id'],1))%11 !== 0; };
$checks=0;
foreach ($queries as $query) {
    $expected=$engine->execute('stream_test',$rows,[],$query,$context,$allow);
    $actual=$engine->executeBatches('stream_test',array_chunk($rows,100),[],$query,$context,$allow);
    if ($actual['exportRows'] instanceof UnifiedQueryExternalRows) $actual['exportRows']=iterator_to_array($actual['exportRows'],false);
    foreach (['rows','pagination','summaries','groups','exportRows','consistencyFingerprint'] as $key) {
        if ($actual[$key] !== $expected[$key]) throw new RuntimeException('parity '.$checks.' '.$key.' '.json_encode([$actual[$key],$expected[$key]],JSON_UNESCAPED_UNICODE));
    }
    ++$checks;
}
// 聚合自定义字段必须在权限过滤后、普通筛选前计算；跨批次精度不能变。
foreach (['sum','average','minimum','maximum','count'] as $op) {
    $definition=['field_key'=>'cf_aaaaaaaaaaaaaaaaaaaa','name'=>'自定义金额','version'=>1,'status'=>'active','return_type'=>$op==='count'?'integer':'amount',
        'expression'=>['type'=>'operator','operator'=>$op,'args'=>[['type'=>'field','key'=>'amount']]]];
    $query=['filters'=>[['field'=>'amount','operator'=>'greater_than','value'=>'20']],'page'=>3,'limit'=>20];
    $expected=$engine->execute('stream_test',$rows,[$definition],$query,$context,$allow);
    $actual=$engine->executeBatches('stream_test',array_chunk($rows,100),[$definition],$query,$context,$allow);
    if($actual['rows']!==$expected['rows'] || $actual['pagination']!==$expected['pagination'])throw new RuntimeException('aggregate parity '.$op);
    ++$checks;
}
// 重复ID跨越多个批次仍拒绝，不允许导出半份结果。
try { $engine->executeBatches('stream_test', [[$rows[1]],[$rows[1]]], [], [], $context, static fn()=>true); throw new RuntimeException('duplicate not rejected'); }
catch (app\services\query\UnifiedQueryException $e) { if ($e->getErrorCode()!=='UNIFIED_QUERY_STABLE_KEY_DUPLICATE') throw $e; ++$checks; }
$before=memory_get_usage(true);
$source=(function () { for ($batch=0;$batch<600;++$batch) { $rows=[]; for ($n=0;$n<100;++$n) { $i=$batch*100+$n; $rows[]=['record_id'=>sprintf('x%06d',$i),'name'=>$i===0?'=HYPERLINK("bad")':'中文'.$i,'amount'=>'123.45','count'=>$i,'day'=>'2026-10-01 00:00:00']; } yield $rows; } })();
$large=$engine->executeBatches('stream_test',$source,[],['page'=>2000,'limit'=>20,'export'=>['scope'=>'query','fields'=>['record_id','name','amount','count','day']]],$context,static fn()=>true);
if ($large['pagination']['total']!==60000 || count($large['exportRows'])!==60000 || $large['rows'][0]['record_id']!=='x039980') throw new RuntimeException('large count/page mismatch');
$fields=[]; foreach (['record_id'=>'text','name'=>'text','amount'=>'amount','count'=>'integer','day'=>'datetime'] as $key=>$type) $fields[]=['key'=>$key,'label'=>$key,'type'=>$type];
$file='/tmp/r67-streaming-query.xlsx';
if ((new UnifiedQueryStreamingWorkbook())->write($file,$fields,$large['exportRows'])!==60000) throw new RuntimeException('xlsx row count');
$zip=new ZipArchive();$zip->open($file);$xml=$zip->getStream('xl/worksheets/sheet1.xml');$temp=tempnam('/tmp','r67-verify-');$out=fopen($temp,'wb');stream_copy_to_stream($xml,$out);fclose($xml);fclose($out);$zip->close();
$reader=new XMLReader();$reader->open($temp);$physical=0;$first=null;$last=null;
while($reader->read()) if($reader->nodeType===XMLReader::ELEMENT && $reader->localName==='row') {
    $dom=new DOMDocument();$dom->loadXML($reader->readOuterXml(),LIBXML_NONET);
    if($dom->getElementsByTagName('f')->length) throw new RuntimeException('Excel formula injection');
    $values=[];foreach($dom->getElementsByTagName('c') as $cell) $values[]=$cell->textContent;
    if($physical===1)$first=$values;$last=$values;++$physical;
}
$reader->close();unlink($temp);
if($physical!==60001 || $first!==['x000000','=HYPERLINK("bad")','123.45','0','2026-10-01 00:00:00'] || $last[0]!=='x059999') throw new RuntimeException('physical XLSX content mismatch');
$peak=memory_get_peak_usage(true)-$before;
if($peak>64*1024*1024) throw new RuntimeException('unbounded memory '.$peak);
echo json_encode(['status'=>'PASS','parity_cases'=>$checks,'large_rows'=>60000,'cells'=>300000,'page'=>2000,'extra_peak_bytes'=>$peak,'file'=>$file])."\n";

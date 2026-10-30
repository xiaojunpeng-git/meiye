<?php
/** 动态授权列、筛选、跨页导出与单位边界；注入虚构事实，不读写业务库。 */
require dirname(__DIR__,3).'/后端代码/vendor/autoload.php';
use app\services\report\Sidebar007ReportServices as Report;
$checks=0;
function check($ok,$message){global $checks;++$checks;if(!$ok)throw new RuntimeException($message);}
function rejects($fn,$message){try{$fn();}catch(\InvalidArgumentException $e){check(true,$message);return;}catch(\LogicException $e){check(true,$message);return;}catch(\app\services\query\UnifiedQueryException $e){check(true,$message);return;}throw new RuntimeException($message);}
$context=['visible_store_ids'=>[8,9],'basic_scope_mode'=>'stores','query_cutoff_date'=>'2026-10-30','permissions'=>[], 'data_as_of'=>time()];
$payload=['start_date'=>'2026-10-01','end_date'=>'2026-10-30'];
$stores=static function($allowed){return [['id'=>8,'name'=>'测试甲'],['id'=>9,'name'=>'测试乙']];};
$source=static function($report,$allowed,$stores,$range,$context){
 $rows=[];
 for($i=0;$i<25;$i++)$rows[]=['row_id'=>'metric:'.$i,'metric'=>'指标'.$i,'unit'=>$i===0?'money_cents':($i===1?'percent_integer':'count'),'cells'=>['store_8'=>$i===0?12345:$i,'store_9'=>$i===2?null:$i]];
 return ['columns'=>[['key'=>'store_8'],['key'=>'store_9']],'rows'=>$rows,'gaps'=>[['metric'=>'指标2','reason'=>'测试缺源']],'complete'=>false];
};
$reader=new Report($source,$stores);
$first=$reader->query('basic_sales_data',$context,$payload);
check($first['pagination']['total']===25&&count($first['rows'])===20,'第一页及总数');
check($first['rows'][0]['store_8']==='123.45'&&$first['rows'][0]['value_unit']==='money_yuan','金额只转元一次且保持分精度');
check($first['rows'][1]['value_unit']==='percent_integer','比例单位由后端指定');
check($first['rows'][2]['store_9']===null&&!$first['complete'],'缺值不得伪造0');
check($first['table_layout']['summary_position']==='none'&&$first['summaries']===[],'不同单位不混合计');
$last=$reader->query('basic_sales_data',$context,$payload+['page'=>2]);
check(count($last['rows'])===5&&$last['rows'][0]['row_id']==='metric:20','末页稳定行顺序');
$export=$reader->query('basic_sales_data',$context,$payload+['export'=>['scope'=>'query','fields'=>['metric','store_8']]]);
check(count($export['exportRows'])===25&&$export['exportRows'][0]['store_8']==='123.45','导出完整查询与列表同金额');
$filter=['filters'=>[['field'=>'metric','operator'=>'eq','value'=>'指标3']]];
$filtered=$reader->query('basic_sales_data',$context,$payload+$filter+['export'=>['scope'=>'query','fields'=>['metric','store_8']]]);
check($filtered['pagination']['total']===1&&count($filtered['exportRows'])===1&&$filtered['rows'][0]['metric']==='指标3','更多筛选作用于列表和导出');
rejects(static function()use($reader,$context,$payload){$reader->query('basic_sales_data',$context,$payload+['export'=>['scope'=>'query','fields'=>['store_10']]]);},'拒越权动态列导出');
rejects(static function()use($reader,$context,$payload){$reader->query('basic_sales_data',$context,$payload+['summaries'=>[['field'=>'store_8','aggregation'=>'sum']]]);},'拒混合单位合计');
$bad=new Report($source,static function(){return [['id'=>10,'name'=>'越权门店']];});
rejects(static function()use($bad,$context,$payload){$bad->query('basic_sales_data',$context,$payload);},'读取器不能扩大授权');
// 101家店需分块导出，仍保持一行一指标和严格字段白名单。
$wideContext=$context;$wideContext['visible_store_ids']=range(1,101);
$wideStores=static function($ids){return array_map(static function($id){return ['id'=>$id,'name'=>'店'.$id];},$ids);};
$wideSource=static function($r,$a,$stores){$cells=[];$columns=[];foreach($stores as $s){$key='store_'.$s['id'];$cells[$key]=$s['id'];$columns[]=['key'=>$key];}return ['columns'=>$columns,'rows'=>[['row_id'=>'m','metric'=>'客数','unit'=>'count','cells'=>$cells]],'gaps'=>[],'complete'=>true];};
$wide=new Report($wideSource,$wideStores);$wanted=array_merge(['metric'],array_map(static function($id){return 'store_'.$id;},range(1,101)));
$result=$wide->query('basic_sales_data',$wideContext,$payload+['export'=>['scope'=>'query','fields'=>$wanted]]);
check(count($result['exportRows'])===1&&count($result['exportRows'][0])===102&&$result['exportRows'][0]['store_101']===101,'宽表不遗漏右侧门店');
echo json_encode(['pass'=>true,'checks'=>$checks]),PHP_EOL;

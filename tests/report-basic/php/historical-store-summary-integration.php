<?php
declare(strict_types=1);

/** 真实隔离数据库上的历史报表回归；参考值由封存库的原007实现独立生成，禁止写业务库。 */
require getcwd().'/vendor/autoload.php';
(new \think\App())->initialize();
date_default_timezone_set('Asia/Shanghai');
use app\services\report\HistoricalStoreSummaryReadServices;
use app\services\report\HistoricalStoreSummaryFacts;
use app\services\report\StoreSummary007ReadServices;
use app\services\report\BasicBusinessReportServices;
use app\services\report\query\BasicStoreSummaryQueryProvider;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\StructuredExpressionValidator;
use app\services\query\StructuredExpressionEvaluator;
use think\facade\Db;
$config=config('database');
if($config['connections']['mysql']['hostname']!=='mysql')throw new RuntimeException('LOCAL_ONLY');
$config['connections']['mysql']['database']='mohe_report_r18_verify';
\think\facade\Config::set($config,'database');
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$map=['cash_performance'=>'xianjinyeji','actual_performance'=>'shijixianjinyeji',
    'inside_cash_performance'=>'dianneixianjinyeji','consumption_performance'=>'xiaohaoyeji',
    'inside_consumption_performance'=>'dianneixiaohaoyeji','consumption_cash_ratio'=>'xianyehaokabi',
    'customer_count'=>'fuwukeci','designated_customer_count'=>'zhidingke','new_customer_count'=>'xinkeshu',
    'sold_project_count'=>'xiangmushu','consumed_project_count'=>'xiaohaoxiangmushu',
    'new_customer_conversion_rate'=>'xinkechengjiaolv','new_customer_total_count'=>'xinkezongkeshu'];
$checks=0;$cells=0;$timings=[];
$check=static function(bool $ok,string $why)use(&$checks):void{++$checks;if(!$ok)throw new RuntimeException($why);};
$compare=static function(array $actual,array $expected)use($map,$check,&$cells):void{
    $actual=array_column($actual,null,'store_id');$check(count($actual)===count($expected),'门店范围不一致');
    foreach($expected as $e)foreach($map as $key=>$old){++$cells;
        $check(isset($actual[$e['id']][$key])&&bccomp((string)$actual[$e['id']][$key],(string)$e[$old],6)===0,
            '原007不一致:'.$e['id'].':'.$key.':'.($actual[$e['id']][$key]??'NULL').' != '.$e[$old]);}
};
$ids=array_map('intval',array_column($input['month']['rows'],'id'));
$cases=array_merge([['start'=>'2026-10-01','end'=>'2026-10-31','stores'=>$ids,'rows'=>$input['month']['rows']]],$input['periods']['cases']);
$reader=new StoreSummary007ReadServices();
foreach($cases as $case){$t=microtime(true);
    $r=$reader->read($case['stores'],['start'=>$case['start'],'end'=>$case['end']]);
    $timings[]=round((microtime(true)-$t)*1000,1);$compare($r['rows'],$case['rows']);
    $check($r['complete']&&$r['metric_version']==='store-summary-007-sealed-v1','历史认证未生效');
}
$range=['start'=>'2026-10-01','end'=>'2026-10-31'];
$check($reader->read([],$range)['rows']===[],'空授权泄漏数据');
$zeros=$reader->read([132,134],$range)['rows'];
$check(count($zeros)===2&&(int)$zeros[0]['cash_performance']===0&&(int)$zeros[1]['customer_count']===0,'无历史门店不能借用其他门店数据');
// 实际统一查询/导出提供器，不绕过领域查询、后端过滤或完整命中合计。
$registry=new UnifiedQueryPageRegistry();$fields=[];
foreach(BasicBusinessReportServices::fields('basic_store_summary') as $f)
    $fields[]=UnifiedQueryPageRegistry::field($f['key'],$f['label'],$f['type'],true,false);
$registry->registerPage('basic_store_summary','门店统计表',$fields,'row_id');$registry->freeze();
$provider=new BasicStoreSummaryQueryProvider(new UnifiedQueryExecutionServices($registry,
    new StructuredExpressionValidator($registry),new StructuredExpressionEvaluator()));
$ctx=['query_cutoff_date'=>'2026-11-06','visible_store_ids'=>$ids,'basic_scope_mode'=>'stores',
    'tenant_id'=>'0','permissions'=>[]];
$payload=['start_date'=>$range['start'],'end_date'=>$range['end']];
$r=$provider->query($ctx,$payload+['export'=>['scope'=>'query','fields'=>array_merge(['store_id','store_name'],array_keys($map))]]);
$compare($r['rows'],$input['month']['rows']);$compare($r['exportRows'],$input['month']['rows']);
$exportPayload=['pageCode'=>'basic_store_summary','export'=>['scope'=>'query','fields'=>array_merge(['store_id','store_name'],array_keys($map))]];
$xlsxData=\app\services\report\BasicReportXlsxExportServices::payload($r,$exportPayload);
$xlsxPath=sys_get_temp_dir().'/round18-history-'.getmypid().'.xlsx';
(new \app\services\report\StoreReportXlsxWriter())->writeFile($xlsxPath,$xlsxData,'门店统计表',['summary_position'=>'last']);
$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($xlsxPath);$sheetRows=$book->getActiveSheet()->toArray(null,true,false,false);
$xlsxCells=0;
foreach($input['month']['rows'] as $e){$matched=array_values(array_filter($sheetRows,static fn($line)=>($line[1]??null)===$e['name']));
    $check(count($matched)===1,'XLSX门店行缺失或重复');$column=2;
    foreach($map as $key=>$old){++$xlsxCells;$check(bccomp((string)$matched[0][$column++],(string)$e[$old],6)===0,'XLSX数值错误:'.$key);}}
$book->disconnectWorksheets();unlink($xlsxPath);
$summary=array_column($r['summary_cards'],'value','key');
foreach(['cash_performance'=>1335887,'actual_performance'=>1307267,'inside_cash_performance'=>1275327,
    'consumption_performance'=>1237778,'inside_consumption_performance'=>1159589,
    'customer_count'=>6070,'designated_customer_count'=>1919,'new_customer_count'=>226] as $k=>$v)
    $check(bccomp((string)$summary[$k],(string)$v,6)===0,'完整汇总错误:'.$k);
$filtered=$provider->query($ctx,$payload+['filters'=>[['field'=>'store_id','operator'=>'eq','value'=>9]],
    'export'=>['scope'=>'query','fields'=>['store_id','cash_performance']]]);
$check(count($filtered['rows'])===1&&count($filtered['exportRows'])===1&&$filtered['pagination']['total']===1,'过滤/导出范围不一致');
$check((int)array_column($filtered['summary_cards'],'value','key')['cash_performance']===184622,'汇总未跟随门店筛选');
$store=$provider->query(array_merge($ctx,['visible_store_ids'=>[9]]),$payload);
$check($store['rows']===$filtered['rows'],'平台选择单店与门店范围结果不一致');
// 跨月分段仅把未覆盖日期交给现行Reader；已核准十月明细不重复混入。
$dimensions=[['id'=>9,'name'=>'大悦城店']];$calls=[];
$oct31=(new HistoricalStoreSummaryReadServices())->read([9],['start'=>'2026-10-31','end'=>'2026-10-31'],$dimensions,
    static function(){throw new RuntimeException('十月误调用现行来源');});
$current=static function(array $segment)use(&$calls,$oct31){$calls[]=$segment;$p=$oct31;
    $p['scope']['date_range']=$segment;$p['metric_version']='current-fixture';
    foreach($p['rows'][0] as $k=>&$v)if(!in_array($k,['row_id','store_id','store_name'],true))$v=1;unset($v);
    $p['rows'][0]['customer_count']=2;$p['rows'][0]['new_customer_total_count']=2;return $p;};
$mixed=(new HistoricalStoreSummaryReadServices())->read([9],['start'=>'2026-10-31','end'=>'2026-11-02'],$dimensions,$current);
$check($calls===[['start'=>'2026-11-01','end'=>'2026-11-02']],'跨月现行日期错误');
$check((int)$mixed['rows'][0]['cash_performance']===(int)$oct31['rows'][0]['cash_performance']+1,'跨月现金重复/遗漏');
$check($mixed['rows'][0]['customer_count']===$oct31['rows'][0]['customer_count']+2,'跨月客数错误');
$incomplete=(new HistoricalStoreSummaryReadServices())->read([9],['start'=>'2026-10-31','end'=>'2026-11-02'],$dimensions,
    static function($segment)use($current){$p=$current($segment);$p['complete']=false;$p['rows'][0]['cash_performance']=null;return $p;});
$check(!$incomplete['complete']&&$incomplete['rows'][0]['cash_performance']===null
    &&!in_array('cash_performance',$incomplete['sum_fields'],true),'跨月未知值被误当0');
$calls=[];(new HistoricalStoreSummaryReadServices())->read([9],['start'=>'2026-11-01','end'=>'2026-11-02'],$dimensions,$current);
$check($calls===[['start'=>'2026-11-01','end'=>'2026-11-02']],'十一月原路径改变');
$batch=Db::name('report_history_batch')->where('status','active')->find();
try{(new HistoricalStoreSummaryReadServices(static fn()=>[$batch,$batch]))->read([9],$range,$dimensions,$current);
    throw new RuntimeException('重叠批次未拒绝');}catch(LogicException $e){$check(true,'重叠拒绝');}
try{HistoricalStoreSummaryFacts::aggregate(['orders'=>[['source_id'=>1,'store_id'=>99,'business_date'=>'2026-10-01']],
    'staff'=>[],'quantities'=>[]],$dimensions,$range);throw new RuntimeException('越权明细未拒绝');}
catch(LogicException $e){$check(true,'越权拒绝');}
echo json_encode(['pass'=>true,'checks'=>$checks,'reference_cells'=>$cells,'xlsx_cells'=>$xlsxCells,'query_ms'=>$timings,
    'summary'=>$summary,'result'=>$r],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";

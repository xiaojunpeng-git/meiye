<?php
/** 分析范围白名单、日期边界和冻结导出计划的同源契约；不写数据库。 */
$backend=getenv('BACKEND_ROOT')?:dirname(__DIR__,3).'/后端代码';
spl_autoload_register(static function($class)use($backend){if(strpos($class,'app\\')===0){$p=$backend.'/'.str_replace('\\','/',$class).'.php';if(is_file($p))require_once $p;}});
use app\services\query\MemberAnalysisFilterServices as Analysis;
use app\services\query\UnifiedQueryException;
$valid=['cashPerformance'=>['min'=>'0','max'=>'100.25'],'visitPeriod'=>['min'=>'2026-10-01','max'=>'2026-10-31']];
if(Analysis::normalize($valid)!==$valid)throw new Exception('valid/zero lost');
foreach ([['sql'=>[]],['visitCount'=>['min'=>'1.1']],['cashPerformance'=>['min'=>'-1']],['cashPerformance'=>['min'=>'1 OR 1=1']],['visitPeriod'=>['min'=>'2026-02-30','max'=>'2026-03-01']],['visitPeriod'=>['min'=>'2026-10-01']],['remainingTimes'=>['min'=>'2','max'=>'1']]] as $bad){
 try{Analysis::normalize($bad);throw new Exception('invalid condition accepted');}catch(UnifiedQueryException $expected){}
}
$registry=new app\services\query\UnifiedQueryPageRegistry();
(new app\services\query\provider\MemberUnifiedQueryPageRegistrar())->register($registry);
(new app\services\query\provider\PlatformMemberUnifiedQueryRegistrar())->register($registry);$registry->freeze();
$exec=new app\services\query\UnifiedQueryExecutionServices($registry,new app\services\query\StructuredExpressionValidator($registry),new app\services\query\StructuredExpressionEvaluator());
foreach(['member_list','platform_member_list'] as $code){
 $plan=$exec->validatedPlan($code,[],['memberAnalysis'=>$valid],['query_cutoff_date'=>'2026-11-05']);
 if($plan['domain_scope']['member_analysis']!==$valid)throw new Exception('analysis lost in frozen plan');
}
echo "PASS: validation, zero values, invalid dates/ranges rejected, platform/store frozen analysis identical\n";

$tasks=(new ReflectionClass(app\services\query\UnifiedQueryExportTaskServices::class))->newInstanceWithoutConstructor();
foreach(['member_list','platform_member_list'] as $code){$cap=$tasks->exportCapability(['export'=>true],$code);if(!$cap['streaming']||$cap['maxRows']!==null)throw new Exception('member export is still capped at 10000');}
echo "PASS: both member export capabilities use existing bounded streaming pipeline\n";

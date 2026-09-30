<?php
/**
 * R56 local, read-only source/member integration. Run only against the pinned
 * local Ruihao database; no identities or business rows are printed.
 */
if (getenv('MOHE_R56_LOCAL_TEST')!=='LOCAL_READ_ONLY') exit("LOCAL_READ_ONLY opt-in required\n");
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??null)!=='mysql'||($db['database']??null)!=='ruihao') throw new RuntimeException('LOCAL_DATABASE_REQUIRED');
$uuid=think\facade\Db::query('SELECT @@server_uuid uuid')[0]['uuid']??null;
if ($uuid!=='3506c91f-98cb-11f1-af5f-9e1a52451c2a') throw new RuntimeException('LOCAL_INSTANCE_MISMATCH');
$check=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);};
$admission=new app\services\ai\semantic\AiFirstCourseSourceAdmission();
$fresh=$admission->match('这个月来源A的会员有几个');
$check(($fresh['source_term']??null)==='A' && ($fresh['period']['code']??null)==='THIS_MONTH'
    && ($fresh['form']??null)==='count','SOURCE_QUESTION_NOT_ADMITTED');
$check($admission->match('这个月来源A并且消费980的会员有几个')===null,'EXTRA_CONDITION_DROPPED');
$check(($admission->match('会员详情',true)['detail']??false)===true,'DETAIL_FOLLOWUP_NOT_ADMITTED');
$catalog=new app\services\query\metric\FirstCourseSourceCatalog();
$source=$catalog->resolve('A');
$check(($source['source_primary_id']??0)>0 && ($source['source_secondary_id']??-1)===0,'SOURCE_DIRECTORY_MISSING');
$check($catalog->label($source['source_primary_id'])===$source['name'],'SOURCE_LABEL_MISMATCH');
$secondary=think\facade\Db::name('cashier_v3_business_source')
    ->where('parent_id',$source['source_primary_id'])->field('id,name')->find();
if (is_array($secondary)) {
    $resolved=$catalog->resolve((string)$secondary['name']);
    $check($resolved['source_primary_id']===$source['source_primary_id']
        &&$resolved['source_secondary_id']===(int)$secondary['id']
        &&$catalog->label($resolved['source_primary_id'],$resolved['source_secondary_id'])===$secondary['name'],
        'SECONDARY_SOURCE_NOT_RESOLVED');
}
try {$catalog->resolve('来源不存在-'.$source['source_primary_id']);throw new RuntimeException('UNKNOWN_SOURCE_ACCEPTED');}
catch (RuntimeException $e) {$check($e->getMessage()==='AI_SOURCE_NOT_FOUND','UNKNOWN_SOURCE_NOT_REJECTED');}
$metric='member_first_course_count';
$cap=app\services\query\metric\MetricReadViewServices::metricCapabilities();
$check(!empty($cap[$metric]['ai_query_ready']),'SOURCE_METRIC_NOT_REGISTERED');
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();
$context=$resolver->authenticated($id);
$context['_refresh']=static function()use($resolver,$id):array{return $resolver->authenticated($id);};
$check(!empty($context['can_use'])&&!empty($context['member_data_authorized']),'LOCAL_PRINCIPAL_NOT_AUTHORIZED');
$gateway=new app\services\ai\AiGatewayServices();$reflection=new ReflectionClass($gateway);
$initialize=$reflection->getMethod('initialize');$initialize->setAccessible(true);$initialize->invoke($gateway);
$serviceMethod=$reflection->getMethod('queryService');$serviceMethod->setAccessible(true);
$service=$serviceMethod->invoke($gateway,$context);
// Pinned September fixture has known lifecycle facts; current-month semantics
// above are tested separately and must not depend on the wall-clock rollover.
$range=['start'=>'2026-09-01','end'=>'2026-09-30'];
$filters=['object_kind'=>'member','source_primary_id'=>$source['source_primary_id']];
$make=static function(string $shape)use($range,$filters,$metric):array{
    $binding=['subject'=>'member','relation'=>'all','result_form'=>$shape==='condition_list'?'list':'count',
        'conditions'=>[['metric_code'=>$metric,'operator'=>'gt','quantity'=>'0','unit'=>'count']]];
    $set=['subject'=>'member','relation'=>'all','conditions'=>[['metric_code'=>$metric,'operator'=>'gt','value'=>0]]];
    return ['query_shape'=>$shape,'metric_codes'=>[$metric],'start_date'=>$range['start'],'end_date'=>$range['end'],
        'compare_range'=>null,'store_ids'=>[],'business_filters'=>$filters,'ranking'=>null,
        'aggregate_condition'=>$binding,'condition_set'=>$set];
};
$capabilities=app\services\ai\execution\AiAuthority::capabilities(false,$context);
$compiler=new app\services\ai\execution\AiRegisteredPlanCompiler();
$unfiltered=$make('condition_count');
$unfiltered['business_filters']=['object_kind'=>'member'];
try {
    $compiler->compile(['workflow_code'=>'wf_performance_condition_count','query'=>$unfiltered,'output_format'=>'screen'],$capabilities);
    throw new RuntimeException('UNFILTERED_SOURCE_METRIC_ACCEPTED');
} catch (RuntimeException $e) {
    $check($e->getMessage()==='AI_UNSUPPORTED_CONDITION','UNFILTERED_SOURCE_METRIC_NOT_REJECTED');
}
$views=[];$answers=[];$elapsed=[];
foreach(['condition_count','condition_list'] as $shape){
    $start=microtime(true);
    $compiled=$compiler->compile(['workflow_code'=>'wf_performance_'.$shape,'query'=>$make($shape),'output_format'=>'screen'],$capabilities);
    $views[$shape]=$service->create($context,$compiled['query']);
    $answers[$shape]=(new app\services\ai\presentation\AiAnswerRenderer())->render($views[$shape]);
    $elapsed[$shape]=(int)((microtime(true)-$start)*1000);
}
$count=$views['condition_count']['results'][0]['count'];
$list=$views['condition_list']['results'][0];
$check($count>0 && $count===$list['count'],'COUNT_LIST_MISMATCH_'.$count.'_'.($list['count']??'missing'));
$check(strpos($answers['condition_count']['summary'],'首次疗程来源为')!==false,'SOURCE_ANSWER_NOT_BUSINESS_LANGUAGE');
$expectedQuery=think\facade\Db::name('cashier_v3_customer_lifecycle_fact')->alias('f')
    ->where('f.tenant_id',(string)$context['tenant_id'])->whereIn('f.store_id',$context['store_ids'])
    ->where('f.event_type','first_course_completed')->where('f.status','effective')
    ->where('f.source_primary_id',$source['source_primary_id'])
    ->whereBetween('f.business_date',[$range['start'],$range['end']]);
(new app\services\report\StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts(
    $expectedQuery,'f.tenant_id','f.related_order_id');
$expected=(int)$expectedQuery->group('f.member_id')->count();
$check($count===$expected,'INDEPENDENT_FACT_COUNT_MISMATCH_'.$count.'_'.$expected);
$raw=(int)think\facade\Db::name('cashier_v3_customer_lifecycle_fact')->alias('f')
    ->where('f.tenant_id',(string)$context['tenant_id'])->whereIn('f.store_id',$context['store_ids'])
    ->where('f.event_type','first_course_completed')->where('f.status','effective')
    ->where('f.source_primary_id',$source['source_primary_id'])
    ->whereBetween('f.business_date',[$range['start'],$range['end']])->group('f.member_id')->count();
$driftQuery=think\facade\Db::name('cashier_v3_customer_lifecycle_fact')->alias('f')
    ->join('cashier_v3_customer_lifecycle_projection p','p.tenant_id=f.tenant_id AND p.member_id=f.member_id')
    ->where('f.tenant_id',(string)$context['tenant_id'])->whereIn('f.store_id',$context['store_ids'])
    ->where('f.event_type','first_course_completed')->where('f.status','effective')
    ->where('f.source_primary_id',$source['source_primary_id'])
    ->whereBetween('f.business_date',[$range['start'],$range['end']])
    ->whereRaw('f.store_id<>p.store_id');
(new app\services\report\StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts(
    $driftQuery,'f.tenant_id','f.related_order_id');
$storeDrift=(int)$driftQuery->count();
$report=(new app\services\report\CustomerAnalyticsServices())->read(
    'customer_source_analysis',array_map('intval',$context['store_ids']),$range,
    ['source_id'=>$source['source_primary_id'],'_report_scope'=>['mode'=>$context['scope_mode']]],true);
$reported=array_sum(array_map('intval',array_column((array)($report['records']??[]),'member_count')));
$check($reported===$count,'SOURCE_REPORT_PARITY_'.$count.'_'.$reported);
$members=(new app\services\ai\context\MemberDetailContinuationResolver())->resolve(
    $views['condition_list']['query'],$views['condition_list'],['target'=>'set','view'=>'summary']);
$check(count($members['members'])===$count,'DETAIL_SET_MISMATCH');
echo json_encode(['result'=>'PASS','scope_store_count'=>count($context['store_ids']),
    'source_count'=>$count,'raw_before_void_exclusion'=>$raw,'event_vs_current_store_drift'=>$storeDrift,
    'count_ms'=>$elapsed['condition_count'],'list_ms'=>$elapsed['condition_list'],
    'detail_set_count'=>count($members['members']),'report_parity'=>'PASS',
    'business_data_mutated'=>false],JSON_UNESCAPED_UNICODE),PHP_EOL;

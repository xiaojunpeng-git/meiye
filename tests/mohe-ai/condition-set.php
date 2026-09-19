<?php
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\execution\AiRegisteredPlanCompiler;
use app\services\ai\execution\AiConditionSetCompiler;
use app\services\ai\execution\AiWorkflowPlanner;
use app\services\ai\contract\AiIntentUnderstandingContract;
use app\services\ai\contract\AiIntentResultContract;
use app\services\ai\context\IntentContextMerger;
use app\services\ai\presentation\AiAnswerRenderer;
use app\services\query\metric\GroupPerformanceMetricReadServices;
use app\services\query\metric\MetricReadViewServices;
use app\services\query\metric\MetricReadViewStore;
use app\services\query\metric\MetricReadViewExportProvider;
use app\services\query\metric\MetricSemanticCatalog;
use app\services\query\metric\PersonnelAnalysisObjectServices;

$checks=0;
function csCheck($ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function csReject(callable $call,string $expected): void { try {$call();} catch (Throwable $e) {$code=method_exists($e,'getErrorCode')?$e->getErrorCode():$e->getMessage();csCheck($code===$expected,'expected '.$expected.', got '.$code);return;} throw new RuntimeException('expected rejection '.$expected); }

$understandingInstruction=AiIntentUnderstandingContract::modelInstruction();
csCheck(strpos($understandingInstruction,'relation all means every condition must hold')!==false
    &&strpos($understandingInstruction,'relation any means at least one condition may hold')!==false
    &&strpos($understandingInstruction,'the same request joined by “或者” uses relation any')!==false,
    'understanding protocol makes Boolean relation semantic explicit without choosing any business metric in PHP');

final class CsQuery
{
    public static $conditions=[]; public $field=''; public $where=[];
    public function __call($name,$args) { if (in_array($name,['field','fieldRaw'],true)) $this->field=(string)$args[0]; if ($name==='where') $this->where[]=$args; if (isset($args[0])&&is_callable($args[0])) $args[0]($this); return $this; }
    public function find() { return ['amount_cents'=>'0']; }
    public function select() { return $this; }
    public function toArray(): array
    {
        if (strpos($this->field,'position_name')!==false) return [
            ['store_id'=>1,'employee_id'=>7,'employee_name'=>'甲员工','store_name'=>'合成店','cashier_craftsman_enabled'=>1,'cashier_salesperson_enabled'=>1,'position_id'=>2,'position_name'=>'护理师'],
            ['store_id'=>1,'employee_id'=>8,'employee_name'=>'乙员工','store_name'=>'合成店','cashier_craftsman_enabled'=>1,'cashier_salesperson_enabled'=>1,'position_id'=>2,'position_name'=>'护理师'],
        ];
        if (strpos($this->field,'SUM(')!==false) {
            $type=''; foreach ($this->where as $where) if (($where[0]??null)==='p.performance_type') $type=(string)($where[1]??'');
            return $type==='sales_performance_allocated'
                ? [['employee_id'=>'7','business_date'=>'2026-09-19','metric_value'=>'6000000','fact_count'=>'1'],['employee_id'=>'8','business_date'=>'2026-09-19','metric_value'=>'4000000','fact_count'=>'1']]
                : [['employee_id'=>'7','business_date'=>'2026-09-19','metric_value'=>'250000000','fact_count'=>'1'],['employee_id'=>'8','business_date'=>'2026-09-19','metric_value'=>'100000000','fact_count'=>'1']];
        }
        return [
            ['store_id'=>1,'employee_id'=>7,'employee_name'=>'甲员工'],
            ['store_id'=>1,'employee_id'=>8,'employee_name'=>'乙员工'],
        ];
    }
}

$scope=['personnel_authorized'=>true,'store_ids'=>[1],'employee_id'=>0,'permission_version'=>'condition-fixture-v1'];
$factory=static function(): CsQuery { return new CsQuery(); };
$objects=new PersonnelAnalysisObjectServices($factory,static function()use(&$scope): array { return $scope; });
$reader=new GroupPerformanceMetricReadServices($factory,static function($query,$tenant,$order): void { $query->normalScope($tenant,$order); });
$binding=['instance_id'=>'fixture','subject_ref'=>'fixture','terminal'=>'platform','tenant_id'=>'0','permission_version'=>'condition-fixture-v1','report_capability_code'=>'group_management_dashboard','scope_provider_code'=>'current_report_scope_v1','scope_mode'=>'stores','store_ids'=>[1]];
$store=new MetricReadViewStore(sys_get_temp_dir().'/mohe-condition-set-'.bin2hex(random_bytes(8)),str_repeat('condition-fixture',4));
$views=new MetricReadViewServices($store,static function()use(&$binding): array { return $binding; },static function($call)use($reader) { return $call($reader); },null,$objects);
$query=['query_shape'=>'condition_list','metric_codes'=>['staff_sales_yeji','staff_labor_yeji'],'start_date'=>'2026-09-19','end_date'=>'2026-09-19','compare_range'=>null,'store_ids'=>[],
    'business_filters'=>['object_kind'=>'person','selection_ref'=>'cohort:active_personnel'],'ranking'=>null,
    'aggregate_condition'=>['subject'=>'person','relation'=>'all','result_form'=>'list','conditions'=>[
        ['metric_code'=>'staff_sales_yeji','operator'=>'gte','quantity'=>'50000','unit'=>'yuan'],
        ['metric_code'=>'staff_labor_yeji','operator'=>'gte','quantity'=>'2000000','unit'=>'yuan'],
    ]],
    'condition_set'=>['subject'=>'person','relation'=>'all','conditions'=>[
        ['metric_code'=>'staff_sales_yeji','operator'=>'gte','value'=>5000000],
        ['metric_code'=>'staff_labor_yeji','operator'=>'gte','value'=>200000000],
    ]]];
$capabilities=['metric_codes'=>$query['metric_codes'],'query_shapes'=>['condition_count','condition_list'],'output_formats'=>['screen'],
    'metric_readiness'=>MetricReadViewServices::metricCapabilities(),'definition_metric_codes'=>[],'metadata_readiness'=>[],'store_ids'=>[]];
$compiled=(new AiRegisteredPlanCompiler())->compile(['query'=>$query,'output_format'=>'screen'],$capabilities);
csCheck($compiled['workflow_code']==='wf_performance_condition_list','registered condition list compiles through one workflow');
csCheck($compiled['query']['condition_set']===$query['condition_set'],'condition relation and canonical values survive compilation');
(new AiRegisteredPlanCompiler())->assertCompiled($compiled);
csCheck(($compiled['capability_snapshot']['metrics']['staff_sales_yeji']['storage_unit']??null)==='fen'
    &&($compiled['capability_snapshot']['metrics']['staff_labor_yeji']['storage_unit']??null)==='fen',
    'frozen capability preserves registered storage units so execution preflight can revalidate condition values');
$memberQuery=['query_shape'=>'condition_list','metric_codes'=>['sales_collected_amount'],'start_date'=>'2026-09-01','end_date'=>'2026-09-19',
    'compare_range'=>null,'store_ids'=>[],'business_filters'=>['object_kind'=>'member'],'ranking'=>null,
    'aggregate_condition'=>['subject'=>'member','relation'=>'all','result_form'=>'list','conditions'=>[
        ['metric_code'=>'sales_collected_amount','operator'=>'gte','quantity'=>'1000','unit'=>'yuan'],
    ]],
    'condition_set'=>['subject'=>'member','relation'=>'all','conditions'=>[
        ['metric_code'=>'sales_collected_amount','operator'=>'gte','value'=>100000],
    ]]];
$memberCapabilities=['metric_codes'=>['sales_collected_amount'],'query_shapes'=>['condition_count','condition_list'],'output_formats'=>['screen'],
    'metric_readiness'=>MetricReadViewServices::metricCapabilities(),'definition_metric_codes'=>[],'metadata_readiness'=>[],'store_ids'=>[]];
$memberCompiled=(new AiRegisteredPlanCompiler())->compile(['query'=>$memberQuery,'output_format'=>'screen'],$memberCapabilities);
csCheck($memberCompiled['query']['business_filters']===['object_kind'=>'member']
    &&($memberCompiled['capability_snapshot']['metrics']['sales_collected_amount']['query_shapes']??[])!==[],
    'registered member condition list compiles as an analytical population without a private member selector');
$memberMulti=$memberQuery;
$memberMulti['metric_codes']=['member_service_visit_count','sales_collected_amount'];
$memberMulti['aggregate_condition']['conditions']=[
    ['metric_code'=>'member_service_visit_count','operator'=>'gte','quantity'=>'3','unit'=>'count'],
    ['metric_code'=>'sales_collected_amount','operator'=>'gte','quantity'=>'20000','unit'=>'yuan'],
];
$memberMulti['condition_set']=(new AiConditionSetCompiler())->compile(
    $memberMulti['aggregate_condition'],['metric_readiness'=>MetricReadViewServices::metricCapabilities()],'condition_list'
);
$memberMultiCapabilities=$memberCapabilities;$memberMultiCapabilities['metric_codes']=$memberMulti['metric_codes'];
$memberMultiCompiled=(new AiRegisteredPlanCompiler())->compile(['query'=>$memberMulti,'output_format'=>'screen'],$memberMultiCapabilities);
csCheck($memberMultiCompiled['query']['condition_set']['conditions']===[
    ['metric_code'=>'member_service_visit_count','operator'=>'gte','value'=>3],
    ['metric_code'=>'sales_collected_amount','operator'=>'gte','value'=>2000000],
], 'registered member visit and collected-sales conditions compile together without a question-specific branch');
$normalizeMetricQuery=Closure::bind(static function(MetricReadViewServices $service,array $query): array {
    return $service->query($query);
},null,MetricReadViewServices::class);
csCheck($normalizeMetricQuery($views,$memberMulti)['metric_codes']===$memberMulti['metric_codes'],
    'read-view admission keeps validated member multi-condition populations while single-metric ranking rules stay closed');
$memberEvidence=['query'=>$memberQuery,'results'=>[[]+
    ['period'=>'current','metric_code'=>'sales_collected_amount','storage_unit'=>'count','object_kind'=>'member',
        'condition_set'=>$memberQuery['condition_set'],'count'=>1,'list_limit'=>100,'has_more'=>false,
        'rows'=>[['member_id'=>101,'member_name'=>'测试会员','metrics'=>['sales_collected_amount'=>890001]]]]]];
$memberAnswer=(new AiAnswerRenderer())->render($memberEvidence);
csCheck(strpos($memberAnswer['summary'],'客户共有1人')!==false
    &&($memberAnswer['table']['columns'][0]['label']??null)==='会员'
    &&($memberAnswer['table']['rows'][0]['label']??null)==='测试会员',
    'member condition evidence renders the customer count and list without exposing internal identifiers');
$memberMultiEvidence=['query'=>$memberMulti,'results'=>[[
    'period'=>'current','metric_code'=>'member_service_visit_count','storage_unit'=>'count','object_kind'=>'member',
    'condition_set'=>$memberMulti['condition_set'],'count'=>1,'list_limit'=>100,'has_more'=>false,
    'rows'=>[['member_id'=>101,'member_name'=>'测试会员','metrics'=>[
        'member_service_visit_count'=>4,'sales_collected_amount'=>2350000,
    ]]],
]]];
$memberMultiAnswer=(new AiAnswerRenderer())->render($memberMultiEvidence);
csCheck(strpos($memberMultiAnswer['summary'],'全部2项条件')!==false
    &&count($memberMultiAnswer['table']['rows']??[])===2
    &&($memberMultiAnswer['table']['rows'][0]['unit']??null)==='次'
    &&($memberMultiAnswer['table']['rows'][1]['unit']??null)==='元',
    'member multi-condition evidence renders every registered metric and its own unit');
$memberExport=MetricReadViewExportProvider::project($memberEvidence);
csCheck(count($memberExport)===1&&$memberExport[0]['store_name']==='测试会员；范围：当前授权范围'
    &&$memberExport[0]['metric_value']==='8900.01'&&$memberExport[0]['unit']==='元',
    'member condition list export reuses verified rows and registered units instead of exporting only the count');
$view=$views->create([],$query);
csCheck($view['results'][0]['count']===1&&$view['results'][0]['rows'][0]['employee_name']==='甲员工','AND set evaluates against one authorised current-personnel population');
csCheck(($view['results'][0]['list_limit']??null)===100&&($view['results'][0]['has_more']??null)===false,
    'personnel condition lists publish their bounded display limit and truncation state');
$truncatedEvidence=$view;
$truncatedEvidence['results'][0]['count']=101;
$truncatedEvidence['results'][0]['has_more']=true;
$truncatedAnswer=(new AiAnswerRenderer())->render($truncatedEvidence);
csCheck(strpos($truncatedAnswer['summary'],'当前展示前100条')!==false
    &&strpos($truncatedAnswer['summary'],'精确数量为准')!==false,
    'bounded personnel lists disclose truncation instead of presenting the visible slice as a complete list');
csCheck(($view['results'][0]['rows'][0]['metrics']['staff_sales_yeji']??null)===6000000
    &&($view['results'][0]['rows'][0]['metrics']['staff_labor_yeji']??null)===250000000,'list preserves every registered condition metric rather than reapplying a threshold to facts');
$any=$query;$any['query_shape']='condition_count';$any['condition_set']['relation']='any';$any['condition_set']['conditions'][1]['value']=100000000;
$any['aggregate_condition']['relation']='any';$any['aggregate_condition']['result_form']='count';$any['aggregate_condition']['conditions'][1]['quantity']='1000000';
csCheck($views->create([],$any)['results'][0]['count']===2,'OR set evaluates each candidate once and does not require every condition');
$bad=$query;$bad['condition_set']['conditions'][1]['metric_code']='staff_sales_yeji';
csReject(static function()use($views,$bad): void {$views->create([],$bad);},'METRIC_QUERY_SCHEMA_INVALID');
$scope['store_ids']=[];
csReject(static function()use($views,$query): void {$views->create([],$query);},'AI_PERSONNEL_PERMISSION_REQUIRED');

$humanCondition=['subject'=>'person','relation'=>'all','result_form'=>'list','conditions'=>[
    ['metric_code'=>'staff_sales_yeji','operator'=>'gte','quantity'=>'50000','unit'=>'yuan'],
    ['metric_code'=>'staff_service_num','operator'=>'gte','quantity'=>'24.5','unit'=>'count'],
]];
$compiledSet=(new AiConditionSetCompiler())->compile($humanCondition,[
    'metric_readiness'=>[
        'staff_sales_yeji'=>['ai_query_ready'=>true,'query_shapes'=>['condition_list'],'condition_subjects'=>['person'],'storage_unit'=>'fen'],
        'staff_service_num'=>['ai_query_ready'=>true,'query_shapes'=>['condition_list'],'condition_subjects'=>['person'],'storage_unit'=>'customer_tenth'],
    ],
],'condition_list');
csCheck($compiledSet['conditions'][0]['value']===5000000&&$compiledSet['conditions'][1]['value']===245,
    'human yuan and fractional service counts convert through registered storage units without floats');
$threeMetrics=['staff_sales_yeji','staff_service_num','staff_project_num'];
$threeCondition=['subject'=>'person','relation'=>'all','result_form'=>'list','conditions'=>[
    ['metric_code'=>'staff_sales_yeji','operator'=>'gte','quantity'=>'50000','unit'=>'yuan'],
    ['metric_code'=>'staff_service_num','operator'=>'gte','quantity'=>'24','unit'=>'count'],
    ['metric_code'=>'staff_project_num','operator'=>'gte','quantity'=>'15','unit'=>'count'],
]];
$threeCaps=['metric_codes'=>$threeMetrics,'query_shapes'=>['condition_count','condition_list'],'output_formats'=>['screen'],
    'metric_readiness'=>MetricReadViewServices::metricCapabilities(),'definition_metric_codes'=>[],
    'metadata_readiness'=>[],'store_ids'=>[],'current_store_bound'=>true];
$threeSet=(new AiConditionSetCompiler())->compile($threeCondition,$threeCaps,'condition_list');
$threePlan=(new AiWorkflowPlanner())->compile([
    'blocking_reason'=>null,'unresolved_condition'=>false,
    'signals'=>['staff_project_num','staff_sales_yeji','staff_service_num','condition_list'],
    'dates'=>['2026-08-21','2026-09-19'],'semantic_intent'=>['constraints'=>[]],
],[
    'decision'=>'query','query_shape'=>'condition_list','metric_codes'=>$threeMetrics,
    'ranking'=>['direction'=>'unspecified','limit'=>null],'aggregate_condition'=>$threeCondition,
    'condition_set'=>$threeSet,'object_kind'=>'person',
],$threeCaps,'screen','2026-09-19');
csCheck($threePlan['plan']['query']['metric_codes']===$threeMetrics
    &&array_column($threePlan['plan']['query']['condition_set']['conditions'],'metric_code')===$threeMetrics,
    'condition workflow preserves accepted metric-to-threshold order instead of registry signal order');
csReject(static function()use($humanCondition): void {
    $bad=$humanCondition;$bad['conditions'][1]['quantity']='24.55';
    (new AiConditionSetCompiler())->compile($bad,['metric_readiness'=>[
        'staff_sales_yeji'=>['ai_query_ready'=>true,'query_shapes'=>['condition_list'],'condition_subjects'=>['person'],'storage_unit'=>'fen'],
        'staff_service_num'=>['ai_query_ready'=>true,'query_shapes'=>['condition_list'],'condition_subjects'=>['person'],'storage_unit'=>'customer_tenth'],
    ]],'condition_list');
},'AI_CONDITION_PRECISION_INVALID');

$storeBinding=['subject'=>'store','relation'=>'all','result_form'=>'list','conditions'=>[
    ['metric_code'=>'actual_performance','operator'=>'gte','quantity'=>'50000','unit'=>'yuan'],
]];
$storeSet=(new AiConditionSetCompiler())->compile($storeBinding,[
    'metric_readiness'=>MetricReadViewServices::metricCapabilities(),
],'condition_list');
csCheck($storeSet===['subject'=>'store','relation'=>'all','conditions'=>[
    ['metric_code'=>'actual_performance','operator'=>'gte','value'=>5000000],
]], 'registered store conditions compile through the same unit-safe contract as people and members');
$storeCapabilities=['metric_codes'=>['actual_performance'],'query_shapes'=>['condition_count','condition_list'],'output_formats'=>['screen'],
    'metric_readiness'=>MetricReadViewServices::metricCapabilities(),'definition_metric_codes'=>[],
    'metadata_readiness'=>[],'store_ids'=>[],'current_store_bound'=>true];
$storeEvidence=['query'=>[
    'query_shape'=>'condition_list','metric_codes'=>['actual_performance'],'start_date'=>'2026-09-01','end_date'=>'2026-09-19',
    'compare_range'=>null,'store_ids'=>[],'business_filters'=>['object_kind'=>'store'],'ranking'=>null,
    'aggregate_condition'=>$storeBinding,'condition_set'=>$storeSet,
],'results'=>[[
    'period'=>'current','metric_code'=>'actual_performance','storage_unit'=>'count','object_kind'=>'store',
    'condition_set'=>$storeSet,'count'=>1,'list_limit'=>100,'has_more'=>false,
    'rows'=>[['store_id'=>7,'store_name'=>'测试门店','metrics'=>['actual_performance'=>6688000]]],
]]];
$storeCompiled=(new AiRegisteredPlanCompiler())->compile(['query'=>$storeEvidence['query'],'output_format'=>'screen'],$storeCapabilities);
csCheck(($storeCompiled['query']['business_filters']??null)===['object_kind'=>'store']
    &&($storeCompiled['capability_snapshot']['metrics']['actual_performance']['condition_subjects']??null)===['store'],
    'compiled store conditions freeze the registered candidate-object contract for replay');
$normalizedStore=$normalizeMetricQuery($views,$storeEvidence['query']);
csCheck(($normalizedStore['condition_set']['subject']??null)==='store'
    &&($normalizedStore['condition_set']['relation']??null)==='all'
    &&($normalizedStore['condition_set']['conditions'][0]??null)===['metric_code'=>'actual_performance','operator'=>'gte','value'=>5000000],
    'read-view admission accepts only the registered store subject and canonical condition set');
$storeAnswer=(new AiAnswerRenderer())->render($storeEvidence);
csCheck(strpos($storeAnswer['summary'],'门店共有1家')!==false
    &&($storeAnswer['table']['columns'][0]['label']??null)==='门店'
    &&($storeAnswer['table']['rows'][0]['label']??null)==='测试门店',
    'store condition evidence renders a store count and registered metric values without a store-name branch');
$storeExport=MetricReadViewExportProvider::project($storeEvidence);
csCheck(count($storeExport)===1&&$storeExport[0]['store_name']==='测试门店；范围：当前授权范围'
    &&$storeExport[0]['metric_value']==='66880.00',
    'store condition list export reuses the same verified object row and monetary storage unit');

$itemSubjects=['order','sale_line','card','project','product'];
$itemCaps=MetricReadViewServices::metricCapabilities();
$salesLanguage=\app\services\query\metric\MetricSemanticCatalog::entries()['sales_amount']['terms']??[];
csCheck(in_array('销售订单金额',$salesLanguage,true)&&in_array('销售明细金额',$salesLanguage,true)
    &&in_array('成交额',$salesLanguage,true),
    'completed-sale business aliases remain registry-owned and reusable across sale analytical objects');
foreach (['sales_amount','sales_quantity'] as $metricCode) {
    csCheck(array_diff($itemSubjects,$itemCaps[$metricCode]['condition_subjects']??[])===[]
        &&in_array('condition_count',$itemCaps[$metricCode]['query_shapes']??[],true)
        &&in_array('condition_list',$itemCaps[$metricCode]['query_shapes']??[],true),
        $metricCode.' explicitly registers every completed-sale condition subject');
}
$productBinding=['subject'=>'product','relation'=>'all','result_form'=>'list','conditions'=>[
    ['metric_code'=>'sales_amount','operator'=>'gte','quantity'=>'5000','unit'=>'yuan'],
    ['metric_code'=>'sales_quantity','operator'=>'gte','quantity'=>'10','unit'=>'count'],
]];
$productSet=(new AiConditionSetCompiler())->compile($productBinding,['metric_readiness'=>$itemCaps],'condition_list');
$productQuery=['query_shape'=>'condition_list','metric_codes'=>['sales_amount','sales_quantity'],
    'start_date'=>'2026-09-01','end_date'=>'2026-09-19','compare_range'=>null,'store_ids'=>[],
    'business_filters'=>['object_kind'=>'product'],'ranking'=>null,
    'aggregate_condition'=>$productBinding,'condition_set'=>$productSet];
$productCapabilities=['metric_codes'=>$productQuery['metric_codes'],'query_shapes'=>['condition_count','condition_list'],
    'output_formats'=>['screen'],'metric_readiness'=>$itemCaps,'definition_metric_codes'=>[],
    'metadata_readiness'=>[],'store_ids'=>[],'current_store_bound'=>true];
$productCompiled=(new AiRegisteredPlanCompiler())->compile(['query'=>$productQuery,'output_format'=>'screen'],$productCapabilities);
csCheck(($productCompiled['query']['business_filters']??null)===['object_kind'=>'product']
    &&($productCompiled['query']['condition_set']??null)===$productSet,
    'product amount and quantity conditions compile through the generic registered-object path');
csCheck(($normalizeMetricQuery($views,$productQuery)['condition_set']['subject']??null)==='product',
    'read-view admission accepts a registered product condition population without a product-name branch');
$productEvidence=['query'=>$productQuery,'results'=>[[
    'period'=>'current','metric_code'=>'sales_amount','storage_unit'=>'count','object_kind'=>'product','object_label'=>'产品',
    'condition_set'=>$productSet,'count'=>1,'list_limit'=>100,'has_more'=>false,
    'rows'=>[['entity_id'=>901,'entity_name'=>'测试产品','metrics'=>['sales_amount'=>880000,'sales_quantity'=>12]]],
]]];
$productAnswer=(new AiAnswerRenderer())->render($productEvidence);
csCheck(strpos($productAnswer['summary'],'产品共有1个')!==false
    &&($productAnswer['table']['columns'][0]['label']??null)==='产品'
    &&count($productAnswer['table']['rows']??[])===2,
    'generic dimension evidence renders the product conclusion, object label and both registered metrics');
$saleLineEvidence=$productEvidence;
$saleLineEvidence['query']['business_filters']=['object_kind'=>'sale_line'];
$saleLineEvidence['query']['condition_set']['subject']='sale_line';
$saleLineEvidence['results'][0]['object_kind']='sale_line';
$saleLineEvidence['results'][0]['object_label']='销售明细';
$saleLineEvidence['results'][0]['condition_set']['subject']='sale_line';
$saleLineEvidence['results'][0]['rows'][0]['entity_id']='sale-fact:line-001';
$saleLineAnswer=(new AiAnswerRenderer())->render($saleLineEvidence);
csCheck(strpos($saleLineAnswer['summary'],'销售明细')!==false
    &&($saleLineAnswer['table']['columns'][0]['label']??null)==='销售明细'
    &&count($saleLineAnswer['table']['rows']??[])===2,
    'opaque immutable sale-line identity is verified without being exposed or rejected by rendering');
$productExport=MetricReadViewExportProvider::project($productEvidence);
csCheck(count($productExport)===2&&$productExport[0]['store_name']==='测试产品；范围：当前授权范围'
    &&$productExport[0]['metric_value']==='8800.00'&&$productExport[1]['metric_value']==='12',
    'generic dimension list export preserves verified product metrics and units');

$memberStateCaps=MetricReadViewServices::metricCapabilities();
$statedStateTerms=MetricSemanticCatalog::registeredTermsInText('现在有剩余项目次数，但是超过90天没来的客户有多少？');
csCheck(array_column($statedStateTerms,'metric_code')===['member_remaining_project_times','member_days_since_last_visit']
    &&array_column($statedStateTerms,'ai_query_ready')===[true,false],
    'combined natural wording retains every unambiguous registered measurement including a non-ready one');
csCheck(($memberStateCaps['member_remaining_project_times']['condition_unit']??null)==='count'
    &&($memberStateCaps['member_days_since_last_visit']['condition_unit']??null)==='day'
    &&($memberStateCaps['member_days_since_last_visit']['condition_subjects']??null)===['member']
    &&($memberStateCaps['member_days_since_last_visit']['ai_query_ready']??true)===false
    &&($memberStateCaps['member_days_since_last_visit']['readiness_reasons']??null)===['HISTORICAL_SERVICE_COVERAGE_INCOMPLETE'],
    'member entitlement stays available while incomplete last-visit history is explicitly unavailable');
$memberStateBinding=['subject'=>'member','relation'=>'all','result_form'=>'list','conditions'=>[
    ['metric_code'=>'member_remaining_project_times','operator'=>'gt','quantity'=>'0','unit'=>'count'],
    ['metric_code'=>'member_days_since_last_visit','operator'=>'gt','quantity'=>'90','unit'=>'day'],
]];
$memberStateCompilerCaps=$memberStateCaps;
$memberStateCompilerCaps['member_days_since_last_visit']['ai_query_ready']=true;
$memberStateCompilerCaps['member_days_since_last_visit']['readiness_reasons']=[];
$memberStateSet=(new AiConditionSetCompiler())->compile(
    $memberStateBinding,['metric_readiness'=>$memberStateCompilerCaps],'condition_list'
);
csCheck($memberStateSet['conditions']=== [
    ['metric_code'=>'member_remaining_project_times','operator'=>'gt','value'=>0],
    ['metric_code'=>'member_days_since_last_visit','operator'=>'gt','value'=>90],
], 'current-state count and elapsed-day predicates compile without treating days as a generic count');
csCheck((new AiConditionSetCompiler())->usesCurrentSnapshot(
    ['subject'=>'member','relation'=>'all','conditions'=>[$memberStateSet['conditions'][0]]],
    ['metric_readiness'=>$memberStateCompilerCaps]
)===true && (new AiConditionSetCompiler())->usesCurrentSnapshot(
    $memberStateSet,['metric_readiness'=>$memberStateCompilerCaps]
)===true && (new AiConditionSetCompiler())->usesCurrentSnapshot(
    ['subject'=>'member','relation'=>'all','conditions'=>[[
        'metric_code'=>'sales_collected_amount','operator'=>'gte','value'=>100000,
    ]]],['metric_readiness'=>$memberStateCompilerCaps]
)===false,'only state and as-of conditions receive the server-owned current snapshot date');
$memberStateQuery=['query_shape'=>'condition_list','metric_codes'=>['member_remaining_project_times','member_days_since_last_visit'],
    'start_date'=>'2026-09-19','end_date'=>'2026-09-19','compare_range'=>null,'store_ids'=>[],
    'business_filters'=>['object_kind'=>'member'],'ranking'=>null,
    'aggregate_condition'=>$memberStateBinding,'condition_set'=>$memberStateSet];
csReject(static function()use($normalizeMetricQuery,$views,$memberStateQuery): void {
    $normalizeMetricQuery($views,$memberStateQuery);
},'METRIC_NOT_REGISTERED');
$memberStateEvidence=['query'=>$memberStateQuery,'results'=>[[
    'period'=>'current','metric_code'=>'member_remaining_project_times','storage_unit'=>'count','object_kind'=>'member',
    'condition_set'=>$memberStateSet,'count'=>1,'list_limit'=>100,'has_more'=>false,
    'rows'=>[['member_id'=>88,'member_name'=>'测试会员','metrics'=>[
        'member_remaining_project_times'=>3,'member_days_since_last_visit'=>121,
    ]]],
]]];
$memberStateAnswer=(new AiAnswerRenderer())->render($memberStateEvidence);
csCheck(($memberStateAnswer['table']['rows'][0]['unit']??null)==='次'
    &&($memberStateAnswer['table']['rows'][1]['unit']??null)==='天'
    &&($memberStateAnswer['table']['rows'][1]['value']??null)==='121',
    'member state evidence keeps entitlement counts and elapsed days visibly distinct');
csCheck(strpos(AiIntentUnderstandingContract::modelInstruction(),'unit":"yuan|count|day"')!==false
    &&strpos(AiIntentResultContract::modelInstruction(false),'yuan, count or day')!==false,
    'both semantic stages publish the elapsed-day unit instead of forcing a day threshold into count');

$safe=['schema_version'=>'sanitized-question-v2','question'=>'最近30天销售业绩达到5万元并且服务次数达到24次的员工有哪些？',
    'has_unresolved_conditions'=>false,'server_resolved_fields'=>[],'reference_date'=>'2026-09-19','recent_questions'=>[],
    'evidence_messages'=>[['id'=>'current','text'=>'最近30天销售业绩达到5万元并且服务次数达到24次的员工有哪些？']],'prior_query'=>null];
$semantic=['goal'=>'列出最近30天同时达到销售业绩和服务次数条件的员工','status'=>'understood','requirements'=>[
    [
        'id'=>'r1','meaning'=>'最近30天销售业绩达到5万元并且服务次数达到24次的员工名单',
        'fields'=>['metric_codes','object_kind','operation','periods','aggregate_condition'],
        'values'=>[
            'metric_terms'=>['销售业绩','服务次数'],'object_kind'=>'person','operation'=>'condition_list',
            'periods'=>[['kind'=>'relative_days','days'=>30,'end_offset_days'=>0]],
            'aggregate_condition'=>['subject'=>'person','relation'=>'all','result_form'=>'list','conditions'=>[
                ['metric_term'=>'销售业绩','operator'=>'gte','quantity'=>'50000','unit'=>'yuan'],
                ['metric_term'=>'服务次数','operator'=>'gte','quantity'=>'24','unit'=>'count'],
            ]],
        ],
        'evidence'=>[['message_id'=>'current','quote'=>'最近30天销售业绩达到5万元并且服务次数达到24次的员工有哪些？']],
    ],
]];
$semantic=AiIntentUnderstandingContract::normalize($semantic,$safe);
$memberNaturalSafe=['schema_version'=>'sanitized-question-v2','question'=>'最近30天来过至少3次，而且实际收款销售额达到2万元的客户有哪些？',
    'has_unresolved_conditions'=>false,'server_resolved_fields'=>[],'reference_date'=>'2026-09-19','recent_questions'=>[],
    'evidence_messages'=>[['id'=>'current','text'=>'最近30天来过至少3次，而且实际收款销售额达到2万元的客户有哪些？']],'prior_query'=>null];
$memberNatural=AiIntentUnderstandingContract::normalize(['goal'=>'列出同时符合到店次数和实际收款销售额条件的客户','status'=>'understood','requirements'=>[[
    'id'=>'r1','meaning'=>'最近30天到店至少3次且实际收款销售额达到2万元的客户名单',
    'fields'=>['metric_codes','object_kind','operation','periods','aggregate_condition'],'values'=>[
        'metric_terms'=>['来过','实际收款销售额'],'object_kind'=>'member','operation'=>'condition_list',
        'periods'=>[['kind'=>'relative_days','days'=>30,'end_offset_days'=>0]],
        'aggregate_condition'=>['subject'=>'member','relation'=>'all','result_form'=>'list','conditions'=>[
            ['metric_term'=>'到店次数','operator'=>'gte','quantity'=>'3','unit'=>'count'],
            ['metric_term'=>'实际收款销售额','operator'=>'gte','quantity'=>'20000','unit'=>'yuan'],
        ]],
    ],'evidence'=>[['message_id'=>'current','quote'=>'最近30天来过至少3次，而且实际收款销售额达到2万元的客户有哪些？']],
]]],$memberNaturalSafe);
csCheck($memberNatural['requirements'][0]['values']['metric_terms']===['到店次数','实际收款销售额'],
    'generic conditions admit only registry-proven alias normalization grounded by literal customer evidence');
$omittedRedundant=$memberNatural;
$omittedRedundant['requirements'][0]['fields']=['periods','aggregate_condition'];
unset($omittedRedundant['requirements'][0]['values']['metric_terms'],$omittedRedundant['requirements'][0]['values']['object_kind'],$omittedRedundant['requirements'][0]['values']['operation']);
$projectedRedundant=AiIntentUnderstandingContract::normalize($omittedRedundant,$memberNaturalSafe);
csCheck(array_diff(['metric_codes','object_kind','operation','aggregate_condition'],$projectedRedundant['requirements'][0]['fields'])===[]
    &&$projectedRedundant['requirements'][0]['values']['object_kind']==='member'
    &&$projectedRedundant['requirements'][0]['values']['operation']==='condition_list',
    'generic condition carrier deterministically projects omitted redundant audit fields');
$paraphrasedSemantic=$semantic;
$paraphrasedSemantic['requirements'][0]['values']['aggregate_condition']['conditions'][1]['metric_term']='员工服务完成次数';
csReject(static function()use($paraphrasedSemantic,$safe): void {
    AiIntentUnderstandingContract::normalize($paraphrasedSemantic,$safe);
},'AI_MODEL_INTENT_CONTRACT_INVALID');
$incompleteAuditSemantic=$semantic;
$incompleteAuditSemantic['requirements'][0]['values']['metric_terms']=['销售业绩'];
$recoveredAuditSemantic=AiIntentUnderstandingContract::normalize($incompleteAuditSemantic,$safe);
csCheck($recoveredAuditSemantic['requirements'][0]['values']['metric_terms']===['销售业绩','服务次数'],
    'validated generic condition terms own the redundant ordered metric audit projection');
$boundCondition=$humanCondition;$boundCondition['conditions'][1]['quantity']='24';
$binding=['object_kind'=>'person','object_term'=>'','operation'=>'condition_list','metric_codes'=>['staff_sales_yeji','staff_service_num'],
    'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],
    'periods'=>[['kind'=>'relative_days','days'=>30,'end_offset_days'=>0]],'scope'=>'unspecified','aggregate_condition'=>$boundCondition,
    'requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['staff_sales_yeji','staff_service_num']]],
    'unresolved_fragments'=>[]];
$incompleteExactBinding=$binding;
$incompleteExactBinding['object_kind']='unknown';
$incompleteExactBinding['operation']='summary';
$incompleteExactBinding['metric_codes']=['staff_sales_yeji'];
$incompleteExactBinding['action_codes']=['sales'];
$incompleteExactBinding['scope']='invalid_scope';
$incompleteExactBinding['ranking']=['direction'=>'top','limit'=>1];
$incompleteExactBinding['periods']=[];
$incompleteExactBinding['unresolved_fragments']=['provider decoration'];
$incompleteExactBinding['aggregate_condition']['conditions']=[$incompleteExactBinding['aggregate_condition']['conditions'][0]];
$incompleteExactBinding['requirement_bindings'][0]['metric_codes']=['staff_sales_yeji'];
$canonicalExactBinding=AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
    $incompleteExactBinding,$semantic,$safe,['staff_sales_yeji','staff_service_num']
);
$canonicalExactBound=AiIntentResultContract::normalize(
    $canonicalExactBinding,['staff_sales_yeji','staff_service_num'],[],$safe,$semantic
);
csCheck($canonicalExactBound['metric_codes']===['staff_sales_yeji','staff_service_num']
    &&$canonicalExactBound['object_kind']==='person'&&$canonicalExactBound['operation']==='condition_list'
    &&array_column($canonicalExactBound['aggregate_condition']['conditions'],'metric_code')===['staff_sales_yeji','staff_service_num']
    &&$canonicalExactBound['action_codes']===[]&&$canonicalExactBound['scope']==='unspecified'
    &&$canonicalExactBound['ranking']===['direction'=>'unspecified','limit'=>null]
    &&$canonicalExactBound['periods']===[['kind'=>'relative_days','days'=>30,'end_offset_days'=>0]]
    &&$canonicalExactBound['unresolved_fragments']===[],
    'multiple exact registered condition terms recover a complete one-to-one binding without dropping a predicate');
$bound=AiIntentResultContract::normalize($binding,['staff_sales_yeji','staff_service_num'],[],$safe,$semantic);
csCheck($bound['aggregate_condition']===$boundCondition&&$bound['metric_codes']===['staff_sales_yeji','staff_service_num'],
    'understood condition meanings bind to registered codes without changing thresholds, relation or order');
$partialBinding=$binding;
$partialBinding['aggregate_condition']['conditions']=[$partialBinding['aggregate_condition']['conditions'][0]];
$projectedBound=AiIntentResultContract::normalize($partialBinding,['staff_sales_yeji','staff_service_num'],[],$safe,$semantic);
csCheck($projectedBound['aggregate_condition']===$boundCondition,
    'signed condition semantics and ordered registered bindings project a complete carrier when the binding model drops a copied predicate');
$splitSemantic=AiIntentUnderstandingContract::normalize(['goal'=>'列出最近30天同时达到销售业绩和服务次数条件的员工','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'销售业绩条件','fields'=>['metric_codes','object_kind','operation','periods','aggregate_condition'],'values'=>[
        'metric_terms'=>['销售业绩'],'object_kind'=>'person','operation'=>'condition_list','periods'=>[['kind'=>'relative_days','days'=>30,'end_offset_days'=>0]],
        'aggregate_condition'=>['subject'=>'person','relation'=>'all','result_form'=>'list','conditions'=>[
            ['metric_term'=>'销售业绩','operator'=>'gte','quantity'=>'50000','unit'=>'yuan'],
        ]]],'evidence'=>[['message_id'=>'current','quote'=>$safe['question']]]],
    ['id'=>'r2','meaning'=>'服务次数条件','fields'=>['metric_codes','object_kind','operation','periods','aggregate_condition'],'values'=>[
        'metric_terms'=>['服务次数'],'object_kind'=>'person','operation'=>'condition_list','periods'=>[['kind'=>'relative_days','days'=>30,'end_offset_days'=>0]],
        'aggregate_condition'=>['subject'=>'person','relation'=>'all','result_form'=>'list','conditions'=>[
            ['metric_term'=>'服务次数','operator'=>'gte','quantity'=>'24','unit'=>'count'],
        ]]],'evidence'=>[['message_id'=>'current','quote'=>$safe['question']]]],
]],$safe);
$splitBinding=$binding;
$splitBinding['requirement_bindings']=[
    ['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['staff_sales_yeji']],
    ['requirement_id'=>'r2','status'=>'satisfied','metric_codes'=>['staff_service_num']],
];
$splitBound=AiIntentResultContract::normalize($splitBinding,['staff_sales_yeji','staff_service_num'],[],$safe,$splitSemantic);
csCheck($splitBound['aggregate_condition']===$boundCondition,
    'compatible condition carriers split across semantic requirements merge into one ordered executable set');
csCheck(AiIntentResultContract::repairableFormat('missing_replacement:aggregate_condition')
    &&AiIntentResultContract::repairableFormat('binding_requirement_value_mismatch:aggregate_condition')
    &&AiIntentResultContract::repairableFormat('binding_requirement_value_mismatch:result_reference'),
    'missing or changed bound condition carrier has one bounded model-owned repair path');

$priorQuery=$query;$priorQuery['query_shape']='condition_count';$priorQuery['aggregate_condition']['result_form']='count';
$followSafe=['schema_version'=>'sanitized-question-v2','question'=>'明细有哪些？','has_unresolved_conditions'=>false,
    'server_resolved_fields'=>[],'reference_date'=>'2026-09-19','recent_questions'=>[$safe['question']],
    'evidence_messages'=>[['id'=>'current','text'=>'明细有哪些？'],['id'=>'recent_1','text'=>$safe['question']]],
    'prior_query'=>IntentContextMerger::modelView($priorQuery)];
$badReferenceUnderstanding=['goal'=>'查看明细','status'=>'understood','requirements'=>[[
    'id'=>'r1','meaning'=>'查看上一结果','fields'=>['operation','result_reference'],
    'values'=>['operation'=>'condition_list','result_reference'=>['group'=>'top','ordinal'=>1]],
    'evidence'=>[['message_id'=>'current','quote'=>'明细有哪些？']],
]]];
$badReferencePredicate=null;
try { AiIntentUnderstandingContract::normalize($badReferenceUnderstanding,$followSafe); }
catch (\app\services\ai\contract\AiContractException $error) { $badReferencePredicate=$error->diagnostic()['predicate']??null; }
csCheck($badReferencePredicate==='result_reference_without_ranked_prior'
    &&AiIntentUnderstandingContract::repairable($badReferencePredicate),
    'a non-ranking continuation cannot acquire a top/bottom result reference and receives one semantic repair');
$priorValidator=Closure::bind(static function($client,array $query): void {$client->validPriorQuery($query);},null,\app\services\ai\model\SiliconFlowClient::class);
$priorValidator(new \app\services\ai\model\SiliconFlowClient(),$followSafe['prior_query']);
csCheck(true,'a verified generic condition set is accepted as safe model context for a natural follow-up');
$objectVocabulary=Closure::bind(static function(array $items): array {return \app\services\ai\model\SiliconFlowClient::objectVocabulary($items);},null,\app\services\ai\model\SiliconFlowClient::class);
$measurementVocabulary=Closure::bind(static function(array $items): array {return \app\services\ai\model\SiliconFlowClient::measurementVocabulary($items);},null,\app\services\ai\model\SiliconFlowClient::class);
$projectedObjects=$objectVocabulary([
    ['object_kind'=>'order','object_label'=>'销售订单'],['object_kind'=>'sale_line','object_label'=>'销售明细'],['object_kind'=>'card','object_label'=>'卡项'],
]);
$projectedMeasurements=$measurementVocabulary([[
    'measurement_label'=>'销售额','customer_terms'=>['销售额'],'meaning'=>'统计销售事实金额',
    'analytical_object_kinds'=>['order','sale_line','card','project','product'],
]]);
csCheck(array_column($projectedObjects,'object_kind')===['order','sale_line','card']
    &&($projectedMeasurements[0]['analytical_object_kinds']??[])===['order','sale_line','card','project','product'],
    'all registered condition object kinds survive the model vocabulary boundary');
$dimensionIdentity=Closure::bind(static function($reader,$value){return $reader->dimensionIdentity($value);},null,\app\services\query\metric\RegisteredMetricReadServices::class);
$identityReader=(new ReflectionClass(\app\services\query\metric\RegisteredMetricReadServices::class))->newInstanceWithoutConstructor();
csCheck($dimensionIdentity($identityReader,'901')===901
    &&$dimensionIdentity($identityReader,'sale:20260919|opaque-id')==='sale:20260919|opaque-id',
    'condition dimensions accept numeric item ids and bounded immutable order or sale-line ids');
csReject(static function()use($dimensionIdentity,$identityReader): void {$dimensionIdentity($identityReader,'unsafe id');},'METRIC_SOURCE_RESULT_INVALID');
$followUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'列出上一条件集合的员工名单','status'=>'understood','requirements'=>[[
    'id'=>'r1','meaning'=>'查看名单','fields'=>['operation'],'values'=>['operation'=>'condition_list'],
    'evidence'=>[['message_id'=>'current','quote'=>'明细有哪些？']],
]]],$followSafe);
$reusedResultForm=AiIntentResultContract::inheritedConditionResultFormContextIntent(
    $followUnderstanding,$priorQuery,['presentation_origin'=>'customer_or_verified_context']
);
csCheck(($reusedResultForm['operation']??null)==='condition_list'
    &&($reusedResultForm['aggregate_condition']['result_form']??null)==='list'
    &&($reusedResultForm['metric_codes']??null)===$priorQuery['metric_codes']
    &&($reusedResultForm['context_delta']['operation']??null)==='replace'
    &&($reusedResultForm['context_delta']['aggregate_condition']??null)==='replace',
    'a typed count/list-only continuation reuses the complete signed condition population');
$notResultForm=$followUnderstanding;$notResultForm['requirements'][0]['fields']=['operation','periods'];
$notResultForm['requirements'][0]['values']['periods']=[['kind'=>'month_offset','offset_months'=>0]];
csCheck(AiIntentResultContract::inheritedConditionResultFormContextIntent(
    $notResultForm,$priorQuery,['presentation_origin'=>'customer_or_verified_context']
)===null,'the result-form shortcut rejects every continuation carrying another meaning');
$repeatedPriorCondition=['goal'=>'列出上一条件集合的员工名单','status'=>'understood','requirements'=>[[
    'id'=>'r1','meaning'=>'查看名单','fields'=>['metric_codes','object_kind','operation','aggregate_condition'],'values'=>[
        'metric_terms'=>['销售业绩','劳动业绩'],'object_kind'=>'person','operation'=>'condition_list',
        'aggregate_condition'=>['subject'=>'person','relation'=>'all','result_form'=>'list','conditions'=>[
            ['metric_term'=>'销售业绩','operator'=>'gte','quantity'=>'50000','unit'=>'yuan'],
            ['metric_term'=>'劳动业绩','operator'=>'gte','quantity'=>'2000000','unit'=>'yuan'],
        ]],
    ],'evidence'=>[['message_id'=>'current','quote'=>'明细有哪些？']],
]]];
$collapsedPriorCondition=AiIntentUnderstandingContract::normalize($repeatedPriorCondition,$followSafe);
csCheck(!in_array('metric_codes',$collapsedPriorCondition['requirements'][0]['fields'],true)
    &&!in_array('aggregate_condition',$collapsedPriorCondition['requirements'][0]['fields'],true)
    &&($collapsedPriorCondition['requirements'][0]['values']['operation']??null)==='condition_list',
    'an exact repeated signed condition collapses to the current response-form delta instead of forging evidence');
$changedPriorCondition=$repeatedPriorCondition;
$changedPriorCondition['requirements'][0]['values']['aggregate_condition']['conditions'][0]['quantity']='50001';
csReject(static function()use($changedPriorCondition,$followSafe): void {
    AiIntentUnderstandingContract::normalize($changedPriorCondition,$followSafe);
},'AI_MODEL_INTENT_CONTRACT_INVALID');
$delta=array_fill_keys(AiIntentResultContract::DELTA_FIELDS,'inherit');$delta['operation']='replace';
$followBinding=['object_kind'=>'person','object_term'=>'','operation'=>'condition_list','metric_codes'=>[],
    'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],
    'periods'=>[],'scope'=>'unspecified','aggregate_condition'=>null,'requirement_bindings'=>[],
    'context_delta'=>$delta,'result_reference'=>['group'=>'top','ordinal'=>1],'unresolved_fragments'=>[]];
$followBound=AiIntentResultContract::normalize($followBinding,['staff_sales_yeji','staff_labor_yeji'],[],$followSafe,$followUnderstanding);
$followMerged=IntentContextMerger::merge($priorQuery,$followBound)['intent'];
csCheck($followBound['result_reference']===null
    &&$followMerged['operation']==='condition_list'
    &&$followMerged['metric_codes']===$priorQuery['metric_codes']
    &&$followMerged['aggregate_condition']['result_form']==='list'
    &&$followMerged['aggregate_condition']['conditions']===$priorQuery['aggregate_condition']['conditions'],
    'a natural list follow-up changes only response form and inherits every signed predicate');

$memberUpdateSource=$memberMulti;
$memberUpdateSource['query_shape']='condition_count';
$memberUpdateSource['aggregate_condition']['result_form']='count';
$memberUpdateSafe=['schema_version'=>'sanitized-question-v2','question'=>'金额门槛改成1万元','has_unresolved_conditions'=>false,
    'server_resolved_fields'=>[],'reference_date'=>'2026-09-19','recent_questions'=>[$memberNaturalSafe['question']],
    'evidence_messages'=>[['id'=>'current','text'=>'金额门槛改成1万元']],
    'prior_query'=>IntentContextMerger::modelView($memberUpdateSource)];
$memberUpdateUnderstanding=AiIntentUnderstandingContract::normalize([
    'goal'=>'修改上一条件集合中的金额门槛','status'=>'understood','requirements'=>[[]+[
        'id'=>'r1','meaning'=>'把金额门槛改为1万元','fields'=>['condition_update'],
        'values'=>['condition_update'=>['target_term'=>'金额门槛','operator'=>'gte','quantity'=>'10000','unit'=>'yuan']],
        'evidence'=>[['message_id'=>'current','quote'=>'金额门槛改成1万元']],
    ]],
],$memberUpdateSafe);
$memberUpdateIntent=AiIntentResultContract::inheritedConditionUpdateContextIntent(
    $memberUpdateUnderstanding,$memberUpdateSource
);
csCheck(is_array($memberUpdateIntent)
    &&($memberUpdateIntent['context_delta']['aggregate_condition']??null)==='replace'
    &&($memberUpdateIntent['context_delta']['metric_codes']??null)==='inherit',
    'a typed single-condition edit reuses the signed condition binding without asking the model to guess a new metric');
$memberUpdateMerged=IntentContextMerger::merge($memberUpdateSource,$memberUpdateIntent)['intent'];
csCheck($memberUpdateMerged['metric_codes']===$memberUpdateSource['metric_codes']
    &&$memberUpdateMerged['aggregate_condition']['conditions'][0]===$memberUpdateSource['aggregate_condition']['conditions'][0]
    &&$memberUpdateMerged['aggregate_condition']['conditions'][1]['quantity']==='10000'
    &&$memberUpdateMerged['aggregate_condition']['conditions'][1]['metric_code']==='sales_collected_amount',
    'a unique yuan predicate changes while the visit condition, object, relation and registered metric order remain signed');
$implicitUpdateSafe=['schema_version'=>'sanitized-question-v2','question'=>'改成2000元呢','has_unresolved_conditions'=>false,
    'server_resolved_fields'=>[],'reference_date'=>'2026-09-20','recent_questions'=>[$memberNaturalSafe['question']],
    'evidence_messages'=>[['id'=>'current','text'=>'改成2000元呢']],
    'prior_query'=>IntentContextMerger::modelView($memberUpdateSource)];
$implicitUpdateUnderstanding=AiIntentUnderstandingContract::normalize([
    'goal'=>'修改上一条件中唯一的金额门槛','status'=>'understood','requirements'=>[[]+[
        'id'=>'r1','meaning'=>'把金额门槛改为2000元','fields'=>['condition_update'],
        // Providers sometimes repeat the signed metric label even though the
        // terse current turn only states the replacement value.
        'values'=>['condition_update'=>['target_term'=>'实际收款销售额','operator'=>'gte','quantity'=>'2000','unit'=>'yuan']],
        'evidence'=>[['message_id'=>'current','quote'=>'改成2000元呢']],
    ]],
],$implicitUpdateSafe);
$implicitUpdateIntent=AiIntentResultContract::inheritedConditionUpdateContextIntent(
    $implicitUpdateUnderstanding,$memberUpdateSource
);
csCheck(is_array($implicitUpdateIntent)
    &&($implicitUpdateIntent['aggregate_condition']['conditions'][0]??null)===$memberUpdateSource['aggregate_condition']['conditions'][0]
    &&($implicitUpdateIntent['aggregate_condition']['conditions'][1]['metric_code']??null)==='sales_collected_amount'
    &&($implicitUpdateIntent['aggregate_condition']['conditions'][1]['quantity']??null)==='2000',
    'a terse threshold edit may target the one signed predicate with its unit without copying an unstated metric label');
$ambiguousMoneySource=$memberUpdateSource;
$ambiguousMoneySource['metric_codes']=['sales_collected_amount','cash_performance'];
$ambiguousMoneySource['aggregate_condition']['conditions']=[
    ['metric_code'=>'sales_collected_amount','operator'=>'gte','quantity'=>'20000','unit'=>'yuan'],
    ['metric_code'=>'cash_performance','operator'=>'gte','quantity'=>'5000','unit'=>'yuan'],
];
csCheck(AiIntentResultContract::inheritedConditionUpdateContextIntent($memberUpdateUnderstanding,$ambiguousMoneySource)===null,
    'an ambiguous amount edit never chooses between two signed yuan predicates by position or guesswork');
csReject(static function()use($implicitUpdateSafe,$ambiguousMoneySource): void {
    $safe=$implicitUpdateSafe;$safe['prior_query']=IntentContextMerger::modelView($ambiguousMoneySource);
    AiIntentUnderstandingContract::normalize([
        'goal'=>'修改金额门槛','status'=>'understood','requirements'=>[[]+[
            'id'=>'r1','meaning'=>'把门槛改为2000元','fields'=>['condition_update'],
            'values'=>['condition_update'=>['target_term'=>'未在当前话语中的指标','operator'=>'gte','quantity'=>'2000','unit'=>'yuan']],
            'evidence'=>[['message_id'=>'current','quote'=>'改成2000元呢']],
        ]],
    ],$safe);
},'AI_MODEL_INTENT_CONTRACT_INVALID');

$personUpdateSource=$any;
$personUpdateSafe=['schema_version'=>'sanitized-question-v2','question'=>'销售业绩门槛改成3万元','has_unresolved_conditions'=>false,
    'server_resolved_fields'=>[],'reference_date'=>'2026-09-19','recent_questions'=>[$safe['question']],
    'evidence_messages'=>[['id'=>'current','text'=>'销售业绩门槛改成3万元']],
    'prior_query'=>IntentContextMerger::modelView($personUpdateSource)];
$personUpdateUnderstanding=AiIntentUnderstandingContract::normalize([
    'goal'=>'修改上一条件集合中的销售业绩门槛','status'=>'understood','requirements'=>[[]+[
        'id'=>'r1','meaning'=>'把销售业绩门槛改为3万元','fields'=>['metric_codes','condition_update'],
        'values'=>['metric_terms'=>['销售业绩'],'condition_update'=>[
            'target_term'=>'销售业绩门槛','operator'=>'lt','quantity'=>'30000','unit'=>'yuan',
        ]],
        'evidence'=>[['message_id'=>'current','quote'=>'销售业绩门槛改成3万元']],
    ]],
],$personUpdateSafe);
$personUpdateIntent=AiIntentResultContract::inheritedConditionUpdateContextIntent(
    $personUpdateUnderstanding,$personUpdateSource
);
csCheck(is_array($personUpdateIntent)
    &&($personUpdateIntent['aggregate_condition']['relation']??null)==='any'
    &&($personUpdateIntent['aggregate_condition']['conditions'][0]['quantity']??null)==='30000'
    &&($personUpdateIntent['aggregate_condition']['conditions'][1]??null)===$personUpdateSource['aggregate_condition']['conditions'][1],
    'a redundant named-metric audit carrier updates only its unique signed predicate and preserves OR');
$splitPersonUpdate=AiIntentUnderstandingContract::normalize([
    'goal'=>'修改上一条件集合中的销售业绩门槛','status'=>'understood','requirements'=>[
        [
            'id'=>'r1','meaning'=>'修改销售业绩门槛','fields'=>['condition_update'],
            'values'=>['condition_update'=>[
                'target_term'=>'销售业绩门槛','operator'=>'lt','quantity'=>'30000','unit'=>'yuan',
            ]],
            'evidence'=>[['message_id'=>'current','quote'=>'销售业绩门槛改成3万元']],
        ],
        [
            'id'=>'r2','meaning'=>'销售业绩是被修改的指标','fields'=>['metric_codes'],
            'values'=>['metric_terms'=>['销售业绩']],
            'evidence'=>[['message_id'=>'current','quote'=>'销售业绩门槛改成3万元']],
        ],
    ],
],$personUpdateSafe);
$splitPersonIntent=AiIntentResultContract::inheritedConditionUpdateContextIntent($splitPersonUpdate,$personUpdateSource);
csCheck(is_array($splitPersonIntent)
    &&($splitPersonIntent['metric_codes']??null)===$personUpdateSource['metric_codes']
    &&($splitPersonIntent['context_delta']['metric_codes']??null)==='inherit'
    &&($splitPersonIntent['aggregate_condition']['relation']??null)==='any'
    &&($splitPersonIntent['aggregate_condition']['conditions'][0]['quantity']??null)==='30000'
    &&($splitPersonIntent['aggregate_condition']['conditions'][1]??null)===$personUpdateSource['aggregate_condition']['conditions'][1],
    'a provider split between condition update and named metric updates one predicate without replacing the signed metric set');
$wrongMetricUpdate=$personUpdateUnderstanding;
$wrongMetricUpdate['requirements'][0]['values']['metric_terms']=['服务次数'];
csCheck(AiIntentResultContract::inheritedConditionUpdateContextIntent($wrongMetricUpdate,$personUpdateSource)===null,
    'a redundant metric carrier that points at another predicate cannot authorize a threshold update');

$legacyPrior=['query_shape'=>'threshold_count','metric_codes'=>['sales_collected_amount'],'start_date'=>'2026-09-01','end_date'=>'2026-09-19',
    'compare_range'=>null,'store_ids'=>[],'business_filters'=>['object_kind'=>'member'],'ranking'=>null,
    'aggregate_condition'=>['subject'=>'member','aggregation'=>'period_total','operator'=>'gte','amount_cents'=>100000]];
$legacyDelta=array_fill_keys(AiIntentResultContract::DELTA_FIELDS,'inherit');$legacyDelta['operation']='replace';
$legacyFollow=['object_kind'=>'member','object_term'=>'','operation'=>'condition_list','metric_codes'=>[],
    'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],
    'periods'=>[],'scope'=>'unspecified','aggregate_condition'=>null,'requirement_bindings'=>[],
    'context_delta'=>$legacyDelta,'result_reference'=>null,'unresolved_fragments'=>[]];
$legacyMerged=IntentContextMerger::merge($legacyPrior,$legacyFollow)['intent'];
csCheck($legacyMerged['operation']==='condition_list'
    &&$legacyMerged['metric_codes']===['sales_collected_amount']
    &&$legacyMerged['aggregate_condition']===['subject'=>'member','relation'=>'all','result_form'=>'list','conditions'=>[
        ['metric_code'=>'sales_collected_amount','operator'=>'gte','quantity'=>'1000','unit'=>'yuan'],
    ]],
    'a list follow-up promotes a verified legacy member threshold without changing metric, operator, amount, period or scope');

echo "PASS condition set: $checks checks (synthetic data only)\n";

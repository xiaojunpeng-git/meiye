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
use app\services\ai\semantic\AiConditionResponseFormResolver;

$checks=0;
function csCheck($ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function csReject(callable $call,string $expected): void { try {$call();} catch (Throwable $e) {$code=method_exists($e,'getErrorCode')?$e->getErrorCode():$e->getMessage();csCheck($code===$expected,'expected '.$expected.', got '.$code);return;} throw new RuntimeException('expected rejection '.$expected); }
csCheck(AiConditionResponseFormResolver::resolve('2026年消费4980的有多少个人')==='count',
    'population count is an answer form rather than a second spend metric');
csCheck(AiConditionResponseFormResolver::resolve('今天收了多少钱')===null,
    'money amount question is not misclassified as a population count');

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
$explicitMonth=(new \app\services\ai\model\AiModelInputProjector())->project(
    '本月累计实际收款销售额达到1000元的客户有多少？'
);
csCheck(($explicitMonth['date_terms']??null)===[['code'=>'THIS_MONTH']]
    && empty($explicitMonth['date_grouping_ambiguous']),
    'an explicit month in a condition count cannot fall through to the neutral today default');
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
// Current assets share the member-grain condition contract with historical
// visits and collected cash; no question-specific pair is installed.
$assetCodes=['member_days_since_last_visit','member_account_balance',
    'member_remaining_project_amount','member_total_assets','member_service_visit_count'];
$assetConditions=[
    ['metric_code'=>$assetCodes[0],'operator'=>'gt','quantity'=>'90','unit'=>'day'],
    ['metric_code'=>$assetCodes[1],'operator'=>'gte','quantity'=>'500','unit'=>'yuan'],
    ['metric_code'=>$assetCodes[2],'operator'=>'gt','quantity'=>'0','unit'=>'yuan'],
    ['metric_code'=>$assetCodes[3],'operator'=>'gte','quantity'=>'1000','unit'=>'yuan'],
    ['metric_code'=>$assetCodes[4],'operator'=>'gte','quantity'=>'2','unit'=>'count'],
];
$assetQuery=$memberQuery;
$assetQuery['metric_codes']=$assetCodes;
$assetQuery['aggregate_condition']['conditions']=$assetConditions;
$assetQuery['condition_set']=(new AiConditionSetCompiler())->compile(
    $assetQuery['aggregate_condition'],['metric_readiness'=>MetricReadViewServices::metricCapabilities()],'condition_list'
);
$assetCaps=$memberCapabilities;$assetCaps['metric_codes']=$assetCodes;
$assetCompiled=(new AiRegisteredPlanCompiler())->compile(['query'=>$assetQuery,'output_format'=>'screen'],$assetCaps);
csCheck(count($assetCompiled['query']['condition_set']['conditions'])===5
    &&($assetCompiled['query']['condition_set']['conditions'][1]['value']??null)===50000
    &&($assetCompiled['query']['condition_set']['conditions'][3]['value']??null)===100000,
    'five registered member conditions compile with distinct units and intact order');
csCheck($normalizeMetricQuery($views,$assetQuery)['metric_codes']===$assetCodes,
    'Reader admission accepts five registered conditions without dropping any metric');
csCheck(MetricSemanticCatalog::registeredNonOverlappingTermsInText(
    '90天没有到店且总余额在500元以上', $assetCodes
)===[
    ['metric_code'=>'member_days_since_last_visit','term'=>'没有到店','ai_query_ready'=>true],
    ['metric_code'=>'member_total_assets','term'=>'总余额','ai_query_ready'=>true],
], 'nested balance wording and no-visit wording each retain one registered meaning');
$boundedCodes=[];$boundedConditions=[];
for ($index=1;$index<=\app\services\query\metric\MetricDefinitionRegistry::MAX_CONDITIONS;$index++) {
    $code='fixture_metric_'.$index;
    $boundedCodes[$code]=['ai_query_ready'=>true,'query_shapes'=>['condition_count'],
        'condition_subjects'=>['member'],'storage_unit'=>'count'];
    $boundedConditions[]=['metric_code'=>$code,'operator'=>'gte','quantity'=>'1','unit'=>'count'];
}
$bounded=['subject'=>'member','relation'=>'all','result_form'=>'count','conditions'=>$boundedConditions];
csCheck(count((new AiConditionSetCompiler())->compile($bounded,['metric_readiness'=>$boundedCodes],'condition_count')['conditions'])
    ===\app\services\query\metric\MetricDefinitionRegistry::MAX_CONDITIONS,'bounded compound query retains every admitted predicate');
csReject(static function()use($bounded,$boundedCodes):void {
    $extra=$bounded;$extra['conditions'][]=$bounded['conditions'][0];
    (new AiConditionSetCompiler())->compile($extra,['metric_readiness'=>$boundedCodes],'condition_count');
},'AI_UNSUPPORTED_CONDITION');
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
// Predicate periods are typed and signed per leaf. The query date is only
// their frozen reference day; the list must label both measured windows.
$dated=$memberMulti;
$dated['start_date']='2026-09-19';$dated['end_date']='2026-09-19';
$dated['aggregate_condition']['conditions'][0]['period']=[
    'kind'=>'calendar_period','unit'=>'month','offset'=>-1,'count'=>1,'end_mode'=>'full',
];
$dated['aggregate_condition']['conditions'][1]['period']=[
    'kind'=>'calendar_period','unit'=>'month','offset'=>0,'count'=>1,'end_mode'=>'to_reference',
];
$dated['condition_set']=(new AiConditionSetCompiler())->compile(
    $dated['aggregate_condition'],['metric_readiness'=>MetricReadViewServices::metricCapabilities()],'condition_list'
);
$datedCaps=$memberCapabilities;$datedCaps['metric_codes']=$dated['metric_codes'];
$datedCompiled=(new AiRegisteredPlanCompiler())->compile(['query'=>$dated,'output_format'=>'screen'],$datedCaps);
csCheck($datedCompiled['query']['condition_set']['conditions'][0]['period']['offset']===-1
    &&$datedCompiled['query']['condition_set']['conditions'][1]['period']['offset']===0
    &&$normalizeMetricQuery($views,$dated)['condition_set']['conditions'][1]['period']['offset']===0,
    'separate typed condition periods survive signed compilation and Reader admission');
$datedEvidence=['query'=>$dated,'results'=>[[
    'period'=>'current','metric_code'=>'member_service_visit_count','storage_unit'=>'count',
    'object_kind'=>'member','condition_set'=>$dated['condition_set'],'count'=>1,
    'list_limit'=>100,'has_more'=>false,'rows'=>[[
        'member_id'=>101,'member_name'=>'测试会员','metrics'=>[
            'member_service_visit_count'=>4,'sales_collected_amount'=>2350000,
        ],'condition_values'=>[4,2350000],
    ]],
]]];
$datedAnswer=(new AiAnswerRenderer())->render($datedEvidence);
$datedExport=MetricReadViewExportProvider::project($datedEvidence);
csCheck(count($datedAnswer['table']['rows'])===2
    &&strpos($datedAnswer['table']['rows'][0]['metric'],'2026-08-01 至 2026-08-31')!==false
    &&strpos($datedAnswer['table']['rows'][1]['metric'],'2026-09-01 至 2026-09-19')!==false
    &&($datedExport[0]['period_name']??null)==='2026-08-01 至 2026-08-31'
    &&($datedExport[1]['period_name']??null)==='2026-09-01 至 2026-09-19',
    'screen and export identify the factual window of every dated predicate');
$missingPeriod=$dated['aggregate_condition'];unset($missingPeriod['conditions'][1]['period']);
csReject(static function()use($missingPeriod):void {
    (new AiConditionSetCompiler())->compile($missingPeriod,
        ['metric_readiness'=>MetricReadViewServices::metricCapabilities()],'condition_list');
},'AI_UNSUPPORTED_CONDITION');
$assetAny=$assetQuery;$assetAny['condition_set']['relation']='any';
$assetAny['aggregate_condition']['relation']='any';
$assetAnyView=['query'=>$assetAny,'results'=>[[
    'period'=>'current','metric_code'=>$assetCodes[0],'storage_unit'=>'count','object_kind'=>'member',
    'condition_set'=>$assetAny['condition_set'],'count'=>1,'list_limit'=>100,'has_more'=>false,
    'rows'=>[['member_id'=>101,'member_name'=>'测试会员','metrics'=>[
        'member_days_since_last_visit'=>null,'member_account_balance'=>50000,
        'member_remaining_project_amount'=>0,'member_total_assets'=>50000,
        'member_service_visit_count'=>0,
    ]]],
]]];
$assetAnyAnswer=(new AiAnswerRenderer())->render($assetAnyView);
csCheck(strpos($assetAnyAnswer['summary'],'任一5项条件')!==false
    &&strpos($assetAnyAnswer['summary'],'现有有效服务记录中')===false
    &&($assetAnyAnswer['table']['rows'][0]['value']??null)==='未记录'
    &&count($assetAnyAnswer['table']['rows']??[])===5,
    'OR answer keeps every metric while an unknown last-visit age remains unmeasured');
$memberExport=MetricReadViewExportProvider::project($memberEvidence);
csCheck(count($memberExport)===1&&$memberExport[0]['store_name']==='测试会员；范围：当前授权范围'
    &&$memberExport[0]['metric_value']==='8900.01'&&$memberExport[0]['unit']==='元',
    'member condition list export reuses verified rows and registered units instead of exporting only the count');
$view=$views->create([],$query);
csCheck($view['results'][0]['count']===1&&$view['results'][0]['rows'][0]['employee_name']==='甲员工','AND set evaluates against one authorised current-personnel population');
$datedPersonnel=$query;
$datedPersonnel['aggregate_condition']['conditions'][0]['period']=[
    'kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-19',
];
$datedPersonnel['aggregate_condition']['conditions'][1]['period']=[
    'kind'=>'date_range','start'=>'2026-09-19','end'=>'2026-09-19',
];
$datedPersonnel['condition_set']=(new AiConditionSetCompiler())->compile(
    $datedPersonnel['aggregate_condition'],$capabilities,'condition_list'
);
$datedPersonnelView=$views->create([],$datedPersonnel);
csCheck($datedPersonnelView['results'][0]['count']===1
    &&count($datedPersonnelView['results'][0]['rows'][0]['condition_values']??[])===2,
    'personnel condition list keeps separate registered values for two periods');
// Two stated bounds are predicates on one registered value, so the signed
// metric list remains unique while the condition carrier keeps both bounds.
$rangeQuery=$query;
$rangeQuery['metric_codes']=['staff_sales_yeji'];
$rangeQuery['aggregate_condition']['conditions']=[
    ['metric_code'=>'staff_sales_yeji','operator'=>'gte','quantity'=>'50000','unit'=>'yuan'],
    ['metric_code'=>'staff_sales_yeji','operator'=>'lte','quantity'=>'60000','unit'=>'yuan'],
];
$rangeQuery['condition_set']=(new AiConditionSetCompiler())->compile(
    $rangeQuery['aggregate_condition'],$capabilities,'condition_list'
);
$rangeCaps=$capabilities;$rangeCaps['metric_codes']=['staff_sales_yeji'];
$rangeCompiled=(new AiRegisteredPlanCompiler())->compile(['query'=>$rangeQuery,'output_format'=>'screen'],$rangeCaps);
csCheck($rangeCompiled['query']['condition_set']===$rangeQuery['condition_set']
    &&$views->create([],$rangeQuery)['results'][0]['count']===1,
    'same-metric lower and upper bounds keep one selected metric and one qualifying person');
$rangeExport=MetricReadViewExportProvider::project($views->create([],$rangeQuery));
csCheck(count($rangeExport)===1,
    'two undated bounds on one metric export one measured column');
$grouped=$query;
$grouped['aggregate_condition']['conditions']=[
    ['metric_code'=>'staff_sales_yeji','operator'=>'gte','quantity'=>'50000','unit'=>'yuan'],
    ['metric_code'=>'staff_labor_yeji','operator'=>'gte','quantity'=>'3000000','unit'=>'yuan'],
    ['metric_code'=>'staff_sales_yeji','operator'=>'lte','quantity'=>'60000','unit'=>'yuan'],
];
$grouped['aggregate_condition']['expression']=['relation'=>'all','children'=>[
    0,['relation'=>'any','children'=>[1,2]],
]];
$grouped['condition_set']=(new AiConditionSetCompiler())->compile(
    $grouped['aggregate_condition'],$capabilities,'condition_list'
);
$groupedCompiled=(new AiRegisteredPlanCompiler())->compile(['query'=>$grouped,'output_format'=>'screen'],$capabilities);
$groupView=$views->create([],$grouped);
csCheck($groupedCompiled['query']['condition_set']===$grouped['condition_set']
    &&$groupView['results'][0]['count']===1,
    'mixed AND/OR grouping evaluates every stated predicate on one authorized person');
$groupAnswer=(new AiAnswerRenderer())->render($groupView);
csCheck(strpos($groupAnswer['summary'],'组合3项条件')!==false
    &&count($groupAnswer['table']['rows']??[])===2,
    'grouped answer explains the combined condition and displays a repeated metric once');
$droppedGroup=$grouped['aggregate_condition'];
$droppedGroup['expression']=['relation'=>'all','children'=>[0,1]];
csReject(static function()use($droppedGroup,$capabilities):void {
    (new AiConditionSetCompiler())->compile($droppedGroup,$capabilities,'condition_list');
},'AI_UNSUPPORTED_CONDITION');
csCheck(($view['results'][0]['list_limit']??null)===1000&&($view['results'][0]['has_more']??null)===false,
    'personnel condition lists retain the bounded full-result ceiling for export');
$truncatedEvidence=$view;
$truncatedEvidence['results'][0]['count']=101;
$truncatedEvidence['results'][0]['has_more']=true;
$truncatedAnswer=(new AiAnswerRenderer())->render($truncatedEvidence);
csCheck(strpos($truncatedAnswer['summary'],'当前展示前1000条')!==false
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
$gateway=(new ReflectionClass(\app\services\ai\AiGatewayServices::class))->newInstanceWithoutConstructor();
$singleTitleRecovery=new ReflectionMethod($gateway,'applyUniqueRegisteredMetricTermBinding');
$completeConditionIntent=['operation'=>'condition_list','metric_codes'=>['sales_amount','sales_quantity'],
    'aggregate_condition'=>$productBinding];
csCheck($singleTitleRecovery->invoke($gateway,$completeConditionIntent,[],[],null,[])===$completeConditionIntent,
    'single-title recovery cannot collapse an accepted two-metric condition set');
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
    &&array_column($statedStateTerms,'ai_query_ready')===[true,true],
    'combined natural wording retains both registered measurements under observed-service scope');
$staffConditionTerms=MetricSemanticCatalog::registeredNonOverlappingTermsInText(
    '近30天员工业绩达到5万元且服务次数达到24次的有哪些'
);
csCheck(array_column($staffConditionTerms,'metric_code')===['staff_sales_yeji','staff_service_num'],
    'employee performance uses the registered first-reading metric while service count stays independent');
csCheck(($memberStateCaps['member_remaining_project_times']['condition_unit']??null)==='count'
    &&($memberStateCaps['member_days_since_last_visit']['condition_unit']??null)==='day'
    &&($memberStateCaps['member_days_since_last_visit']['condition_subjects']??null)===['member']
    &&($memberStateCaps['member_days_since_last_visit']['ai_query_ready']??false)===true
    &&($memberStateCaps['member_days_since_last_visit']['observed_answer_prefix']??null)==='现有有效服务记录中',
    'last-visit age is readable only as a qualified observed-record answer');
$memberStateBinding=['subject'=>'member','relation'=>'all','result_form'=>'list','conditions'=>[
    ['metric_code'=>'member_remaining_project_times','operator'=>'gt','quantity'=>'0','unit'=>'count'],
    ['metric_code'=>'member_days_since_last_visit','operator'=>'gt','quantity'=>'90','unit'=>'day'],
]];
$memberStateCompilerCaps=$memberStateCaps;
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
csCheck(($normalizeMetricQuery($views,$memberStateQuery)['condition_set']['conditions'][1]['value']??null)===90,
    'last-visit predicate is admitted through the same validated member condition query');
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
    &&($memberStateAnswer['table']['rows'][1]['value']??null)==='121'
    &&strpos($memberStateAnswer['summary'],'现有有效服务记录中查到')===0,
    'member state evidence keeps entitlement counts and elapsed days visibly distinct');
$memberStateEmpty=$memberStateEvidence;
$memberStateEmpty['results'][0]['count']=0;
$memberStateEmpty['results'][0]['rows']=[];
$memberStateNoData=(new AiAnswerRenderer())->render($memberStateEmpty);
csCheck(strpos($memberStateNoData['summary'],'现有有效服务记录中未查到符合条件的会员。')===0
    &&strpos($memberStateNoData['summary'],'共有0人')===false,
    'empty observed-service results are reported as no matching records, never a complete historical zero');
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
csCheck(($memberNatural['requirements'][0]['values']['object_relation']??null)==='analysis',
    'a complete candidate condition set supplies its own analytical relation when the provider omits it');
$spokenVisitSafe=$memberNaturalSafe;
$spokenVisitSafe['question']='最近30天到店至少2次，而且实际收款销售额达到1万元的会员有哪些？';
$spokenVisitSafe['evidence_messages'][0]['text']=$spokenVisitSafe['question'];
$spokenVisit=$memberNatural;
$spokenVisit['requirements'][0]['values']['aggregate_condition']['conditions'][0]['quantity']='2';
$spokenVisit['requirements'][0]['values']['aggregate_condition']['conditions'][1]['quantity']='10000';
$spokenVisit['requirements'][0]['evidence'][0]['quote']=$spokenVisitSafe['question'];
$spokenVisit=AiIntentUnderstandingContract::normalize($spokenVisit,$spokenVisitSafe);
csCheck($spokenVisit['requirements'][0]['values']['metric_terms']===['到店次数','实际收款销售额'],
    'spoken "visit N times" is proven by the central metric alias without a full-sentence template');
$wrongPopulationForm=$spokenVisit;
$wrongPopulationForm['requirements'][0]['values']['aggregate_condition']['result_form']='count';
$wrongPopulationForm['requirements'][0]['values']['operation']='condition_count';
$correctedPopulationForm=AiIntentUnderstandingContract::normalize($wrongPopulationForm,$spokenVisitSafe);
csCheck(($correctedPopulationForm['requirements'][0]['values']['aggregate_condition']['result_form']??null)==='list'
    &&($correctedPopulationForm['requirements'][0]['values']['operation']??null)==='condition_list',
    'explicit member-list wording corrects only a provider response-form error without changing predicates');
$countPopulationSafe=$spokenVisitSafe;
$countPopulationSafe['question']='最近30天到店至少2次，而且实际收款销售额达到1万元的会员有多少个？';
$countPopulationSafe['evidence_messages'][0]['text']=$countPopulationSafe['question'];
$countPopulation=$wrongPopulationForm;
$countPopulation['requirements'][0]['evidence'][0]['quote']=$countPopulationSafe['question'];
$countPopulation=AiIntentUnderstandingContract::normalize($countPopulation,$countPopulationSafe);
csCheck(($countPopulation['requirements'][0]['values']['aggregate_condition']['result_form']??null)==='count'
    &&($countPopulation['requirements'][0]['values']['operation']??null)==='condition_count',
    'explicit member-count wording remains count and is not confused with an amount threshold');
$nonOverlapping=MetricSemanticCatalog::registeredNonOverlappingTermsInText($spokenVisitSafe['question']);
csCheck(array_column($nonOverlapping,'metric_code')===['member_service_visit_count','sales_collected_amount'],
    'a complete collected-sales title suppresses a shorter sales metric contained only inside the same text span');
csCheck(MetricSemanticCatalog::containingSelectedOwnerCode(
    '销售额',$spokenVisitSafe['question'],[],['member_service_visit_count','sales_collected_amount']
)==='sales_collected_amount',
    'a redundant short audit term resolves to the selected longer registry owner');
csCheck(MetricSemanticCatalog::containingSelectedOwnerCode(
    '销售额',$spokenVisitSafe['question'].'，另外单独看销售额',[],['member_service_visit_count','sales_collected_amount']
)===null,
    'an independently stated short metric is never swallowed by a longer registry owner');
$gateway=new \app\services\ai\AiGatewayServices();
$registeredCondition=(new ReflectionMethod($gateway,'registeredConditionIntent'));
$countQuestion='2026年消费4980的有多少个人';
$countSafe=$memberNaturalSafe;
$countSafe['question']=$countQuestion;
$countSafe['evidence_messages'][0]['text']=$countQuestion;
$countSafe['reference_date']='2026-09-29';
$countSemantic=['goal'=>'统计期间消费金额符合条件的客户人数','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'期间消费金额条件与客户人数','fields'=>['metric_codes','object_kind','operation','periods','aggregate_condition'],
        'values'=>['metric_terms'=>['消费'],'object_kind'=>'member','operation'=>'condition_count',
            'periods'=>[['kind'=>'date_range','start'=>'2026-01-01','end'=>'2026-09-29']],
            'aggregate_condition'=>['subject'=>'member','relation'=>'all','result_form'=>'count','conditions'=>[
                ['metric_term'=>'消费','operator'=>'eq','quantity'=>'4980','unit'=>'yuan'],
            ]]],'evidence'=>[['message_id'=>'current','quote'=>$countQuestion]]],
    ['id'=>'r2','meaning'=>'询问符合条件的人数','fields'=>['metric_codes'],
        'values'=>['metric_terms'=>['有多少个人']],
        'evidence'=>[['message_id'=>'current','quote'=>'有多少个人']]],
]];
$normalizedCount=AiIntentUnderstandingContract::normalize($countSemantic,$countSafe);
csCheck(count($normalizedCount['requirements'])===1
    &&($normalizedCount['requirements'][0]['values']['aggregate_condition']['conditions'][0]['operator']??null)==='eq',
    'a separately repeated count phrase cannot become a second financial metric or alter equality');
$compiledCount=$registeredCondition->invoke($gateway,$normalizedCount,[
    ['metric_code'=>'cash_performance','object_contracts'=>[]],
],$countSafe);
csCheck(($compiledCount['metric_codes']??null)===['cash_performance']
    &&($compiledCount['aggregate_condition']['conditions'][0]['quantity']??null)==='4980'
    &&($compiledCount['periods'][0]['end']??null)==='2026-09-29',
    'registered consumption condition retains amount and year-to-date period');
$localCount=(new ReflectionMethod($gateway,'localClosedMemberMoneyCountUnderstanding'));
$localCountCaps=['metric_codes'=>array_keys(MetricReadViewServices::metricCapabilities()),
    'metric_readiness'=>MetricReadViewServices::metricCapabilities()];
$localCountMeaning=$localCount->invoke($gateway,$countSafe,['local_conditions'=>[]],'2026-09-29',$localCountCaps);
csCheck(($localCountMeaning['requirements'][0]['values']['aggregate_condition']['conditions'][0]['quantity']??null)==='4980'
    &&($localCountMeaning['requirements'][0]['values']['aggregate_condition']['conditions'][0]['operator']??null)==='eq'
    &&($localCountMeaning['requirements'][0]['values']['periods'][0]['end']??null)==='2026-09-29',
    'closed first-turn count uses the exact registry metric, amount and calendar before model splitting');
$localCountIntent=$registeredCondition->invoke($gateway,$localCountMeaning,[
    ['metric_code'=>'cash_performance','object_contracts'=>[]],
],$countSafe);
csCheck(($localCountIntent['metric_codes']??null)===['cash_performance']
    &&($localCountIntent['operation']??null)==='condition_count',
    'closed first-turn meaning still binds through the registered condition compiler');
$independentSafe=$countSafe;
$independentSafe['question']='2026年9月消费大于1980元的客户有多少人';
$independentSafe['evidence_messages'][0]['text']=$independentSafe['question'];
$independentSafe['prior_query']=['operation'=>'condition_count','aggregate_condition'=>[
    'subject'=>'member','relation'=>'all','result_form'=>'count','conditions'=>[
        ['metric_code'=>'cash_performance','operator'=>'gt','quantity'=>'980','unit'=>'yuan'],
    ],
]];
$independentMeaning=$localCount->invoke($gateway,$independentSafe,['local_conditions'=>[]],'2026-09-29',$localCountCaps);
csCheck(($independentMeaning['requirements'][0]['values']['aggregate_condition']['conditions'][0]['quantity']??null)==='1980'
    &&($independentMeaning['requirements'][0]['values']['aggregate_condition']['conditions'][0]['operator']??null)==='gt'
    &&($independentMeaning['requirements'][0]['values']['periods'][0]??null)===[
        'kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-29'],
    'R55 a complete new member-count sentence does not inherit an older amount or date');
$strictSafe=$independentSafe;
$strictSafe['question']='2026年9月消费严格大于1980元的客户有多少人？';
$strictSafe['evidence_messages'][0]['text']=$strictSafe['question'];
$strictMeaning=$localCount->invoke($gateway,$strictSafe,['local_conditions'=>[]],'2026-09-29',$localCountCaps);
csCheck(($strictMeaning['requirements'][0]['values']['aggregate_condition']['conditions'][0]['operator']??null)==='gt'
    &&($strictMeaning['requirements'][0]['values']['aggregate_condition']['conditions'][0]['quantity']??null)==='1980',
    'R55 strict open comparison is the same registered gt predicate, without a second model round trip');
$privateCollisionConfig=['enabled'=>true,'external_processing_authorized'=>true,
    'external_scope_supported'=>true,'external_scope_version'=>'sanitized-question-v1'];
$publicComparators=AiConditionResponseFormResolver::protectedStrictComparisonTerms($strictSafe['question']);
$strictProjection=(new \app\services\ai\model\AiSafeQuestionProjector())->projectConversation(
    $strictSafe['question'],[],$privateCollisionConfig,['严格'],array_merge(['消费'],$publicComparators)
);
csCheck($publicComparators===['严格大于']
    &&($strictProjection['outbound']['question']??null)===$strictSafe['question']
    &&($strictProjection['local_conditions']??null)===[],
    'R55 a same-named private object cannot mask a complete public numeric comparator');
$privateNameProjection=(new \app\services\ai\model\AiSafeQuestionProjector())->projectConversation(
    '查看严格的会员详情',[],$privateCollisionConfig,['严格'],
    AiConditionResponseFormResolver::protectedStrictComparisonTerms('查看严格的会员详情')
);
csCheck(($privateNameProjection['local_conditions']['local_condition_1']??null)==='严格'
    &&strpos($privateNameProjection['outbound']['question'],'严格')===false,
    'R55 a standalone matching private name remains masked');
csCheck(AiConditionResponseFormResolver::parseClosedMemberMoneyCount(
    '2026年9月消费严格大于等于1980元的客户有多少人？','消费')===null,
    'contradictory strict-inclusive comparison must not be silently admitted');
$arrivalQuestion='最近30天到店至少2次，而且实际收款销售额达到1万元的会员有哪些';
$arrivalSafe=$countSafe;
$arrivalSafe['question']=$arrivalQuestion;
$arrivalSafe['evidence_messages'][0]['text']=$arrivalQuestion;
$arrivalSafe['reference_date']='2026-09-30';
$arrivalMeaning=AiIntentUnderstandingContract::normalize([
    'goal'=>'查看同时符合到店与收款条件的会员','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>'两个会员条件','fields'=>['metric_codes','object_kind','operation','aggregate_condition'],
        'values'=>['metric_terms'=>['到店','实际收款销售额'],'object_kind'=>'member','operation'=>'condition_list',
            'aggregate_condition'=>['subject'=>'member','relation'=>'all','result_form'=>'list','conditions'=>[
                ['metric_term'=>'到店','operator'=>'gte','quantity'=>'2','unit'=>'count'],
                ['metric_term'=>'实际收款销售额','operator'=>'gte','quantity'=>'10000','unit'=>'yuan'],
            ]]],'evidence'=>[['message_id'=>'current','quote'=>$arrivalQuestion]],
    ]],
],$arrivalSafe);
$arrivalPeriods=array_values(array_filter($arrivalMeaning['requirements'],static function(array $requirement):bool {
    return in_array('periods',$requirement['fields'],true);
}));
csCheck(count($arrivalPeriods)===1
    &&($arrivalPeriods[0]['values']['periods'][0]??null)===[
        'kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-30']
    &&($arrivalMeaning['requirements'][0]['values']['aggregate_condition']['conditions'][1]['quantity']??null)==='10000'
    &&!isset($arrivalMeaning['groups']),
    'R55 a complete arrival period omitted by the provider is recovered without changing either business predicate');
$duplicatedPeriodCarrier=$arrivalMeaning;
$duplicatedPeriodCarrier['requirements'][0]['fields'][]='periods';
$duplicatedPeriodCarrier=AiIntentUnderstandingContract::normalize($duplicatedPeriodCarrier,$arrivalSafe);
csCheck(($duplicatedPeriodCarrier['requirements'][0]['values']['periods'][0]??null)===[
        'kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-30']
    &&($duplicatedPeriodCarrier['requirements'][1]['values']['periods'][0]??null)===[
        'kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-30'],
    'one stated period repairs a missing duplicate carrier without issuing a second model request');
$ambiguousPeriodCarrier=$arrivalMeaning;
$ambiguousPeriodCarrier['requirements'][0]['fields'][]='periods';
$ambiguousPeriodCarrier['requirements'][0]['values']['periods']=[];
$ambiguousSafe=$arrivalSafe;
$ambiguousSafe['question']='2026年3月和9月到店至少2次，而且实际收款销售额达到1万元的会员有哪些';
$ambiguousSafe['evidence_messages'][0]['text']=$ambiguousSafe['question'];
$ambiguousPeriodCarrier['requirements'][0]['evidence'][0]['quote']=$ambiguousSafe['question'];
$ambiguousPeriodCarrier['requirements'][1]['evidence'][0]['quote']=$ambiguousSafe['question'];
$ambiguousPeriodCarrier=AiIntentUnderstandingContract::normalize($ambiguousPeriodCarrier,$ambiguousSafe);
csCheck(($ambiguousPeriodCarrier['requirements'][0]['values']['periods']??null)===[],
    'multiple stated dates cannot be collapsed into one repaired period');
$arrivalIntent=$registeredCondition->invoke($gateway,$arrivalMeaning,[
    ['metric_code'=>'member_service_visit_count','object_contracts'=>[]],
    ['metric_code'=>'sales_collected_amount','object_contracts'=>[]],
],$arrivalSafe);
csCheck(($arrivalIntent['periods'][0]['start']??null)==='2026-09-01'
    &&array_column($arrivalIntent['aggregate_condition']['conditions'],'metric_code')===
        ['member_service_visit_count','sales_collected_amount'],
    'R55 recovered period and both registered conditions reach one intact binding');
// A provider may distribute one AND population across two condition owners
// and repeat each registered measurement in separate audit rows. The local
// compiler must accept only the same two predicates, in the original order.
$splitArrival=$arrivalMeaning;
$firstCondition=$arrivalMeaning['requirements'][0]['values']['aggregate_condition']['conditions'][0];
$secondCondition=$arrivalMeaning['requirements'][0]['values']['aggregate_condition']['conditions'][1];
$splitPeriod=$arrivalPeriods[0];$splitPeriod['id']='r5';
$splitArrival['requirements']=[
    ['id'=>'r1','fields'=>['metric_codes','object_kind','operation','aggregate_condition'],
        'values'=>['metric_terms'=>['到店'],'object_kind'=>'member','operation'=>'condition_list',
            'aggregate_condition'=>['subject'=>'member','relation'=>'all','result_form'=>'list','conditions'=>[$firstCondition]]]],
    ['id'=>'r2','fields'=>['metric_codes','aggregate_condition'],
        'values'=>['metric_terms'=>['实际收款销售额'],
            'aggregate_condition'=>['subject'=>'member','relation'=>'all','result_form'=>'list','conditions'=>[$secondCondition]]]],
    ['id'=>'r3','fields'=>['metric_codes'],'values'=>['metric_terms'=>['到店']]],
    ['id'=>'r4','fields'=>['metric_codes'],'values'=>['metric_terms'=>['实际收款销售额']]],
    $splitPeriod,
];
$splitFailure=null;$splitContractFailure=null;
$splitArgs=[$splitArrival,[
    ['metric_code'=>'member_service_visit_count','object_contracts'=>[]],
    ['metric_code'=>'sales_collected_amount','object_contracts'=>[]],
],$arrivalSafe,&$splitFailure,&$splitContractFailure];
$splitIntent=$registeredCondition->invokeArgs($gateway,$splitArgs);
csCheck(($splitIntent['operation']??null)==='condition_list'
    &&array_column($splitIntent['aggregate_condition']['conditions']??[],'metric_code')===
        ['member_service_visit_count','sales_collected_amount'],
    'R55 split condition owners retain both AND predicates: '.json_encode($splitContractFailure));
$localWrongQuestion=$countSafe;
$localWrongQuestion['question']='2026年消费4980并且积分200的有多少个人';
$localWrongQuestion['evidence_messages'][0]['text']=$localWrongQuestion['question'];
csCheck($localCount->invoke($gateway,$localWrongQuestion,['local_conditions'=>[]],'2026-09-29',$localCountCaps)===null,
    'first-turn local path refuses a second independently stated condition');
$localCountUnitQuestion=$countSafe;
$localCountUnitQuestion['question']='2026年到店次数3的有多少个人';
$localCountUnitQuestion['evidence_messages'][0]['text']=$localCountUnitQuestion['question'];
csCheck($localCount->invoke($gateway,$localCountUnitQuestion,['local_conditions'=>[]],'2026-09-29',$localCountCaps)===null,
    'a registered count-unit metric cannot be treated as a monetary predicate');
$providerProjectedCount=$countSemantic;
$providerProjectedCount['requirements'][1]['values']['metric_terms']=['消费人数','客户数量'];
$providerProjectedCount['requirements'][1]['evidence'][0]['quote']=$countQuestion;
$providerProjectedCount=AiIntentUnderstandingContract::normalize($providerProjectedCount,$countSafe);
csCheck(count($providerProjectedCount['requirements'])===1
    &&($registeredCondition->invoke($gateway,$providerProjectedCount,[
        ['metric_code'=>'cash_performance','object_contracts'=>[]],
    ],$countSafe)['aggregate_condition']['conditions'][0]['quantity']??null)==='4980',
    'provider-only extra count metrics are removed only after the complete customer sentence proves one amount');
$wrongAmountCount=$countSemantic;
$wrongAmountCount['requirements'][0]['values']['aggregate_condition']['conditions'][0]['quantity']='980';
$wrongAmountCount['requirements'][1]['values']['metric_terms']=['消费'];
$wrongAmountCount['requirements'][1]['evidence'][0]['quote']=$countQuestion;
$wrongAmountCount=AiIntentUnderstandingContract::normalize($wrongAmountCount,$countSafe);
csCheck(count($wrongAmountCount['requirements'])===2,
    'an amount invented by the provider cannot use the closed count repair');
$greaterCount=$countSemantic;
$greaterSafe=$countSafe;
$greaterSafe['question']='2026年消费大于980元的客户有多少人';
$greaterSafe['evidence_messages'][0]['text']=$greaterSafe['question'];
$greaterCount['requirements'][0]['values']['aggregate_condition']['conditions'][0]['operator']='gt';
$greaterCount['requirements'][0]['values']['aggregate_condition']['conditions'][0]['quantity']='980';
$greaterCount['requirements'][0]['evidence'][0]['quote']=$greaterSafe['question'];
$greaterCount['requirements'][1]['values']['metric_terms']=['客户数量'];
$greaterCount['requirements'][1]['evidence'][0]['quote']=$greaterSafe['question'];
$greaterCount=AiIntentUnderstandingContract::normalize($greaterCount,$greaterSafe);
csCheck(count($greaterCount['requirements'])===1,
    'the same safety proof supports a stated greater-than threshold without hardcoding one amount');
$localGreater=$localCount->invoke($gateway,$greaterSafe,['local_conditions'=>[]],'2026-09-29',$localCountCaps);
csCheck(($localGreater['requirements'][0]['values']['aggregate_condition']['conditions'][0]['operator']??null)==='gt'
    &&($localGreater['requirements'][0]['values']['aggregate_condition']['conditions'][0]['quantity']??null)==='980',
    'first-turn local path retains a different threshold and explicit comparator');
$wrongOperatorCount=$countSemantic;
$wrongOperatorCount['requirements'][0]['values']['aggregate_condition']['conditions'][0]['operator']='gt';
$wrongOperatorCount['requirements'][1]['values']['metric_terms']=['消费'];
$wrongOperatorCount['requirements'][1]['evidence'][0]['quote']=$countQuestion;
$wrongOperatorCount=AiIntentUnderstandingContract::normalize($wrongOperatorCount,$countSafe);
csCheck(count($wrongOperatorCount['requirements'])===2,
    'a greater-than operator cannot be silently substituted for bare equality');
$independentCount=$countSemantic;
$independentCount['requirements'][1]['values']['metric_terms']=['积分'];
$independentCount['requirements'][1]['evidence'][0]['quote']='积分';
$independentSafe=$countSafe;
$independentSafe['question']='2026年消费4980并且积分等于2的有多少个人';
$independentSafe['evidence_messages'][0]['text']=$independentSafe['question'];
$independentCount['requirements'][0]['evidence'][0]['quote']=$independentSafe['question'];
$independentCount=AiIntentUnderstandingContract::normalize($independentCount,$independentSafe);
csCheck(count($independentCount['requirements'])===2
    &&$registeredCondition->invoke($gateway,$independentCount,[
        ['metric_code'=>'cash_performance','object_contracts'=>[]],
    ],$independentSafe)===null,
    'an independent unregistered measurement cannot be discarded as count wording');
$compiledSpokenVisit=$registeredCondition->invoke($gateway,$spokenVisit,[
    ['metric_code'=>'member_service_visit_count','object_contracts'=>[]],
    ['metric_code'=>'sales_collected_amount','object_contracts'=>[]],
],$spokenVisitSafe);
csCheck(($compiledSpokenVisit['operation']??null)==='condition_list'
    &&($compiledSpokenVisit['metric_codes']??null)===['member_service_visit_count','sales_collected_amount']
    &&array_column($compiledSpokenVisit['aggregate_condition']['conditions']??[],'metric_code')===['member_service_visit_count','sales_collected_amount'],
    'accepted member conditions compile all uniquely registered bindings without a second model decision');
$spokenVisitWithNestedAudit=$spokenVisit;
$spokenVisitWithNestedAudit['requirements'][]=[
    'id'=>'r2','meaning'=>'销售额指标审计回声','fields'=>['metric_codes'],
    'values'=>['metric_terms'=>['销售额']],
    'evidence'=>[['message_id'=>'current','quote'=>$spokenVisitSafe['question']]],
];
$spokenVisitWithNestedAudit=AiIntentUnderstandingContract::normalize($spokenVisitWithNestedAudit,$spokenVisitSafe);
$compiledNestedAudit=$registeredCondition->invoke($gateway,$spokenVisitWithNestedAudit,[
    ['metric_code'=>'member_service_visit_count','object_contracts'=>[]],
    ['metric_code'=>'sales_collected_amount','object_contracts'=>[]],
],$spokenVisitSafe);
csCheck(($compiledNestedAudit['metric_codes']??null)===['member_service_visit_count','sales_collected_amount']
    &&count($compiledNestedAudit['requirement_bindings']??[])===2,
    'a nested short registry audit row is accounted to its selected longer condition owner');
$fragmentVisit=$spokenVisit;
$fragmentVisit['requirements'][0]['values']['aggregate_condition']['conditions'][0]['metric_term']='至少2次';
$fragmentVisit['requirements'][0]['values']['metric_terms']=['至少2次','实际收款销售额'];
$fragmentVisit=AiIntentUnderstandingContract::normalize($fragmentVisit,$spokenVisitSafe);
$compiledFragmentVisit=$registeredCondition->invoke($gateway,$fragmentVisit,[
    ['metric_code'=>'member_service_visit_count','object_contracts'=>[]],
    ['metric_code'=>'sales_collected_amount','object_contracts'=>[]],
],$spokenVisitSafe);
csCheck(($compiledFragmentVisit['metric_codes']??null)===['member_service_visit_count','sales_collected_amount'],
    'a predicate fragment binds only through the question ordered owners with matching subject and units');
$reversedFragmentVisit=$fragmentVisit;
$reversedFragmentVisit['requirements'][0]['values']['aggregate_condition']['conditions']=array_reverse(
    $reversedFragmentVisit['requirements'][0]['values']['aggregate_condition']['conditions']
);
$reversedFragmentVisit['requirements'][0]['values']['metric_terms']=array_reverse(
    $reversedFragmentVisit['requirements'][0]['values']['metric_terms']
);
$reversedFragmentVisit=AiIntentUnderstandingContract::normalize($reversedFragmentVisit,$spokenVisitSafe);
$compiledReversedFragmentVisit=$registeredCondition->invoke($gateway,$reversedFragmentVisit,[
    ['metric_code'=>'member_service_visit_count','object_contracts'=>[]],
    ['metric_code'=>'sales_collected_amount','object_contracts'=>[]],
],$spokenVisitSafe);
csCheck(($compiledReversedFragmentVisit['metric_codes']??null)===['sales_collected_amount','member_service_visit_count'],
    'unique subject-and-unit matching preserves accepted condition order even when it differs from wording order');
$spokenVisitWithPrior=$spokenVisitSafe;
$spokenVisitWithPrior['prior_query']=[
    'metric_codes'=>['cash_performance'],'operation'=>'ranking',
    'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-24']],
    'ranking'=>['direction'=>'top','limit'=>1],'scope'=>'authorized','object_kind'=>'member',
    'has_business_filter'=>true,'has_object_selection'=>false,'has_store_scope_restriction'=>false,
    'presentation_origin'=>'customer_or_verified_context',
];
$compiledSpokenVisitWithPrior=$registeredCondition->invoke($gateway,$spokenVisit,[
    ['metric_code'=>'member_service_visit_count','object_contracts'=>[]],
    ['metric_code'=>'sales_collected_amount','object_contracts'=>[]],
],$spokenVisitWithPrior);
csCheck(($compiledSpokenVisitWithPrior['context_delta']['business_filters']??null)==='replace'
    &&($compiledSpokenVisitWithPrior['context_delta']['aggregate_condition']??null)==='replace'
    &&($compiledSpokenVisitWithPrior['context_delta']['ranking_direction']??null)==='clear',
    'a complete member condition replaces an earlier topic through explicit context deltas');
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
$rangeSafe=$safe;
$rangeSafe['question']='最近30天销售业绩在5万元到6万元之间的员工有哪些？';
$rangeSafe['evidence_messages'][0]['text']=$rangeSafe['question'];
$rangeSemantic=$semantic;
$rangeSemantic['goal']='列出销售业绩在5万元到6万元之间的员工';
$rangeSemantic['requirements'][0]['evidence'][0]['quote']=$rangeSafe['question'];
$rangeSemantic['requirements'][0]['values']['metric_terms']=['销售业绩'];
$rangeSemantic['requirements'][0]['values']['aggregate_condition']['conditions']=[
    ['metric_term'=>'销售业绩','operator'=>'gte','quantity'=>'50000','unit'=>'yuan'],
    ['metric_term'=>'销售业绩','operator'=>'lte','quantity'=>'60000','unit'=>'yuan'],
];
$rangeSemantic=AiIntentUnderstandingContract::normalize($rangeSemantic,$rangeSafe);
$rangeCandidate=$binding;
$rangeCandidate['metric_codes']=['staff_sales_yeji'];
$rangeCandidate['aggregate_condition']=null;
$rangeCandidate['requirement_bindings'][0]['metric_codes']=['staff_sales_yeji'];
$rangeCandidate=AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
    $rangeCandidate,$rangeSemantic,$rangeSafe,['staff_sales_yeji']
);
$rangeBound=AiIntentResultContract::normalize($rangeCandidate,['staff_sales_yeji'],[],$rangeSafe,$rangeSemantic);
csCheck($rangeBound['metric_codes']===['staff_sales_yeji']
    &&array_column($rangeBound['aggregate_condition']['conditions'],'operator')===['gte','lte']
    &&array_column($rangeBound['aggregate_condition']['conditions'],'metric_code')===['staff_sales_yeji','staff_sales_yeji'],
    'exact registered metric keeps both bounds through semantic binding and unique catalogue selection');
$groupSafe=$safe;
$groupSafe['question']='最近30天销售业绩达到5万元且（劳动业绩达到300万元或销售业绩不超过6万元）的员工有哪些？';
$groupSafe['evidence_messages'][0]['text']=$groupSafe['question'];
$groupSemantic=$semantic;
$groupSemantic['requirements'][0]['evidence'][0]['quote']=$groupSafe['question'];
$groupSemantic['requirements'][0]['values']['aggregate_condition']['conditions']=[
    ['metric_term'=>'销售业绩','operator'=>'gte','quantity'=>'50000','unit'=>'yuan'],
    ['metric_term'=>'劳动业绩','operator'=>'gte','quantity'=>'3000000','unit'=>'yuan'],
    ['metric_term'=>'销售业绩','operator'=>'lte','quantity'=>'60000','unit'=>'yuan'],
];
$groupSemantic['requirements'][0]['values']['aggregate_condition']['expression']=[
    'relation'=>'all','children'=>[0,['relation'=>'any','children'=>[1,2]]],
];
$groupSemantic=AiIntentUnderstandingContract::normalize($groupSemantic,$groupSafe);
$groupCandidate=$binding;
$groupCandidate['aggregate_condition']=null;
$groupCandidate=AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
    $groupCandidate,$groupSemantic,$groupSafe,['staff_sales_yeji','staff_labor_yeji']
);
$groupBound=AiIntentResultContract::normalize(
    $groupCandidate,['staff_sales_yeji','staff_labor_yeji'],[],$groupSafe,$groupSemantic
);
csCheck($groupBound['metric_codes']===['staff_sales_yeji','staff_labor_yeji']
    &&$groupBound['aggregate_condition']['expression']===$groupSemantic['requirements'][0]['values']['aggregate_condition']['expression']
    &&array_column($groupBound['aggregate_condition']['conditions'],'metric_code')===
        ['staff_sales_yeji','staff_labor_yeji','staff_sales_yeji'],
    'mixed grouping and repeated metric remain identical across understood and bound contracts');
$datedSafe=$safe;
$datedSafe['question']='上月销售业绩达到5万元且本月服务次数达到24次的员工有哪些？';
$datedSafe['evidence_messages'][0]['text']=$datedSafe['question'];
$datedSemantic=$semantic;
$datedSemantic['goal']='列出分别满足上月销售业绩和本月服务次数条件的员工';
$datedSemantic['requirements'][0]['evidence'][0]['quote']=$datedSafe['question'];
$datedSemantic['requirements'][0]['fields']=array_values(array_diff(
    $datedSemantic['requirements'][0]['fields'],['periods']
));
unset($datedSemantic['requirements'][0]['values']['periods']);
$datedSemantic['requirements'][0]['values']['aggregate_condition']['conditions'][0]['period']=[
    'kind'=>'calendar_period','unit'=>'month','offset'=>-1,'count'=>1,'end_mode'=>'full',
];
$datedSemantic['requirements'][0]['values']['aggregate_condition']['conditions'][1]['period']=[
    'kind'=>'calendar_period','unit'=>'month','offset'=>0,'count'=>1,'end_mode'=>'to_reference',
];
$datedSemantic=AiIntentUnderstandingContract::normalize($datedSemantic,$datedSafe);
$datedBinding=$binding;
$datedBinding['periods']=[];
$datedBinding['aggregate_condition']['conditions'][0]['period']=
    $datedSemantic['requirements'][0]['values']['aggregate_condition']['conditions'][0]['period'];
$datedBinding['aggregate_condition']['conditions'][1]['period']=
    $datedSemantic['requirements'][0]['values']['aggregate_condition']['conditions'][1]['period'];
$datedBound=AiIntentResultContract::normalize(
    $datedBinding,['staff_sales_yeji','staff_service_num'],[],$datedSafe,$datedSemantic
);
csCheck($datedBound['periods']===[]
    &&$datedBound['aggregate_condition']['conditions'][0]['period']['offset']===-1
    &&$datedBound['aggregate_condition']['conditions'][1]['period']['offset']===0,
    'different dates stay attached to their own understood and bound predicates');
$bound=AiIntentResultContract::normalize($binding,['staff_sales_yeji','staff_service_num'],[],$safe,$semantic);
csCheck($bound['aggregate_condition']===$boundCondition&&$bound['metric_codes']===['staff_sales_yeji','staff_service_num'],
    'understood condition meanings bind to registered codes without changing thresholds, relation or order');
$currentConditionSafe=$safe;
$currentConditionSafe['prior_query']=IntentContextMerger::modelView($query);
$currentConditionBinding=$binding;
$currentConditionBinding['context_delta']=['metric_codes'=>'replace','object'=>'replace',
    'business_filters'=>'replace','store_scope'=>'inherit','periods'=>'replace','operation'=>'replace',
    'ranking_direction'=>'clear','ranking_limit'=>'clear','scope'=>'inherit','aggregate_condition'=>'inherit'];
$currentConditionBound=AiIntentResultContract::normalize($currentConditionBinding,
    ['staff_sales_yeji','staff_service_num'],[],$currentConditionSafe,$semantic);
csCheck($currentConditionBound['aggregate_condition']===$boundCondition
    &&$currentConditionBound['context_delta']['aggregate_condition']==='replace',
    'complete current condition replaces an inherited carrier without comparing metric terms to prior metric codes');
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
$reusedResultForm=AiIntentResultContract::inheritedConditionContinuationContextIntent(
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
$combinedResultForm=AiIntentResultContract::inheritedConditionContinuationContextIntent(
    $notResultForm,$priorQuery,['presentation_origin'=>'customer_or_verified_context']
);
csCheck(($combinedResultForm['operation']??null)==='condition_list'
    &&($combinedResultForm['periods']??null)===[['kind'=>'month_offset','offset_months'=>0]]
    &&($combinedResultForm['context_delta']['periods']??null)==='replace'
    &&($combinedResultForm['aggregate_condition']['conditions']??null)===$priorQuery['aggregate_condition']['conditions'],
    'one typed turn changes month and answer form while preserving every signed predicate');
$periodOnly=$followUnderstanding;
$periodOnly['requirements'][0]['fields']=['periods'];
$periodOnly['requirements'][0]['values']=['periods'=>[['kind'=>'month_offset','offset_months'=>0]]];
$periodOnly['requirements'][0]['evidence']=[['message_id'=>'current','quote'=>'只看本月，条件不变']];
$periodIntent=AiIntentResultContract::inheritedConditionContinuationContextIntent(
    $periodOnly,$priorQuery,['presentation_origin'=>'customer_or_verified_context']
);
csCheck(($periodIntent['operation']??null)===$priorQuery['query_shape']
    &&($periodIntent['periods']??null)===[['kind'=>'month_offset','offset_months'=>0]]
    &&($periodIntent['aggregate_condition']['conditions']??null)===$priorQuery['aggregate_condition']['conditions']
    &&($periodIntent['context_delta']['periods']??null)==='replace',
    'R55 a period-only continuation preserves the signed customer predicate and result form');
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
$memberUpdateIntent=AiIntentResultContract::inheritedConditionContinuationContextIntent(
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
$singleUpdateSource=$memberUpdateSource;
$singleUpdateSource['metric_codes']=['sales_collected_amount'];
$singleUpdateSource['aggregate_condition']['conditions']=[$memberUpdateSource['aggregate_condition']['conditions'][1]];
$closedQuestion='把条件改成大于2980元，仍看2026年，人数是多少';
$closedSafe=$memberUpdateSafe;
$closedSafe['question']=$closedQuestion;
$closedSafe['evidence_messages']=[['id'=>'current','text'=>$closedQuestion]];
$closedSafe['prior_query']=IntentContextMerger::modelView($singleUpdateSource);
$closedAdmission=(new ReflectionClass(\app\services\ai\AiGatewayServices::class))->getMethod('localVerifiedConditionDeltaUnderstanding');
$closedGateway=(new ReflectionClass(\app\services\ai\AiGatewayServices::class))->newInstanceWithoutConstructor();
$closedResult=$closedAdmission->invoke($closedGateway,$closedSafe,['local_conditions'=>[]],'2026-09-29');
csCheck(is_array($closedResult)
    &&($closedResult['requirements'][0]['values']['condition_update']['quantity']??null)==='2980'
    &&($closedResult['requirements'][1]['values']['periods'][0]['start']??null)==='2026-01-01',
    'complete numeric/date continuation reuses one signed condition without a model request');
$closedIntent=AiIntentResultContract::inheritedConditionContinuationContextIntent($closedResult,$singleUpdateSource);
$closedMerged=IntentContextMerger::merge($singleUpdateSource,$closedIntent)['intent'];
csCheck(($closedMerged['aggregate_condition']['conditions'][0]['operator']??null)==='gt'
    &&($closedMerged['aggregate_condition']['conditions'][0]['quantity']??null)==='2980'
    &&($closedMerged['metric_codes']??null)===$singleUpdateSource['metric_codes'],
    'closed numeric edit preserves the signed metric and changes only the requested predicate');
$sameConditionSafe=$closedSafe;
$sameConditionSafe['question']='只看本月，条件不变，有多少人';
$sameConditionSafe['evidence_messages']=[['id'=>'current','text'=>$sameConditionSafe['question']]];
$sameCondition=$closedAdmission->invoke($closedGateway,$sameConditionSafe,['local_conditions'=>[]],'2026-09-29');
$sameConditionIntent=is_array($sameCondition)
    ?AiIntentResultContract::inheritedConditionContinuationContextIntent($sameCondition,$singleUpdateSource):null;
$sameConditionMerged=is_array($sameConditionIntent)
    ?IntentContextMerger::merge($singleUpdateSource,$sameConditionIntent)['intent']:null;
csCheck(is_array($sameConditionMerged)
    &&($sameConditionMerged['periods'][0]['start']??null)==='2026-09-01'
    &&($sameConditionMerged['aggregate_condition']['conditions']??null)===$singleUpdateSource['aggregate_condition']['conditions']
    &&($sameConditionMerged['operation']??null)==='condition_count',
    'R55 month-only count follow-up keeps the exact signed greater-than condition');
$changedConditionSafe=$sameConditionSafe;
$changedConditionSafe['question']='只看本月，条件不变，再加上积分条件，有多少人';
csCheck($closedAdmission->invoke($closedGateway,$changedConditionSafe,['local_conditions'=>[]],'2026-09-29')===null,
    'R55 unknown extra condition cannot hide inside the same-condition shorthand');
$unknownQuestion='把条件改成大于2980元，再加上积分条件，仍看2026年，人数是多少';
$unknownSafe=$closedSafe;$unknownSafe['question']=$unknownQuestion;
csCheck($closedAdmission->invoke($closedGateway,$unknownSafe,['local_conditions'=>[]],'2026-09-29')===null,
    'an extra business condition cannot enter the closed numeric continuation path');
$ambiguousClosedSource=$singleUpdateSource;
$ambiguousClosedSource['aggregate_condition']['conditions'][]=$singleUpdateSource['aggregate_condition']['conditions'][0];
$ambiguousClosedSafe=$closedSafe;$ambiguousClosedSafe['prior_query']=IntentContextMerger::modelView($ambiguousClosedSource);
csCheck($closedAdmission->invoke($closedGateway,$ambiguousClosedSafe,['local_conditions'=>[]],'2026-09-29')===null,
    'two signed predicates require ordinary language understanding rather than a guessed target');
$combinedSafe=$memberUpdateSafe;
$combinedSafe['question']='把金额门槛改成980元，只看本月并列出客户';
$combinedSafe['evidence_messages']=[['id'=>'current','text'=>$combinedSafe['question']]];
$combinedUnderstanding=AiIntentUnderstandingContract::normalize([
    'goal'=>'修改条件、期间并查看名单','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'修改金额条件','fields'=>['condition_update'],
            'values'=>['condition_update'=>['target_term'=>'金额门槛','operator'=>'eq','quantity'=>'980','unit'=>'yuan']],
            'evidence'=>[['message_id'=>'current','quote'=>'金额门槛改成980元']]],
        ['id'=>'r2','meaning'=>'只看本月','fields'=>['periods'],
            'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],
            'evidence'=>[['message_id'=>'current','quote'=>'本月']]],
        ['id'=>'r3','meaning'=>'列出客户','fields'=>['operation'],
            'values'=>['operation'=>'condition_list'],
            'evidence'=>[['message_id'=>'current','quote'=>'列出客户']]],
    ],
],$combinedSafe);
$combinedIntent=AiIntentResultContract::inheritedConditionContinuationContextIntent($combinedUnderstanding,$memberUpdateSource);
$combinedMerged=IntentContextMerger::merge($memberUpdateSource,$combinedIntent)['intent'];
csCheck($combinedMerged['operation']==='condition_list'
    &&$combinedMerged['periods']===[['kind'=>'month_offset','offset_months'=>0]]
    &&$combinedMerged['aggregate_condition']['conditions'][0]===$memberUpdateSource['aggregate_condition']['conditions'][0]
    &&$combinedMerged['aggregate_condition']['conditions'][1]['operator']==='eq'
    &&$combinedMerged['aggregate_condition']['conditions'][1]['quantity']==='980'
    &&$combinedMerged['context_delta']['store_scope']==='inherit',
    'one current turn composes amount, operator, month and list form without losing the other predicate or store scope');
$detailSafe=$memberUpdateSafe;
$detailSafe['question']='客户详情';
$detailSafe['evidence_messages']=[['id'=>'current','text'=>'客户详情']];
$detailUnderstanding=AiIntentUnderstandingContract::normalize([
    'goal'=>'查看上一条件集合的客户','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>'查看客户详情','fields'=>['object_detail'],
        'values'=>['object_detail'=>['view'=>'summary','target'=>'set','ordinal'=>null]],
        'evidence'=>[['message_id'=>'current','quote'=>'客户详情']],
    ]],
],$detailSafe);
$detailIntent=AiIntentResultContract::inheritedConditionContinuationContextIntent($detailUnderstanding,$memberUpdateSource);
csCheck(($detailIntent['operation']??null)==='condition_list'
    &&($detailIntent['aggregate_condition']['conditions']??null)===$memberUpdateSource['aggregate_condition']['conditions'],
    'a count-backed detail request first reads the same bounded member list because count evidence has no identities');
$gatewayReflection=new ReflectionClass(app\services\ai\AiGatewayServices::class);
$gatewayWithoutDependencies=$gatewayReflection->newInstanceWithoutConstructor();
$displayRequest=$gatewayReflection->getMethod('inheritedCountDetailRequest')->invoke($gatewayWithoutDependencies,$detailUnderstanding);
$attached=$gatewayReflection->getMethod('attachPopulationDetail')->invoke($gatewayWithoutDependencies,
    ['kind'=>'plan','plan'=>['query'=>['query_shape'=>'condition_list']]],$displayRequest);
csCheck(($attached['_member_detail_request']??null)===['view'=>'summary','target'=>'set','ordinal'=>null],
    'count-to-list continuation retains the detail request instead of publishing only its filter list');
$explicitMemberDetail=$detailUnderstanding;
$explicitMemberDetail['requirements'][0]['fields']=['object_kind','object_relation','object_detail'];
$explicitMemberDetail['requirements'][0]['values']+=['object_kind'=>'member','object_relation'=>'analysis'];
csCheck((AiIntentResultContract::inheritedConditionContinuationContextIntent(
    $explicitMemberDetail,$memberUpdateSource
)['operation']??null)==='condition_list',
    'an explicitly restated member kind can still inherit the signed member population');
$detailWithListForm=$detailUnderstanding;
$detailWithListForm['requirements'][0]['fields'][]='operation';
$detailWithListForm['requirements'][0]['values']['operation']='condition_list';
csCheck((AiIntentResultContract::inheritedConditionContinuationContextIntent(
    $detailWithListForm,$memberUpdateSource
)['operation']??null)==='condition_list',
    'a model may state both inspect-the-set and list form without losing the verified count population');
$ordinalDetail=$detailUnderstanding;
$ordinalDetail['requirements'][0]['values']['object_detail']['ordinal']=2;
csCheck(AiIntentResultContract::inheritedConditionContinuationContextIntent($ordinalDetail,$memberUpdateSource)===null,
    'an ordinal cannot be resolved from a count without verified list order');
$otherSubjectDetail=$explicitMemberDetail;
$otherSubjectDetail['requirements'][0]['values']['object_kind']='person';
csCheck(AiIntentResultContract::inheritedConditionContinuationContextIntent($otherSubjectDetail,$memberUpdateSource)===null,
    'a changed analytical subject cannot silently inherit the member population');
$groupedDetail=$detailUnderstanding;
$groupedDetail['groups']=[['id'=>'q1','requirement_ids'=>['r1']],['id'=>'q2','requirement_ids'=>['r1']]];
csCheck(AiIntentResultContract::inheritedConditionContinuationContextIntent($groupedDetail,$memberUpdateSource)===null,
    'multi-result context cannot select one group as the implicit detail source');
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
$implicitUpdateIntent=AiIntentResultContract::inheritedConditionContinuationContextIntent(
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
csCheck(AiIntentResultContract::inheritedConditionContinuationContextIntent($memberUpdateUnderstanding,$ambiguousMoneySource)===null,
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
$personUpdateIntent=AiIntentResultContract::inheritedConditionContinuationContextIntent(
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
$splitPersonIntent=AiIntentResultContract::inheritedConditionContinuationContextIntent($splitPersonUpdate,$personUpdateSource);
csCheck(is_array($splitPersonIntent)
    &&($splitPersonIntent['metric_codes']??null)===$personUpdateSource['metric_codes']
    &&($splitPersonIntent['context_delta']['metric_codes']??null)==='inherit'
    &&($splitPersonIntent['aggregate_condition']['relation']??null)==='any'
    &&($splitPersonIntent['aggregate_condition']['conditions'][0]['quantity']??null)==='30000'
    &&($splitPersonIntent['aggregate_condition']['conditions'][1]??null)===$personUpdateSource['aggregate_condition']['conditions'][1],
    'a provider split between condition update and named metric updates one predicate without replacing the signed metric set');
$wrongMetricUpdate=$personUpdateUnderstanding;
$wrongMetricUpdate['requirements'][0]['values']['metric_terms']=['服务次数'];
csCheck(AiIntentResultContract::inheritedConditionContinuationContextIntent($wrongMetricUpdate,$personUpdateSource)===null,
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

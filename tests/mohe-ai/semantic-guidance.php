<?php
declare(strict_types=1);
// Synthetic, offline semantic/choice tests. No framework, database, model or account.
$root=dirname(__DIR__,2).'/后端代码/app/services/ai/';
foreach(['contract/AiContractException.php','model/AiModelInputProjector.php','execution/AiWorkflowPlanner.php'] as $file) require_once $root.$file;
$parser=new \app\services\ai\model\AiModelInputProjector();
$planner=new \app\services\ai\execution\AiWorkflowPlanner();
$checks=0;
function verify($condition,string $label):void { global $checks;$checks++;if(!$condition)throw new RuntimeException('FAIL '.$label); }
function rejected(callable $call,string $reason):void { try{$call();}catch(Throwable $e){verify($e->getMessage()===$reason,'expected '.$reason.' got '.$e->getMessage());return;}throw new RuntimeException('expected '.$reason); }
$cap=['metric_codes'=>['cash_performance','refund_performance','actual_performance','consume_amount','sales_amount','balance_deduction_amount','recharge_amount'],'query_shapes'=>['summary','trend','ranking','comparison'],'output_formats'=>['screen','screen_and_xlsx'],'current_store_bound'=>true,'definition_metric_codes'=>['cash_performance','refund_performance','actual_performance','consume_amount','sales_amount','balance_deduction_amount','recharge_amount']];
function build(string $question,array $capOverride=[]):array {
    global $parser,$planner,$cap;
    $p=$parser->project($question);$s=$p['signals'];
    $shape=in_array('definition',$s,true)?'definition':(in_array('ranking',$s,true)?'ranking':(in_array('comparison',$s,true)?'comparison':(in_array('trend',$s,true)?'trend':'summary')));
    $selection=['decision'=>in_array('ambiguous_metric',$s,true)?'clarify':'query','metric_codes'=>array_values(array_intersect($cap['metric_codes'],$s)),'query_shape'=>$shape,'date_code'=>'UNSPECIFIED'];
    return $planner->compile($p,$selection,array_merge($cap,$capOverride),'screen','2026-10-09');
}
foreach(['今天收了多少钱？','今天现金业绩多少？','今日收款金额是多少？','麻烦帮我看看今天现金业绩情况'] as $q) {
    $r=build($q);verify($r['kind']==='plan' && $r['plan']['query']['metric_codes']===['cash_performance'],'daily cash synonyms');
    verify($r['plan']['query']['start_date']==='2026-10-09','server date frozen');
}
foreach(['这个月','本月','这月','这一个月'] as $month) {
    $r=build($month.'收了多少钱');verify($r['kind']==='plan','month phrasing direct');
    verify([$r['plan']['query']['start_date'],$r['plan']['query']['end_date']]===['2026-10-01','2026-10-09'],'month complete intended range');
}
foreach(['上个月','上月'] as $month) {
    $r=build($month.'现金业绩');verify([$r['plan']['query']['start_date'],$r['plan']['query']['end_date']]===['2026-09-01','2026-09-30'],'previous full calendar month no clipping');
}
$r=build('今天店里收得怎么样？');verify($r['kind']==='plan','receipt scenario without report title');
rejected(static function(){build('今天店里收得怎么样？',['current_store_bound'=>false]);},'AI_UNSUPPORTED_CONDITION');
$r=build('本月每天现金与消耗');verify($r['plan']['query']['query_shape']==='trend' && $r['plan']['query']['metric_codes']===['cash_performance','consume_amount'],'joint daily goal retained');
foreach(['本月现金最高和最低的五家店','本月现金最高的前5家店和最低的后5家店'] as $q) {
    $r=build($q);verify($r['plan']['query']['ranking']===['direction'=>'top_and_bottom','limit'=>5],'both directions five');
}
foreach(['本月前十家店的现金业绩','给我全部前十名，不是前五名','本月现金前6家店'] as $q) rejected(static function()use($q){build($q);},'AI_RANK_LIMIT_NOT_READY');
$r=build('本月现金最好的门店');verify($r['kind']==='clarification' && $r['fields'][0]['key']==='rank_limit','best never invents five');
$r=$planner->choose($r,['rank_limit'=>'5']);verify($r['plan']['query']['ranking']['limit']===5,'explicit limit consent');
$r=build('今天业绩多少');verify(count($r['fields'])===1 && count($r['fields'][0]['options'])===count($cap['metric_codes']),'all executable metric options come from registry');
verify($r['confirmed_summary'][0]['label']==='查询期间','known period summarized');
$r=$planner->choose($r,['metric_code'=>'consume_amount']);verify($r['kind']==='plan','one-step resolved executes');
$r=build('今天服务业绩多少');verify($r['kind']==='clarification' && count($r['fields'][0]['options'])>=2,'service meaning ambiguity remains');
$serviceOptions=$r['fields'][0]['options'];$serviceLast=end($serviceOptions);
verify($serviceLast['action']==='stop','unknown meaning is not silently replaced');
rejected(static function()use($planner,$r){$planner->choose($r,['metric_code'=>'other_service_meaning']);},'AI_CAPABILITY_NOT_READY');
$r=build('今天现金和服务业绩多少');$r=$planner->choose($r,['metric_code'=>'consume_amount']);verify($r['plan']['query']['metric_codes']===['cash_performance','consume_amount'],'explicit joint metric not replaced by clarification');
$r=build('现金和消耗多少');verify($r['fields'][0]['key']==='start_date','joint goal asks only missing date');
$r=$planner->choose($r,['start_date'=>'2026-09-01','end_date'=>'2026-09-30']);verify(count($r['plan']['query']['metric_codes'])===2,'joint metrics survive date answer');
// Multi-step state is separate from root Run counters; no hidden new Run/model call.
$r=build('哪几家店需要关注');$steps=[];
foreach([['metric_code'=>'cash_performance'],['start_date'=>'2026-09-01','end_date'=>'2026-09-30'],['rank_direction'=>'bottom'],['rank_limit'=>'5']] as $choice) {
    verify($r['kind']==='clarification' && count($r['fields'])<=2,'one relevant semantic question');$steps[]=$r['guidance_step'];$r=$planner->choose($r,$choice);
}
verify($steps===['metric_code','start_date','rank_direction','rank_limit'],'no preset minimum step count');
verify($r['kind']==='plan' && $r['plan']['max_tool_calls']===1,'last necessary step completes bounded plan');
verify($r['plan']['query']['ranking']['direction']==='bottom','attention is not automatic diagnosis');
$initial=build('业绩多少');$cashDate=$planner->choose($initial,['metric_code'=>'cash_performance']);$consumeDate=$planner->choose($initial,['metric_code'=>'consume_amount']);
verify($cashDate['resolved_metrics']===['cash_performance'] && $consumeDate['resolved_metrics']===['consume_amount'],'replay frozen initial envelope supports correction without old candidate restriction');
rejected(static function()use($planner,$initial){$planner->choose($initial,['metric_code'=>'cash_performance','sql'=>'anything']);},'AI_CLARIFICATION_INVALID');
rejected(static function()use($planner,$cashDate){$planner->choose($cashDate,['start_date'=>'2026-02-30','end_date'=>'2026-03-01']);},'AI_DATE_INVALID');
foreach([
 '今天服务了几个人'=>'unparsed_business_condition','今天服务多少人、多少次'=>'unparsed_business_condition',
 '本月销售数量'=>'unparsed_business_condition','本月项目赚了多少钱'=>'category_filter',
 '本月生美现金，排除体验和离职员工'=>'category_filter','店长们的消耗是多少'=>'person_filter',
 '合作方分了多少钱'=>'source_filter','今年有多少会员'=>'history_point',
 '上个月末还有多少没消耗'=>'history_point','现在仓库还剩多少'=>'unparsed_business_condition',
 '按这个图选的条件查现金和消耗'=>'page_reference','本月现金，同时看现在库存'=>'unparsed_business_condition',
 '本月现金排除体验'=>'exclusion','今天不是现金是消耗'=>'exclusion',
] as $q=>$type) {
    $p=$parser->project($q);verify(in_array($type,array_column($p['semantic_intent']['constraints'],'type'),true),'all semantic constraints retained '.$type);
    rejected(static function()use($q,$type){build($q);},$type==='unparsed_business_condition'?'AI_INTENT_UNRESOLVED':'AI_CAPABILITY_NOT_READY');
}
rejected(static function(){build('今天张某某现金是多少');},'AI_INTENT_UNRESOLVED');
$r=build('今天实际业绩');verify($r['plan']['query']['metric_codes']===['actual_performance'],'actual performance is executable from registered reader');
$r=build('今天退款金额');verify($r['plan']['query']['metric_codes']===['refund_performance'],'refund performance is executable from registered reader');
$r=build('本月销售额');verify($r['plan']['query']['metric_codes']===['sales_amount'],'sales amount is executable from registered reader');
rejected(static function(){build('本月现金前五家10000');},'AI_INTENT_UNRESOLVED');
// Neither client answers nor history can silently bind executable slots.
$view=$parser->modelView($parser->validateConversation('那上个月呢',[['question'=>'本月现金是多少','answer'=>['amount'=>987654321,'scope'=>'ALL','name'=>'SECRET_PERSON']]]));
verify(!in_array('cash_performance',$view['current']['signals'],true),'follow-up not auto-filled from untrusted history');
verify($view['current']['semantic_intent']['followup']==='requested','follow-up intent explicit');
verify(strpos(json_encode($view),'987654321')===false && strpos(json_encode($view),'SECRET_PERSON')===false,'history answer data not externalized');
$sameDate=$parser->project('相同日期的现金业绩，生成Excel');
verify($sameDate['semantic_intent']['followup']==='requested' && $sameDate['blocking_reason']===null
    && in_array('cash_performance',$sameDate['signals'],true) && in_array('xlsx',$sameDate['signals'],true), 'same-date export is a legal signed-context follow-up');
$r=build('那上个月呢');verify($r['fields'][0]['key']==='metric_code' && $r['resolved_range']===['start'=>'2026-09-01','end'=>'2026-09-30'],'follow-up keeps new date and asks only missing metric');
$r=build('换成消耗呢');verify($r['fields'][0]['key']==='start_date' && $r['resolved_metrics']===['consume_amount'],'metric follow-up never inherits untrusted dates');
$r=build('昨天和今天消耗业绩对比');verify($r['plan']['query']['start_date']==='2026-10-08' && $r['plan']['query']['compare_range']['start']==='2026-10-09','comparison order preserved');
$r=build('本月现金和上月相比');verify($r['plan']['query']['compare_range']===['start'=>'2026-09-01','end'=>'2026-09-30'],'two calendar periods preserved');
$r=build('2026-09-01到2026-09-03和2026-08-01至2026-08-03消耗对比');verify($r['plan']['query']['end_date']==='2026-09-03' && $r['plan']['query']['compare_range']['end']==='2026-08-03','full explicit comparison ranges');
$r=build('2026-09-01和2026-09-03现金');verify($r['kind']==='clarification','two disconnected dates are not silently merged to interval');
foreach(['现金业绩指什么？','解释消耗业绩口径说明'] as $q) {
    $r=build($q);verify($r['kind']==='plan' && $r['plan']['query_shape']==='definition','registered definition path');
    verify(!isset($r['plan']['query']) && !isset($r['plan']['start_date']),'definition no business snapshot/date');
}
rejected(static function(){build('现金业绩指什么',['definition_metric_codes'=>[]]);},'AI_METRIC_NOT_READY');
$r=build('业绩指什么');verify($r['fields'][0]['key']==='metric_code' && $r['pending_fields']===[],'definition only asks concept not date');
$r=$planner->choose($r,['metric_code'=>'cash_performance']);verify($r['plan']['query_shape']==='definition','definition clarification finishes metadata plan');
verify(strpos(json_encode($parser->project('张老师手机号13800000000现金前五家')),'13800000000')===false,'unparsed names/digits never retained');
echo 'Semantic guidance offline: '.$checks." checks PASS\n";

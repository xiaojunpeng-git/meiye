<?php
// Gateway regressions for model context deltas. Synthetic facts/model only.
require __DIR__.'/r6-gateway-harness.php';

$checks=0;
function cdgCheck($ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('context delta gateway: '.$label);$checks++;}
function cdgDelta(): array {return array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');}

$h=null;
try {
    $h=new R6GatewayHarness(3,[1,2],'platform');
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],'scope'=>'authorized','unresolved_fragments'=>[]];
    $source=$h->start('9月1日到9月8日现金业绩多少？');
    cdgCheck($source['status']==='COMPLETED'&&isset($source['answer']['context_ref']),'verified source query is available');

    // A model-declared missing metric may not execute an empty query.  It
    // first asks whether the verified previous metric should be retained.
    $pendingDelta=cdgDelta();$pendingDelta['metric_codes']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$pendingDelta,'unresolved_fragments'=>[]];
    $pending=$h->start('那这一段呢？',$source['answer']['context_ref']);
    cdgCheck($pending['status']==='WAITING_CLARIFICATION'&&$pending['clarification']['fields'][0]['key']==='pending_metric_codes','pending metric is held for an explicit context decision instead of executing or failing');
    $retained=$h->choose($pending,['pending_metric_codes'=>'retain']);
    cdgCheck($retained['status']==='COMPLETED','only an explicit retain decision may execute the prior verified metric');

    // Replacing the scope uses the verbatim current store name only to bind
    // an already-authorized catalog entry; it must not fall back to a choice.
    $storeDelta=cdgDelta();$storeDelta['store_scope']='replace';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'二号门店','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$storeDelta,'unresolved_fragments'=>[]];
    $switched=$h->start('改查二号门店，其他条件不变',$source['answer']['context_ref']);
    cdgCheck($switched['status']==='COMPLETED','a verbatim authorized replacement store binds without redundant store selection');
    $evidence=$h->private->read($h->row($switched)['evidence_ref']);
    cdgCheck(($evidence['query']['store_ids']??null)===[2],'replacement store plan contains only the authorized chosen store');

    // A missing store scope must retain the signed store restriction until the
    // customer chooses otherwise; it may never be compiled as all stores.
    $scopePending=cdgDelta();$scopePending['store_scope']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$scopePending,'unresolved_fragments'=>[]];
    $heldScope=$h->start('那这个门店范围呢？',$switched['answer']['context_ref']);
    cdgCheck($heldScope['status']==='WAITING_CLARIFICATION'&&$heldScope['clarification']['fields'][0]['key']==='pending_store_scope','pending store scope cannot execute with an empty store list');
    $retainedScope=$h->choose($heldScope,['pending_store_scope'=>'retain']);
    $scopeEvidence=$h->private->read($h->row($retainedScope)['evidence_ref']);
    cdgCheck($retainedScope['status']==='COMPLETED'&&($scopeEvidence['query']['store_ids']??null)===[2],'retained store scope preserves the signed narrowed range');
    $heldClear=$h->start('改为全部范围',$switched['answer']['context_ref']);
    cdgCheck($heldClear['status']==='WAITING_CLARIFICATION','a second pending scope awaits a direct clear decision');
    $clearedScope=$h->choose($heldClear,['pending_store_scope'=>'clear_store_scope']);
    $clearEvidence=$h->private->read($h->row($clearedScope)['evidence_ref']);
    cdgCheck($clearedScope['status']==='COMPLETED'&&($clearEvidence['query']['store_ids']??null)===[],'explicitly clearing scope removes the previous store restriction from the executed query');

    // A missing response form is equally a clarification, rather than an
    // AI_INTENT_UNRESOLVED failure or a guessed summary.
    $operationPending=cdgDelta();$operationPending['operation']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$operationPending,'unresolved_fragments'=>[]];
    $heldOperation=$h->start('展示方式还没确定',$switched['answer']['context_ref']);
    cdgCheck($heldOperation['status']==='WAITING_CLARIFICATION'&&$heldOperation['clarification']['fields'][0]['key']==='pending_operation','pending operation is a controlled decision, not a failed Run');
    cdgCheck($h->choose($heldOperation,['pending_operation'=>'retain'])['status']==='COMPLETED','retained response form completes only after confirmation');

    // Pending fields form one serial decision chain. Choosing the metric must
    // not release an unresolved period or execute the first preview plan.
    $multiDelta=cdgDelta();$multiDelta['metric_codes']='pending';$multiDelta['periods']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$multiDelta,'unresolved_fragments'=>[]];
    $multi=$h->start('换一个指标和时间',$source['answer']['context_ref']);
    cdgCheck($multi['status']==='WAITING_CLARIFICATION'&&$multi['clarification']['fields'][0]['key']==='pending_metric_codes','first unresolved context field is presented');
    $multi=$h->choose($multi,['pending_metric_codes'=>'metric:consume_amount']);
    cdgCheck($multi['status']==='WAITING_CLARIFICATION'&&$multi['clarification']['fields'][0]['key']==='start_date','second unresolved context field remains required after metric selection');
    $multi=$h->choose($multi,['start_date'=>'2026-09-03','end_date'=>'2026-09-04']);
    $multiEvidence=$h->private->read($h->row($multi)['evidence_ref']);
    cdgCheck($multi['status']==='COMPLETED'&&($multiEvidence['query']['metric_codes']??null)===['consume_amount']&&($multiEvidence['query']['start_date']??null)==='2026-09-03','only the fully confirmed context reaches execution');

    // Removing a restriction from a member ranking must keep its member
    // dimension. It may never silently change the answer into a store ranking.
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>5],'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],'scope'=>'authorized','unresolved_fragments'=>[]];
    $memberSource=$h->start('会员现金业绩排行');
    cdgCheck($memberSource['status']==='COMPLETED','member source ranking is available');

    // Operation choices come from the registered member/metric contract. A
    // member cash ranking must not offer a summary that will fail downstream.
    $memberOperationPending=cdgDelta();$memberOperationPending['operation']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$memberOperationPending,'unresolved_fragments'=>[]];
    $memberOperation=$h->start('展示方式未确定',$memberSource['answer']['context_ref']);
    cdgCheck(array_column($memberOperation['clarification']['fields'][0]['options']??[],'value')===['retain','operation:ranking'],'operation guidance lists only shapes registered for the selected member metric');
    $forgedOperation=$h->choose($memberOperation,['pending_operation'=>'operation:summary']);
    cdgCheck($forgedOperation['status']==='WAITING_CLARIFICATION'&&($forgedOperation['clarification']['fields'][0]['key']??null)==='pending_operation','a forged unavailable operation is rejected at the clarification boundary');
    cdgCheck($h->choose($memberOperation,['pending_operation'=>'retain'])['status']==='COMPLETED','the supported prior member ranking remains executable after confirmation');

    // Metric choices are projected from the same object/shape contract as the
    // compiler. The UI must never offer a metric that fails immediately after
    // the customer selects it.
    $memberMetricPending=cdgDelta();$memberMetricPending['metric_codes']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$memberMetricPending,'unresolved_fragments'=>[]];
    $memberMetric=$h->start('换一个评价指标',$memberSource['answer']['context_ref']);
    $memberMetricValues=array_column($memberMetric['clarification']['fields'][0]['options']??[],'value');
    cdgCheck($memberMetricValues===['retain','metric:cash_performance'],'member ranking metric choices contain only registered executable contracts');
    cdgCheck($h->choose($memberMetric,['pending_metric_codes'=>'metric:sales_amount'])['status']==='WAITING_CLARIFICATION','a forged incompatible metric cannot pass the clarification boundary');
    cdgCheck($h->choose($memberMetric,['pending_metric_codes'=>'retain'])['status']==='COMPLETED','every displayed member metric choice remains executable');

    $filterPending=cdgDelta();$filterPending['business_filters']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$filterPending,'unresolved_fragments'=>[]];
    $memberFollow=$h->start('不沿用刚才的对象筛选',$memberSource['answer']['context_ref']);
    cdgCheck(in_array('clear_business_filter',array_column($memberFollow['clarification']['fields'][0]['options']??[],'value'),true),'only an option actually rendered to the customer may clear a member restriction');
    $memberFollow=$h->choose($memberFollow,['pending_business_filters'=>'clear_business_filter']);
    $memberEvidence=$h->private->read($h->row($memberFollow)['evidence_ref']);
    cdgCheck($memberFollow['status']==='COMPLETED'&&($memberEvidence['query']['business_filters']??null)===['object_kind'=>'member'],'clearing a member restriction retains the member query object');

    // Choosing comparison is incomplete until a second time range is supplied.
    $comparePending=cdgDelta();$comparePending['operation']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$comparePending,'unresolved_fragments'=>[]];
    $compare=$h->start('改为对比',$source['answer']['context_ref']);
    $compare=$h->choose($compare,['pending_operation'=>'operation:comparison']);
    cdgCheck(($compare['clarification']['fields'][0]['key']??null)==='compare_start_date','comparison asks for the comparison period instead of executing an incomplete plan');
    $compare=$h->choose($compare,['compare_start_date'=>'2026-08-20','compare_end_date'=>'2026-08-21']);
    $compareEvidence=$h->private->read($h->row($compare)['evidence_ref']);
    cdgCheck($compare['status']==='COMPLETED'&&($compareEvidence['query']['compare_range']??null)===['start'=>'2026-08-20','end'=>'2026-08-21'],'comparison executes only after its second period is confirmed');

    // A new dimension plus an unfinished response form is a three-step
    // conversation, not an early "combination unavailable" failure.
    $replacePending=cdgDelta();$replacePending['object']='replace';$replacePending['operation']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$replacePending,'unresolved_fragments'=>[]];
    $replacement=$h->start('改查看会员，但展示方式未确定',$source['answer']['context_ref']);
    cdgCheck($replacement['status']==='WAITING_CLARIFICATION'&&($replacement['clarification']['fields'][0]['key']??null)==='replace_previous_object_filter','object replacement starts with confirmation rather than an unavailable-combination failure');
    $replacement=$h->choose($replacement,['replace_previous_object_filter'=>'replace']);
    cdgCheck(array_column($replacement['clarification']['fields'][0]['options']??[],'value')===['operation:ranking'],'object replacement regenerates response choices from the new member contract and cannot retain an unsupported store summary');
    $replacement=$h->choose($replacement,['pending_operation'=>'operation:ranking']);
    $replacement=$h->choose($replacement,['pending_ranking_direction'=>'top','pending_ranking_limit'=>'5']);
    $replacementEvidence=$h->private->read($h->row($replacement)['evidence_ref']);
    cdgCheck($replacement['status']==='COMPLETED'&&($replacementEvidence['query']['business_filters']??null)===['object_kind'=>'member'],'replacement plus pending form recompiles a member ranking after all confirmations');

    // Moving from a member dimension to stores must actually remove the
    // member dimension from the final query, not merely acknowledge it.
    $toStore=cdgDelta();$toStore['object']='replace';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>5],'periods'=>[],'scope'=>'unspecified','context_delta'=>$toStore,'unresolved_fragments'=>[]];
    $storeReplacement=$h->start('改查看门店排行',$memberSource['answer']['context_ref']);
    $storeReplacement=$h->choose($storeReplacement,['replace_previous_object_filter'=>'replace']);
    $storeReplacementEvidence=$h->private->read($h->row($storeReplacement)['evidence_ref']);
    cdgCheck($storeReplacement['status']==='COMPLETED'&&($storeReplacementEvidence['query']['business_filters']??null)===[],'confirmed replacement from member to store executes a store query');

    // A single pending ranking field replaces just that field. The unchanged
    // direction/quantity remain from the signed query.
    $limitPending=cdgDelta();$limitPending['ranking_limit']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$limitPending,'unresolved_fragments'=>[]];
    $newLimit=$h->start('改成前五个',$memberSource['answer']['context_ref']);
    $newLimit=$h->choose($newLimit,['pending_ranking_limit'=>'ranking_limit:5']);
    $newLimitEvidence=$h->private->read($h->row($newLimit)['evidence_ref']);
    cdgCheck($newLimit['status']==='COMPLETED'&&($newLimitEvidence['query']['ranking']??null)===['direction'=>'top','limit'=>5],'confirmed ranking quantity is not overwritten by an empty model delta');

    $directionPending=cdgDelta();$directionPending['ranking_direction']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$directionPending,'unresolved_fragments'=>[]];
    $newDirection=$h->start('改为从低到高',$memberSource['answer']['context_ref']);
    $newDirection=$h->choose($newDirection,['pending_ranking_direction'=>'ranking_direction:bottom']);
    $newDirectionEvidence=$h->private->read($h->row($newDirection)['evidence_ref']);
    cdgCheck($newDirection['status']==='COMPLETED'&&($newDirectionEvidence['query']['ranking']??null)===['direction'=>'bottom','limit'=>5],'confirmed ranking direction is not overwritten by an empty model delta');

    // A direct, confirmed dimension replacement reaches the registered target
    // dimension after confirmation; it does not retain the prior store plan.
    $memberReplace=cdgDelta();$memberReplace['object']='replace';$memberReplace['operation']='replace';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>5],'periods'=>[],'scope'=>'unspecified','context_delta'=>$memberReplace,'unresolved_fragments'=>[]];
    $memberReplacement=$h->start('改查看会员排行',$source['answer']['context_ref']);
    $memberReplacement=$h->choose($memberReplacement,['replace_previous_object_filter'=>'replace']);
    cdgCheck(($memberReplacement['clarification']['fields'][0]['key']??null)==='dimension_direction','a replacement query asks only for the target ranking details that the user did not state');
    $memberReplacement=$h->choose($memberReplacement,['dimension_direction'=>'top']);
    cdgCheck(($memberReplacement['clarification']['fields'][0]['key']??null)==='dimension_limit','target ranking quantity remains an explicit choice when it was not stated');
    $memberReplacement=$h->choose($memberReplacement,['dimension_limit'=>'5']);
    $memberReplacementEvidence=$h->private->read($h->row($memberReplacement)['evidence_ref']);
    cdgCheck($memberReplacement['status']==='COMPLETED'&&($memberReplacementEvidence['query']['business_filters']??null)===['object_kind'=>'member'],'confirmed store-to-member replacement executes a member query');

    // Changing a comparison's main period keeps its independently confirmed
    // comparison period. Changing away from comparison clears it.
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'comparison','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08'],['kind'=>'date_range','start'=>'2026-08-20','end'=>'2026-08-27']],'scope'=>'authorized','unresolved_fragments'=>[]];
    $comparisonSource=$h->start('现金业绩对比');
    cdgCheck($comparisonSource['status']==='COMPLETED','comparison source query is available');
    $comparisonPeriod=cdgDelta();$comparisonPeriod['periods']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'comparison','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$comparisonPeriod,'unresolved_fragments'=>[]];
    $changedPeriod=$h->start('换统计时间',$comparisonSource['answer']['context_ref']);
    $changedPeriod=$h->choose($changedPeriod,['start_date'=>'2026-09-03','end_date'=>'2026-09-05']);
    $changedPeriodEvidence=$h->private->read($h->row($changedPeriod)['evidence_ref']);
    cdgCheck($changedPeriod['status']==='COMPLETED'&&($changedPeriodEvidence['query']['compare_range']??null)===['start'=>'2026-08-20','end'=>'2026-08-27'],'changing a comparison period retains its confirmed comparison range');

    $comparisonOperation=cdgDelta();$comparisonOperation['operation']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$comparisonOperation,'unresolved_fragments'=>[]];
    $changedOperation=$h->start('改为汇总',$comparisonSource['answer']['context_ref']);
    $changedOperation=$h->choose($changedOperation,['pending_operation'=>'operation:summary']);
    $changedOperationEvidence=$h->private->read($h->row($changedOperation)['evidence_ref']);
    cdgCheck($changedOperation['status']==='COMPLETED'&&array_key_exists('compare_range',$changedOperationEvidence['query']??[])&&$changedOperationEvidence['query']['compare_range']===null,'changing from comparison clears the obsolete comparison range');
    echo "PASS context delta gateway: $checks checks (SQLite, synthetic model/facts only)\n";
} finally {if($h)$h->close();}

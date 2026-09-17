<?php
// Isolated fixture transport: no real HTTP request can leave this test process.
namespace app\services\ai\model {
    function curl_init($endpoint) { $GLOBALS['sfEndpoint'] = $endpoint; return new \stdClass(); }
    function curl_setopt_array($handle, $options) { $GLOBALS['sfOptions'] = $options; return true; }
    function curl_exec($handle) {
        $options = $GLOBALS['sfOptions'];
        $progress=defined('CURLOPT_XFERINFOFUNCTION')?constant('CURLOPT_XFERINFOFUNCTION'):CURLOPT_PROGRESSFUNCTION;
        if ($options[$progress]() !== 0) return false;
        if (!empty($GLOBALS['sfTransportFailure'])) return false;
        $body = $GLOBALS['sfResponse'];
        return $options[CURLOPT_WRITEFUNCTION]($handle, $body) === strlen($body);
    }
    function curl_getinfo($handle, $option) { return $GLOBALS['sfStatus']; }
    function curl_errno($handle) { return (int)($GLOBALS['sfErrno'] ?? 0); }
    function curl_close($handle) {}
}
namespace {
    $root = dirname(__DIR__, 2) . '/后端代码/app/services/ai/';
    require_once __DIR__ . '/fixture-autoload.php';
    foreach (['contract/AiContractException.php','contract/AiStrictJson.php','model/AiModelInputProjector.php','model/SiliconFlowClient.php','execution/AiWorkflowPlanner.php','config/AiPrivateStorage.php','config/AiConfigStore.php'] as $file) require_once $root . $file;
    use app\services\ai\model\AiModelInputProjector;
    use app\services\ai\model\SiliconFlowClient;
    use app\services\ai\execution\AiWorkflowPlanner;
    use app\services\ai\config\AiPrivateStorage;
    use app\services\ai\config\AiConfigStore;
    $checks = 0;
    function check($condition, $label) { global $checks; if (!$condition) throw new \RuntimeException('FAIL: ' . $label); $checks++; }
    function rejects(callable $fn, $code) { try { $fn(); } catch (\Throwable $e) { check($e->getMessage() === $code, 'expected ' . $code . ', got ' . get_class($e) . ':' . $e->getMessage()); return; } throw new \RuntimeException('FAIL: expected ' . $code); }
    $projector = new AiModelInputProjector();
    $conversation = $projector->validateConversation('今天现金业绩多少？', [['question'=>'昨天现金业绩多少？','answer'=>'秘密客户张三 123456 元']]);
    $view = $projector->modelView($conversation);
    check(strpos(json_encode($view, JSON_UNESCAPED_UNICODE),'秘密客户') === false, 'answers never sent');
    check(strpos(json_encode($view),'123456') === false, 'answer money never sent');
    check($view['current']['signals'] === ['cash_performance','TODAY'], 'Q002 vocabulary');
    check($projector->project('今天收了多少钱？')['signals'] === ['cash_performance','TODAY'], 'Q001 vocabulary');
    check($projector->project('今天消耗业绩多少？')['signals'] === ['consume_amount','TODAY'], 'Q003 vocabulary');
    foreach (['本月现金业绩最高的前五家店','本月现金业绩最高的前5家店'] as $q) {
        $p=$projector->project($q); check(!$p['unresolved_condition'] && in_array('top_5',$p['signals'],true),'explicit top five phrasing');
    }
    check($projector->project('本月现金业绩最高的前五家店排除张三')['unresolved_condition'],'ranking alias retains unknown filter');
    check($projector->project('本月现金业绩最高的前十家店')['blocking_reason']==='AI_RANK_LIMIT_NOT_READY','ranking alias does not silently change requested limit');
    $history = array_fill(0,20,['question'=>'今天现金业绩','answer'=>['summary'=>'测试']]);
    check(count($projector->validateConversation('今天业绩',$history)['history']) === 20,'20 complete rounds');
    rejects(function()use($projector,$history){$history[]=$history[0];$projector->validateConversation('问',$history);},'AI_CONVERSATION_INVALID');
    foreach ([[['question'=>'问']], [['question'=>'问','answer'=>'答','system'=>'inject']], ['sparse'=>['question'=>'问','answer'=>'答']]] as $invalid) rejects(function()use($projector,$invalid){$projector->validateConversation('问',$invalid);},'AI_CONVERSATION_INVALID');
    rejects(function()use($projector){$projector->validateConversation("\xff",[]);},'AI_CONVERSATION_INVALID');
    $cap = ['metric_codes'=>['cash_performance','refund_performance','actual_performance','consume_amount'],'query_shapes'=>['summary']];
    $selection = ['decision'=>'query','query_shape'=>'summary','metric_codes'=>['cash_performance'],'date_code'=>'TODAY'];
    $planner = new AiWorkflowPlanner();
    $compiled = $planner->compile($projector->project('今天现金业绩多少？'),$selection,$cap,'screen','2026-09-08');
    check($compiled['kind']==='plan' && $compiled['plan']['query']['start_date']==='2026-09-08','today exact');
    check($compiled['plan']['query']['metric_codes']===['cash_performance'],'exact metric');
    check($compiled['plan']['workflow_code']==='wf_performance_summary' && !isset($compiled['plan']['nodes']),'raw plan names the registered workflow without editable nodes');
    foreach (['今天张三现金业绩多少？'=>'AI_INTENT_UNRESOLVED','今天店长现金业绩多少？'=>'AI_CAPABILITY_NOT_READY','今天生美现金业绩多少？'=>'AI_CAPABILITY_NOT_READY','今天现金业绩排除张三'=>'AI_CAPABILITY_NOT_READY','今天现金业绩和销售数量'=>'AI_METRIC_NOT_READY'] as $unknown=>$expectedError) rejects(function()use($planner,$projector,$selection,$cap,$unknown){$planner->compile($projector->project($unknown),$selection,$cap,'screen','2026-09-08');},$expectedError);
    $envelope = $planner->compile($projector->project('业绩多少？'),$selection,$cap,'screen','2026-09-08');
    check($envelope['kind']==='clarification' && count($envelope['fields'])===1,'only current semantic question displayed');
    $dateStep=$planner->choose($envelope,['metric_code'=>'consume_amount']);
    $chosen = $planner->choose($dateStep,['start_date'=>'2026-09-01','end_date'=>'2026-09-08']);
    check($chosen['kind']==='plan','needed date step completes plan without model call');
    $actualDateStep=$planner->choose($envelope,['metric_code'=>'actual_performance']);
    check($actualDateStep['kind']==='clarification','registered actual metric remains available during clarification');
    rejects(function()use($planner,$dateStep){$planner->choose($dateStep,['start_date'=>'2026-02-30','end_date'=>'2026-09-08']);},'AI_DATE_INVALID');
    rejects(function()use($planner,$envelope){$planner->choose($envelope,['metric_code'=>'cash_performance','start_date'=>'2026-09-01']);},'AI_CLARIFICATION_INVALID');
    $actualPlan=$planner->compile($projector->project('今天实际业绩'),array_merge($selection,['metric_codes'=>['actual_performance']]),$cap,'screen','2026-09-08');
    check($actualPlan['kind']==='plan' && $actualPlan['plan']['query']['metric_codes']===['actual_performance'],'registered actual metric compiles directly');
    // Source compiler intentionally refuses export until shared export readiness.
    rejects(function()use($planner,$projector,$selection,$cap){$planner->compile($projector->project('今天现金业绩'),$selection,$cap,'screen_and_xlsx','2026-09-08');},'AI_EXPORT_NOT_READY');
    $compareCap=['metric_codes'=>['cash_performance','consume_amount'],'query_shapes'=>['comparison']];
    $compareSelection=['decision'=>'query','query_shape'=>'comparison','metric_codes'=>['consume_amount'],'date_code'=>'TODAY'];
    foreach ([
        ['今天和昨天消耗业绩对比','2026-09-08','2026-09-08','2026-09-07','2026-09-07'],
        ['昨天与今天消耗业绩对比','2026-09-07','2026-09-07','2026-09-08','2026-09-08'],
        ['上月和本月消耗业绩对比','2026-08-01','2026-08-31','2026-09-01','2026-09-08'],
        ['本月和上月消耗业绩对比','2026-09-01','2026-09-08','2026-08-01','2026-08-31'],
        ['2026-09-07与2026-09-08消耗业绩对比','2026-09-07','2026-09-07','2026-09-08','2026-09-08'],
        ['2026-09-01到2026-09-03和2026-08-01至2026-08-03消耗业绩对比','2026-09-01','2026-09-03','2026-08-01','2026-08-03'],
        ['昨天和2026-09-01到2026-09-03消耗业绩对比','2026-09-07','2026-09-07','2026-09-01','2026-09-03'],
    ] as [$q,$start,$end,$compareStart,$compareEnd]) {
        $result=$planner->compile($projector->project($q),$compareSelection,$compareCap,'screen','2026-09-08');
        check($result['kind']==='plan','two explicit periods need no redundant clarification');
        $query=$result['plan']['query'];
        check([$query['start_date'],$query['end_date'],$query['compare_range']['start'],$query['compare_range']['end']]===[$start,$end,$compareStart,$compareEnd],'comparison preserves textual period order and both full ranges');
        check($result['plan']['workflow_code']==='wf_performance_comparison' && !isset($result['plan']['nodes']),'comparison selects a registered workflow without an editable budget');
    }
    $ambiguous=$planner->compile($projector->project('今天和昨天业绩对比'),array_merge($compareSelection,['metric_codes'=>[],'decision'=>'clarify']),$compareCap,'screen','2026-09-08');
    check(array_column($ambiguous['fields'],'key')===['metric_code'],'resolved comparison dates survive metric-only clarification');
    $chosen=$planner->choose($ambiguous,['metric_code'=>'consume_amount']);
    check($chosen['plan']['query']['compare_range']===['start'=>'2026-09-07','end'=>'2026-09-07'],'metric clarification keeps comparison range');
    $missing=$planner->compile($projector->project('今天消耗业绩对比'),$compareSelection,$compareCap,'screen','2026-09-08');
    check(array_column($missing['fields'],'key')===['compare_start','compare_end'],'only missing comparison period requested');
    $multiple=$planner->compile($projector->project('2026-09-01和2026-09-02和2026-09-03消耗业绩对比'),$compareSelection,$compareCap,'screen','2026-09-08');
    check(array_column($multiple['fields'],'key')===['start_date','end_date'] && array_column($multiple['pending_fields'],'key')===['compare_start','compare_end'],'ambiguous multiple dates preserve pending comparison');
    $secondPeriod=$planner->choose($multiple,['start_date'=>'2026-09-01','end_date'=>'2026-09-02']);
    $chosen=$planner->choose($secondPeriod,['compare_start'=>'2026-09-03','compare_end'=>'2026-09-03']);
    check($chosen['kind']==='plan','two needed period steps complete without losing either period');
    $grouping=$planner->compile($projector->project('昨天到今天消耗业绩对比'),$compareSelection,$compareCap,'screen','2026-09-08');
    check(array_column($grouping['fields'],'key')===['start_date','end_date'] && count($grouping['pending_fields'])===2,'range connector cannot be silently reinterpreted as two comparison periods');
    $ordered=$projector->project('昨天和今天张三消耗业绩对比');
    check(array_column($ordered['date_terms'],'code')===['YESTERDAY','TODAY'],'date order is independent of lexicon signal order');
    check(strpos(json_encode($ordered,JSON_UNESCAPED_UNICODE),'张三')===false,'date projection never forwards raw user text');
    rejects(function()use($planner,$ordered,$compareSelection,$compareCap){$planner->compile($ordered,$compareSelection,$compareCap,'screen','2026-09-08');},'AI_INTENT_UNRESOLVED');
    if (!function_exists('curl_init')) throw new \RuntimeException('curl extension required for offline option constants');
    $client = new SiliconFlowClient();
    $GLOBALS['sfStatus']=200;
    $response = function($content,$finish='stop') { return json_encode(['choices'=>[['finish_reason'=>$finish,'message'=>['content'=>$content]]],'usage'=>['prompt_tokens'=>8,'completion_tokens'=>4]]); };
    $GLOBALS['sfResponse']=$response('{"ok":true}');
    $skills=(new \app\services\ai\registry\AiBusinessRegistry())->modelSkills('store_operations');
    $result=$client->probe('fixture/model','fixture-key',1000,function(){});
    check($result['usage']['input_tokens']===8,'model configuration probe records bounded usage');
    check($GLOBALS['sfEndpoint']===SiliconFlowClient::ENDPOINT,'fixed endpoint');
    check(SiliconFlowClient::MAX_REQUEST_TIMEOUT_MS===45000,'intent understanding can use the bounded 45-second provider window');
    check($GLOBALS['sfOptions'][CURLOPT_FOLLOWLOCATION]===false,'redirect prohibited');
    check(json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true)['temperature']===0,'configuration probe uses deterministic transport settings');
    $GLOBALS['sfResponse']=$response('{"ok":false}');
    rejects(function()use($client){$client->probe('fixture/model','fixture-key',1000,function(){});},'AI_MODEL_RESPONSE_INVALID');
    $GLOBALS['sfResponse']=$response('{"ok":true}','length');
    try { $client->probe('fixture/model','fixture-key',1000,function(){}); throw new RuntimeException('missing truncated response'); }
    catch (\app\services\ai\contract\AiContractException $error) { check($error->getMessage()==='AI_MODEL_RESPONSE_TRUNCATED' && ($error->diagnostic()['stage']??'')==='response_envelope' && ($error->diagnostic()['finish_reason']??'')==='length','truncated response has bounded diagnostic'); }
    $GLOBALS['sfTransportFailure']=true; $GLOBALS['sfErrno']=28;
    try { $client->probe('fixture/model','fixture-key',1000,function(){}); throw new RuntimeException('missing unknown transport result'); }
    catch (\app\services\ai\contract\AiContractException $error) { $d=$error->diagnostic(); check($error->getMessage()==='AI_MODEL_RESULT_UNKNOWN' && ($d['stage']??'')==='transport' && ($d['predicate']??'')==='timeout' && ($d['transport_errno']??null)===28 && isset($d['http_status'],$d['elapsed_ms']),'unknown transport result has bounded useful diagnostic'); }
    $GLOBALS['sfTransportFailure']=false; $GLOBALS['sfErrno']=0;
    $GLOBALS['sfStatus']=401; rejects(function()use($client){$client->probe('fixture/model','fixture-key',1000,function(){});},'AI_MODEL_ACCOUNT_UNAVAILABLE');
    $GLOBALS['sfStatus']=200; $GLOBALS['sfResponse']=str_repeat('x',131073); rejects(function()use($client){$client->probe('fixture/model','fixture-key',1000,function(){});},'AI_MODEL_RESPONSE_TOO_LARGE');
    rejects(function()use($client){$client->probe('fixture/model',"bad\rkey",1000,function(){});},'AI_MODEL_CONFIG_INVALID');
    $temp = sys_get_temp_dir() . '/mohe-ai-gateway-fixture-' . bin2hex(random_bytes(8));
    $safeQuestion=['schema_version'=>'sanitized-question-v2','question'=>'今天做得最好的技师是谁','recent_questions'=>['昨天哪个技师表现最好'],
        'evidence_messages'=>[['id'=>'current','text'=>'今天做得最好的技师是谁'],['id'=>'recent_1','text'=>'昨天哪个技师表现最好']],
        'prior_query'=>null,'has_unresolved_conditions'=>false,'server_resolved_fields'=>[],'reference_date'=>'2026-09-10'];
    $meanings=[['metric_code'=>'staff_labor_yeji','name'=>'劳动业绩','summary'=>'按规则分配给手艺人的业绩','object_contracts'=>[['object_kind'=>'person','action_codes'=>['service']]]]];
    $understanding=['goal'=>'查询今天劳动业绩最好的技师','requirements'=>[['id'=>'r1','meaning'=>'查询今天劳动业绩最好的技师','fields'=>['object_kind','operation','periods','ranking'],'values'=>['object_kind'=>'person','operation'=>'ranking','periods'=>[['kind'=>'relative_days','end_offset_days'=>0,'days'=>1]],'ranking'=>['direction'=>'top','limit'=>1]],'evidence'=>[['message_id'=>'current','quote'=>'今天做得最好的技师是谁']]]],'status'=>'understood'];
    $GLOBALS['sfResponse']=$response(json_encode($understanding));
    $understood=$client->understandMeaning($safeQuestion,'fixture/model','fixture-key',1000,function(){},$skills);
    check($understood['understanding']['requirements'][0]['id']==='r1','understanding phase preserves a customer requirement without a metric code');
    $outbound=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    $understandingUser=array_values(array_filter($outbound['messages'],static function($message){return ($message['role']??null)==='user';}));
    $understandingInput=json_decode($understandingUser[0]['content'],true);
    check(!isset($understandingInput['capabilities']) && $understandingInput['question']['recent_questions']===['昨天哪个技师表现最好'],'understanding receives de-identified text but no capability catalogue');
    $outboundText=json_encode($outbound,JSON_UNESCAPED_UNICODE);
    check(strpos($outboundText,'skill_intent_understanding')!==false&&strpos($outboundText,'# 用户意图理解')!==false&&strpos($outboundText,'# 门店运营')===false,
        'understanding receives only the source-owned language Skill; business binding guidance stays in the next phase');
    check(strpos($outboundText,\app\services\ai\contract\AiIntentUnderstandingContract::VERSION)!==false,'understanding prompt uses its independent contract');
    $timeFollowQuestion=$safeQuestion;
    $timeFollowQuestion['question']='那本月呢？';
    $timeFollowQuestion['recent_questions']=['今天做得最好的技师是谁'];
    $timeFollowQuestion['evidence_messages']=[['id'=>'current','text'=>'那本月呢？'],['id'=>'recent_1','text'=>'今天做得最好的技师是谁']];
    $timeFollowQuestion['prior_query']=['metric_codes'=>['staff_labor_yeji'],'operation'=>'ranking','periods'=>[['kind'=>'relative_days','end_offset_days'=>0,'days'=>1]],'ranking'=>['direction'=>'top','limit'=>1]];
    $timeOnlyUnderstanding=['goal'=>'查看本月','requirements'=>[['id'=>'r1','meaning'=>'本月','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'本月']]]],'status'=>'understood'];
    $GLOBALS['sfResponse']=$response(json_encode($timeOnlyUnderstanding));
    $client->understandMeaning($timeFollowQuestion,'fixture/model','fixture-key',1000,function(){},$skills);
    $timeFollowWire=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    $timeFollowText=implode("\n",array_map(static function($message){return (string)($message['content']??'');},$timeFollowWire['messages']));
    check(strpos($timeFollowText,'Do not restate a verified prior measurement as a current metric_codes requirement')!==false,
        'a verified continuation foregrounds the generic current-evidence rule before the provider request');
    check(strpos(\app\services\ai\contract\AiIntentResultContract::modelInstruction(false),'recommended_initial_answer')!==false,
        'binding contract permits a model-owned professional first answer without a phrase-specific server rule');
    check(\app\services\ai\contract\AiIntentResultContract::repairableFormat('context_constraint_without_source:business_filters'),
        'an ungrounded follow-up restriction change receives one bounded model correction');
    check(strpos(\app\services\ai\contract\AiIntentUnderstandingContract::repairInstruction('values:metric_terms'),'periods requirement with a complete values.periods carrier')!==false,
        'time-only continuation recovery keeps the changed typed condition without inventing a current metric');
    $GLOBALS['sfResponse']=$response(json_encode($understanding));
    $repairedUnderstanding=$client->understandMeaning($safeQuestion,'fixture/model','fixture-key',1000,function(){},$skills,'values:periods');
    $repairWire=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    check($repairedUnderstanding['understanding']['requirements'][0]['id']==='r1'
        && strpos($repairWire['messages'][0]['content'],'values.periods')!==false,
        'understanding repair gives a missing carrier value to the model without a phrase-specific rule');
    $intent=['object_kind'=>'person','object_term'=>'技师','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'top','limit'=>1],'periods'=>[['kind'=>'relative_days','end_offset_days'=>0,'days'=>1]],'scope'=>'authorized','requirement_bindings'=>[],'unresolved_fragments'=>[],
        'provenance'=>['object_kind'=>['source'=>'customer','requirements'=>['r1']],'metric_codes'=>['source'=>'system','requirements'=>[]],'operation'=>['source'=>'customer','requirements'=>['r1']],'periods'=>['source'=>'customer','requirements'=>['r1']],'ranking'=>['source'=>'customer','requirements'=>['r1']],'scope'=>['source'=>'system','requirements'=>[]]]];
    $GLOBALS['sfResponse']=$response(json_encode($intent));
    $bound=$client->understand($safeQuestion,$meanings,$understood['understanding'],'fixture/model','fixture-key',1000,function(){},$skills);
    check($bound['intent']===$intent,'binding phase converts accepted meaning only to registered candidate fields');
    $bindingWire=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    $bindingInput=json_decode(array_values(array_filter($bindingWire['messages'],static function($message){return ($message['role']??null)==='user';}))[0]['content'],true);
    check(isset($bindingInput['understanding'],$bindingInput['capabilities'])&&!isset($bindingInput['question']['answers']),'binding sees accepted meaning and registered boundary but no answer data');
    $bindingText=implode("\n",array_map(static function($message){return (string)($message['content']??'');},$bindingWire['messages']));
    check(strpos($bindingText,'skill_store_operations')!==false&&strpos($bindingText,'skill_intent_understanding')===false,
        'binding keeps the business Skill but does not resend the language Skill after typed understanding is accepted');
    check(strpos($bindingText,'requirement_bindings contains only accepted requirements carrying metric_codes')!==false,
        'binding prompt keeps a model-selected professional first answer separate from customer metric requirements');
    $multiObjectCapabilities=array_merge($meanings,[['metric_code'=>'cash_performance','name'=>'现金业绩','summary'=>'已收成功金额','object_contracts'=>[['object_kind'=>'store','action_codes'=>['sale']]]]]);
    $GLOBALS['sfResponse']=$response(json_encode($intent));
    $client->understand($safeQuestion,$multiObjectCapabilities,$understood['understanding'],'fixture/model','fixture-key',1000,function(){},$skills);
    $objectProjectedWire=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    $objectProjectedInput=json_decode(array_values(array_filter($objectProjectedWire['messages'],static function($message){return ($message['role']??null)==='user';}))[0]['content'],true);
    check(array_column($objectProjectedInput['capabilities'],'metric_code')===['staff_labor_yeji'],
        'binding sends only registry entries compatible with the independently understood analytical object');
    $timeOnlyQuestion=['schema_version'=>'sanitized-question-v2','question'=>'上个月呢？','recent_questions'=>[],
        'evidence_messages'=>[['id'=>'current','text'=>'上个月呢？']],
        'prior_query'=>['metric_codes'=>['project_sales_amount'],'operation'=>'ranking','periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],
            'ranking'=>['direction'=>'top','limit'=>1],'scope'=>'authorized','object_kind'=>'project','has_business_filter'=>false,
            'has_object_selection'=>false,'has_store_scope_restriction'=>false,'presentation_origin'=>'customer_or_verified_context'],
        'has_unresolved_conditions'=>false,'server_resolved_fields'=>[],'reference_date'=>'2026-09-10'];
    $timeOnlyUnderstanding=['goal'=>'查看上个月','status'=>'understood','requirements'=>[['id'=>'r1','meaning'=>'上个月','fields'=>['periods'],
        'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>-1]]],'evidence'=>[['message_id'=>'current','quote'=>'上个月']]]]];
    $timeOnlyCapabilities=array_merge($multiObjectCapabilities,[['metric_code'=>'project_sales_amount','name'=>'项目销售额','summary'=>'项目销售金额',
        'object_contracts'=>[['object_kind'=>'project','action_codes'=>['sale']]]]]);
    $timeOnlyIntent=['object_kind'=>'project','object_term'=>'','operation'=>'ranking','metric_codes'=>['project_sales_amount'],'action_codes'=>[],
        'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>1],'periods'=>[['kind'=>'month_offset','offset_months'=>-1]],'scope'=>'authorized',
        'context_delta'=>['metric_codes'=>'inherit','object'=>'inherit','business_filters'=>'inherit','store_scope'=>'inherit','periods'=>'replace',
            'operation'=>'inherit','ranking_direction'=>'inherit','ranking_limit'=>'inherit','scope'=>'inherit'],'requirement_bindings'=>[],'unresolved_fragments'=>[]];
    $GLOBALS['sfResponse']=$response(json_encode($timeOnlyIntent));
    $client->understand($timeOnlyQuestion,$timeOnlyCapabilities,$timeOnlyUnderstanding,'fixture/model','fixture-key',1000,function(){},$skills);
    $timeOnlyWire=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    $timeOnlyInput=json_decode(array_values(array_filter($timeOnlyWire['messages'],static function($message){return ($message['role']??null)==='user';}))[0]['content'],true);
    check(array_column($timeOnlyInput['capabilities'],'metric_code')===['project_sales_amount'],
        'a pure time continuation projects only the verified prior analytical object registry view');
    $GLOBALS['sfResponse']=$response(json_encode($intent));
    $corrected=$client->understand($safeQuestion,$meanings,$understood['understanding'],'fixture/model','fixture-key',1000,function(){},$skills,'bad_value:requirement_bindings');
    $correctionWire=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    $correctionMessages=implode("\n",array_map(static function($message){return (string)($message['content']??'');},$correctionWire['messages']));
    check($corrected['intent']===$intent && strpos($correctionMessages,'exactly one row for each accepted requirement')!==false
        && strpos($correctionMessages,'requirement_bindings MUST be []')!==false,
        'bounded binding correction preserves the distinction between an accepted metric requirement and a recommended first answer');
    $GLOBALS['sfResponse']=$response(json_encode($intent));
    $referenceCorrected=$client->understand($safeQuestion,$meanings,$understood['understanding'],'fixture/model','fixture-key',1000,function(){},$skills,'bad_value:result_reference');
    $referenceCorrectionWire=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    $referenceCorrectionMessages=implode("\n",array_map(static function($message){return (string)($message['content']??'');},$referenceCorrectionWire['messages']));
    check($referenceCorrected['intent']===$intent && strpos($referenceCorrectionMessages,'Omit result_reference unless the accepted understanding explicitly contains')!==false,
        'bounded binding correction removes an ungrounded result reference without supplying a customer condition');
    // The independent reviewer uses the same full sanitized-question contract
    // as the understanding and binding calls. A reduced lookalike input must
    // fail locally before any provider request; the complete one must reach
    // the fixture transport successfully.
    $reviewCandidate=$intent;
    $reviewCandidate['metric_codes']=['staff_labor_yeji'];
    $reviewCandidate['needs_metric_choice']=false;
    $reviewCandidate['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['staff_labor_yeji']]];
    $GLOBALS['sfResponse']=$response(json_encode(['decision'=>'accept','rejected_requirement_ids'=>[]]));
    $reviewed=$client->verifyBinding($safeQuestion,$meanings,$understood['understanding'],$reviewCandidate,'fixture/model','fixture-key',1000,function(){});
    check($reviewed['review']===['decision'=>'accept','rejected_requirement_ids'=>[]],'semantic reviewer accepts the complete sanitized-question contract');
    $broadQuestion=$safeQuestion;$broadQuestion['question']='今天业绩多少';$broadQuestion['recent_questions']=[];
    $broadQuestion['evidence_messages']=[['id'=>'current','text'=>$broadQuestion['question']]];
    $broadUnderstanding=\app\services\ai\contract\AiIntentUnderstandingContract::normalize([
        'goal'=>'了解今天业绩','status'=>'understood','requirements'=>[
            ['id'=>'r1','meaning'=>'了解业绩','fields'=>['metric_codes'],'values'=>['metric_terms'=>['业绩']],'evidence'=>[['message_id'=>'current','quote'=>'业绩']]],
            ['id'=>'r2','meaning'=>'今天','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'今天']]],
        ],
    ],$broadQuestion);
    $broadCandidate=['object_kind'=>'store','metric_codes'=>['actual_performance']];
    $broadCaps=[
        ['metric_code'=>'cash_performance','name'=>'现金业绩','summary'=>'成功收取的金额','object_contracts'=>[['object_kind'=>'store','action_codes'=>[]]]],
        ['metric_code'=>'actual_performance','name'=>'实际业绩','summary'=>'收款减现金退款的净业绩','object_contracts'=>[['object_kind'=>'store','action_codes'=>[]]]],
    ];
    $GLOBALS['sfResponse']=$response(json_encode(['decision'=>'ambiguous','metric_code'=>'']));
    $broadReview=$client->verifyBinding($broadQuestion,$broadCaps,$broadUnderstanding,$broadCandidate,'fixture/model','fixture-key',1000,function(){});
    check($broadReview['review']===['decision'=>'metric_choice','rejected_requirement_ids'=>[]],
        'candidate-blind review turns a non-unique measurement into a controlled choice');
    $blindWire=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    $blindInput=json_decode(array_values(array_filter($blindWire['messages'],static function($message){return ($message['role']??null)==='user';}))[0]['content'],true);
    check(!isset($blindInput['candidate_binding']) && isset($blindInput['understanding'],$blindInput['capabilities']),
        'uniqueness reviewer never sees the candidate it is meant to check independently');
    $overviewCandidate=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['cash_performance','actual_performance'],
        'action_codes'=>[],'needs_metric_choice'=>false,'initial_observation'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized','requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance','actual_performance']]],'unresolved_fragments'=>[]];
    $GLOBALS['sfResponse']=$response(json_encode(['decision'=>'accept','rejected_requirement_ids'=>[]]));
    $overviewReview=$client->verifyBinding($broadQuestion,$broadCaps,$broadUnderstanding,$overviewCandidate,'fixture/model','fixture-key',1000,function(){});
    check($overviewReview['review']===['decision'=>'accept','rejected_requirement_ids'=>[]],'initial observation uses the independent coverage reviewer instead of a forced metric choice');
    $overviewWire=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    $overviewInput=json_decode(array_values(array_filter($overviewWire['messages'],static function($message){return ($message['role']??null)==='user';}))[0]['content'],true);
    check(($overviewInput['candidate_binding']['initial_observation']??false)===true,'independent reviewer receives the model-marked observation decision for semantic admission');
    check(array_key_exists('prior_query',$overviewInput['question']) && $overviewInput['question']['prior_query']===($broadQuestion['prior_query']??null),
        'independent reviewer receives the same de-identified prior-query projection as binding, never answer data');
    $GLOBALS['sfResponse']=$response(json_encode(['decision'=>'unique','metric_code'=>'cash_performance']));
    $wrongUnique=$client->verifyBinding($broadQuestion,$broadCaps,$broadUnderstanding,$broadCandidate,'fixture/model','fixture-key',1000,function(){});
    check($wrongUnique['review']===['decision'=>'reject','rejected_requirement_ids'=>['r1']],
        'a unique but different metric cannot validate the proposed candidate');
    rejects(function()use($client,$meanings,$understood,$reviewCandidate){
        $client->verifyBinding(['question'=>'今天做得最好的技师是谁','reference_date'=>'2026-09-10'],$meanings,$understood['understanding'],$reviewCandidate,'fixture/model','fixture-key',1000,function(){});
    },'AI_MODEL_INPUT_INVALID');
    $unboundQuestion=$safeQuestion;$unboundQuestion['question']='想了解课程学习后的掌握情况';$unboundQuestion['recent_questions']=[];$unboundQuestion['evidence_messages']=[['id'=>'current','text'=>$unboundQuestion['question']]];
    $unboundUnderstanding=['goal'=>'了解课程学习后的掌握情况','requirements'=>[['id'=>'r1','meaning'=>'了解课程学习后的掌握情况','fields'=>['object_kind','unbound'],'values'=>['object_kind'=>'course'],'evidence'=>[['message_id'=>'current','quote'=>'课程学习后的掌握情况']]]],'status'=>'understood'];
    $unboundIntent=['object_kind'=>'course','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'authorized','requirement_bindings'=>[],'unresolved_fragments'=>[],
        'provenance'=>['object_kind'=>['source'=>'customer','requirements'=>['r1']],'metric_codes'=>['source'=>'system','requirements'=>[]],'operation'=>['source'=>'system','requirements'=>[]],'periods'=>['source'=>'system','requirements'=>[]],'ranking'=>['source'=>'system','requirements'=>[]],'scope'=>['source'=>'system','requirements'=>[]]]];
    $GLOBALS['sfResponse']=$response(json_encode($unboundIntent));
    $unboundReply=$client->understand($unboundQuestion,[],$unboundUnderstanding,'fixture/model','fixture-key',1000,function(){},$skills);
    check($unboundReply['intent']['metric_codes']===[],'binding can report no faithful capability without pretending the meaning is unclear');
    mkdir($temp,0700); $private = new AiPrivateStorage($temp);
    try {
        check(strlen($private->signingKey())===32,'key created'); check($private->signingKey()===$private->signingKey(),'stable key');
        $ref=$private->put('evidence',['test'=>'fixture-data'],time()+60); check($private->read($ref)===['test'=>'fixture-data'],'encrypted round trip');
        check(strpos(file_get_contents($temp.'/'.$ref),'fixture-data')===false,'private data encrypted');
        rejects(function()use($private){$private->read('../master.key');},'AI_PRIVATE_OBJECT_INVALID');
        rejects(function()use($private){$private->put('chat',['question'=>'not allowed'],time()+60);},'AI_PRIVATE_OBJECT_INVALID');
        rejects(function()use($private){$private->put('answer',[],time()+86402);},'AI_PRIVATE_OBJECT_INVALID');
        $bytes=file_get_contents($temp.'/'.$ref); $bytes[30]=chr(ord($bytes[30])^1); file_put_contents($temp.'/'.$ref,$bytes);
        rejects(function()use($private,$ref){$private->read($ref);},'AI_PRIVATE_OBJECT_UNAVAILABLE');
        check($private->cleanup()===0 && is_file($temp.'/'.$ref),'fresh corrupt object retained during possible write');
        touch($temp.'/'.$ref,time()-86401); clearstatcache();
        check($private->cleanup()===1,'old corrupt fixture object cleaned');
        $safeRef=$private->put('evidence',['test'=>'keep-on-master-failure'],time()+60);
        $master=file_get_contents($temp.'/master.key');file_put_contents($temp.'/master.key','broken');
        rejects(function()use($private){$private->cleanup();},'AI_PRIVATE_STORAGE_UNAVAILABLE');
        check(is_file($temp.'/'.$safeRef),'master failure does not delete object');
        file_put_contents($temp.'/master.key',$master);
        $db=new \PDO('sqlite::memory:'); $db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE mohe_ai_config (instance_id TEXT PRIMARY KEY,enabled INTEGER,model TEXT,encrypted_key TEXT,external_authorized INTEGER,version INTEGER)');
        $store=new AiConfigStore($db,'','fixture-instance',$private); check($store->read()['version']===0,'initial config version');
        $input=['enabled'=>true,'external_processing_authorized'=>true,'model'=>'fixture/model','api_key'=>'fixture-only-key','version'=>0];
        $public=$store->save($input); check(!array_key_exists('api_key',$public) && $public['has_api_key']===true,'public key not returned');
        check(strpos(json_encode($db->query('SELECT * FROM mohe_ai_config')->fetch(\PDO::FETCH_ASSOC)),'fixture-only-key')===false,'key not cleartext in db');
        check($store->read(true)['api_key']==='fixture-only-key','trusted secret decrypt');
        rejects(function()use($store,$input){$store->save($input);},'AI_CONFIG_VERSION_CONFLICT');
        $input['version']=1;$input['api_key']='';$input['enabled']=false;
        check($store->save($input)['version']===2 && $store->read(true)['api_key']==='fixture-only-key','empty preserves key / version increments');
        $input['version']=2;$input['enabled']=true;$input['external_processing_authorized']=false;
        rejects(function()use($store,$input){$store->save($input);},'AI_EXTERNAL_AUTHORIZATION_REQUIRED');
        $other=new AiConfigStore($db,'','fixture-other',$private);check($other->read()['has_api_key']===false,'instance config isolated');
        check(!$store->read()['external_scope_supported'] && !AiConfigStore::allowsSanitizedQuestion($store->read()),'legacy schema never grants expanded scope');
        $input['enabled']=true;$input['external_processing_authorized']=true;$input['external_scope_version']=AiConfigStore::QUESTION_SCOPE;
        rejects(function()use($store,$input){$store->save($input);},'AI_CONFIG_SCOPE_MIGRATION_REQUIRED');
        $db->exec("ALTER TABLE mohe_ai_config ADD COLUMN external_scope_version TEXT NOT NULL DEFAULT ''");
        check($store->read()['external_scope_supported'] && !AiConfigStore::allowsSanitizedQuestion($store->read()),'migration does not grant consent');
        $public=$store->save($input);
        check($public['version']===3 && AiConfigStore::allowsSanitizedQuestion($public),'explicit versioned consent stored');
        check(!AiConfigStore::allowsSanitizedQuestion($other->read()),'consent not shared across instances');
        $input['version']=3;unset($input['external_scope_version']);
        check(AiConfigStore::allowsSanitizedQuestion($store->save($input)),'legacy request preserves already explicit scope');
        $input['version']=4;$input['enabled']=false;$input['external_processing_authorized']=false;
        check($store->save($input)['external_scope_version']==='','revocation clears scope durably');
        $input['version']=5;$input['enabled']=true;$input['external_processing_authorized']=true;
        check(!AiConfigStore::allowsSanitizedQuestion($store->save($input)),'re-enabling old authorization does not restore expanded consent');
        $input['version']=6;$input['external_scope_version']='future-unapproved-scope';
        rejects(function()use($store,$input){$store->save($input);},'AI_CONFIG_INVALID');
        $input['external_scope_version']=null;
        rejects(function()use($store,$input){$store->save($input);},'AI_CONFIG_INVALID');
    } finally {
        // Exact test-created directory only; no user/config/production paths.
        foreach (new \DirectoryIterator($temp) as $file) if ($file->isFile() && !$file->isLink()) unlink($file->getPathname());
        rmdir($temp);
    }
    echo 'Gateway components offline: '.$checks." checks PASS\n";
}

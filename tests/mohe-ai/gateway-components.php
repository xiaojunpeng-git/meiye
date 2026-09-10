<?php
// Isolated fixture transport: no real HTTP request can leave this test process.
namespace app\services\ai\model {
    function curl_init($endpoint) { $GLOBALS['sfEndpoint'] = $endpoint; return new \stdClass(); }
    function curl_setopt_array($handle, $options) { $GLOBALS['sfOptions'] = $options; return true; }
    function curl_exec($handle) {
        $options = $GLOBALS['sfOptions'];
        $progress=defined('CURLOPT_XFERINFOFUNCTION')?constant('CURLOPT_XFERINFOFUNCTION'):CURLOPT_PROGRESSFUNCTION;
        if ($options[$progress]() !== 0) return false;
        $body = $GLOBALS['sfResponse'];
        return $options[CURLOPT_WRITEFUNCTION]($handle, $body) === strlen($body);
    }
    function curl_getinfo($handle, $option) { return $GLOBALS['sfStatus']; }
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
    foreach (['今天张三现金业绩多少？'=>'AI_INTENT_UNRESOLVED','今天店长现金业绩多少？'=>'AI_CAPABILITY_NOT_READY','今天生美现金业绩多少？'=>'AI_CAPABILITY_NOT_READY','今天现金业绩排除张三'=>'AI_CAPABILITY_NOT_READY','今天现金业绩和销售数量'=>'AI_INTENT_UNRESOLVED'] as $unknown=>$expectedError) rejects(function()use($planner,$projector,$selection,$cap,$unknown){$planner->compile($projector->project($unknown),$selection,$cap,'screen','2026-09-08');},$expectedError);
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
    $validJson = json_encode($selection);
    $GLOBALS['sfResponse']=$response($validJson);
    $skill=(new \app\services\ai\registry\AiBusinessRegistry())->modelSkill('store_operations');
    $skills=(new \app\services\ai\registry\AiBusinessRegistry())->modelSkills('store_operations');
    $result=$client->select($view,$cap['metric_codes'],'fixture/model','fixture-key',1000,function(){},$skill);
    check($result['selection']===$selection,'model valid response');
    check($GLOBALS['sfEndpoint']===SiliconFlowClient::ENDPOINT,'fixed endpoint');
    check($GLOBALS['sfOptions'][CURLOPT_FOLLOWLOCATION]===false,'redirect prohibited');
    check(json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true)['temperature']===0,'deterministic vocabulary selection temperature');
    check((json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true)['messages'][4]['content']??'')!=='' && strpos(json_encode(json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true)),'skill_store_operations')!==false,'real model request carries the published runtime Skill contract');
    check($result['usage']['input_tokens']===8,'usage recorded');
    $definitionSelection=['decision'=>'query','query_shape'=>'definition','metric_codes'=>['cash_performance'],'date_code'=>'UNSPECIFIED'];
    $GLOBALS['sfResponse']=$response(json_encode($definitionSelection));
    $definitionResult=$client->select(['current'=>$projector->project('现金业绩指什么'),'recent_user_intents'=>[]],['cash_performance'],'fixture/model','fixture-key',1000,function(){});
    check($definitionResult['selection']===$definitionSelection,'definition is a bounded model selection not a generated explanation');
    $definitionWire=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    check(strpos(json_encode($definitionWire),'Missing slots and ambiguous_metric are not unsupported')!==false,'missing guidance slots do not conflict with semantic model rule');
    $rankView=$projector->modelView($projector->validateConversation('本月现金业绩最高的前五家店',[
        ['question'=>'本月消耗业绩','answer'=>'PRIVATE_ANSWER_NOT_FOR_MODEL'],
    ]));
    check(count(array_intersect(['cash_performance','THIS_MONTH','top_5'],$rankView['current']['signals']))===3 && !in_array('consume_amount',$rankView['current']['signals'],true) && !$rankView['current']['unresolved_condition'],'current ranking is separate from historical consume');
    $rankSelection=['decision'=>'query','query_shape'=>'ranking','metric_codes'=>['cash_performance'],'date_code'=>'THIS_MONTH'];
    $GLOBALS['sfResponse']=$response(json_encode($rankSelection));
    $rankResult=$client->select($rankView,$cap['metric_codes'],'fixture/model','fixture-key',1000,function(){});
    $wire=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    $rules=implode("\n",array_column(array_filter($wire['messages'],function($m){return $m['role']==='system';}),'content'));
    check(strpos($rules,'Selection scope is intent.current only.')!==false && strpos($rules,'top_5 or bottom_5 means query_shape=ranking')!==false,'real request includes current-only selection and ranking alias rules');
    check(strpos(json_encode($wire),'PRIVATE_ANSWER_NOT_FOR_MODEL')===false,'history answers never reach model request');
    $rankCap=['metric_codes'=>['cash_performance','consume_amount'],'query_shapes'=>['ranking']];
    check($planner->compile($rankView['current'],$rankResult['selection'],$rankCap,'screen','2026-09-08')['kind']==='plan','current-only ranking selection compiles with unrelated history');
    $historicalMetrics=$rankSelection; $historicalMetrics['metric_codes'][]='consume_amount';
    rejects(function()use($planner,$rankView,$historicalMetrics,$rankCap){$planner->compile($rankView['current'],$historicalMetrics,$rankCap,'screen','2026-09-08');},'AI_MODEL_SELECTION_MISMATCH');
    $wrongShape=$rankSelection; $wrongShape['query_shape']='summary';
    rejects(function()use($planner,$rankView,$wrongShape,$rankCap){$planner->compile($rankView['current'],$wrongShape,$rankCap,'screen','2026-09-08');},'AI_MODEL_SELECTION_MISMATCH');
    foreach (['{"metric_codes":[],"metric_codes":[],"query_shape":"summary","date_code":"TODAY","decision":"query"}'=>'AI_JSON_DUPLICATE_KEY', '{"metric_codes":["invented"],"query_shape":"summary","date_code":"TODAY","decision":"query"}'=>'AI_MODEL_METRIC_UNKNOWN', '{"metric_codes":[],"query_shape":"summary","date_code":"TODAY","decision":"query","sql":"SELECT 1"}'=>'AI_MODEL_RESPONSE_INVALID'] as $json=>$error) {
        $GLOBALS['sfResponse']=$response($json); rejects(function()use($client,$view,$cap){$client->select($view,$cap['metric_codes'],'fixture/model','fixture-key',1000,function(){});},$error);
    }
    $GLOBALS['sfResponse']=$response($validJson,'length'); rejects(function()use($client,$view,$cap){$client->select($view,$cap['metric_codes'],'fixture/model','fixture-key',1000,function(){});},'AI_MODEL_RESPONSE_INVALID');
    $GLOBALS['sfStatus']=401; rejects(function()use($client,$view,$cap){$client->select($view,$cap['metric_codes'],'fixture/model','fixture-key',1000,function(){});},'AI_MODEL_ACCOUNT_UNAVAILABLE');
    $GLOBALS['sfStatus']=200; $GLOBALS['sfResponse']=str_repeat('x',131073); rejects(function()use($client,$view,$cap){$client->select($view,$cap['metric_codes'],'fixture/model','fixture-key',1000,function(){});},'AI_MODEL_RESPONSE_TOO_LARGE');
    rejects(function()use($client,$view,$cap){$client->select($view,$cap['metric_codes'],'fixture/model',"bad\rkey",1000,function(){});},'AI_MODEL_CONFIG_INVALID');
    $temp = sys_get_temp_dir() . '/mohe-ai-gateway-fixture-' . bin2hex(random_bytes(8));
    $safeQuestion=['schema_version'=>'sanitized-question-v2','question'=>'今天做的最好技师是谁','has_unresolved_conditions'=>false,'server_resolved_fields'=>['period']];
    $meanings=[['metric_code'=>'staff_labor_yeji','name'=>'劳动业绩','summary'=>'按规则分配给手艺人的业绩','object_contracts'=>[['object_kind'=>'person','action_codes'=>['service']]]]];
    $intent=['object_kind'=>'person','object_term'=>'技师','operation'=>'ranking','metric_codes'=>[],'action_codes'=>['service'],'needs_metric_choice'=>true,'ranking'=>['direction'=>'top','limit'=>1],'unresolved_fragments'=>[]];
    $GLOBALS['sfResponse']=$response(json_encode($intent));
    $understood=$client->understand($safeQuestion,$meanings,'fixture/model','fixture-key',1000,function(){},$skills);
    check($understood['intent']===$intent,'general interpretation separates object, operation and missing criterion');
    $outbound=json_decode($GLOBALS['sfOptions'][CURLOPT_POSTFIELDS],true);
    check(strpos(json_encode($outbound),'recent_user_intents')===false,'new question understanding does not replay conversation history');
    check(strpos(json_encode($outbound),'member_operations')!==false&&strpos(json_encode($outbound),'partner_product_performance')!==false&&strpos(json_encode($outbound),'skill_intent_understanding')!==false,'model understanding receives the general intent Skill and all business capability groups');
    foreach ([array_merge($intent,['sql'=>'SELECT 1']),array_merge($intent,['metric_codes'=>['invented']]),array_merge($intent,['object_term'=>'张三'])] as $invalid) {
        $GLOBALS['sfResponse']=$response(json_encode($invalid));
        rejects(function()use($client,$safeQuestion,$meanings,$skills){$client->understand($safeQuestion,$meanings,'fixture/model','fixture-key',1000,function(){},$skills);},isset($invalid['metric_codes'][0])?'AI_MODEL_METRIC_UNKNOWN':'AI_MODEL_RESPONSE_INVALID');
    }
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

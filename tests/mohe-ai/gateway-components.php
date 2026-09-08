<?php
// Isolated fixture transport: no real HTTP request can leave this test process.
namespace app\services\ai\model {
    function curl_init($endpoint) { $GLOBALS['sfEndpoint'] = $endpoint; return new \stdClass(); }
    function curl_setopt_array($handle, $options) { $GLOBALS['sfOptions'] = $options; return true; }
    function curl_exec($handle) {
        $options = $GLOBALS['sfOptions'];
        if ($options[CURLOPT_XFERINFOFUNCTION]() !== 0) return false;
        $body = $GLOBALS['sfResponse'];
        return $options[CURLOPT_WRITEFUNCTION]($handle, $body) === strlen($body);
    }
    function curl_getinfo($handle, $option) { return $GLOBALS['sfStatus']; }
    function curl_close($handle) {}
}
namespace {
    $root = dirname(__DIR__, 2) . '/后端代码/app/services/ai/';
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
    $history = array_fill(0,20,['question'=>'今天现金业绩','answer'=>['summary'=>'测试']]);
    check(count($projector->validateConversation('今天业绩',$history)['history']) === 20,'20 complete rounds');
    rejects(function()use($projector,$history){$history[]=$history[0];$projector->validateConversation('问',$history);},'AI_CONVERSATION_INVALID');
    foreach ([[['question'=>'问']], [['question'=>'问','answer'=>'答','system'=>'inject']], ['sparse'=>['question'=>'问','answer'=>'答']]] as $invalid) rejects(function()use($projector,$invalid){$projector->validateConversation('问',$invalid);},'AI_CONVERSATION_INVALID');
    rejects(function()use($projector){$projector->validateConversation("\xff",[]);},'AI_CONVERSATION_INVALID');
    $cap = ['metric_codes'=>['cash_performance','consume_amount'],'query_shapes'=>['summary']];
    $selection = ['decision'=>'query','query_shape'=>'summary','metric_codes'=>['cash_performance'],'date_code'=>'TODAY'];
    $planner = new AiWorkflowPlanner();
    $compiled = $planner->compile($projector->project('今天现金业绩多少？'),$selection,$cap,'screen','2026-09-08');
    check($compiled['kind']==='plan' && $compiled['plan']['query']['start_date']==='2026-09-08','today exact');
    check($compiled['plan']['query']['metric_codes']===['cash_performance'],'exact metric');
    check(count($compiled['plan']['nodes'])===3 && $compiled['plan']['max_tool_calls']===1,'bounded nodes');
    foreach (['今天张三现金业绩多少？','今天店长现金业绩多少？','今天生美现金业绩多少？','今天现金业绩排除张三','今天现金业绩和销售数量'] as $unknown) rejects(function()use($planner,$projector,$selection,$cap,$unknown){$planner->compile($projector->project($unknown),$selection,$cap,'screen','2026-09-08');},'AI_UNSUPPORTED_CONDITION');
    $envelope = $planner->compile($projector->project('业绩多少？'),$selection,$cap,'screen','2026-09-08');
    check($envelope['kind']==='clarification' && count($envelope['fields'])===3,'one combined clarification envelope');
    $chosen = $planner->choose($envelope,['metric_code'=>'consume_amount','start_date'=>'2026-09-01','end_date'=>'2026-09-08']);
    check($chosen['kind']==='plan','choice leads to plan, no second clarification');
    rejects(function()use($planner,$envelope){$planner->choose($envelope,['metric_code'=>'actual_performance','start_date'=>'2026-09-01','end_date'=>'2026-09-08']);},'AI_CLARIFICATION_INVALID');
    rejects(function()use($planner,$envelope){$planner->choose($envelope,['metric_code'=>'cash_performance','start_date'=>'2026-02-30','end_date'=>'2026-09-08']);},'AI_DATE_INVALID');
    rejects(function()use($planner,$envelope){$planner->choose($envelope,['metric_code'=>'cash_performance']);},'AI_CLARIFICATION_INVALID');
    rejects(function()use($planner,$projector,$selection,$cap){$planner->compile($projector->project('今天实际业绩'),$selection,$cap,'screen','2026-09-08');},'AI_METRIC_NOT_READY');
    // Source compiler intentionally refuses export until shared export readiness.
    rejects(function()use($planner,$projector,$selection,$cap){$planner->compile($projector->project('今天现金业绩'),$selection,$cap,'screen_and_xlsx','2026-09-08');},'AI_EXPORT_NOT_READY');
    if (!function_exists('curl_init')) throw new \RuntimeException('curl extension required for offline option constants');
    $client = new SiliconFlowClient();
    $GLOBALS['sfStatus']=200;
    $response = function($content,$finish='stop') { return json_encode(['choices'=>[['finish_reason'=>$finish,'message'=>['content'=>$content]]],'usage'=>['prompt_tokens'=>8,'completion_tokens'=>4]]); };
    $validJson = json_encode($selection);
    $GLOBALS['sfResponse']=$response($validJson);
    $result=$client->select($view,$cap['metric_codes'],'fixture/model','fixture-key',1000,function(){});
    check($result['selection']===$selection,'model valid response');
    check($GLOBALS['sfEndpoint']===SiliconFlowClient::ENDPOINT,'fixed endpoint');
    check($GLOBALS['sfOptions'][CURLOPT_FOLLOWLOCATION]===false,'redirect prohibited');
    check($result['usage']['input_tokens']===8,'usage recorded');
    foreach (['{"metric_codes":[],"metric_codes":[],"query_shape":"summary","date_code":"TODAY","decision":"query"}'=>'AI_JSON_DUPLICATE_KEY', '{"metric_codes":["invented"],"query_shape":"summary","date_code":"TODAY","decision":"query"}'=>'AI_MODEL_METRIC_UNKNOWN', '{"metric_codes":[],"query_shape":"summary","date_code":"TODAY","decision":"query","sql":"SELECT 1"}'=>'AI_MODEL_RESPONSE_INVALID'] as $json=>$error) {
        $GLOBALS['sfResponse']=$response($json); rejects(function()use($client,$view,$cap){$client->select($view,$cap['metric_codes'],'fixture/model','fixture-key',1000,function(){});},$error);
    }
    $GLOBALS['sfResponse']=$response($validJson,'length'); rejects(function()use($client,$view,$cap){$client->select($view,$cap['metric_codes'],'fixture/model','fixture-key',1000,function(){});},'AI_MODEL_RESPONSE_INVALID');
    $GLOBALS['sfStatus']=401; rejects(function()use($client,$view,$cap){$client->select($view,$cap['metric_codes'],'fixture/model','fixture-key',1000,function(){});},'AI_MODEL_ACCOUNT_UNAVAILABLE');
    $GLOBALS['sfStatus']=200; $GLOBALS['sfResponse']=str_repeat('x',131073); rejects(function()use($client,$view,$cap){$client->select($view,$cap['metric_codes'],'fixture/model','fixture-key',1000,function(){});},'AI_MODEL_RESPONSE_TOO_LARGE');
    rejects(function()use($client,$view,$cap){$client->select($view,$cap['metric_codes'],'fixture/model',"bad\rkey",1000,function(){});},'AI_MODEL_CONFIG_INVALID');
    $temp = sys_get_temp_dir() . '/mohe-ai-gateway-fixture-' . bin2hex(random_bytes(8));
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
    } finally {
        // Exact test-created directory only; no user/config/production paths.
        foreach (new \DirectoryIterator($temp) as $file) if ($file->isFile() && !$file->isLink()) unlink($file->getPathname());
        rmdir($temp);
    }
    echo 'Gateway components offline: '.$checks." checks PASS\n";
}

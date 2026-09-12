<?php
/** Offline registry/compiler/real scheduler fixtures. No database, model, queue or HTTP access. */
$fixture=__DIR__.'/fixture-autoload.php'; require_once $fixture;
$backend=dirname(__DIR__,2).'/后端代码/';
foreach (['contract/AiContractException.php','registry/AiRegistryValue.php','registry/AiBusinessManifest.php','registry/AiBusinessRegistry.php',
    'execution/AiRegisteredPlanCompiler.php','execution/AiRegisteredWorkflowExecutor.php'] as $file) require_once $backend.'app/services/ai/'.$file;
require_once $backend.'app/services/query/metric/MetricReadViewServices.php';
require_once $backend.'app/services/query/metric/MetricDefinitionRegistry.php';
require_once $backend.'app/services/metric/MetricDictionaryServices.php';

use app\services\ai\registry\AiBusinessRegistry;
use app\services\ai\registry\AiBusinessManifest;
use app\services\ai\registry\AiRegistryValue;
use app\services\ai\execution\AiRegisteredPlanCompiler;
use app\services\ai\execution\AiRegisteredWorkflowExecutor;
use app\services\query\metric\MetricReadViewServices;

$checks=0;
function registryCheck(bool $condition,string $name):void { global $checks; if (!$condition) throw new RuntimeException('FAIL: '.$name); ++$checks; }
function registryReject(callable $operation,string $reason):void
{
    try { $operation(); } catch (Throwable $error) { registryCheck($error->getMessage()===$reason,'expected '.$reason.', got '.$error->getMessage()); return; }
    throw new RuntimeException('FAIL: expected '.$reason);
}
function registryCapabilities():array
{
    return ['metric_codes'=>['cash_performance','consume_amount','completed_service_item_count'],'query_shapes'=>['summary','trend','ranking','comparison'],
        'output_formats'=>['screen','screen_and_xlsx'],'metric_readiness'=>MetricReadViewServices::metricCapabilities(),
        'definition_metric_codes'=>['cash_performance'],'metadata_readiness'=>[
            'cash_performance'=>['user_ready'=>true,'metric_version'=>'fixture-confirmed-dictionary-v2','description_ref'=>'fixture:cash-description:v2']]];
}
function registryPlan(string $shape='summary',string $format='screen'):array
{
    return ['schema_version'=>'mohe-executable-workflow-v1','workflow_code'=>'wf_performance_'.$shape,
        'query'=>['query_shape'=>$shape,'metric_codes'=>['cash_performance','consume_amount'],'start_date'=>'2026-09-01','end_date'=>'2026-09-08',
            'compare_range'=>$shape==='comparison'?['start'=>'2026-08-10','end'=>'2026-08-17']:null,
            'store_ids'=>[],'business_filters'=>[],'ranking'=>$shape==='ranking'?['direction'=>'top_and_bottom','limit'=>5]:null], 'output_format'=>$format];
}
function registryHandlers(array &$seen):array
{
    return [
        'unified_metric_query'=>function($input,$node,$plan,$heartbeat)use(&$seen):array {
            $seen[]=$node['id']; $heartbeat();
            return ['ai_query_ready'=>true,'result_status'=>'complete','results'=>[['metric_code'=>'cash_performance','amount_cents'=>123456]],'query'=>$input['query']];
        },
        'all_evidence_guard'=>function($input,$node)use(&$seen):array {
            $seen[]=$node['id']; registryCheck(array_keys($input['dependencies'])===['query'],'guard sees only declared query dependency');
            return ['verified'=>true,'evidence'=>$input['dependencies']['query']];
        },
        'deterministic_answer'=>function($input,$node)use(&$seen):array {
            $seen[]=$node['id']; registryCheck(array_keys($input['dependencies'])===['evidence'],'renderer sees only verified dependency');
            return ['answer'=>['summary'=>'fixture verified answer','cards'=>$input['dependencies']['evidence']['evidence']['results']]];
        },
        'verified_export_create'=>function($input,$node)use(&$seen):array {
            $seen[]=$node['id']; registryCheck(array_keys($input['dependencies'])===['evidence','render'],'export requires verified evidence and answer');
            return ['deferred'=>true,'task_ref'=>'fixture-export-ref'];
        },
        'metric_catalog_read'=>function($input,$node)use(&$seen):array {
            $seen[]=$node['id']; registryCheck($input['query']===null&&$input['definition_metric_codes']===['cash_performance'],'metadata never carries a business query');
            return ['definitions'=>[['metric_code'=>'cash_performance','description'=>'fixture confirmed description']]];
        },
        'metadata_guard'=>function($input,$node)use(&$seen):array { $seen[]=$node['id']; return ['verified'=>true,'definitions'=>$input['dependencies']['catalog']['definitions']]; },
        'deterministic_definition'=>function($input,$node)use(&$seen):array { $seen[]=$node['id']; return ['answer'=>['definitions'=>$input['dependencies']['evidence']['definitions']]]; },
    ];
}

try {
    $registry=new AiBusinessRegistry(); $compiler=new AiRegisteredPlanCompiler($registry); $cap=registryCapabilities();
    $manifest=AiBusinessManifest::definitions();
    registryCheck(($manifest['intent_contract']['code']??null)==='intent_result' && ($manifest['intent_contract']['version']??null)==='intent-binding-v3'
        && preg_match('/^[a-f0-9]{64}$/D',$manifest['intent_contract']['hash']??'')===1,'intent result contract participates in the registry fingerprint');
    $snapshot=$registry->snapshot($cap);
    registryCheck(count($snapshot['metrics'])===3&&!isset($snapshot['metrics']['actual_performance']),'only approved metric contracts, no actual formula invented');
    $reordered=array_reverse($cap,true); $reordered['metric_codes']=array_reverse($cap['metric_codes']);
    $reordered['query_shapes']=array_reverse($cap['query_shapes']); $reordered['metric_readiness']=array_reverse($cap['metric_readiness'],true);
    registryCheck($registry->snapshot($reordered)===$snapshot,'capability fingerprint independent of set/key order');
    $skill=\app\services\ai\registry\AiSkillDocument::storeOperations();
    registryCheck(count($registry->discover($snapshot,1)['items'])===1,'one store-operations Skill is discoverable without report names');
    registryCheck($skill['skill_code']==='skill_store_operations'&&$skill['version']===15,'runtime Skill has a stable published identity');
    registryCheck(strpos($skill['markdown'],'# 门店运营')===0&&preg_match('/^[a-f0-9]{64}$/D',$skill['source_hash'])===1,'runtime Skill markdown has a fixed source hash');
    $modelSkill=$registry->modelSkill('store_operations');
    registryCheck($modelSkill['skill_code']===$skill['skill_code']&&$modelSkill['skill_version']===$skill['version']&&$modelSkill['skill_source_hash']===$skill['source_hash'],'model Skill is the exact validated published source');
    registryCheck($modelSkill['instructions']===$skill['markdown'],'model receives the complete source-owned Skill, not a reduced phrase catalog');
    registryCheck(count($registry->discover($snapshot,2,['metric_codes'=>['cash_performance']])['items'])===1,'bounded metric discovery');
    registryCheck($registry->discover($snapshot,2,['scene_codes'=>['store_operations']])['items'][0]['query_shapes']===['comparison','ranking','summary','trend'],'level two exposes registered operations without a report scenario');
    $discovered=$registry->discover($snapshot,3,['scene_codes'=>['store_operations'],'metric_codes'=>['cash_performance']]);
    registryCheck(in_array('wf_performance_ranking',array_column($discovered['items'],'workflow_code'),true),'store-operations Skill exposes registered reusable workflows');
    registryCheck(strpos(json_encode($discovered),'handler')===false&&strpos(json_encode($discovered),'depends_on')===false,'discovery excludes server handlers and graph');
    registryReject(function()use($registry,$snapshot){$registry->discover($snapshot,4);},'AI_DISCOVERY_LEVEL_INVALID');
    registryReject(function()use($registry,$snapshot){$registry->discover($snapshot,2,['sql'=>[]]);},'AI_REGISTRY_SCHEMA_INVALID');
    $broken=$snapshot; $broken['metrics']=[];
    registryReject(function()use($registry,$broken){$registry->discover($broken,1);},'AI_CAPABILITY_CHANGED');
    $none=$cap; $none['metric_codes']=[]; $none['definition_metric_codes']=[];
    registryCheck($registry->snapshot($none)['workflows']===[],'no legal contracts cannot mint workflows');
    $notReady=$cap; $notReady['metric_readiness']['cash_performance']['ai_query_ready']=false;
    registryCheck(!isset($registry->snapshot($notReady)['metrics']['cash_performance']),'lower readiness can withdraw executable metric');
    $future=$cap; $future['metric_codes'][]='gross_profit'; $future['metric_readiness']['gross_profit']=$cap['metric_readiness']['cash_performance'];
    registryCheck(!isset($registry->snapshot($future)['metrics']['gross_profit']),'lower layer new capability does not auto-open AI whitelist');
    $version=$cap; $version['metric_readiness']['cash_performance']['metric_version']='fixture-next';
    registryCheck($registry->snapshot($version)['snapshot_hash']!==$snapshot['snapshot_hash'],'contract evolution changes capability snapshot');
    $missing=$cap; unset($missing['metric_readiness']['cash_performance']['mapping_version']);
    registryReject(function()use($registry,$missing){$registry->snapshot($missing);},'AI_METRIC_CONTRACT_INCOMPLETE');
    $metadata=$cap; unset($metadata['metadata_readiness']['cash_performance']['description_ref']);
    registryReject(function()use($registry,$metadata){$registry->snapshot($metadata);},'AI_METADATA_CONTRACT_INCOMPLETE');
    foreach (['summary','trend','ranking','comparison'] as $shape) {
        $compiled=$compiler->compile(registryPlan($shape),$cap); $compiler->assertCompiled($compiled);
        registryCheck($compiled['workflow_code']==='wf_performance_'.$shape,'registered '.$shape.' fragment');
        registryCheck($compiled['query']===registryPlan($shape)['query'],'all '.$shape.' query slots retained exactly');
        registryCheck($compiled['budget']['counters']['tool_call_count']===1&&$compiled['budget']['counters']['skill_execution_count']===1,'actual calls counted once');
        registryCheck($compiled['scene_code']==='store_operations','business Skill stays separate from reusable shape');
    }
    $compiled=$compiler->compile(registryPlan(),$cap);
    registryCheck($compiled['dependency_versions']['tool']===['unified_metric_query'=>1],'complete tool dependency version frozen');
    registryCheck($compiled['dependency_versions']['skill']===['skill_store_operations'=>15],'business Skill has explicit immutable id and version');
    $memberPlan=registryPlan('ranking');
    $memberPlan['query']['metric_codes']=['cash_performance'];
    $memberPlan['query']['business_filters']=['object_kind'=>'member'];
    $memberPlan['query']['ranking']=['direction'=>'top','limit'=>5];
    $memberCompiled=$compiler->compile($memberPlan,$cap); $compiler->assertCompiled($memberCompiled);
    registryCheck($memberCompiled['query']['business_filters']===['object_kind'=>'member']&&$memberCompiled['query']['ranking']['limit']===5,'registered member dimension compiles only through the frozen metric contract');
    $projectPlan=registryPlan('ranking');
    $projectPlan['query']['metric_codes']=['completed_service_item_count'];
    $projectPlan['query']['business_filters']=['object_kind'=>'project'];
    $projectPlan['query']['ranking']=['direction'=>'top','limit'=>5];
    $projectCompiled=$compiler->compile($projectPlan,$cap); $compiler->assertCompiled($projectCompiled);
    registryCheck($projectCompiled['query']['business_filters']===['object_kind'=>'project'],'registered project dimension compiles without a compiler object-name branch');
    $projectSalesCap=$cap;$projectSalesCap['metric_codes'][]='sales_amount';sort($projectSalesCap['metric_codes']);
    $projectSalesPlan=registryPlan('ranking');
    $projectSalesPlan['query']['metric_codes']=['sales_amount'];
    $projectSalesPlan['query']['business_filters']=['object_kind'=>'project'];
    $projectSalesPlan['query']['ranking']=['direction'=>'top','limit'=>5];
    $projectSalesCompiled=$compiler->compile($projectSalesPlan,$projectSalesCap); $compiler->assertCompiled($projectSalesCompiled);
    $salesDefinition=\app\services\query\metric\MetricDefinitionRegistry::get('sales_amount');
    registryCheck($projectSalesCompiled['query']['business_filters']===['object_kind'=>'project']
        && ($salesDefinition['source']['dimensions']['project']['analysis_source_filters']??null)===['source_type'=>'project'],
        'project sales ranking is a registered sales-fact dimension, never an AI-side fact query');
    registryCheck($compiled['dependency_versions']['skill_source']===['skill_store_operations'=>$skill['source_hash']],'compiled plan freezes the exact SKILL.md source');
    $metadataPlan=['query_shape'=>'definition','definition_metric_codes'=>['cash_performance'],'output_format'=>'screen'];
    $definition=$compiler->compile($metadataPlan,$cap); $compiler->assertCompiled($definition);
    registryCheck($definition['query']===null&&$definition['scene_code']==='store_operations'&&$definition['budget']['counters']['skill_execution_count']===1,'definition remains metadata-only while using the operating Skill boundary');
    $noMetadata=$cap; unset($noMetadata['definition_metric_codes']);
    registryReject(function()use($compiler,$metadataPlan,$noMetadata){$compiler->compile($metadataPlan,$noMetadata);},'AI_METADATA_NOT_READY');
    foreach (['dao','sql','formula','table','url'] as $key) {
        $invalid=registryPlan(); $invalid[$key]='untrusted';
        registryReject(function()use($compiler,$cap,$invalid){$compiler->compile($invalid,$cap);},'AI_REGISTRY_SCHEMA_INVALID');
    }
    $invalid=registryPlan(); $invalid['nodes'][]='arbitrary_handler';
    registryReject(function()use($compiler,$cap,$invalid){$compiler->compile($invalid,$cap);},'AI_WORKFLOW_GRAPH_INVALID');
    $invalid=registryPlan(); $invalid['query']['metric_codes']=['actual_performance'];
    registryReject(function()use($compiler,$cap,$invalid){$compiler->compile($invalid,$cap);},'AI_METRIC_NOT_READY');
    $invalid=registryPlan(); $invalid['query']['business_filters']=[['person'=>'fixture']];
    registryReject(function()use($compiler,$cap,$invalid){$compiler->compile($invalid,$cap);},'AI_UNSUPPORTED_CONDITION');
    $invalid=registryPlan(); $invalid['query']['store_ids']=[2];
    registryReject(function()use($compiler,$cap,$invalid){$compiler->compile($invalid,$cap);},'AI_SCOPE_INVALID');
    $scoped=$cap; $scoped['store_ids']=[1,2]; $scopedCompiled=$compiler->compile($invalid,$scoped); $compiler->assertCompiled($scopedCompiled);
    registryCheck($scopedCompiled['query']['store_ids']===[2],'trusted authority can only narrow scope');
    $invalid=registryPlan('ranking'); $invalid['query']['ranking']['limit']=10;
    registryCheck($compiler->compile($invalid,$cap)['query']['ranking']['limit']===10,'registered ranking count is preserved instead of being rewritten to five');
    $invalid=registryPlan('comparison'); $invalid['query']['compare_range']['start']='2026-08-01';
    registryReject(function()use($compiler,$cap,$invalid){$compiler->compile($invalid,$cap);},'AI_DATA_COVERAGE_INCOMPLETE');
    $invalid=registryPlan(); $invalid['query']['start_date']='2026-02-30';
    registryReject(function()use($compiler,$cap,$invalid){$compiler->compile($invalid,$cap);},'AI_DATE_INVALID');
    registryReject(function()use($compiler,$cap){$compiler->compile(registryPlan(),$cap,['remaining_execution_ms'=>44000]);},'AI_WORKFLOW_BUDGET_EXHAUSTED');
    registryReject(function()use($compiler,$cap){$compiler->compile(registryPlan(),$cap,['run_budget_ms'=>300001]);},'AI_WORKFLOW_BUDGET_INVALID');
    $noExport=$cap; $noExport['output_formats']=['screen'];
    registryReject(function()use($compiler,$noExport){$compiler->compile(registryPlan('summary','screen_and_xlsx'),$noExport);},'AI_EXPORT_NOT_READY');
    $export=$compiler->compile(registryPlan('summary','screen_and_xlsx'),$cap); $compiler->assertCompiled($export);
    registryCheck(count($export['nodes'])===4&&$export['max_tool_calls']===2,'optional export is real counted dependent node');
    $tampered=$compiled; $tampered['nodes'][0]['handler']='deterministic_answer';
    registryReject(function()use($compiler,$tampered){$compiler->assertCompiled($tampered);},'AI_COMPILED_PLAN_INVALID');
    unset($tampered['compiled_run_hash']); $tampered['compiled_run_hash']=AiRegistryValue::hash($tampered);
    registryReject(function()use($compiler,$tampered){$compiler->assertCompiled($tampered);},'AI_COMPILED_PLAN_INVALID');
    foreach (['missing_tool','schema','cycle','duplicate','unbounded','arbitrary_handler','incomplete_domain'] as $mutation) {
        $manifest=AiBusinessManifest::definitions();
        if ($mutation==='missing_tool') unset($manifest['tools']['unified_metric_query']);
        if ($mutation==='schema') $manifest['workflows']['wf_performance_summary']['nodes'][1]['input_schema']='answer_result';
        if ($mutation==='cycle') $manifest['workflows']['wf_performance_summary']['nodes'][0]['depends_on']=['render'];
        if ($mutation==='duplicate') $manifest['workflows']['wf_performance_summary']['nodes'][2]['id']='query';
        if ($mutation==='unbounded') $manifest['workflows']['wf_performance_summary']['nodes'][0]['max_visits']=0;
        if ($mutation==='arbitrary_handler') $manifest['tools']['unified_metric_query']['handler']='AnyClass::query';
        if ($mutation==='incomplete_domain') $manifest['scenes']['store_operations']['instructions']='';
        $reason=['missing_tool'=>'AI_REGISTRY_DEPENDENCY_INVALID','schema'=>'AI_REGISTRY_SCHEMA_INCOMPATIBLE','cycle'=>'AI_WORKFLOW_GRAPH_INVALID',
            'duplicate'=>'AI_WORKFLOW_GRAPH_INVALID','unbounded'=>'AI_WORKFLOW_NODE_INVALID','arbitrary_handler'=>'AI_TOOL_CONTRACT_INVALID',
            'incomplete_domain'=>'AI_REGISTRY_VERSION_INVALID'][$mutation];
        registryReject(function()use($manifest){new AiBusinessRegistry($manifest);},$reason);
    }
    $seen=[]; $events=[]; $handlers=registryHandlers($seen);
    $checkpoint=function($event,$node,$trace)use(&$events):void { $events[]=$event.':'.$node['id']; };
    $executor=new AiRegisteredWorkflowExecutor();
    $result=$executor->execute($compiled,$handlers,$checkpoint);
    registryCheck($seen===['query','evidence','render'],'executor actually runs registered callbacks in dependency order');
    registryCheck($result['status']==='COMPLETED'&&$result['outputs']['render']['answer']['cards'][0]['amount_cents']===123456,'same authority cents passed to answer without AI arithmetic');
    registryCheck($result['counters']===$compiled['budget']['counters'],'scheduler accounting matches compiled worst path');
    registryCheck($events[0]==='before_node:query'&&end($events)==='after_node:render','checkpoints fence every node');
    registryCheck(strpos(json_encode($result['trace']),'123456')===false,'trace never copies business payload');
    $seen=[]; $result=$executor->execute($definition,$handlers,$checkpoint);
    registryCheck($seen===['catalog','evidence','render']&&$result['status']==='COMPLETED','definition performs only metadata/verification/render path');
    $seen=[]; $result=$executor->execute($export,$handlers,$checkpoint);
    registryCheck($seen===['query','evidence','render','export']&&$result['status']==='WAITING_EXTERNAL','file queue handoff defers, never pretends file completed');
    $seen=[]; $missingHandler=$handlers; unset($missingHandler['deterministic_answer']);
    registryReject(function()use($executor,$compiled,$missingHandler,$checkpoint){$executor->execute($compiled,$missingHandler,$checkpoint);},'AI_HANDLER_UNAVAILABLE');
    registryCheck($seen===[],'missing downstream handler rejected before data read');
    $seen=[]; $cancel=function($event,$node):void { if ($event==='before_node'&&$node['id']==='evidence') throw new RuntimeException('AI_CANCELLED'); };
    registryReject(function()use($executor,$compiled,$handlers,$cancel){$executor->execute($compiled,$handlers,$cancel);},'AI_CANCELLED');
    registryCheck($seen===['query'],'cancel prevents verification/render/export; executor never publishes');
    $seen=[]; $failure=$handlers; $failure['all_evidence_guard']=function(){return ['verified'=>false];};
    registryReject(function()use($executor,$compiled,$failure,$checkpoint){$executor->execute($compiled,$failure,$checkpoint);},'AI_EVIDENCE_INCOMPLETE');
    registryCheck($seen===['query'],'incomplete evidence cannot reach rendering');
    $failure=$handlers; $failure['unified_metric_query']=function(){return null;};
    registryReject(function()use($executor,$compiled,$failure,$checkpoint){$executor->execute($compiled,$failure,$checkpoint);},'AI_NODE_OUTPUT_INVALID');
    $now=1000; $timed=new AiRegisteredWorkflowExecutor(null,function()use(&$now){return $now;});
    $failure=$handlers; $failure['unified_metric_query']=function()use(&$now){$now+=10001;return ['ai_query_ready'=>true,'result_status'=>'complete','results'=>[['amount_cents'=>0]]];};
    registryReject(function()use($timed,$compiled,$failure,$checkpoint){$timed->execute($compiled,$failure,$checkpoint);},'AI_NODE_TIMEOUT');
    $now=1000; $failure=$handlers; $failure['unified_metric_query']=function($input,$node,$plan,$heartbeat)use(&$now){$now+=10000;$heartbeat();return [];};
    registryReject(function()use($timed,$compiled,$failure,$checkpoint){$timed->execute($compiled,$failure,$checkpoint);},'AI_NODE_TIMEOUT');
    $calls=0; $failure=$handlers; $failure['unified_metric_query']=function()use(&$calls){++$calls;throw new RuntimeException('AI_MODEL_RESULT_UNKNOWN');};
    registryReject(function()use($executor,$compiled,$failure,$checkpoint){$executor->execute($compiled,$failure,$checkpoint);},'AI_MODEL_RESULT_UNKNOWN');
    registryCheck($calls===1,'unknown outcomes never automatically retried/replanned');
    echo 'PASS registry-execution: '.$checks." checks (offline fixtures; not business acceptance)\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n"); exit(1); }

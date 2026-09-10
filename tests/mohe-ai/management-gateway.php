<?php
// Reuse the existing synthetic gateway harness and run its baseline first.
// No application bootstrap, customer database, credentials or real model calls.
require __DIR__.'/gateway-r5-guidance.php';

use app\services\ai\management\AiManagementStore;

$managementChecks=0;
function mgcheck($ok,string $label): void {global $managementChecks;if(!$ok)throw new RuntimeException('R6 FAIL '.$label);$managementChecks++;}
function mgdeny(callable $call,string $reason): void {try{$call();}catch(Throwable $e){mgcheck($e->getMessage()===$reason,$reason.' got '.$e->getMessage());return;}throw new RuntimeException('R6 missing denial '.$reason);}
function mgcall($h,string $operation,array $input=[]){return $h->gateway->handle($operation,$h->context,$input);}
function mgboot($h): array {return $h->gateway->handle('bootstrap',$h->context,['client_session_id'=>'device1']);}
function mgpublish($h,array $document): array {
    $state=mgcall($h,'management_get');
    $saved=mgcall($h,'management_save',['expected_revision'=>$state['revision'],'document'=>$document]);
    mgcall($h,'management_validate',['expected_revision'=>$saved['revision']]);
    return mgcall($h,'management_publish',['expected_revision'=>$saved['revision']]);
}
$h=null;
try {
    $h=new R5Harness(3);
    $h->db->exec('CREATE TABLE mohe_ai_management_state(instance_key TEXT PRIMARY KEY,revision INTEGER NOT NULL,active_version TEXT NOT NULL,draft_json TEXT NOT NULL,updated_at INTEGER NOT NULL)');
    $h->db->exec('CREATE TABLE mohe_ai_management_version(instance_key TEXT NOT NULL,version TEXT NOT NULL,document_json TEXT NOT NULL,document_hash TEXT NOT NULL,created_at INTEGER NOT NULL,action TEXT NOT NULL,source_version TEXT NOT NULL,PRIMARY KEY(instance_key,version))');
    $store=new AiManagementStore($h->db,'','fixture_r6');
    $property=new ReflectionProperty($h->gateway,'management');if(PHP_VERSION_ID<80100)$property->setAccessible(true);$property->setValue($h->gateway,$store);
    $h->auth['terminal']='platform';$h->context=$h->auth;$h->context['_refresh']=function()use($h){return $h->auth;};
    $h->boot=mgboot($h);
    $state=mgcall($h,'management_get');$baseline=$state['draft'];
    mgcheck($state['active_version']==='source','source baseline');
    $metricRegistry=mgcall($h,'metric_registry_get');
    mgcheck(($metricRegistry['registry_version']??'')===\app\services\query\metric\MetricDefinitionRegistry::VERSION
        && count($metricRegistry['items']??[])===11,'metric registry is a source-owned read-only catalog');
    $beforeModels=$h->models;$beforeQueries=$h->queries;
    foreach(['store','merchant'] as $terminal){$context=$h->context;$context['terminal']=$terminal;mgdeny(function()use($h,$context){$h->gateway->handle('management_get',$context,[]);},'AI_PERMISSION_DENIED');mgdeny(function()use($h,$context){$h->gateway->handle('metric_registry_get',$context,[]);},'AI_PERMISSION_DENIED');}
    $context=$h->context;$context['can_configure']=false;$context['_refresh']=function()use($context){return $context;};
    mgdeny(function()use($h,$context){$h->gateway->handle('management_get',$context,[]);},'AI_PERMISSION_DENIED');
    mgdeny(function()use($h,$context){$h->gateway->handle('config_get',$context,[]);},'AI_PERMISSION_DENIED');
    $h->auth['can_configure']=false;
    mgdeny(function()use($h){mgcall($h,'management_get');},'AI_PERMISSION_DENIED');
    mgdeny(function()use($h){mgcall($h,'config_get');},'AI_PERMISSION_DENIED');
    $h->auth['can_configure']=true;
    mgcheck($h->models===$beforeModels&&$h->queries===$beforeQueries,'management denials never call model/facts');

    // A running clarification retains its exact version across draft and publish.
    $old=$h->start('业绩多少');$h->step($old,'metric_code',1);
    $document=$baseline;$document['guidance']['max_rounds']=5;
    $document['guidance']['slot_order']=['start_date','metric_code','compare_start','rank_direction','rank_limit'];
    $document['guidance']['prompts']['start_date']='请选择本次查询的日期范围（新版）';
    $saved=mgcall($h,'management_save',['expected_revision'=>$state['revision'],'document'=>$document]);
    mgcheck(mgboot($h)['max_clarification_rounds']===3,'draft does not affect active bootstrap');
    $beforeModels=$h->models;$beforeQueries=$h->queries;
    $preview=mgcall($h,'management_preview',['expected_revision'=>$saved['revision'],'question'=>'业绩多少']);
    mgcheck($preview['kind']==='clarification'&&$preview['fields'][0]['key']==='start_date','preview uses draft order');
    mgcheck($h->models===$beforeModels&&$h->queries===$beforeQueries&&$preview['model_called']===false&&$preview['business_data_read']===false,'preview never calls model/facts');
    $published=mgcall($h,'management_publish',['expected_revision'=>$saved['revision']]);
    mgcheck($published['active_version']!=='source','publish activates a version');
    mgcheck(mgboot($h)['max_clarification_rounds']===5,'new bootstrap sees published rounds');
    $old=$h->choose($old,['metric_code'=>'consume_amount']);$h->step($old,'start_date',2);
    mgcheck($old['clarification']['max_clarification_rounds']===3,'old Run retains rounds');
    mgcheck($old['clarification']['question']!==$document['guidance']['prompts']['start_date'],'old Run retains old prompt');
    $old=$h->choose($old,['start_date'=>'2026-09-01','end_date'=>'2026-09-08']);
    mgcheck($old['status']==='COMPLETED','old Run completes under frozen source registry');
    $new=$h->start('业绩多少');$h->step($new,'start_date',1);
    mgcheck($new['clarification']['question']===$document['guidance']['prompts']['start_date'],'new Run uses new prompt and order');
    mgcheck($new['clarification']['max_clarification_rounds']===5,'new Run freezes new rounds');
    $new=$h->choose($new,['start_date'=>'2026-09-01','end_date'=>'2026-09-08']);$h->step($new,'metric_code',2);
    $new=$h->choose($new,['metric_code'=>'consume_amount']);mgcheck($new['status']==='COMPLETED','new order completes');

    // Even a not-yet-executed accepted Run keeps the prior published contract.
    $readyInput=['client_request_id'=>'ready-before-publish','conversation_id'=>'conversation1','client_session_id'=>'device1','window_token'=>$h->boot['window_token'],'question'=>'今天消耗业绩多少','history'=>[],'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2'];
    $ready=$h->gateway->handle('create',$h->context,$readyInput);
    // Disable a registered workflow: new Runs cannot access Tool or substitute shape.
    $document['workflows']['wf_performance_summary']['enabled']=false;mgpublish($h,$document);
    $ready=$h->gateway->handle('execute',$h->context,$h->binding($ready)+$readyInput,$ready['run_id']);
    mgcheck($ready['status']==='COMPLETED','accepted unexecuted Run retains pre-disable policy');
    $beforeQueries=$h->queries;$disabled=$h->start('今天消耗业绩多少');
    mgcheck($disabled['status']==='FAILED'&&$disabled['reason']==='AI_WORKFLOW_DISABLED','disabled workflow stops with explicit reason');
    mgcheck($h->queries===$beforeQueries,'disabled workflow never accesses facts');
    $state=mgcall($h,'management_get');$rolled=mgcall($h,'management_rollback',['expected_revision'=>$state['revision'],'target_version'=>'source']);
    mgcheck($rolled['active_version']!=='source'&&mgboot($h)['max_clarification_rounds']===3,'rollback creates new audited active version with source policy');
    $source=$h->start('9月1日到9月8日现金业绩合计多少');
    mgcheck($source['status']==='COMPLETED'&&isset($source['answer']['context_ref']),'source query available after restore');
    $h->auth['permission_version']='permission-revoked';$beforeQueries=$h->queries;
    $revoked=$h->start('那就换成消耗业绩，其他条件别动。',$source['answer']['context_ref']);
    mgcheck($revoked['status']==='FAILED'&&$h->queries===$beforeQueries,'published management cannot revive expired source authority');

    // A source upgrade must keep management reachable, require explicit admin
    // rebase + publish, and never silently execute a stale document.
    $state=mgcall($h,'management_get');$stale=$state['draft'];$stale['source_registry_hash']=str_repeat('0',64);
    $json=json_encode($stale,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$hash=\app\services\ai\registry\AiRegistryValue::hash($stale);
    $stmt=$h->db->prepare('UPDATE mohe_ai_management_state SET draft_json=? WHERE instance_key=?');$stmt->execute([$json,'fixture_r6']);
    $stmt=$h->db->prepare('UPDATE mohe_ai_management_version SET document_json=?,document_hash=? WHERE instance_key=? AND version=?');$stmt->execute([$json,$hash,'fixture_r6',$state['active_version']]);
    $staleState=mgcall($h,'management_get');mgcheck($staleState['source_changed']===true&&$staleState['active_document']===null,'stale source remains administrable but cannot appear active');
    $rebased=mgcall($h,'management_rebase',['expected_revision'=>$staleState['revision']]);mgcheck($rebased['source_changed']===true&&$rebased['draft']['source_registry_hash']!==$stale['source_registry_hash'],'rebase updates draft only');
    mgcall($h,'management_validate',['expected_revision'=>$rebased['revision']]);$republished=mgcall($h,'management_publish',['expected_revision'=>$rebased['revision']]);
    mgcheck($republished['source_changed']===false&&is_array($republished['active_document']),'explicit publish activates current source');

    // A maintainer without report authority can configure, but cannot ask for data.
    $h->auth['can_use']=false;$h->context['can_use']=false;
    mgcheck(is_array(mgcall($h,'management_get')),'management independent of report permission');
    mgcheck(is_array(mgcall($h,'config_get')),'basic configuration independent of report permission');
    mgdeny(function()use($h){$h->start('今天现金业绩多少');},'AI_PERMISSION_DENIED');
    echo 'R6 management gateway: '.$managementChecks." checks PASS (SQLite, synthetic model/facts; not live acceptance)\n";
} finally {if($h)$h->close();}

<?php
/** Offline real SQLite config persistence; no model, business database or live writes. */
$base=dirname(__DIR__,2).'/后端代码/app/services/ai/';
foreach(['contract/AiContractException','registry/AiRegistryValue','registry/AiBusinessManifest','registry/AiBusinessRegistry','management/AiManagementPolicy','management/AiManagementStore'] as $f) require_once $base.$f.'.php';
use app\services\ai\management\AiManagementPolicy as Policy;
use app\services\ai\management\AiManagementStore as Store;
$checks=0;
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}
function rejects(callable $f,string $reason){try{$f();}catch(Throwable $e){check($e->getMessage()===$reason,$e->getMessage().' != '.$reason);return;}throw new RuntimeException('Expected '.$reason);}
$d=Policy::defaults();check(Policy::validate($d)===$d,'defaults');$catalog=Policy::catalog();check(count($catalog['workflows'])===count(\app\services\ai\registry\AiBusinessManifest::definitions()['workflows']),'catalog follows the source-owned workflow registry');
check(($catalog['scenes']['store_operations']['runtime_skill_document']['source_hash']??null)===$catalog['scenes']['store_operations']['skill_source_hash'],'catalog keeps the exact readable Skill source with its scene');
check(($catalog['scenes']['store_operations']['runtime_skill_document']['markdown']??'')!=='' ,'catalog supplies runtime markdown even for scene-only clients');Policy::applyManifest($d);$checks++;
foreach(['sql','metric_codes','permissions','handler'] as $key){$bad=$d;$bad[$key]='arbitrary';rejects(function()use($bad){Policy::validate($bad);},'AI_MANAGEMENT_DOCUMENT_INVALID');}
$bad=$d;$bad['source_registry_hash']=str_repeat('0',64);rejects(function()use($bad){Policy::validate($bad);},'AI_MANAGEMENT_SOURCE_CHANGED');
$old=$bad;$old['guidance']['max_rounds']=5;$old['guidance']['prompts']['metric_code']='请选择业绩类型';
$rebased=Policy::rebase($old);check($rebased['source_registry_hash']===$d['source_registry_hash'],'rebase adopts current source hash');
check($rebased['guidance']['max_rounds']===5&&$rebased['guidance']['prompts']['metric_code']==='请选择业绩类型','rebase preserves editable guidance');
$legacyScene=$old;$legacyScene['scenes']=['registered_metric_analysis'=>$old['scenes']['store_operations']];$legacyScene['scenes']['registered_metric_analysis']['label']='旧版经营分析';
$legacyRebased=Policy::rebase($legacyScene);check($legacyRebased['scenes']['store_operations']['label']==='旧版经营分析','legacy Skill presentation rebases onto store operations');
$badSchema=$old;$badSchema['schema_version']='unknown';rejects(function()use($badSchema){Policy::rebase($badSchema);},'AI_MANAGEMENT_REBASE_INVALID');
foreach([2,6,'3'] as $round){$bad=$d;$bad['guidance']['max_rounds']=$round;rejects(function()use($bad){Policy::validate($bad);},'AI_MANAGEMENT_DOCUMENT_INVALID');}
foreach([3,4,5] as $round){$good=$d;$good['guidance']['max_rounds']=$round;check(Policy::validate($good)===$good,'legal rounds');}
$bad=$d;$bad['workflows']['wf_metric_definition']['allow_export']=true;rejects(function()use($bad){Policy::validate($bad);},'AI_MANAGEMENT_DOCUMENT_INVALID');
$bad=$d;$bad['workflows']['wf_performance_summary']['node_timeouts']['evidence']=0;rejects(function()use($bad){Policy::validate($bad);},'AI_MANAGEMENT_BUDGET_INVALID');
$bad=$d;$bad['workflows']['wf_performance_summary']['node_timeouts']['query']=11000;rejects(function()use($bad){Policy::validate($bad);},'AI_MANAGEMENT_BUDGET_INVALID');
$bad=$d;$bad['workflows']['wf_performance_summary']['max_path_ms']=100;rejects(function()use($bad){Policy::validate($bad);},'AI_MANAGEMENT_BUDGET_INVALID');
$bad=$d;$bad['guidance']['slot_order'][0]='start_date';rejects(function()use($bad){Policy::validate($bad);},'AI_MANAGEMENT_DOCUMENT_INVALID');
$bad=$d;$bad['guidance']['prompts']['metric_code']='<script>';rejects(function()use($bad){Policy::validate($bad);},'AI_MANAGEMENT_DOCUMENT_INVALID');
$good=$d;$good['guidance']['slot_order']=['start_date','metric_code','compare_start','rank_direction','rank_limit'];
$env=['kind'=>'clarification','fields'=>[['key'=>'metric_code','options'=>[['value'=>'cash','label'=>'Cash']]]],'pending_fields'=>[['key'=>'start_date'],['key'=>'end_date']],'resolved_metrics'=>[]];
$out=Policy::decorateEnvelope($good,$env);check(array_column($out['fields'],'key')===['start_date','end_date'],'date pair together');check($out['pending_fields'][0]===$env['fields'][0],'options unchanged');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$store=new Store($pdo,'t_','local');check($store->active()['version']==='source','missing table source');
$pdo->exec('CREATE TABLE t_mohe_ai_management_state(instance_key TEXT PRIMARY KEY,revision INTEGER NOT NULL,active_version TEXT NOT NULL,draft_json TEXT NOT NULL,updated_at INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE t_mohe_ai_management_version(instance_key TEXT NOT NULL,version TEXT NOT NULL,document_json TEXT NOT NULL,document_hash TEXT NOT NULL,created_at INTEGER NOT NULL,action TEXT NOT NULL,source_version TEXT NOT NULL,PRIMARY KEY(instance_key,version))');
$rebaseStore=new Store($pdo,'t_','rebase');$rebaseState=$rebaseStore->read();
$stmt=$pdo->prepare('UPDATE t_mohe_ai_management_state SET draft_json=? WHERE instance_key=?');$stmt->execute([json_encode($old,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'rebase']);
$rebaseState=$rebaseStore->rebaseDraft($rebaseState['revision']);check($rebaseState['revision']===2&&$rebaseState['draft']===$rebased,'stale draft rebased atomically');
$state=$store->read();check($state['revision']===1&&$state['active_version']==='source','initial');$good['guidance']['max_rounds']=5;
$state=$store->saveDraft(1,$good);check($state['revision']===2,'saved');check((new Store($pdo,'t_','local'))->read()['draft']===$good,'refresh persistent');check($store->active()['document']===$d,'draft not live');
rejects(function()use($store,$d){$store->saveDraft(1,$d);},'AI_MANAGEMENT_REVISION_CONFLICT');check($store->validateDraft(2)['valid'],'validate');
$state=$store->publish(2);$v=$state['active_version'];check($state['revision']===3&&$v!=='source','published');check($store->active()['document']===$good,'live published');
$store->saveDraft(3,$d);check($store->version($v)['document']===$good,'old immutable');$state=$store->rollback(4,'source');check($state['revision']===5&&$state['active_version']!==$v,'rollback new version');check($store->active()['document']===$d,'rollback source');check($store->version($v)['document']===$good,'old run freeze');
$other=new Store($pdo,'t_','other');rejects(function()use($other,$v){$other->version($v);},'AI_MANAGEMENT_VERSION_NOT_FOUND');check($other->active()['version']==='source','instance isolated');
rejects(function()use($store){$store->publish(4);},'AI_MANAGEMENT_REVISION_CONFLICT');check(count($store->read()['versions'])===2,'no ghost version');
$pdo->exec("UPDATE t_mohe_ai_management_version SET document_hash='bad' WHERE instance_key='local'");rejects(function()use($store){$store->active();},'AI_MANAGEMENT_DOCUMENT_CORRUPT');
echo "PASS management core: {$checks} checks\n";

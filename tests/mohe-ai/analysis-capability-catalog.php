<?php
/** Pure capability composition, no model, application boot or business database. */
use app\services\query\metric\AnalysisCapabilityCatalog as Catalog;
use app\services\query\metric\AnalysisCapabilityCatalogFactory as Factory;
$checks=0;
function verify($condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function rejects(callable $call): void { try { $call(); } catch (RuntimeException $e) { verify($e->getMessage()==='ANALYSIS_CAPABILITY_CONTRACT_INVALID','contract rejection'); return; } throw new RuntimeException('Expected rejection'); }
$definition=['code'=>'learning_progress','name'=>'学习进度','summary'=>'已登记学习进度','user_ready'=>true,'dev_source'=>'DO_NOT_EXPOSE','aliases'=>['private_field']];
$binding=['capability_code'=>'education.progress','metric_code'=>'learning_progress','object_kind'=>'person','operations'=>['summary','ranking'],'filter_keys'=>['position'],'relation_role'=>'learner','contract_version'=>'learning-v1'];
$catalog=new Catalog([$definition, ['code'=>'unknown_result','name'=>'待确认','user_ready'=>false]],[$binding]);
$inventory=$catalog->inventory();
verify(count($inventory['items'])===2,'all dictionary definitions included, no fixed metric list');
verify(strpos(json_encode($inventory),'DO_NOT_EXPOSE')===false && strpos(json_encode($inventory),'private_field')===false,'technical fields not disclosed');
verify($inventory['items'][0]['status']==='provider_registered','provider exists, not proof of runtime readiness');
verify($inventory['items'][1]['status']==='definition_pending','unconfirmed definition never promoted');
$request=['metric_codes'=>['learning_progress'],'object_kind'=>'person','operation'=>'ranking','filter_keys'=>['position'],'relation_role'=>'learner'];
$allow=static function(array $binding): bool { return $binding['capability_code']==='education.progress'; };
verify($catalog->discover($request,$allow)['complete_request_supported'],'new domain works without new scene');
verify(!$catalog->discover($request,static function(): bool { return false; })['items'],'authorization rejection hides candidates');
verify(!$catalog->discover($request,static function() { return 1; })['items'],'authorization must explicitly return true');
foreach (['object_kind'=>'store','operation'=>'forecast','relation_role'=>'seller','filter_keys'=>['position','category'],'metric_codes'=>['learning_progress','missing']] as $key=>$value) {
    $bad=$request;$bad[$key]=$value;verify(!$catalog->discover($bad,$allow)['complete_request_supported'],'complete condition preserved: '.$key);
}
$bad=$request;$bad['sql']='select 1';rejects(static function()use($catalog,$bad,$allow){$catalog->discover($bad,$allow);});
$bad=$request;$bad['metric_codes']=['learning_progress','learning_progress'];rejects(static function()use($catalog,$bad,$allow){$catalog->discover($bad,$allow);});
rejects(static function()use($definition,$binding){new Catalog([$definition],[$binding,$binding]);});
$changed=$definition;$changed['summary']='新口径';verify((new Catalog([$changed, ['code'=>'unknown_result','name'=>'待确认','user_ready'=>false]],[$binding]))->fingerprint()!==$catalog->fingerprint(),'definition update invalidates catalog');
$only=new Catalog([$definition],[]);verify($only->inventory()['items'][0]['status']==='definition_only','definition does not grant query');
$real=Factory::make()->inventory();$map=array_column($real['items'],null,'metric_code');
verify(isset($map['staff_labor_yeji']) && isset($map['staff_sales_yeji']),'real person dictionary discovered');
verify($map['staff_labor_yeji']['status']==='provider_registered','personnel adapter is explicit, not inferred from fact presence');
verify($map['cash_performance']['status']==='provider_registered','existing provider reused');
verify($real['instance_readiness_verified']===false,'no invented instance readiness');
echo "PASS analysis capability catalog: {$checks} checks\n";

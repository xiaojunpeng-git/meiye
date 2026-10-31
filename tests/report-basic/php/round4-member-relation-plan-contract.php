<?php
declare(strict_types=1);
require __DIR__.'/../../../后端代码/database/upgrades/2026-10-30-010报表会员关系补齐/plan.php';
function r4check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$members=[];foreach([975,6787,9052,9647]as $uid)$members[]=['uid'=>$uid,'belong_store_id'=>4,'status'=>1,'is_del'=>0,'delete_time'=>null];
$p=report010MemberRelationPlan($members,[]);r4check(count($p['insert'])===4&&!$p['blocked'],'Missing four planned');
$relations=[['id'=>99,'uid'=>975,'store_id'=>4,'status'=>1],['id'=>100,'uid'=>975,'store_id'=>2,'status'=>1]];
$p=report010MemberRelationPlan($members,$relations);r4check(count($p['insert'])===3&&count($p['already_present'])===1,'Retry preserves other store');
$relations[0]['status']=0;$p=report010MemberRelationPlan($members,$relations);r4check(count($p['blocked'])===1,'Inactive relation is not silently enabled');
$relations[0]['status']=1;$relations[]=$relations[0];$p=report010MemberRelationPlan($members,$relations);r4check(count($p['blocked'])===1,'Duplicate relation blocks');
$members[0]['belong_store_id']=7;$p=report010MemberRelationPlan($members,[]);r4check(count($p['blocked'])===1,'Member transfer blocks stale plan');
echo "round4-member-relation-plan-contract PASS\n";

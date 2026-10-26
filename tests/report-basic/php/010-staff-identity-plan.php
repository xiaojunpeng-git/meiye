<?php
declare(strict_types=1);

/** Permanent synthetic PHP tests: no framework, DB, SSH or real PII. Phone
 * grouping is explicitly approved; source staff/history/status never change. */
require_once dirname(__DIR__,3).'/scripts/lib/r51-010-staff-identity-plan.php';
$checks=0;
function check51(bool $ok,string $label): void
{
    global $checks;
    if(!$ok)throw new RuntimeException($label);
    ++$checks;
}
function row51(int $id=19,array $replace=[]): array
{
    return array_replace(['id'=>$id,'store_id'=>1,'status'=>1,'is_del'=>0,'position'=>4,'uid'=>0,
        'add_time'=>100,'phone'=>'13900000019','employee_id'=>null,'staff_name'=>'PRIVATE_NAME',
        'avatar'=>'PRIVATE_AVATAR','account'=>'PRIVATE_ACCOUNT','pwd'=>'PRIVATE_PASSWORD','roles'=>'PRIVATE_RULES'],$replace);
}
function plan51(array $s,array $t,array $e=[],array $j=[]): array
{
    $result=R51StaffIdentityPlanner::plan($s,$t,$e,$j);
    check51(array_keys($result)===['private','report'],'Only stable private/report envelope');
    check51(array_keys($result['private'])===['employee_inserts','staff_links'],'Only employee INSERT and staff link private operations');
    $view=$result['report'];
    // Test-only non-PII view derived from the canonical private payload. The
    // assertions do NOT depend on optional report.operations compatibility.
    $view['operations']=['create_employee'=>array_map(static function($person){return [
        'source_staff_id'=>$person['id'],'employee_id'=>$person['id'],
        'employee_status'=>$person['status'],'employee_is_del'=>$person['is_del'],'uid'=>$person['uid']];
    },$result['private']['employee_inserts']),'link_staff'=>$result['private']['staff_links']];
    return $view;
}
function reason51(array $p,string $reason): bool
{
    foreach($p['isolated']as$r)if(in_array($reason,$r['reasons'],true))return true;
    return false;
}
function collateral51(array $p): void
{
    foreach(['jobs','accounts','permissions','organizations','historical_facts']as$k)check51(in_array($k,$p['forbidden_operations'],true),'No '.$k);
    check51($p['side_effects']===false,'No side effects');
}
function replay51(array $sources,array $targets,array $employees=[],array $jobs=[]): array
{
    $before=serialize([$sources,$targets,$employees,$jobs]);
    $plan=plan51($sources,$targets,$employees,$jobs);
    check51(!$plan['blocked']&&!$plan['isolated'],'Replay fixture safe');
    $private=R51StaffIdentityPlanner::plan($sources,$targets,$employees,$jobs)['private'];
    foreach($private['employee_inserts']as$person)$employees[]=$person;
    foreach($targets as&$target)foreach($private['staff_links']as$link)if($target['id']===$link['staff_id'])$target['employee_id']=$link['employee_id'];
    unset($target);
    $again=plan51($sources,$targets,$employees,$jobs);
    check51(!$again['blocked']&&!$again['isolated'],'Replanned state valid');
    check51($again['operations']['create_employee']===[]&&$again['operations']['link_staff']===[],'Replanning is zero operations');
    collateral51($again);
    return [$plan,$again,$targets,$employees,$before];
}

$s=row51();$serialized=serialize($s);
$good=plan51([$s],[$s]);
check51($good===plan51([$s],[$s]),'Repeated input stable');
check51(serialize($s)===$serialized,'Input never mutated');
check51($good['operations']['create_employee']===[['source_staff_id'=>19,'employee_id'=>19,'employee_status'=>1,'employee_is_del'=>0,'uid'=>null]],'Executor metadata exact API');
check51($good['operations']['link_staff']===[['staff_id'=>19,'employee_id'=>19,'expected_employee_id'=>null]],'CAS links exact API');
collateral51($good);
$envelope=R51StaffIdentityPlanner::plan([$s],[$s],[],[]);
$private=$envelope['private'];
check51($envelope['report']['input_sha256']===$good['input_sha256'],'Stable report input digest');
check51($private['employee_inserts'][0]['phone']===$s['phone']&&$private['employee_inserts'][0]['uid']===null,'Only private payload contains phone; UID NULL');
foreach(['13900000019','PRIVATE_NAME','PRIVATE_AVATAR','PRIVATE_ACCOUNT','PRIVATE_PASSWORD','PRIVATE_RULES']as$v)check51(strpos(json_encode($good),$v)===false,'Public report has no private input');
check51(strpos(json_encode($private),'PRIVATE_PASSWORD')===false&&strpos(json_encode($private),'PRIVATE_RULES')===false,'Private payload excludes credentials/grants');
replay51([$s],[$s]);

// Same exact phone across stores and departed/deleted rows is one person.
// Even a departed lowest ID remains the stable identity key for a new group.
$old=row51(19,['status'=>0,'is_del'=>1,'store_id'=>2,'uid'=>88]);
$current=row51(24,['store_id'=>3,'uid'=>77]);
[$group,$again,$linked,$people]=replay51([$current,$old],[$current,$old]);
check51(count($group['operations']['create_employee'])===1&&count($group['operations']['link_staff'])===2,'Duplicate phone group merges identity only');
check51($group['operations']['create_employee'][0]['employee_id']===19,'Minimum ALL-source ID, not minimum active ID');
check51($people[0]['uid']===null&&$people[0]['status']===1&&$people[0]['is_del']===0,'Group state derives from any active source, no member UID copy');
check51($linked[1]['status']===0&&$linked[1]['is_del']===1&&$linked[1]['store_id']===2&&$linked[1]['id']===19,'Departed staff never revived/moved/renumbered');
check51(count($group['mappings'])===2&&$group['mappings'][0]['source_staff_id']===19&&$group['mappings'][1]['employee_id']===19,'Exact source-to-target employee mapping');
$inactive=row51(24,['status'=>0]);$deleted=row51(19,['status'=>0,'is_del'=>1]);
[$inactivePlan,$inactiveReplay,$inactiveTargets,$inactivePeople]=replay51([$inactive,$deleted],[$inactive,$deleted]);
check51($inactivePeople[0]['status']===0&&$inactivePeople[0]['is_del']===0,'Pure inactive group not activated');
$deleted2=$inactive;$deleted2['is_del']=1;
[$deletedPlan,$deletedReplay,$deletedTargets,$deletedPeople]=replay51([$deleted,$deleted2],[$deleted,$deleted2]);
check51($deletedPeople[0]['status']===0&&$deletedPeople[0]['is_del']===1,'Pure deleted group stays deleted; replay accepts it');

// Unique existing employee phone is authoritative, even if numeric ID differs.
// Existing UID/profile/state are retained, not overwritten by new-employee policy.
$existing=['id'=>99,'phone'=>$s['phone'],'name'=>'EXISTING_PRIVATE','uid'=>42,'status'=>0,'is_del'=>1];
[$reuse,$reuseReplay,$reuseTargets,$reusePeople]=replay51([$s],[$s],[$existing]);
check51($reuse['operations']['create_employee']===[]&&$reuse['operations']['link_staff'][0]['employee_id']===99,'Unique existing phone reuses ID99');
check51($reusePeople===[$existing],'Existing profile/UID/state unchanged');
$occupied=['id'=>19,'phone'=>'13900000999','uid'=>null,'status'=>1,'is_del'=>0];
$conflict=plan51([$s],[$s],[$occupied]);
check51($conflict['blocked']&&reason51($conflict,'EMPLOYEE_ID_OCCUPIED'),'Minimum ID occupied by other phone blocks');
check51($conflict['operations']['create_employee']===[]&&R51StaffIdentityPlanner::plan([$s],[$s],[$occupied],[])['private']['employee_inserts']===[],'Blocked has no executable private writes');
$duplicate=$existing;$duplicate['id']=100;
check51(reason51(plan51([$s],[$s],[$existing,$duplicate]),'EMPLOYEE_PHONE_NOT_UNIQUE'),'Multiple existing employees by phone block, never guess');
$wrong=$s;$wrong['employee_id']=98;
check51(plan51([$s],[$wrong],[$existing])['blocked'],'Wrong existing staff identity blocks');
$right=$s;$right['employee_id']=99;
check51(!plan51([$s],[$right],[$existing])['blocked'],'Correct non-equal staff/employee ID supported');
$job=['id'=>1,'staff_id'=>19,'employee_id'=>98,'store_id'=>1];
check51(reason51(plan51([$s],[$s],[$existing],[$job]),'PREEXISTING_JOB_IDENTITY_MISMATCH'),'Conflicting job not rewritten');
$job['employee_id']=99;
check51(!plan51([$s],[$s],[$existing],[$job])['blocked'],'Compatible job kept, no job created');

// All required source/target structure and raw phones must be unchanged.
check51(reason51(plan51([],[$s]),'SOURCE_PRIMARY_KEY_MISSING'),'No exact source rejected');
check51(reason51(plan51([$s],[]),'TARGET_PRIMARY_KEY_MISSING'),'No exact target rejected');
foreach(['store_id'=>2,'status'=>0,'is_del'=>1,'position'=>2,'uid'=>99,'add_time'=>101]as$k=>$v){
    $drift=$s;$drift[$k]=$v;
    $p=plan51([$s],[$drift]);
    check51($p['blocked']&&reason51($p,'STRUCTURE_MISMATCH_'.strtoupper($k)),'No structural drift '.$k);
}
$changedPhone=$s;$changedPhone['phone']='13900000024';
check51(reason51(plan51([$s],[$changedPhone]),'PHONE_SOURCE_TARGET_MISMATCH'),'No target phone mismatch');
foreach([' 13900000019','13900000019 ','13900000019'."\n",'139 00000019','+8613900000019','',13900000019]as$v){
    $bad=$s;$bad['phone']=$v;
    check51(reason51(plan51([$bad],[$bad]),'INVALID_STRICT_PHONE'),'Illegal phone isolated, no trim/coercion');
}

// The11 originally mapped managers must remain exactly as before.
$managerSources=[];$managerTargets=[];$managerEmployees=[];
foreach([1,2,3,4,5,6,7,8,9,10,106]as$id){
    $r=row51($id,['phone'=>'138'.str_pad((string)$id,8,'0',STR_PAD_LEFT),'employee_id'=>$id,'is_admin'=>1,'level'=>0]);
    $managerSources[]=$r;$managerTargets[]=$r;
    $managerEmployees[]=['id'=>$id,'phone'=>$r['phone'],'uid'=>null,'status'=>1,'is_del'=>0];
}
$managerOriginal=serialize([$managerSources,$managerTargets,$managerEmployees]);
$managers=plan51($managerSources,$managerTargets,$managerEmployees);
check51(!$managers['blocked']&&count($managers['skipped'])===11,'All11 managers unchanged');
check51(!$managers['operations']['create_employee']&&!$managers['operations']['link_staff'],'Managers zero changes');
check51(serialize([$managerSources,$managerTargets,$managerEmployees])===$managerOriginal,'Manager input credentials/profile unchanged');
$unmapped=$managerTargets[0];$unmapped['employee_id']=null;
check51(plan51([$managerSources[0]],[$unmapped],[$managerEmployees[0]])['blocked'],'Never repair a broken manager silently');

// Active/inactive full synthetic249-source dryrun and zero-write replay.
$all=$managerSources;$allTargets=$managerTargets;
for($i=0;$i<238;++$i){
    $id=200+$i;
    $row=row51($id,['phone'=>'137'.str_pad((string)intdiv($i,2),8,'0',STR_PAD_LEFT),
        'status'=>$i%3===0?0:1,'is_del'=>$i%5===0?1:0,'store_id'=>1+($i%11)]);
    $all[]=$row;$allTargets[]=$row;
}
[$allPlan,$allReplay]=replay51($all,$allTargets,$managerEmployees);
check51(count($allPlan['mappings'])===249&&count($allPlan['operations']['link_staff'])===238,'All249 source staff precisely resolved');
check51(count($allPlan['operations']['create_employee'])===119,'238 missing staff form119 exact phone groups');
check51($allReplay['counts']['already_mapped']===249,'All249 idempotent');
$flipped=static function($r){return array_reverse($r,true);};
$permuted=plan51(array_map($flipped,array_reverse($all)),array_map($flipped,array_reverse($allTargets)),array_reverse($managerEmployees));
check51($allPlan===$permuted,'Input ordering/key ordering stable digest and full report');
$changed=$s;$changed['roles']='DIFFERENT_PRIVATE_ROLE';
check51(plan51([$changed],[$s])['input_sha256']!==$good['input_sha256'],'Raw unknown fields included in digest');
check51(plan51([$s,$s],[$s])['blocked'],'Duplicate source PK blocks');
check51(plan51([$s],[$s],[$existing,$existing])['blocked'],'Duplicate employee PK blocks');
check51(plan51([$s],[$s],[],[$job,$job])['blocked'],'Duplicate job PK blocks');
collateral51($allPlan);collateral51($managers);collateral51($conflict);
echo json_encode(['result'=>'PASS','checks'=>$checks,'remote_connections'=>0,'database_connections'=>0,'writes'=>0,'pii_reported'=>false]),PHP_EOL;

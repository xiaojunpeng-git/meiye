<?php
/** Synthetic local population and drilldown reconciliation; every inserted row is rolled back. */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',$e->getMessage().' '.$e->getFile().':'.$e->getLine().PHP_EOL);exit(1);});
use think\facade\Db;
use app\services\report\EmployeeDashboardPopulationServices;
use app\services\report\EmployeeDashboardServices;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function seed(string $table,array $r):array{foreach(Db::query('SHOW COLUMNS FROM eb_'.$table)as$f){$k=$f['Field'];if(array_key_exists($k,$r)||$f['Default']!==null||$f['Null']==='YES'||strpos($f['Extra'],'auto_increment')!==false)continue;$r[$k]=preg_match('/int|decimal|float|double/',$f['Type'])?0:'';}return $r;}
function add(string $t,array $r):int{if(in_array($t,['staff_job_position','staff_tenure_period'],true))$r['request_token']=bin2hex(random_bytes(16));return (int)Db::name($t)->insertGetId(seed($t,$r));}
function ok($v,string $msg):void{if(!$v)throw new RuntimeException($msg);echo "PASS $msg\n";}
$tag='drill-'.bin2hex(random_bytes(4));$pop=new EmployeeDashboardPopulationServices();$svc=new EmployeeDashboardServices();$range=['start'=>'2026-11-01','end'=>'2026-11-06'];
// Exercise the actual controller boundary before any population query.
$controllerReflection = new ReflectionClass(app\controller\admin\v1\report\UnifiedReport::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$adminProperty = $controllerReflection->getProperty('adminId'); $adminProperty->setAccessible(true); $adminProperty->setValue($controller, 0);
try { $controller->employeeDashboardPeople(new app\Request(), $svc); throw new RuntimeException('UNGUARDED_ENDPOINT'); }
catch (mohe\exceptions\AdminException $e) { ok(true, 'actual people endpoint denies missing personnel identity'); }
Db::startTrans();try{
$s1=add('system_store',['name'=>$tag,'is_del'=>0,'is_show'=>1]);$s2=add('system_store',['name'=>$tag.'outside','is_del'=>0,'is_show'=>1]);
$p1=add('position',['name'=>'美容师','status'=>1]);$p2=add('position',['name'=>'美容师助理','status'=>1]);$p3=add('position',['name'=>'店长','status'=>1]);$employees=[];$staff=[];
for($i=0;$i<27;$i++){
 $e=add('employee',['name'=>$tag.'-'.$i,'education'=>'高中以下','phone'=>'199'.random_int(10000000,99999999),'status'=>1,'is_del'=>0]);$employees[]=$e;
 $ss=add('system_store_staff',['employee_id'=>$e,'store_id'=>$i===26?$s2:$s1,'staff_name'=>$tag.'-'.$i,'status'=>1,'is_del'=>0,'cashier_craftsman_enabled'=>$i<25?0:1,'age'=>40,'join_date'=>'2026-02-01']);$staff[]=$ss;
 add('staff_job_position',['employee_id'=>$e,'staff_id'=>$ss,'store_id'=>$i===26?$s2:$s1,'position_id'=>$i<25?$p1:$p2,'status'=>1,'is_del'=>0,'end_time'=>0]);
}
// Duplicate assignments and cross-store memberships must not inflate a person count.
add('staff_job_position',['employee_id'=>$employees[0],'staff_id'=>$staff[0],'store_id'=>$s1,'position_id'=>$p3,'status'=>1,'is_del'=>0,'end_time'=>0]);
$duplicate=add('system_store_staff',['employee_id'=>$employees[0],'store_id'=>$s2,'status'=>1,'is_del'=>0]);add('staff_job_position',['employee_id'=>$employees[0],'staff_id'=>$duplicate,'store_id'=>$s2,'position_id'=>$p1,'status'=>1,'is_del'=>0,'end_time'=>0]);
$active=$pop->active([$s1],'',$range['end']);ok(count($active)===26,'authorized store population');ok(count($pop->active([$s1,$s2],'',$range['end']))===27,'cross-store employee deduplication');
$beauty=$pop->active([$s1],'美容师',$range['end']);ok(count($beauty)===25,'exact actual job name excludes assistant and ignores qualification flag');ok(count($pop->select($active,'beautician_count'))===25,'beautician card agrees with actual job filter');
ok($svc->people([$s1],$range,['kind'=>'total','_employee_id'=>$employees[0]])['count']===1,'personal permission cannot expose coworkers');
ok($svc->people([$s1],$range,['kind'=>'total','_employee_id'=>$employees[26]])['count']===0,'personal identity cannot widen store scope');
$first=$svc->people([$s1],$range,['kind'=>'beautician_count','page'=>1]);$second=$svc->people([$s1],$range,['kind'=>'beautician_count','page'=>2]);ok($first['count']===25&&count($first['list'])===20&&count($second['list'])===5,'pagination 20 plus remainder with full total');ok(!array_intersect(array_column($first['list'],'employee_id'),array_column($second['list'],'employee_id')),'stable pages do not overlap');
ok($svc->people([$s1],$range,['kind'=>'beautician_count','keyword'=>$tag.'-25'])['count']===0,'additional search only narrows cohort');
foreach(['education'=>'4','age'=>'2','tenure'=>'0']as$kind=>$bucket)ok($svc->people([$s1],$range,['kind'=>$kind,'bucket'=>$bucket,'role'=>'美容师'])['count']===25,'precise '.$kind.' bucket');
foreach([25=>0,26=>1,35=>1,36=>2,45=>2,46=>3,55=>3,56=>4]as$age=>$bucket)ok($pop->profile(['age'=>$age],$range['end'])['age_bucket']===$bucket,'age boundary '.$age);
ok($pop->profile([],$range['end'])['age_bucket']===null&&$pop->profile([],$range['end'])['tenure_bucket']===null,'missing age and tenure remain missing');
$end=strtotime('2026-11-03');add('staff_tenure_period',['employee_id'=>$employees[0],'staff_id'=>$staff[0],'store_id'=>$s1,'status'=>0,'is_del'=>0,'action'=>'leave','end_time'=>$end]);
add('staff_job_position',['employee_id'=>$employees[0],'staff_id'=>$staff[0],'store_id'=>$s1,'position_id'=>$p1,'status'=>0,'is_del'=>0,'end_time'=>$end]);
add('staff_tenure_period',['employee_id'=>$employees[1],'staff_id'=>$staff[1],'store_id'=>$s1,'status'=>0,'is_del'=>0,'action'=>'transfer','end_time'=>$end]);
$leave=$svc->people([$s1],$range,['kind'=>'departures','role'=>'美容师']);ok($leave['count']===1&&$leave['list'][0]['employee_id']===$employees[0],'leave fact retained for rehired employee; transfer excluded');
$full=$svc->dashboard([$s1],$range,['role'=>'美容师']);
ok(in_array('美容师助理',$full['role_options'],true)&&in_array('店长',$full['role_options'],true),'role options use actual scoped job names');
foreach($full['overview']['cards']as$c)if(isset($c['drilldown']))ok($svc->people([$s1],$range,$c['drilldown'])['count']===$c['value'],'server drill descriptor reconciles '.$c['key']);
foreach($full['roles']as$row)ok($svc->people([$s1],$range,$row['drilldown'])['count']===$row['value'],'position drill descriptor reconciles');
$overview=new ReflectionMethod($svc,'overview');$overview->setAccessible(true);$report=$overview->invoke($svc,$beauty,$range['end'],1);
foreach($report['cards']as$c){if(in_array($c['key'],['total','active_staff','beautician_count','departures'],true))ok($svc->people([$s1],$range,['kind'=>$c['key'],'role'=>'美容师'])['count']===$c['value'],'card and details reconcile '.$c['key']);}
foreach(['education'=>'education','ages'=>'age','tenure'=>'tenure']as$section=>$kind)foreach($report[$section]as$i=>$row)ok($svc->people([$s1],$range,['kind'=>$kind,'bucket'=>(string)$i,'role'=>'美容师'])['count']===$row['value'],'bucket reconciles '.$section.' '.$i);
try{$svc->people([$s1],$range,['kind'=>'age','bucket'=>'arbitrary']);throw new RuntimeException('UNSAFE_FILTER');}catch(InvalidArgumentException $e){ok(true,'invalid drilldown fails closed');}
ok($svc->people([],$range,['kind'=>'total'])['count']===0,'empty authorized scope never expands');
}finally{Db::rollback();}
ok(!Db::name('employee')->whereLike('name',$tag.'%')->count(),'all synthetic data rolled back');

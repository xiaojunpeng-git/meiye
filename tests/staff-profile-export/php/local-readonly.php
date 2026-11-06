<?php
/** Local-only, read-only reconciliation against the exact editor source. */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;use app\services\employee\EmployeeProfileExportServices;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function ok($v,$label){if(!$v)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
$s=new EmployeeProfileExportServices();$r=$s->project(['employee_id'=>1,'staff_id'=>2,'staff_name'=>'=TEST','phone'=>'0012345','id_card'=>'001234567890123456','employee_number'=>'00001','pwd'=>'secret','token'=>'secret','status'=>0,'employment_type_code'=>'partner','cashier_salesperson_enabled'=>1,'cashier_craftsman_enabled'=>0,'mobile_enabled'=>1,'is_customer'=>0,'salary_status'=>1,'birthday_type'=>1,'jobs'=>[['position_name'=>'店长'],['position_name'=>'店长']],'scope'=>['scope_mode'=>'personal'],'departure_records'=>[['left_at_text'=>'2026-11-01','store_text'=>'原店','operator_name'=>'经办人','reason'=>'离职']]],true);
ok(count($r)===35,'all 35 approved business columns');ok(!isset($r['pwd'])&&!isset($r['token']),'credentials excluded');ok($r['phone']==='0012345'&&$r['id_card']==='001234567890123456'&&$r['employee_number']==='00001','long identifiers remain exact text');ok($r['position_names']==='店长','actual job names deduplicated');ok($r['status']==='离职'&&$r['employment_type_code']==='合作方','status and employment type translated');ok($r['scope_mode']==='个人','scope semantics');ok(strpos($r['departure_records'],'经办人')!==false,'departure fields included');ok($s->project(['employment_type_code'=>'partner'],false)['employment_type_code']==='无权限查看','field permission preserved');
$ss=Db::name('system_store_staff')->alias('ss')->join('employee e','e.id=ss.employee_id')->where('ss.is_del',0)->where('ss.status',1)->where('e.is_del',0)->field('ss.id,ss.employee_id')->find();
$d=app()->make(app\services\employee\EmployeePersonCompleteWriteServices::class)->getComplete((int)$ss['employee_id'],(int)$ss['id'],'hq',true);
$export=$s->page(['list'=>[$ss],'count'=>101],true);ok($export['count']===101&&count($export['export'])===1,'page rows and full matched count preserved');
foreach(['staff_name','phone','avatar','account','education','id_card','join_date','birthday_date']as$k)ok($export['export'][0][$k]===(string)($d[$k]??''),'editor reconciliation '.$k);
ok(count($s->page(['list'=>[],'count'=>0],true)['header'])===35,'empty results retain complete header');

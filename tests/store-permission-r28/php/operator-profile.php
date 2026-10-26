<?php
/** 本地本人档案读取验证：真实登录身份对账姓名、手机号与有效岗位；只写临时验证文档，不改业务表。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
$c=config('database.connections.mysql');if($c['hostname']!=='mysql'||$c['database']!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
$s=json_decode(file_get_contents('/tmp/r28-browser-sessions.json'),true);
$profile=app()->make(app\services\cashier\LoginServices::class)->parseToken($s['cashier']);
$method=new ReflectionMethod(app\services\cashier\v3\projection\CashierV3RootDomainAssembler::class,'operatorDetails');$method->setAccessible(true);
$service=new app\services\cashier\v3\projection\CashierV3RootDomainAssembler();$actual=$method->invoke($service,$profile);
$employee=Db::name('employee')->where('id',(int)$profile['employee_id'])->field('name,phone')->find();
if($actual['name']!==trim((string)$employee['name'])||$actual['phone']!==trim((string)$employee['phone']))throw new RuntimeException('SELF_PROFILE_MISMATCH');
$names=Db::name('staff_job_position')->alias('j')->join('position p','p.id=j.position_id')->where('j.staff_id',(int)$profile['id'])->where('j.employee_id',(int)$profile['employee_id'])->where('j.is_del',0)->where('j.status',1)->where('j.end_time',0)->where('p.status',1)->order('j.id')->column('p.name');
if($actual['positions']!==array_values(array_unique(array_filter(array_map('trim',$names)))))throw new RuntimeException('SELF_POSITIONS_MISMATCH');
$empty=$method->invoke($service,['staff_name'=>'Test operator']);if($empty!==['name'=>'Test operator','phone'=>'','positions'=>[]])throw new RuntimeException('MISSING_PROFILE_FALLBACK_INVALID');
file_put_contents('/tmp/r28-operator-expected.json',json_encode($actual,JSON_UNESCAPED_UNICODE));chmod('/tmp/r28-operator-expected.json',0600);echo "PASS authenticated employee name/phone and effective jobs match authority; missing fields remain empty\n";

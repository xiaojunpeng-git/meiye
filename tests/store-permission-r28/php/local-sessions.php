<?php
/** 仅本地验证用：签发隔离浏览器的短期测试会话，不修改账号密码，不打印或归档令牌。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
$c=config('database.connections.mysql');if($c['hostname']!=='mysql'||$c['database']!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
$a=think\facade\Db::name('system_admin')->where('level',0)->where('is_del',0)->where('status',1)->find();
$admin=app()->make(app\services\system\admin\SystemAdminServices::class)->createToken((int)$a['id'],'admin',(string)$a['pwd']);
$resolver=app()->make(app\services\cashier\v3\permission\CashierV3FeatureResolver::class);
$s=null;foreach(think\facade\Db::name('system_store_staff')->where('status',1)->where('is_del',0)->where('employee_id','>',0)->order('id')->select()->toArray() as $row){$features=$resolver->resolveGrantedFeatures($row);if(in_array('cashier.v3.cashier',$features)&&in_array('cashier.v3.management.staff',$features)){$s=$row;break;}}
if(!$s)throw new RuntimeException('NO_LOCAL_TEST_STAFF');
$cashier=app()->make(app\services\cashier\LoginServices::class)->createToken((int)$s['id'],'cashier_v3',(string)$s['pwd']);
file_put_contents('/tmp/r28-browser-sessions.json',json_encode(['admin'=>$admin['token'],'cashier'=>$cashier['token'],'storeId'=>$s['store_id']]));chmod('/tmp/r28-browser-sessions.json',0600);echo "LOCAL_SESSIONS_READY\n";

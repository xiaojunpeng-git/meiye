<?php
/** 固定候选的真实岗位→任职→操作投影回归；全部合成数据和岗位修改在本地事务回滚。 */
error_reporting(E_ALL & ~E_DEPRECATED);
require '/var/www/html/vendor/autoload.php';
$app=new think\App('/var/www/html/');$app->initialize();
use think\facade\Db;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\cashier\v3\permission\CashierV3StaffFeatureOverrideServices;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local only');
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',get_class($e).':'.$e->getMessage().PHP_EOL);exit(1);});
$checks=[];function check($name,$ok){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
$key='cashier.v3.cashier.custom_card_entry';$tag='r13-card-'.bin2hex(random_bytes(5));
$person=Db::name('system_store_staff')->where('status',1)->where('is_del',0)->where('employee_id','>',0)->where('store_id','>',0)->find();
Db::startTrans();try {
 $employee=(int)Db::name('employee')->insertGetId(['name'=>$tag,'status'=>1,'is_del'=>0]);
 $pid=(int)Db::name('position')->insertGetId(['name'=>$tag,'status'=>1,'use_store'=>1]);
 $staff=(int)Db::name('system_store_staff')->insertGetId(['employee_id'=>$employee,'store_id'=>$person['store_id'],'account'=>$tag,'staff_name'=>$tag,'status'=>1,'is_del'=>0]);
 Db::name('staff_job_position')->insert(['employee_id'=>$employee,'staff_id'=>$staff,'position_id'=>$pid,'status'=>1,'is_del'=>0,'end_time'=>0]);
 $rule=(int)Db::name('job_position_channel_rule')->insertGetId(['position_id'=>$pid,'channel'=>'store_v3','rules'=>'301001','status'=>1,'version'=>1]);
 $resolver=new CashierV3FeatureResolver();$profile=['id'=>$staff,'employee_id'=>$employee,'store_id'=>$person['store_id']];
 $read=fn()=>$resolver->resolveGrantedFeatures($profile);
 check('前台岗位未勾选定制卡不产生操作授权',!in_array($key,$read(),true));
 check('收银入口保持可用',in_array('cashier.v3.cashier',$read(),true));
 check('权限快照操作白名单认识该选择',in_array($key,CashierV3StaffFeatureOverrideServices::operationFeatureCodes(),true));
 Db::name('job_position_channel_rule')->where('id',$rule)->update(['rules'=>'301001,301052','version'=>2]);
 check('明确勾选后授权能够传入当前员工',in_array($key,$read(),true));
 Db::name('job_position_channel_rule')->where('id',$rule)->update(['rules'=>'301001','version'=>3]);
 check('取消后同一会话资料重新解析立即失效',!in_array($key,$read(),true));
 check('父级收银权限不会补回取消的定制卡',Db::name('job_position_channel_rule')->where('id',$rule)->value('rules')==='301001'&&!in_array($key,$read(),true));
 Db::name('job_position_channel_rule')->where('id',$rule)->update(['rules'=>'301001,301052,301053','version'=>4]);
 Db::name('position')->where('id',$pid)->update(['status'=>0]);
 check('停用岗位不继续获得按钮授权',!in_array($key,$read(),true));
 Db::name('position')->where('id',$pid)->update(['status'=>1]);
 check('普通卡修改与定制卡分别传递',in_array('cashier.v3.cashier.ordinary_card_edit',$read(),true)&&in_array($key,$read(),true));
 echo json_encode(['result'=>'PASS','checks'=>$checks,'passed'=>count($checks),'rolledBack'=>true],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{Db::rollback();}

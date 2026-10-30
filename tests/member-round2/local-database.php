<?php
declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\activity\coupon\CouponShareServices;
use app\services\activity\combination\MemberCombinationRecordServices;
use app\services\member\MemberStorePreferenceServices;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL ONLY');
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
foreach(explode(';',file_get_contents('/var/www/html/database/upgrades/2026-10-31-会员优惠券分享转赠/02-正式升级.sql'))as$sql)if(trim($sql)!=='')Db::execute($sql);
$checks=0;
function check2($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS ',$label,PHP_EOL;}
function reject2(callable $fn,$label){try{$fn();}catch(think\exception\ValidateException $e){check2(true,$label);return;}throw new RuntimeException('NOT REJECTED '.$label);}
function seed2($table,$data){foreach(Db::query('SHOW COLUMNS FROM eb_'.$table)as$f){$k=$f['Field'];if(array_key_exists($k,$data)||$f['Default']!==null||$f['Null']==='YES'||strpos($f['Extra'],'auto_increment')!==false)continue;$data[$k]=preg_match('/int|decimal|float|double/',$f['Type'])?0:'';}return (int)Db::name($table)->insertGetId($data);}
$stores=[['id'=>2,'latitude'=>24,'longitude'=>118],['id'=>1,'latitude'=>25,'longitude'=>119]];
check2(MemberStorePreferenceServices::select(1,$stores,['latitude'=>24,'longitude'=>118])===1,'valid primary wins over nearest');
check2(MemberStorePreferenceServices::select(99,$stores,['latitude'=>24,'longitude'=>118])===2,'invalid primary falls back to nearest');
check2(MemberStorePreferenceServices::select(0,$stores,[])===1,'no position selects stable available store');
check2(MemberStorePreferenceServices::select(0,[])===0,'no available stores does not invent one');
$prefix='R2'.bin2hex(random_bytes(4));$phone='198'.random_int(100000,899999);
Db::startTrans();
try{
 $store=(int)Db::name('system_store')->where('is_del',0)->where('is_show',1)->value('id');
 $sender=seed2('user',['nickname'=>$prefix.'S','real_name'=>$prefix.'S','phone'=>$phone.'01','belong_store_id'=>$store,'status'=>1,'is_del'=>0,'add_time'=>time()-300]);
 $receiver=0;
 $other=seed2('user',['nickname'=>$prefix.'O','real_name'=>$prefix.'O','phone'=>$phone.'03','belong_store_id'=>0,'status'=>1,'is_del'=>0,'add_time'=>time()-300]);
 $noPhone=seed2('user',['nickname'=>$prefix.'N','phone'=>'','status'=>1,'is_del'=>0,'add_time'=>time()]);
 $issue=seed2('store_coupon_issue',['coupon_title'=>'测试抵用券','allow_transfer'=>1,'coupon_type'=>1,'rule'=>'指定项目可用']);
 $make=function()use($sender,$issue){return seed2('store_coupon_user',['uid'=>$sender,'cid'=>$issue,'coupon_title'=>'测试抵用券','coupon_price'=>40,'use_min_price'=>100,'start_time'=>time()-30,'end_time'=>time()+86400,'status'=>0,'is_fail'=>0,'use_time'=>0]);};
 $coupon=$make();$svc=new CouponShareServices();$grant=$svc->create($sender,$coupon);
 Db::name('user')->where('uid',$receiver)->update(['add_time'=>time()]); // 模拟点击分享后才完成的新注册。
 $registrationDefault=(int)Db::name('system_store')->where('is_del',0)->where('is_show',1)->where('id','<>',$store)->value('id');
 $receiver=seed2('user',['nickname'=>$prefix.'R','real_name'=>$prefix.'R','phone'=>$phone.'02','belong_store_id'=>$registrationDefault,'status'=>1,'is_del'=>0,'add_time'=>time()]);
 check2(strlen($grant['token'])===64&&(int)Db::name('store_coupon_user')->where('id',$coupon)->value('uid')===$sender,'sharing keeps coupon with sender');
 reject2(function()use($svc,$sender,$grant){$svc->claim($sender,$grant['token']);},'sender cannot claim own gift');
 reject2(function()use($svc,$noPhone,$grant){$svc->claim($noPhone,$grant['token']);},'phone authorization required before transfer');
 $received=$svc->claim($receiver,$grant['token']);
 check2($received['received']&&(int)Db::name('store_coupon_user')->where('id',$coupon)->value('uid')===$receiver,'verified recipient receives same coupon instance');
 check2((int)Db::name('user')->where('uid',$receiver)->value('belong_store_id')===$store,'new recipient inherits sender primary store');
 check2(Db::name('user_belong_store')->where('uid',$receiver)->where('type','coupon_share')->count()===1,'new-store assignment has one traceable history');
 check2($svc->claim($receiver,$grant['token'])['replayed']&&Db::name('store_coupon_transfer')->where('coupon_user_id',$coupon)->count()===1,'reopening gift does not transfer twice');
 reject2(function()use($svc,$other,$grant){$svc->claim($other,$grant['token']);},'another recipient cannot take claimed gift');
 $second=$make();$g2=$svc->create($sender,$second);$svc->claim($other,$g2['token']);
 check2((int)Db::name('user')->where('uid',$other)->value('belong_store_id')===0,'old unbound member store is preserved');
 $third=$make();$g3=$svc->create($sender,$third);Db::name('store_coupon_user')->where('id',$third)->update(['status'=>1]);
 reject2(function()use($svc,$receiver,$g3){$svc->claim($receiver,$g3['token']);},'used coupon invalidates outstanding gift');
 $fourth=$make();$g4=$svc->create($sender,$fourth);Db::name('store_coupon_share')->where('token_hash',hash('sha256',$g4['token']))->update(['expires_at'=>time()-1]);
 reject2(function()use($svc,$receiver,$g4){$svc->claim($receiver,$g4['token']);},'expired share cannot transfer coupon');
 $fifth=$make();$g5=$svc->create($sender,$fifth);$g6=$svc->create($sender,$fifth);$svc->claim($receiver,$g5['token']);
 reject2(function()use($svc,$other,$g6){$svc->claim($other,$g6['token']);},'second link cannot transfer already claimed instance');
 $sixth=$make();$end=(int)Db::name('store_coupon_user')->where('id',$sixth)->value('end_time');
 app()->make(\app\services\activity\coupon\StoreCouponUserServices::class)->transferCoupon($sender,$sixth,$phone.'03','R2PHONE'.bin2hex(random_bytes(12)));
 check2((int)Db::name('store_coupon_user')->where('id',$sixth)->value('uid')===$other&&(int)Db::name('store_coupon_user')->where('id',$sixth)->value('end_time')===$end,'phone transfer keeps existing function and original expiry');
 $cid=seed2('store_combination',['title'=>'已下架历史活动','is_show'=>0,'is_del'=>1]);
 $pink=seed2('store_pink',['uid'=>$sender,'cid'=>$cid,'k_id'=>0,'people'=>2,'status'=>1,'is_refund'=>0,'stop_time'=>time()-100,'add_time'=>time()-200]);
 $history=new MemberCombinationRecordServices();$detail=$history->detail($sender,$pink);
 check2($detail['status']==='failed'&&$detail['title']==='已下架历史活动','hidden activity remains readable through own historical record');
 check2((int)Db::name('store_pink')->where('id',$pink)->value('status')===1,'historical detail never advances group or triggers refund');
 reject2(function()use($history,$receiver,$pink){$history->detail($receiver,$pink);},'historical detail rejects another member record');
}finally{Db::rollback();}
check2(Db::name('user')->where('nickname','like',$prefix.'%')->count()===0,'synthetic members rolled back');
echo 'MEMBER_ROUND2_PASS ',$checks,PHP_EOL;

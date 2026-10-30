<?php
declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL ONLY');
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
if(($argv[1]??'')==='worker'){
    while(!is_file($argv[4]))usleep(10000);
    try{$r=(new app\services\activity\coupon\CouponShareServices())->claim((int)$argv[2],$argv[3]);echo json_encode(['ok'=>true,'uid'=>(int)$argv[2]]);}
    catch(think\exception\ValidateException $e){echo json_encode(['ok'=>false,'uid'=>(int)$argv[2]]);}
    exit;
}
function seedRace($table,$data){foreach(Db::query('SHOW COLUMNS FROM eb_'.$table)as$f){$k=$f['Field'];if(array_key_exists($k,$data)||$f['Default']!==null||$f['Null']==='YES'||strpos($f['Extra'],'auto_increment')!==false)continue;$data[$k]=preg_match('/int|decimal|float|double/',$f['Type'])?0:'';}return (int)Db::name($table)->insertGetId($data);}
$users=[];$issue=0;$coupon=0;$workers=[];$barrier='/tmp/coupon-race-'.bin2hex(random_bytes(8));
try{
 $phone='197'.random_int(100000,899999);
 for($i=0;$i<3;$i++)$users[]=seedRace('user',['nickname'=>'SHARE_RACE_'.bin2hex(random_bytes(4)),'phone'=>$phone.'0'.$i,'status'=>1,'is_del'=>0,'add_time'=>time()-600]);
 $issue=seedRace('store_coupon_issue',['coupon_title'=>'并发转赠测试','allow_transfer'=>1,'coupon_type'=>1]);
 $coupon=seedRace('store_coupon_user',['uid'=>$users[0],'cid'=>$issue,'coupon_title'=>'并发转赠测试','coupon_price'=>40,'use_min_price'=>100,'start_time'=>time()-10,'end_time'=>time()+3600,'status'=>0,'is_fail'=>0,'use_time'=>0]);
 $gift=(new app\services\activity\coupon\CouponShareServices())->create($users[0],$coupon);
 foreach(array_slice($users,1)as$uid){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker',(string)$uid,$gift['token'],$barrier],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Worker unavailable');fclose($pipes[0]);$workers[]=[$proc,$pipes];}
 file_put_contents($barrier,'go');$results=[];
 foreach($workers as[$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);if($code!==0)throw new RuntimeException('Worker failed '.$err);$results[]=json_decode($out,true);}
 $winners=array_values(array_filter($results,function($r){return !empty($r['ok']);}));
 if(count($winners)!==1||Db::name('store_coupon_transfer')->where('coupon_user_id',$coupon)->count()!==1||(int)Db::name('store_coupon_user')->where('id',$coupon)->value('uid')!==(int)$winners[0]['uid'])throw new RuntimeException('Concurrent gift duplicated or lost');
 echo 'CONCURRENT_SHARE_PASS one winner, one transfer, original coupon instance',PHP_EOL;
}finally{
 if(is_file($barrier))unlink($barrier);
 if($coupon){Db::name('store_coupon_share')->where('coupon_user_id',$coupon)->delete();Db::name('store_coupon_transfer')->where('coupon_user_id',$coupon)->delete();Db::name('store_coupon_user')->where('id',$coupon)->delete();}
 if($issue)Db::name('store_coupon_issue')->where('id',$issue)->delete();
 if($users)Db::name('user')->whereIn('uid',$users)->delete();
}

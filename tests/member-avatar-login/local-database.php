<?php
declare(strict_types=1);
/** Exercise the existing candidate's actual persistence boundary and DAO against local MySQL; roll back every fixture. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\wechat\WechatUserServices;
use app\dao\wechat\WechatUserDao;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local Docker only');
$service=(new ReflectionClass(WechatUserServices::class))->newInstanceWithoutConstructor();
$method=new ReflectionMethod(WechatUserServices::class,'normalizeWechatAvatarForPersistence');$method->setAccessible(true);
$dao=app()->make(WechatUserDao::class);$prefix='LOCAL_AVATAR_'.bin2hex(random_bytes(8));$before=(int)Db::name('wechat_user')->whereLike('openid',$prefix.'%')->count();
function avatarCheck(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name,PHP_EOL;}
Db::startTrans();
try{
 $head=Db::query("SHOW COLUMNS FROM eb_wechat_user LIKE 'headimgurl'")[0];avatarCheck($head['Null']==='NO','real headimgurl column is NOT NULL');
 $seed=['openid'=>$prefix.'1','uid'=>0,'user_type'=>'routine','headimgurl'=>null];
 foreach(Db::query('SHOW COLUMNS FROM eb_wechat_user')as $f){$k=$f['Field'];if(array_key_exists($k,$seed)||$f['Default']!==null||$f['Null']==='YES'||strpos($f['Extra'],'auto_increment')!==false)continue;$seed[$k]=preg_match('/int|decimal|float|double/',$f['Type'])?0:'';}
 $new=$method->invoke($service,$seed,false);$dao->save($new);$id=(int)Db::name('wechat_user')->where('openid',$seed['openid'])->value('id');avatarCheck($id>0&&Db::name('wechat_user')->where('id',$id)->value('headimgurl')==='','new account without avatar persists an empty string through real DAO');
 $avatar='https://example.invalid/original-avatar.png';$dao->update($id,['headimgurl'=>$avatar],'id');
 foreach([null,'   ']as $missing){$update=$method->invoke($service,['headimgurl'=>$missing,'nickname'=>'LOCAL_AVATAR_TEST'],true);avatarCheck(!array_key_exists('headimgurl',$update),'missing update omits avatar');$dao->update($id,$update,'id');avatarCheck(Db::name('wechat_user')->where('id',$id)->value('headimgurl')===$avatar,'existing account preserves original avatar through real DAO');}
 $provided=$method->invoke($service,['headimgurl'=>' https://example.invalid/new-avatar.png '],true);$dao->update($id,$provided,'id');avatarCheck(Db::name('wechat_user')->where('id',$id)->value('headimgurl')==='https://example.invalid/new-avatar.png','provided avatar still updates normally');
}finally{Db::rollback();}
avatarCheck((int)Db::name('wechat_user')->whereLike('openid',$prefix.'%')->count()===$before,'all fabricated rows rolled back');
echo 'AVATAR_DATABASE_PASS; no provider calls, no real account authorization',PHP_EOL;

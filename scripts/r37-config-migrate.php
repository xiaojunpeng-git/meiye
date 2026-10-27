<?php
/** R37 配置定义迁移：按实例绑定，先备份该键，幂等插入默认0；不覆盖既有值或改业务事实。 */
$site=$argv[1]??'';$mode=$argv[2]??'';$dir=$argv[3]??'';
$map=['rh'=>['rh.cc3798.com','ruihao','3306'],'008'=>['008.cc3798.com','mohe_008','3307'],'010'=>['010.cc3798.com','mohe_010_app','3307']];
if(!isset($map[$site])||!in_array($mode,['apply','verify','rollback'],true)||!preg_match('~^/www/backups/'.$site.'/r37-labor-display-[a-f0-9]{8}$~',$dir))throw new RuntimeException('SCOPED_ARGUMENTS_REQUIRED');
[$domain,$database,$port]=$map[$site];$root='/www/wwwroot/'.$domain;
require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
$cfg=config('database.connections.mysql');if($cfg['database']!==$database||(string)$cfg['hostport']!==$port)throw new RuntimeException('WRONG_DATABASE');
use think\facade\Db;
$key='cashier_labor_fee_mode';$query=function()use($key){return Db::name('system_config')->where('menu_name',$key)->where('is_store',0)->order('id')->select()->toArray();};
$other=function()use($key){return hash('sha256',json_encode(Db::name('system_config')->where('menu_name','<>',$key)->order('id')->select()->toArray()));};
$backup=$dir.'/config-before.json';$receipt=$dir.'/config-result.json';
if($mode==='rollback'){
 $v=json_decode(file_get_contents($receipt),true);if($query()!==$v['after'])throw new RuntimeException('CONFIG_CONCURRENT_CHANGE');
 if($v['inserted'])Db::name('system_config')->where('id',$v['after'][0]['id'])->where('menu_name',$key)->where('value',$v['after'][0]['value'])->delete();
 mohe\services\CacheService::redisHandler()->delete('system_config:'.$key);
 echo json_encode(['site'=>$site,'result'=>'PASS','rollback'=>true,'retainedPreexisting'=>!$v['inserted']],JSON_UNESCAPED_UNICODE),PHP_EOL;exit;
}
if($mode==='apply'){
 if(is_file($backup)||is_file($receipt))throw new RuntimeException('ALREADY_STARTED_INSPECT_BEFORE_RETRY');
 $before=['site'=>$site,'rows'=>$query(),'otherSHA256'=>$other(),'sqlSHA256'=>hash_file('sha256',$dir.'/upgrade.sql')];file_put_contents($backup,json_encode($before,JSON_UNESCAPED_UNICODE));chmod($backup,0600);
 Db::startTrans();try{Db::execute(file_get_contents($dir.'/upgrade.sql'));$after=$query();if(count($after)!==1||$other()!==$before['otherSHA256'])throw new RuntimeException('UNRELATED_CONFIG_CHANGE');if($before['rows']&&$after!==$before['rows'])throw new RuntimeException('EXISTING_VALUE_CHANGED');if(!$before['rows']&&(string)$after[0]['value']!=='0')throw new RuntimeException('DEFAULT_NOT_EDITABLE');Db::commit();}catch(Throwable $e){Db::rollback();throw $e;}
 file_put_contents($receipt,json_encode(['site'=>$site,'before'=>$before['rows'],'after'=>$after,'inserted'=>!$before['rows'],'otherSHA256'=>$before['otherSHA256'],'result'=>'PASS'],JSON_UNESCAPED_UNICODE));chmod($receipt,0600);
 // 先保存可回滚回执，再精确失效本键；缓存失败可检查回执恢复，不重复插入。
 mohe\services\CacheService::redisHandler()->delete('system_config:'.$key);
}
$v=json_decode(file_get_contents($receipt),true);if($query()!==$v['after']||$other()!==$v['otherSHA256']||(int)sys_config($key,0)!==(int)$v['after'][0]['value'])throw new RuntimeException('CONFIG_READBACK_FAILED');
echo json_encode(['site'=>$site,'result'=>'PASS','inserted'=>$v['inserted'],'id'=>$v['after'][0]['id'],'value'=>(int)$v['after'][0]['value'],'otherConfigurationUnchanged'=>true,'backup'=>$backup,'businessWrites'=>0],JSON_UNESCAPED_UNICODE),PHP_EOL;

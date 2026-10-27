<?php
/** Local-only temporary switch fixture. Update one config key; restore exact
 * original bytes with an optimistic check, never overwrite another task's edit.
 */
require '/var/www/html/vendor/autoload.php';(new \think\App('/var/www/html/'))->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
$cfg=config('database.connections.mysql');if($cfg['database']!=='ruihao'||!in_array($cfg['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('Local only');
$file='/tmp/r60-debt-switch-local.json';$mode=$argv[1]??'';
if($mode==='off'){
 if(is_file($file))throw new RuntimeException('Existing fixture requires restore');
 $row=Db::name('system_config')->where('menu_name','cashier_debt_pay_switch')->field('id,value')->find();if(!$row)throw new RuntimeException('Config missing');
 file_put_contents($file,json_encode($row));chmod($file,0600);
 Db::name('system_config')->where('id',$row['id'])->where('value',$row['value'])->update(['value'=>'0']);
}elseif($mode==='restore'){
 $row=json_decode(file_get_contents($file),true);if((string)Db::name('system_config')->where('id',$row['id'])->value('value')!=='0')throw new RuntimeException('Concurrent switch edit; refusing restore');
 Db::name('system_config')->where('id',$row['id'])->where('value','0')->update(['value'=>$row['value']]);unlink($file);
}else throw new RuntimeException('off|restore');
\mohe\services\SystemConfigService::clear();echo 'R60_LOCAL_SWITCH_'.strtoupper($mode).'_PASS',PHP_EOL;

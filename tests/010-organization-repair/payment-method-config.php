<?php
require '/var/www/html/vendor/autoload.php';
$app=new think\App('/var/www/html/');$app->initialize();$c=config('database.connections.mysql');
if(!is_file('/.dockerenv')||!in_array($c['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('local only');
$p=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db='test_010_payment_'.bin2hex(random_bytes(4));$assert=function($v,$n){if(!$v)throw new RuntimeException($n);echo "PASS $n\n";};
try{
$p->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");$p->exec("CREATE TABLE `$db`.eb_cashier_v3_payment_method_config LIKE `{$c['database']}`.eb_cashier_v3_payment_method_config");$p->exec("USE `$db`");
$cfg=config('database');$cfg['connections']['mysql']['database']=$db;$app->config->set($cfg,'database');
$s=app()->make(\app\services\cashier\v3\config\CashierV3BusinessConfigServices::class);
try{$s->resolveAccountingMethodSnapshot('cash');throw new RuntimeException('expected failure');}catch(\think\exception\ValidateException $e){$assert(true,'empty_config_reproduces_checkout_failure');}
$sql=file_get_contents('/tmp/010-payment-method.sql');$p->exec($sql);
$assert((int)$p->query('SELECT COUNT(*) FROM eb_cashier_v3_payment_method_config')->fetchColumn()===8,'eight_product_defaults');
foreach(['cash','unionpay','wechat','alipay','dianping_voucher','douyin_voucher','partner_collection','other_collection'] as $code){$v=$s->resolveAccountingMethodSnapshot($code);$assert($v['code']===$code&&$v['displayNameSnapshot']!=='','resolve_'.$code);}
$p->exec("UPDATE eb_cashier_v3_payment_method_config SET display_name='保留自定义',status=0 WHERE code='cash'");$before=$p->query('SELECT * FROM eb_cashier_v3_payment_method_config ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$p->exec($sql);$assert($before===$p->query('SELECT * FROM eb_cashier_v3_payment_method_config ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'repeat_preserves_all_existing_settings');
try{$s->resolveAccountingMethodSnapshot('cash');throw new RuntimeException('expected failure');}catch(\think\exception\ValidateException $e){$assert(true,'disabled_method_remains_rejected');}
}finally{$p->exec("DROP DATABASE IF EXISTS `$db`");}

<?php
/** Verify MySQL trigger-metadata visibility with a disposable least-privilege account; no customer DB access. */
require '/var/www/html/vendor/autoload.php';
$app=new think\App('/var/www/html/');$app->initialize();$c=config('database.connections.mysql');
if(!is_file('/.dockerenv')||!in_array($c['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('local only');
$pdo=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='test_010_trigger_'.bin2hex(random_bytes(4));$user='t010_'.bin2hex(random_bytes(4));$password=bin2hex(random_bytes(16));
$assert=function($ok,$name){if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";};
try{
 $pdo->exec('CREATE DATABASE `'.$schema.'`');$pdo->exec('CREATE TABLE `'.$schema.'`.eb_user LIKE `'.$c['database'].'`.eb_user');
 $pdo->exec('USE `'.$schema.'`');
 $pdo->exec('CREATE TRIGGER eb_user_balance_version_bu BEFORE UPDATE ON eb_user FOR EACH ROW BEGIN IF NOT (NEW.now_money <=> OLD.now_money) OR NOT (NEW.ben_money <=> OLD.ben_money) OR NOT (NEW.give_money <=> OLD.give_money) THEN SET NEW.balance_version=OLD.balance_version+1; ELSE SET NEW.balance_version=OLD.balance_version; END IF; END');
 $pdo->exec("CREATE USER '$user'@'%' IDENTIFIED BY '$password'");$pdo->exec("GRANT SELECT,INSERT,UPDATE ON `$schema`.eb_user TO '$user'@'%'");
 $cfg=config('database');$cfg['connections']['mysql']=array_merge($c,['database'=>$schema,'username'=>$user,'password'=>$password,'fields_cache'=>false]);$app->config->set($cfg,'database');
 $provider=app()->make(\app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider::class);
 $before=$provider->readinessStatus();$assert(!$before['ready']&&$before['schema']['moneyColumnsExact']&&$before['trigger']['reason']==='missing_or_duplicate','same_failure_without_metadata_privilege');
 $definition=$pdo->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$schema'")->fetchColumn();
 $pdo->exec("GRANT TRIGGER ON `$schema`.eb_user TO '$user'@'%'");
 $assert($provider->readinessStatus()['ready'],'provider_ready_after_table_trigger_grant');
 $assert($definition===$pdo->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$schema'")->fetchColumn(),'trigger_definition_unchanged');
 $n=$pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=CONCAT(CHAR(39),'$user',CHAR(39),'@',CHAR(39),'%',CHAR(39)) AND PRIVILEGE_TYPE='TRIGGER'")->fetchColumn();$assert((int)$n===0,'no_schema_wide_trigger_grant');
 $pdo->exec("REVOKE TRIGGER ON `$schema`.eb_user FROM '$user'@'%'");$assert(!$provider->readinessStatus()['ready'],'rollback_restores_original_privileges');
}finally{$pdo->exec("DROP USER IF EXISTS '$user'@'%'");$pdo->exec('DROP DATABASE IF EXISTS `'.$schema.'`');}

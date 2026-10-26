<?php
declare(strict_types=1);
/** 仅本地新建临时数据库验证迁移，不把MySQL DDL描述为可整体事务回滚。 */
require '/var/www/html/vendor/autoload.php';$app=new think\App('/var/www/html/');$app->setEnvName('growth-local');$app->setRuntimePath('/var/www/html/runtime/growth-local/');$app->initialize();
use think\facade\Db;
use think\facade\Config;
use app\services\growth\GrowthSchemaServices;
$cfg=config('database.connections.mysql');if($cfg['database']!=='growth_qa_20261023'||$cfg['prefix']!=='eb_')throw new RuntimeException('仅允许隔离环境执行');
$target='growth_install_qa_'.bin2hex(random_bytes(6));$pdo=new PDO('mysql:host='.$cfg['hostname'].';port='.$cfg['hostport'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$checks=[];
$assert=static function(bool $yes,string $message)use(&$checks){if(!$yes)throw new RuntimeException($message);$checks[]=$message;};
try{
 $pdo->exec('CREATE TABLE `'.$target.'`.`eb_system_menus` LIKE `growth_qa_20261023`.`eb_system_menus`');$pdo->exec('USE `'.$target.'`');$install=$cfg;$install['database']=$target;$db=config('database');$db['default']='growth_install_test';$db['connections']['growth_install_test']=$install;Config::set($db,'database');$assert(Db::query('SELECT DATABASE() name')[0]['name']===$target,'独立连接确实指向临时安装库');
 $files=glob('/var/www/html/database/upgrades/20261023_growth_*.sql');sort($files);foreach(array_slice($files,0,5) as $file)$pdo->exec(file_get_contents($file));$partial=(new GrowthSchemaServices())->status();$assert(!$partial['ready']&&$partial['missing_count']>0,'部分迁移不能被标记安装完成');
 foreach($files as $file)$pdo->exec(file_get_contents($file));$assert((new GrowthSchemaServices())->status()['ready'],'全量新安装字段及唯一键完整');$assert((int)$pdo->query('SELECT enabled FROM eb_growth_setting WHERE id=1')->fetchColumn()===0,'新安装默认关闭成长模块');$assert((int)$pdo->query("SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='admin-growth'")->fetchColumn()===1,'成长只有一个菜单根入口');$assert((int)$pdo->query("SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth LIKE 'admin-growth-%'")->fetchColumn()===9,'只注册九个菜单入口，不生成按钮权限');
 foreach($files as $file)$pdo->exec(file_get_contents($file));$assert((new GrowthSchemaServices())->status()['ready'],'重复执行升级不破坏完整结构');$assert((int)$pdo->query("SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='admin-growth'")->fetchColumn()===1,'重复升级不重复创建菜单');
 $pdo->exec('ALTER TABLE eb_growth_task DROP COLUMN completed_at');$missing=(new GrowthSchemaServices())->status();$assert(!$missing['ready']&&in_array('eb_growth_task.completed_at',$missing['missing_columns'],true),'残表缺字段被准确识别并阻止启用');
 // 临时库没有任务数据，可以受控重建此空表；正式实例须使用明确修复迁移和备份。
 $pdo->exec('DROP TABLE eb_growth_task');$pdo->exec(file_get_contents('/var/www/html/database/upgrades/20261023_growth_02_content_tasks.sql'));
 $pdo->exec('ALTER TABLE eb_growth_receipt DROP INDEX uq_request');$missing=(new GrowthSchemaServices())->status();$assert(!$missing['ready']&&in_array('eb_growth_receipt.uq_request',$missing['missing_unique_keys'],true),'防重复唯一键缺失同样阻止启用');$pdo->exec('ALTER TABLE eb_growth_receipt ADD UNIQUE KEY uq_request(actor_key,action,request_key)');$assert((new GrowthSchemaServices())->status()['ready'],'空库受控修复后重新达到完整契约');
 echo json_encode(['passed'=>$checks,'migration_files'=>count($files),'temporary_database_removed_after_test'=>true,'remote_access'=>false],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
}finally{$pdo->exec('DROP DATABASE `'.$target.'`');}

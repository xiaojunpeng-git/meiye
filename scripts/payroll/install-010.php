<?php
/** 仅010测试实例：固定文件白名单、独立备份、原子替换；DDL只新增空配置结构。 */
declare(strict_types=1);
$root='/www/wwwroot/010.cc3798.com';$directory=$argv[1]??'';$phase=$argv[2]??'';
if (!preg_match('#^/www/backups/010/20261027-payroll-[a-f0-9]{12}$#D',$directory) || !in_array($phase,['install','rollback','verify'],true)) throw new RuntimeException('Invalid fixed deployment target');
$manifest=json_decode(file_get_contents($directory.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
function atomicFile($source,$destination){$temporary=$destination.'.payroll-tmp';if(!is_dir(dirname($destination)))mkdir(dirname($destination),0755,true);if(!copy($source,$temporary))throw new RuntimeException('Copy failed');chmod($temporary,0644);chown($temporary,'www');chgrp($temporary,'www');if(!rename($temporary,$destination))throw new RuntimeException('Atomic replace failed');}
function businessDigest(){return ['performance'=>think\facade\Db::query('SELECT COUNT(*) n,COALESCE(SUM(amount_cents),0) amount_cents,COALESCE(SUM(labor_fee_amount_cents),0) labor_fee_cents FROM eb_cashier_v3_performance_fact')[0],'old_position_rules'=>hash('sha256',json_encode(think\facade\Db::name('position_yeji')->order('id')->select()->toArray())),'project_tiers'=>hash('sha256',json_encode(think\facade\Db::name('yeji_commission')->order('id')->select()->toArray()))];}
foreach($manifest['files'] as $file=>$sha){if(strpos($file,'..')!==false || !preg_match('#^(app/|config/payroll\.php$|public/)#',$file))throw new RuntimeException('Non-allowlisted target');if(hash_file('sha256',$directory.'/payload/'.$file)!==$sha)throw new RuntimeException('Payload drift');}
if($phase==='rollback'){
 $before=json_decode(file_get_contents($directory.'/before.json'),true);
 foreach($before['files'] as $file=>$sha){$target=$root.'/'.$file;if($sha===null){if(is_file($target)&&hash_file('sha256',$target)===$manifest['files'][$file])unlink($target);}else atomicFile($directory.'/before/'.$file,$target);}
 copy($directory.'/env.before',$root.'/.env');chmod($root.'/.env',0640);chown($root.'/.env','www');chgrp($root.'/.env','www');
 echo json_encode(['result'=>'ROLLED_BACK','business_data_restored'=>false,'new_empty_config_tables_retained'=>true]);exit;
}
chdir($root);require 'vendor/autoload.php';(new think\App())->initialize();
// ThinkPHP默认异常处理可返回空正文和退出0；部署必须以明确错误退出，禁止伪成功。
set_exception_handler(static function($error){file_put_contents('php://stderr',get_class($error).': '.$error->getMessage().PHP_EOL);exit(1);});
if((string)think\facade\Db::query('SELECT DATABASE() name')[0]['name']!=='mohe_010_app')throw new RuntimeException('Wrong database');
if($phase==='verify'){
 foreach($manifest['files'] as $file=>$sha)if(!is_file($root.'/'.$file)||hash_file('sha256',$root.'/'.$file)!==$sha)throw new RuntimeException('Installed drift:'.$file);
 $before=json_decode(file_get_contents($directory.'/before.json'),true);$digest=businessDigest();if($digest!==$before['business_digest'])throw new RuntimeException('Original business data changed during deployment');
 if((int)sys_config('cashier_labor_fee_mode',0)!==1 || config('payroll.position_model')!=='independent')throw new RuntimeException('010 algorithm/model not active');
 echo json_encode(['result'=>'PASS','tree'=>$manifest['tree'],'files'=>count($manifest['files']),'business_digest_unchanged'=>true,'mode'=>1,'position_model'=>'independent','new_rules'=>think\facade\Db::name('position_salary_rule')->count(),'rule_audit'=>think\facade\Db::name('position_salary_rule_audit')->count()],JSON_UNESCAPED_UNICODE);exit;
}
if(is_file($directory.'/before.json'))throw new RuntimeException('Existing deployment backup: verify or rollback, never overwrite');
$before=['files'=>[],'business_digest'=>businessDigest()];
foreach($manifest['files'] as $file=>$sha){$target=$root.'/'.$file;$actual=is_file($target)?hash_file('sha256',$target):null;$expected=$manifest['before'][$file]??null;if($actual!==$expected && $actual!==$sha)throw new RuntimeException('Live baseline drift:'.$file);$before['files'][$file]=$actual;if($actual!==null){$backup=$directory.'/before/'.$file;if(!is_dir(dirname($backup)))mkdir(dirname($backup),0700,true);copy($target,$backup);chmod($backup,0600);}}
$env=file_get_contents($root.'/.env');$hasPayroll=(bool)preg_match('/^\s*\[PAYROLL\]\s*$/mi',$env);
// 同轮后续测试修复保留已启用配置；未知模式仍拒绝覆盖。
if($hasPayroll && config('payroll.position_model')!=='independent')throw new RuntimeException('Existing payroll instance configuration requires review');
copy($root.'/.env',$directory.'/env.before');chmod($directory.'/env.before',0600);
file_put_contents($directory.'/before.json',json_encode($before,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));chmod($directory.'/before.json',0600);
// 结构新增与原业务数据分离；原项目档位、职位职级、核销及工资记录全部只读。
// 沿用项目既有010受控DDL通道；应用账号继续只有原权限，不授予CREATE或ALTER。
if ((int)config('database.connections.mysql.hostport')!==3307) throw new RuntimeException('Wrong MySQL port');
$grantsBefore=hash('sha256',json_encode(think\facade\Db::query('SHOW GRANTS')));
$credentials=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW)['client'];
$ddl=new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_app;charset=utf8mb4',$credentials['user'],$credentials['password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($ddl->query('SELECT DATABASE()')->fetchColumn()!=='mohe_010_app'||(int)$ddl->query('SELECT @@port')->fetchColumn()!==3307)throw new RuntimeException('DDL target mismatch');
$createdTables=[];
foreach(array_filter(array_map('trim',explode(';',file_get_contents($directory.'/schema.sql')))) as $sql){
 if(!preg_match('/CREATE TABLE IF NOT EXISTS `(eb_position_salary_rule(?:_audit)?)`/',$sql,$table))throw new RuntimeException('DDL statement outside this task');
 $ddl->exec($sql);$createdTables[]=$table[1];
}
if(count($createdTables)!==2 || $grantsBefore!==hash('sha256',json_encode(think\facade\Db::query('SHOW GRANTS'))))throw new RuntimeException('DDL count or application grants changed');
foreach($manifest['files'] as $file=>$sha)atomicFile($directory.'/payload/'.$file,$root.'/'.$file);
if(!$hasPayroll)file_put_contents($root.'/.env',$env."\n[PAYROLL]\nPOSITION_MODEL = independent\n");chmod($root.'/.env',0640);chown($root.'/.env','www');chgrp($root.'/.env','www');
echo json_encode(['result'=>'INSTALLED','files'=>count($manifest['files']),'backup'=>$directory,'business_writes'=>0]);

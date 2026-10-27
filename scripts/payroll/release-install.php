<?php
/** 逐实例发布器：只写固定清单、报表菜单及原有授权的等价展开，不改业务事实与算法选项。 */
declare(strict_types=1);
/** 仅替换报表读范围键，保留同一实例其余配置；已有段不会重复追加。 */
function payrollReportScopeEnv(string $env):string {
 $lines=preg_split('/\r?\n/',$env);$inside=false;$foundSection=false;$foundKey=false;$out=[];
 foreach($lines as $line){
  if(preg_match('/^\s*\[([^]]+)\]\s*$/',$line,$match)){
   if($inside&&!$foundKey){$out[]='PLATFORM_MENU_ALL_STORES = true';$foundKey=true;}
   $inside=strcasecmp($match[1],'BASIC_REPORT')===0;if($inside){if($foundSection)throw new RuntimeException('Duplicate BASIC_REPORT section');$foundSection=true;}
  }
  if($inside&&preg_match('/^\s*PLATFORM_MENU_ALL_STORES\s*=/i',$line)){if($foundKey)throw new RuntimeException('Duplicate report scope key');$line='PLATFORM_MENU_ALL_STORES = true';$foundKey=true;}
  $out[]=$line;
 }
 if(!$foundSection){$out[]='[BASIC_REPORT]';$out[]='PLATFORM_MENU_ALL_STORES = true';}elseif(!$foundKey)$out[]='PLATFORM_MENU_ALL_STORES = true';
 return implode("\n",$out);
}
if(defined('PAYROLL_RELEASE_CONFIG_TEST_ONLY'))return;
$site=$argv[1]??'';$backup=$argv[2]??'';$phase=$argv[3]??'';
$targets=['rh'=>['ruihao',3306,'/root/.my.cnf'],'008'=>['mohe_008',3307,'/root/.mysql57-008.cnf'],'010'=>['mohe_010_app',3307,'/root/.mysql57-008.cnf']];
if(!isset($targets[$site])||!preg_match('#^/www/backups/'.preg_quote($site,'#').'/20261027-payroll-release-[a-f0-9]{12}$#D',$backup)||!in_array($phase,['prepare','install','verify','rollback'],true))throw new RuntimeException('Invalid explicit release');
$root='/www/wwwroot/'.$site.'.cc3798.com';$m=json_decode(file_get_contents($backup.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);
function atomicCopy(string $source,string $target):void{if(!is_dir(dirname($target)))mkdir(dirname($target),0755,true);$tmp=$target.'.payroll-release';if(!copy($source,$tmp))throw new RuntimeException('Copy failed');chmod($tmp,0644);chown($tmp,'www');chgrp($tmp,'www');if(!rename($tmp,$target))throw new RuntimeException('Rename failed');}
chdir($root);require 'vendor/autoload.php';(new think\App())->initialize();set_exception_handler(function($e){file_put_contents('php://stderr',get_class($e).': '.$e->getMessage());exit(1);});
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!==$targets[$site][0]||(int)config('database.connections.mysql.hostport')!==$targets[$site][1])throw new RuntimeException('Wrong instance');
define('BASIC_MENU_PLAN_ONLY',true);require $backup.'/payload/menu-library.php';
/** 固定原有事实边界允许营业追加；任何旧记录金额/数量变化仍失败，不写业务表。 */
function businessDigest(array $bounds):array{return ['performance'=>Db::query('SELECT COUNT(*) n,COALESCE(SUM(amount_cents),0) amount,COALESCE(SUM(labor_fee_amount_cents),0) fee FROM eb_cashier_v3_performance_fact WHERE id<='.(int)$bounds['performance'])[0],'orders'=>Db::name('store_order')->where('id','<=',(int)$bounds['orders'])->count(),'old_wage_rules'=>hash('sha256',json_encode(Db::name('position_yeji')->order('id')->select()->toArray())),'tiers'=>hash('sha256',json_encode(Db::name('yeji_commission')->order('id')->select()->toArray()))];}
foreach($m['files'] as $p=>$h){if(strpos($p,'..')!==false||!preg_match('#^(app/|route/admin.php$|config/(payroll|basic_report).php$|public/)#',$p)||hash_file('sha256',$backup.'/payload/files/'.$p)!==$h)throw new RuntimeException('Payload mismatch');}
$tables=['system_menus','system_role','store_v3_permission_catalog','job_position_channel_rule','database_upgrade_log'];
if($phase==='prepare'){
 if(is_file($backup.'/before.json'))throw new RuntimeException('Backup exists');
 $before=['files'=>[],'business_bounds'=>$m['before']['business_bounds'],'business'=>businessDigest($m['before']['business_bounds']),'mode'=>(int)sys_config('cashier_labor_fee_mode',0),'position_model'=>config('payroll.position_model','legacy'),'tables'=>[]];
 if($before['business']!==$m['before']['business'])throw new RuntimeException('Business baseline drift');
 foreach($m['files'] as $p=>$h){$actual=is_file($root.'/'.$p)?hash_file('sha256',$root.'/'.$p):null;if($actual!==($m['before']['files'][$p]??null))throw new RuntimeException('Live source drift:'.$p);$before['files'][$p]=$actual;if($actual!==null){$to=$backup.'/before/'.$p;if(!is_dir(dirname($to)))mkdir(dirname($to),0700,true);copy($root.'/'.$p,$to);chmod($to,0600);}}
 foreach($tables as $t)$before['tables'][$t]=Db::name($t)->order('id')->select()->toArray();
 $before['audit_max']=(int)Db::name('store_v3_permission_audit')->max('id');
 copy($root.'/.env',$backup.'/env.before');chmod($backup.'/env.before',0600);
 file_put_contents($backup.'/before.json',json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));chmod($backup.'/before.json',0600);
 // 使用实例既有受控DDL凭据，仅新增两个空配置表，不扩大应用账号权限。
 $credentials=parse_ini_file($targets[$site][2],true,INI_SCANNER_RAW)['client'];$ddl=new PDO('mysql:host=127.0.0.1;port='.$targets[$site][1].';dbname='.$targets[$site][0].';charset=utf8mb4',$credentials['user']??'root',$credentials['password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 if($ddl->query('SELECT DATABASE()')->fetchColumn()!==$targets[$site][0])throw new RuntimeException('Wrong DDL database');
 foreach(array_filter(array_map('trim',explode(';',file_get_contents($backup.'/payload/schema.sql')))) as $sql){if(!preg_match('/CREATE TABLE IF NOT EXISTS `(eb_position_salary_rule(?:_audit)?)`/',$sql))throw new RuntimeException('DDL outside scope');$ddl->exec($sql);}
 echo json_encode(['result'=>'PREPARED','site'=>$site]);exit;
}
$before=json_decode(file_get_contents($backup.'/before.json'),true,512,JSON_THROW_ON_ERROR);
if($phase==='rollback'){
 // 回滚只在发布窗口、仍与本次写后授权完全一致时执行，拒绝覆盖管理员的并发改权。
 $expectedTables=is_file($backup.'/after-tables.json')?json_decode(file_get_contents($backup.'/after-tables.json'),true):$before['tables'];foreach($tables as $t)if(Db::name($t)->order('id')->select()->toArray()!==$expectedTables[$t])throw new RuntimeException('Concurrent permission edit; manual reconciliation required');
 Db::transaction(function()use($tables,$before){foreach($tables as $t){$ids=array_column($before['tables'][$t],'id');if($ids)Db::name($t)->whereNotIn('id',$ids)->delete();foreach($before['tables'][$t] as $row){$id=$row['id'];unset($row['id']);Db::name($t)->where('id',$id)->update($row);}}Db::name('store_v3_permission_audit')->where('id','>',$before['audit_max'])->whereIn('action',['INITIALIZE_BASIC_REPORT_NAVIGATION','INSTALL_BASIC_REPORT_PERMISSION','MIGRATE_BASIC_REPORT_LEAF_GRANTS','MIGRATE_PLATFORM_BASIC_REPORT_MENUS'])->delete();});
 foreach($before['files'] as $p=>$h){if($h===null){if(is_file($root.'/'.$p)&&hash_file('sha256',$root.'/'.$p)===$m['files'][$p])unlink($root.'/'.$p);}else atomicCopy($backup.'/before/'.$p,$root.'/'.$p);}
 copy($backup.'/env.before',$root.'/.env');chmod($root.'/.env',0640);chown($root.'/.env','www');chgrp($root.'/.env','www');echo json_encode(['result'=>'ROLLED_BACK','empty_schema_retained'=>true]);exit;
}
if($phase==='install'){
 foreach($before['files'] as $p=>$h){$actual=is_file($root.'/'.$p)?hash_file('sha256',$root.'/'.$p):null;if($actual!==$h)throw new RuntimeException('Prepared file drift');}
 foreach($tables as $t)if(Db::name($t)->order('id')->select()->toArray()!==$before['tables'][$t])throw new RuntimeException('Permission baseline drift');
 if(businessDigest($before['business_bounds'])!==$before['business'])throw new RuntimeException('Business changed; repeat preflight');
 foreach($m['files'] as $p=>$h)atomicCopy($backup.'/payload/files/'.$p,$root.'/'.$p);
 $result=Db::transaction(function()use($m){
  $root=uniqueMenu('admin-report');$legacy=uniqueMenu('admin-report-basic');$navigation=[];$count=0;
  foreach(basicMenuGroups() as $group){ensureMenu($root,$group['auth'],$group['title'],$group['path'],$group['sort']);$parent=uniqueMenu($group['auth']);$navigation[]=(int)$parent['id'];foreach($group['reports'] as $report){$navigation[]=ensureMenu($parent,$report['auth'],$report['title'],$report['path'],$report['sort']);$count++;}}
  $migrationKey='20261026-basic-report-platform-menu-groups';$alreadyMigrated=(bool)Db::name('database_upgrade_log')->where('upgrade_key',$migrationKey)->count();
  $roles=0;foreach(Db::name('system_role')->field('id,rules')->lock(true)->select()->toArray() as $row){$next=$alreadyMigrated?null:inheritedBasicMenuRules((string)$row['rules'],(int)$legacy['id'],$navigation);if($next!==null){Db::name('system_role')->where('id',$row['id'])->update(['rules'=>$next]);$roles++;}}
  // 新岗位策略的platform通道同样等价继承；一次性标记保护随后人工撤权不被重授。
  $jobChanges=[];if(!$alreadyMigrated)foreach(Db::name('job_position_channel_rule')->where('channel','platform')->lock(true)->select()->toArray() as $row){
   $next=inheritedBasicMenuRules((string)$row['rules'],(int)$legacy['id'],$navigation);if($next===null)continue;
   Db::name('job_position_channel_rule')->where('id',$row['id'])->update(['rules'=>$next,'version'=>(int)$row['version']+1,'update_time'=>time()]);$jobChanges[]=['id'=>(int)$row['id'],'before'=>$row['rules'],'after'=>$next];
  }
  if(!$alreadyMigrated){Db::name('store_v3_permission_audit')->insert(['operator_id'=>0,'action'=>'MIGRATE_PLATFORM_BASIC_REPORT_MENUS','before_json'=>json_encode($jobChanges),'after_json'=>json_encode(['roles_changed'=>count($jobChanges)]),'created_at'=>time()]);Db::name('database_upgrade_log')->insert(['upgrade_key'=>$migrationKey,'title'=>'基础报表平台菜单分组','task_file'=>'2026-10-26-基础报表平台菜单分组/01-正式升级.php','sql_checksum'=>'','code_version'=>$m['source_commit'],'executed_at'=>date('Y-m-d H:i:s'),'executed_by'=>'codex-release','result_note'=>'Equivalent inheritance of prior report authorization; no business import']);}
  Db::name('system_menus')->where('id',$legacy['id'])->update(['is_show'=>0]);
  $catalog=new app\services\organization\StorePermissionCatalogServices();$catalog->initializeBasicReportNavigation();$catalog->initializeBasicReportPermission();$storeRoles=$catalog->migrateBasicReportLeafGrants();
  return ['reports'=>$count,'platform_roles'=>$roles,'platform_job_roles'=>count($jobChanges),'store_roles'=>$storeRoles];
 });
 // 只有报表读范围迁移；保留现有手工费算法和职位模型，不迁移010业务/历史快照。
 $env=file_get_contents($root.'/.env');if($site!=='010'){
  file_put_contents($root.'/.env',payrollReportScopeEnv($env));chmod($root.'/.env',0640);chown($root.'/.env','www');chgrp($root.'/.env','www');
 }
 $after=[];foreach($tables as $t)$after[$t]=Db::name($t)->order('id')->select()->toArray();file_put_contents($backup.'/after-tables.json',json_encode($after));chmod($backup.'/after-tables.json',0600);
 echo json_encode(['result'=>'INSTALLED','site'=>$site]+$result);exit;
}
foreach($m['files']+$m['required_assets'] as $p=>$h)if(!is_file($root.'/'.$p)||hash_file('sha256',$root.'/'.$p)!==$h)throw new RuntimeException('Installed drift:'.$p);
if(businessDigest($before['business_bounds'])!==$before['business']||(int)sys_config('cashier_labor_fee_mode',0)!==$before['mode']||config('payroll.position_model','legacy')!==$before['position_model'])throw new RuntimeException('Business or algorithm changed');
if(!(bool)config('basic_report.platform_menu_all_stores',false))throw new RuntimeException('Report menu range inactive');
$leafIds=array_keys(app\services\organization\StorePermissionCatalogServices::basicReportGrantDefinitions());if(count($leafIds)!==21)throw new RuntimeException('Missing leaf catalog');
foreach(basicMenuGroups() as $group)foreach($group['reports'] as $report)uniqueMenu($report['auth']);
echo json_encode(['result'=>'PASS','site'=>$site,'files'=>count($m['files']),'mode'=>$before['mode'],'position_model'=>$before['position_model'],'business_digest_unchanged'=>true,'leaf_grants'=>count($leafIds),'reports'=>21,'source_tree'=>$m['tree']]);

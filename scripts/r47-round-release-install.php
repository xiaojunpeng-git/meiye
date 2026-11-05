<?php
declare(strict_types=1);
/** R47 file installer: explicit instance, payload hashes, separate additive schema step and owned rollback. */
$dir=$argv[1]??'';$mode=$argv[2]??'';
if(!preg_match('~^/www/backups/(rh|008|010)/r47-card-project-[a-f0-9]{8}$~D',$dir,$match)||!in_array($mode,['backup','schema','migrate','install','delete-retired','verify','rollback'],true))throw new RuntimeException('Explicit release phase required');
$m=json_decode(file_get_contents($dir.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);$site=$match[1];
if($m['site']!==$site||$dir!==$m['backup']||!preg_match('/^[a-f0-9]{40}$/D',$m['commit']))throw new RuntimeException('Release identity mismatch');
$root='/www/wwwroot/'.$site.'.cc3798.com';$expect=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]];
$ini=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$cfg=array_change_key_case($ini['DATABASE']??$ini['database']);if($cfg['database']!==$expect[$site][0]||(int)$cfg['hostport']!==$expect[$site][1])throw new RuntimeException('Database mismatch');
$db=new PDO('mysql:host='.$cfg['hostname'].';port='.$cfg['hostport'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$hash=static function($p){return is_file($p)?hash_file('sha256',$p):null;};
$guard=static function(array $v)use($root,$hash):void{foreach($v as$p=>$h)if($hash($root.'/'.$p)!==$h)throw new RuntimeException('Changed file: '.$p);};
$put=static function(string $from,string $to):void{if(!is_dir(dirname($to)))mkdir(dirname($to),0755,true);$tmp=$to.'.r47-'.getmypid();if(!copy($from,$tmp))throw new RuntimeException('Copy failed');chmod($tmp,0644);chown($tmp,'www');chgrp($tmp,'www');if(!rename($tmp,$to))throw new RuntimeException('Atomic file switch failed');};
$emit=static function($v):void{echo json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;};
$schema=static function()use($db):array{$v=[];foreach(['cashier_v3_checkout_request','cashier_v3_sales_order']as$t)$v[$t]=$db->query("SHOW COLUMNS FROM eb_".$t." LIKE 'project_auto_complete'")->fetchAll();$v['setting']=$db->query("SELECT * FROM eb_system_config WHERE menu_name='cashier_project_auto_complete_switch' AND is_store=0")->fetchAll();return $v;};
if($mode==='backup'){
 $guard($m['guards']);$guard($m['before']);if(is_file($dir.'/backup.json'))throw new RuntimeException('Backup already exists');$meta=['schema'=>$schema(),'create'=>[]];
 foreach(['eb_cashier_v3_checkout_request','eb_cashier_v3_sales_order','eb_system_config']as$t)$meta['create'][$t]=$db->query('SHOW CREATE TABLE '.$t)->fetch();
 foreach($m['before']as$p=>$h)if($h!==null){$to=$dir.'/before/'.hash('sha256',$p);if(!is_dir(dirname($to)))mkdir(dirname($to),0700,true);if(!copy($root.'/'.$p,$to)||$hash($to)!==$h)throw new RuntimeException('Backup failed');}
 file_put_contents($dir.'/backup.json',json_encode($meta,JSON_THROW_ON_ERROR));chmod($dir.'/backup.json',0600);$emit(['result'=>'PASS','backup'=>$dir,'schema'=>$meta['schema'],'files'=>count($m['before'])]);exit;
}
if(!is_file($dir.'/backup.json'))throw new RuntimeException('Independent backup required');
// 010 deliberately gives its application account no ALTER privilege. Use the
// existing server-owned DBA socket only for the two authorized additive columns;
// native config/cache still run as www through the canonical migration below.
if($mode==='schema'){
 if($site!=='010'||($m['migrationAuthorized']??false)!==true)throw new RuntimeException('Scoped schema authorization required');$guard($m['guards']);$guard($m['before']);
 $file='database/upgrades/20261105_project_purchase_fulfillment.php';if($hash($dir.'/payload/'.$file)!==$m['targets'][$file])throw new RuntimeException('Migration hash mismatch');
 $private='/root/.mysql57-008.cnf';if(!is_file($private)||(fileperms($private)&0777)!==0600||fileowner($private)!==0)throw new RuntimeException('DBA identity unavailable');
 $admin=parse_ini_file($private,true,INI_SCANNER_RAW)['client']??[];if(($admin['user']??'')!=='root'||($admin['socket']??'')!=='/run/mysql57-008/mysql.sock')throw new RuntimeException('Wrong DBA socket');
 $ddl=new PDO('mysql:unix_socket='.$admin['socket'].';dbname=mohe_010_app;charset=utf8mb4',$admin['user'],$admin['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 if((int)$ddl->query('SELECT @@port')->fetchColumn()!==3307||$ddl->query('SELECT DATABASE()')->fetchColumn()!=='mohe_010_app')throw new RuntimeException('Wrong schema database');
 $ddl->exec('SET SESSION lock_wait_timeout=5');$added=[];
 foreach(['eb_cashier_v3_checkout_request','eb_cashier_v3_sales_order']as$table){
  if(!$ddl->query("SHOW COLUMNS FROM `".$table."` LIKE 'project_auto_complete'")->fetch()){
   $ddl->exec("ALTER TABLE `".$table."` ADD COLUMN `project_auto_complete` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '本次购买项目自动完成服务，历史默认保持原流程'");$added[]=$table;
  }
 }
 $emit(['result'=>'PASS','site'=>$site,'columnsAdded'=>$added,'applicationGrantsChanged'=>false]);exit;
}
if($mode==='migrate'){
 if(($m['migrationAuthorized']??false)!==true)throw new RuntimeException('Production migration authorization required');
 // Root checks every global guard during backup/install; www validates the scoped
 // R47 files here because unrelated legacy directories may not be traversable.
 $guard(array_intersect_key($m['guards'],$m['before']));$guard($m['before']);
 // Run as www to keep runtime logs/cache ownership consistent. Additive DDL can
 // be safely retried; existing order values remain default-on, never recomputed.
 require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
 think\facade\Db::execute('SET SESSION lock_wait_timeout=5');
 $file='database/upgrades/20261105_project_purchase_fulfillment.php';if($hash($dir.'/payload/'.$file)!==$m['targets'][$file])throw new RuntimeException('Migration hash mismatch');
 $apply=require $dir.'/payload/'.$file;$added=$apply();mohe\services\SystemConfigService::clear();
 $after=$schema();foreach(['cashier_v3_checkout_request','cashier_v3_sales_order']as$t)if(count($after[$t])!==1||(string)$after[$t][0]['Default']!=='1')throw new RuntimeException('Schema verification failed');
 if(count($after['setting'])!==1||(string)$after['setting'][0]['value']!=='1')throw new RuntimeException('New default-on setting mismatch');
 // Native upgrade history records the exact script and deployed code without touching business rows.
 $key='20261105-r47-project-auto-complete';$checksum=$m['targets'][$file];$q=$db->prepare('SELECT sql_checksum FROM eb_database_upgrade_log WHERE upgrade_key=?');$q->execute([$key]);$previous=$q->fetchColumn();
 if($previous!==false&&$previous!==$checksum)throw new RuntimeException('Different migration already recorded');
 if($previous===false){$q=$db->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES (?,?,?,?,?,NOW(),?,?)');$q->execute([$key,'门店端购买项目自动核销','database/upgrades/20261105_project_purchase_fulfillment.php',$checksum,$m['commit'],'Codex R47 www','新增两个默认开启字段和一条配置；旧业务数据不重算']);}
 file_put_contents($dir.'/migration.json',json_encode(['commit'=>$m['commit'],'added'=>$added,'schema'=>$after],JSON_THROW_ON_ERROR));$emit(['result'=>'PASS','added'=>$added,'schema'=>$after]);exit;
}
if($mode==='install'){
 if(!is_file($dir.'/migration.json'))throw new RuntimeException('Verified schema required');$guard($m['guards']);$guard($m['before']);
 foreach($m['targets']as$p=>$h)if($hash($dir.'/payload/'.$p)!==$h)throw new RuntimeException('Payload hash mismatch');
 // New leaf classes precede their callers; immutable assets precede the entry.
 $paths=array_keys($m['targets']);usort($paths,static function($a,$b)use($m){$rank=static function($p)use($m){return $p==='public/view_cashier_v3/index.html'?3:((strpos($p,'app/')===0&&$m['before'][$p]===null)?0:1);};return $rank($a)<=>$rank($b);});
 foreach($paths as$p)$put($dir.'/payload/'.$p,$root.'/'.$p);$guard($m['targets']);$emit(['result'=>'PASS','commit'=>$m['commit'],'files'=>count($paths)]);exit;
}
if($mode==='delete-retired'){
 $guard($m['targets']);foreach($m['retired']as$p=>$h){if($hash($root.'/'.$p)!==$h)throw new RuntimeException('Retired source changed');unlink($root.'/'.$p);}$emit(['result'=>'PASS','removed'=>array_keys($m['retired'])]);exit;
}
if($mode==='verify'){
 // The orchestrator verifies all unrelated files as root after this runtime probe.
 $guard($m['targets']);foreach($m['retired']as$p=>$h)if(is_file($root.'/'.$p))throw new RuntimeException('Retired class remains');$state=$schema();
 if(count($state['setting'])!==1||(string)$state['setting'][0]['value']!=='1')throw new RuntimeException('Fulfillment setting changed during release');
 require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
 think\facade\Db::execute('SET TRANSACTION READ ONLY');think\facade\Db::startTrans();try{
  $policy=app\services\cashier\v3\settlement\CashierV3ProjectPurchasePolicy::class;
  if(!$policy::enabled()||!$policy::resolve(false,['projectAutoComplete'=>true])||$policy::resolve(true,[])!==false||!$policy::deferred(['project_auto_complete'=>0]))throw new RuntimeException('Fulfillment policy regression');
  $display=(new app\services\cashier\v3\projection\CashierV3DisplayPolicyServices())->snapshot();if(($display['projectAutoComplete']??null)!==true)throw new RuntimeException('Shared display projection mismatch');
  $rule=app\services\cashier\v3\card\CashierV3OrdinaryCardSaleAdjustment::class;$base=['sourceKind'=>'card_package','ruleType'=>'normal','productId'=>1,'components'=>[['relationId'=>1,'productId'=>2,'skuUnique'=>'r47-readonly','writeTimes'=>10,'configuredPriceCents'=>0]]];$proof=$rule::seal($base,1,1,298000);
  if($proof['ordinarySaleBaseline']['definition']['componentSaleAmountsCents']!==[298000]||$rule::validate($proof,$base,1,1)!==0)throw new RuntimeException('Ordinary-card original price regression');
  if(!class_exists(app\services\cashier\v3\settlement\CashierV3PurchasedProjectEntitlementServices::class))throw new RuntimeException('Shared issuer unavailable');
 }finally{think\facade\Db::rollback();}
 $emit(['result'=>'PASS','commit'=>$m['commit'],'schema'=>$state,'runtime'=>['policy'=>true,'display'=>true,'ordinaryCardProof'=>true,'sharedIssuer'=>true],'businessWrites'=>0]);exit;
}
// Never erase new deferred orders or roll back over another task's files.
foreach($m['targets']as$p=>$h)if(!in_array($hash($root.'/'.$p),[$m['before'][$p],$h],true))throw new RuntimeException('Concurrent update blocks rollback');
foreach($m['retired']as$p=>$h)if(!in_array($hash($root.'/'.$p),[$h,null],true))throw new RuntimeException('Retired source changed during rollback');
$now=$schema();if($now['setting']&&(string)$now['setting'][0]['value']!=='1')throw new RuntimeException('Changed fulfillment preference requires reconciliation before rollback');
foreach(['cashier_v3_checkout_request','cashier_v3_sales_order']as$t)if($db->query("SHOW COLUMNS FROM eb_".$t." LIKE 'project_auto_complete'")->fetch()&&(int)$db->query('SELECT COUNT(*) FROM eb_'.$t.' WHERE project_auto_complete=0')->fetchColumn()>0)throw new RuntimeException('New deferred facts require reconciliation before rollback');
foreach($m['retired']as$p=>$h)$put($dir.'/before/'.hash('sha256',$p),$root.'/'.$p);
foreach($m['before']as$p=>$h)if($h!==null&&!isset($m['retired'][$p]))$put($dir.'/before/'.hash('sha256',$p),$root.'/'.$p);
foreach($m['before']as$p=>$h)if($h===null&&(strpos($p,'app/')===0||strpos($p,'database/')===0)&&is_file($root.'/'.$p))unlink($root.'/'.$p);
// Additive columns/default-on config remain: no business facts are rewritten or dropped.
$emit(['result'=>'ROLLED_BACK','backup'=>$dir,'additiveSchemaRetained'=>true]);

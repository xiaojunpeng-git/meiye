<?php
declare(strict_types=1);
/** 已批准发布的执行器：只安装固定载荷，独立备份；不迁移历史调查，不授予岗位权限。 */
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});
$site=$argv[1]??'';$backup=$argv[2]??'';$phase=$argv[3]??'';
$targets=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]];
if(!isset($targets[$site])||!preg_match('#^/www/backups/'.preg_quote($site,'#').'/round12-[a-f0-9]{12}-[0-9]{14}$#D',$backup)||!in_array($phase,['prepare','install','verify','rollback'],true))throw new RuntimeException('Explicit release target required');
$root='/www/wwwroot/'.$site.'.cc3798.com';chdir($root);require 'vendor/autoload.php';(new think\App($root.'/'))->initialize();
// 框架初始化会接管异常；发布CLI必须恢复非零退出，不能把被框架吞掉的错误当成功。
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());exit(1);});
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!==$targets[$site][0]||(int)config('database.connections.mysql.hostport')!==$targets[$site][1])throw new RuntimeException('Wrong database');
$m=json_decode(file_get_contents($backup.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if($m['site']!==$site||!preg_match('/^[a-f0-9]{40}$/D',$m['source_commit']))throw new RuntimeException('Wrong payload');
function r12hash(string $p):?string{return is_file($p)?hash_file('sha256',$p):null;}
function r12json(string $p,$v):void{if(file_put_contents($p,json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Cannot record backup');chmod($p,0600);}
function r12copy(string $a,string $b):void{if(!is_dir(dirname($b)))mkdir(dirname($b),0755,true);$mode=is_file($b)?fileperms($b)&0777:0644;$t=$b.'.r12-release';if(!copy($a,$t))throw new RuntimeException('Copy failed');chmod($t,$mode);chown($t,'www');chgrp($t,'www');if(!rename($t,$b))throw new RuntimeException('Atomic rename failed');}
/** 010沿用已批准的3307独立升级通道；仅连接该库，不给运行账号授予DDL。凭据只在服务器内读取。 */
function r12ddl(string $site):PDO{
 if($site!=='010')return Db::connect()->getPdo();
 $client=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW)['client'];
 $pdo=new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_app;charset=utf8mb4',$client['user'],$client['password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='mohe_010_app'||(int)$pdo->query('SELECT @@port')->fetchColumn()!==3307)throw new RuntimeException('DDL channel target mismatch');return $pdo;
}
function r12meta():array{
 $tables=[];foreach(Db::query("SHOW TABLES LIKE 'eb_member_survey_%'")as$r){$t=array_values($r)[0];if(!preg_match('/^eb_member_survey_[a-z_]+$/D',$t))throw new RuntimeException('Unexpected table');$tables[$t]=(int)Db::query('SELECT COUNT(*) n FROM `'.$t.'`')[0]['n'];}
 return ['tables'=>$tables,'menu'=>Db::name('system_menus')->where('unique_auth','admin-user-survey')->order('id')->select()->toArray(),'grants'=>hash('sha256',json_encode([Db::name('system_role')->order('id')->select()->toArray(),Db::name('job_position_channel_rule')->order('id')->select()->toArray()],JSON_THROW_ON_ERROR)),'application_privileges'=>hash('sha256',json_encode(Db::query('SHOW GRANTS'),JSON_THROW_ON_ERROR))];
}
foreach($m['files']as$p=>$h){if(strpos($p,'..')!==false||!preg_match('#^(app/|route/|config/member_survey.php$|database/upgrades/2026-10-29-|public/)#',$p)||r12hash($backup.'/payload/files/'.$p)!==$h)throw new RuntimeException('Invalid payload: '.$p);}
if($phase==='prepare'){
 if(is_file($backup.'/metadata-before.json'))throw new RuntimeException('Already prepared');
 foreach($m['baseline']as$p=>$h)if(r12hash($root.'/'.$p)!==$h)throw new RuntimeException('Live baseline changed: '.$p);
 foreach($m['before']as$p=>$h)if($h!==null){$to=$backup.'/before/'.$p;if(!is_dir(dirname($to)))mkdir(dirname($to),0700,true);if(!copy($root.'/'.$p,$to)||r12hash($to)!==$h)throw new RuntimeException('Backup failed');chmod($to,0600);}
 $meta=r12meta();if($meta['tables'])throw new RuntimeException('Unexpected preexisting survey data');r12json($backup.'/metadata-before.json',$meta);
 echo json_encode(['result'=>'PREPARED','files'=>count($m['files']),'business_rows_written'=>0]);exit;
}
$before=json_decode(file_get_contents($backup.'/metadata-before.json'),true,512,JSON_THROW_ON_ERROR);
if($phase==='install'){
 foreach($m['baseline']as$p=>$h)if(r12hash($root.'/'.$p)!==$h)throw new RuntimeException('Prepared baseline changed: '.$p);
 if(r12meta()!==$before)throw new RuntimeException('Metadata changed');
 // 新依赖和资源先落位；入口最后切换，旧资源不删除，已打开页面仍可使用。
 foreach($m['files']as$p=>$h)if(!in_array($p,['public/system.html','public/index.html'],true))r12copy($backup.'/payload/files/'.$p,$root.'/'.$p);
 // 每站只增加会员H5入口配置；原环境文件保存在本站独立备份内，密钥不传回本机。
 $env=file_get_contents($root.'/.env');$section="\n[MEMBER_SURVEY]\nMEMBER_H5_URL = https://".$site.".cc3798.com\n";
 if(preg_match('/^\[MEMBER_SURVEY\]\s*$/mi',$env))throw new RuntimeException('Existing survey env requires explicit merge');
 r12json($backup.'/env-after.json',['sha256'=>hash('sha256',$env.$section)]);
 if(file_put_contents($root.'/.env',$env.$section)===false)throw new RuntimeException('Env write failed');
 putenv('R12_SURVEY_INSTALL=1');require $backup.'/payload/install.php';
 if($site==='010'){
  installMemberSurveyMetadata($root,$targets[$site][0],true);
  r12ddl($site)->exec(file_get_contents($root.'/database/upgrades/2026-10-29-会员调查/01-schema.sql'));
  Db::connect()->getPdo()->exec(file_get_contents($root.'/database/upgrades/2026-10-29-会员调查/02-menu.sql'));
  $result=['passed'=>true,'database'=>$targets[$site][0],'business_rows_imported'=>0,'existing_roles_changed'=>0,'ddl_channel'=>'existing isolated maintenance connection'];
 }else{$result=installMemberSurveyMetadata($root,$targets[$site][0]);}
 r12json($backup.'/metadata-after.json',r12meta());
 if(r12meta()['grants']!==$before['grants']||r12meta()['application_privileges']!==$before['application_privileges'])throw new RuntimeException('Unexpected role or application privilege changes');
 foreach(['public/system.html','public/index.html']as$p)r12copy($backup.'/payload/files/'.$p,$root.'/'.$p);
 mohe\services\CacheService::redisHandler('system_menus')->clear();
 echo json_encode(['result'=>'INSTALLED','metadata'=>$result,'source_commit'=>$m['source_commit']]);exit;
}
if($phase==='rollback'){
 // 不能为了回滚删除上线后新产生的真实答卷或权益；有并发使用时保护数据并报告。
 $current=r12meta();foreach($current['tables']as$n)if($n!==0)throw new RuntimeException('Survey now contains live data; do not delete');
 $envAfter=is_file($backup.'/env-after.json')?json_decode(file_get_contents($backup.'/env-after.json'),true)['sha256']:$m['before']['.env'];
 if(!in_array(r12hash($root.'/.env'),[$m['before']['.env'],$envAfter],true))throw new RuntimeException('Concurrent environment change');
 if($current['grants']!==$before['grants'])throw new RuntimeException('Concurrent grant change');
 foreach($m['files']as$p=>$h)if(!in_array(r12hash($root.'/'.$p),[$h,$m['before'][$p]],true))throw new RuntimeException('Concurrent file change: '.$p);
 $ddl=r12ddl($site);foreach($current['tables']as$t=>$n)$ddl->exec('DROP TABLE `'.$t.'`');
 $ids=array_column($before['menu'],'id');foreach($current['menu']as$row)if(!in_array($row['id'],$ids,true))Db::name('system_menus')->where('id',$row['id'])->delete();
 foreach($m['before']as$p=>$h){if($h!==null)r12copy($backup.'/before/'.$p,$root.'/'.$p);elseif(isset($m['files'][$p])&&r12hash($root.'/'.$p)===$m['files'][$p])unlink($root.'/'.$p);}
 mohe\services\CacheService::redisHandler('system_menus')->clear();echo json_encode(['result'=>'ROLLED_BACK']);exit;
}
foreach($m['files']as$p=>$h)if(r12hash($root.'/'.$p)!==$h)throw new RuntimeException('Live file mismatch: '.$p);
foreach($m['preserve']as$p=>$h)if(r12hash($root.'/'.$p)!==$h)throw new RuntimeException('Preserved entry changed: '.$p);
$meta=r12meta();if(count($meta['tables'])!==7||count($meta['menu'])!==1||$meta['grants']!==$before['grants']||$meta['application_privileges']!==$before['application_privileges'])throw new RuntimeException('Metadata or grants mismatch');
if(config('member_survey.member_h5_base_url')!=='https://'.$site.'.cc3798.com')throw new RuntimeException('Member H5 configuration mismatch');
$service=new app\services\user\MemberSurveyServices();$list=$service->listTasks(1,20);$questions=$service->listQuestionnaires(1,20);
$policy=new app\services\user\MemberSurveyAccessPolicy();$policy->assertPlatform(['id'=>1,'level'=>0,'admin_type'=>0],[]);$denied=false;try{$policy->assertPlatform(['id'=>2,'level'=>1,'admin_type'=>0],[]);}catch(think\exception\ValidateException$e){$denied=true;}if(!$denied)throw new RuntimeException('Missing permission denial');
echo json_encode(['result'=>'PASS','site'=>$site,'source_commit'=>$m['source_commit'],'files'=>count($m['files']),'survey_tables'=>count($meta['tables']),'existing_grants_unchanged'=>true,'application_privileges_unchanged'=>true,'empty_task_query'=>$list,'empty_questionnaire_query'=>$questions,'unauthorized_management_denied'=>true,'business_rows_written'=>0],JSON_UNESCAPED_UNICODE);

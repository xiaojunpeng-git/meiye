<?php
/** Exercise the release installer against a disposable local database and owned filesystem fixture. */
$c=json_decode(base64_decode('{{CONFIG}}'),true);if($c['hostname']!=='mysql'||$c['database']!=='ruihao')throw new RuntimeException('Local only');
$pdo=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$tag=bin2hex(random_bytes(6));$db='pml_install_'.$tag;$base='/tmp/pml-installer-'.$tag;$site=$base.'/site';$dir=$base.'/backup/rh/platform-member-deadbeef';$created=false;
function ih($p){return is_file($p)?hash_file('sha256',$p):null;}
function iw($p,$s){if(!is_dir(dirname($p)))mkdir(dirname($p),0700,true);file_put_contents($p,$s);}
try{
 $pdo->exec('CREATE DATABASE `'.$db.'`');$created=true;$local=new PDO('mysql:host='.$c['hostname'].';port='.($c['hostport']?:3306).';dbname='.$db,$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$ddl=$pdo->query('SHOW CREATE TABLE eb_database_upgrade_log')->fetch(PDO::FETCH_NUM)[1];$local->exec($ddl);
 $ini="[DATABASE]\n";foreach(['hostname'=>$c['hostname'],'hostport'=>(string)($c['hostport']?:3306),'database'=>$db,'username'=>$c['username'],'password'=>$c['password']]as$k=>$v)$ini.=$k.'="'.str_replace(['\\','"'],['\\\\','\\"'],$v)."\"\n";
 iw($site.'/.env',$ini);iw($site.'/app/services/Fixture.php','before');iw($site.'/public/index.html','preserved H5');
 $files=['app/services/Fixture.php'=>['before'=>ih($site.'/app/services/Fixture.php'),'after'=>hash('sha256','after')],'app/services/New.php'=>['before'=>null,'after'=>hash('sha256','new')]];
 iw($dir.'/payload/files/app/services/Fixture.php','after');iw($dir.'/payload/files/app/services/New.php','new');$migrations=[];
 foreach(['2026-10-28-会员改号撤销登录'=>'eb_member_phone_login_reset','2026-10-28-平台门店会员管理'=>'eb_member_management_receipt']as$f=>$t){$sql=base64_decode(json_decode(base64_decode('{{MIGRATIONS}}'),true)[$f]);iw($dir.'/payload/sql/'.$t.'.sql',$sql);$migrations[]=['file'=>$t.'.sql','table'=>$t,'key'=>$t,'title'=>$t,'source'=>$f,'sha256'=>hash('sha256',$sql),'columns'=>['id'],'existedBefore'=>false];}
 $manifest=['site'=>'rh','commit'=>'deadbeef'.str_repeat('0',32),'environment'=>ih($site.'/.env'),'backendAllowlist'=>array_keys($files),'protected'=>['public/index.html'=>ih($site.'/public/index.html')],'files'=>$files,'migrations'=>$migrations];iw($dir.'/payload/manifest.json',json_encode($manifest));$digest=ih($dir.'/payload/manifest.json');
 $installer=base64_decode('{{INSTALLER}}');$installer=str_replace('^/www/backups/','^'.$base.'/backup/',$installer);$installer=str_replace("'/www/wwwroot/'.(\$site==='rh'?'rh.cc3798.com':\$site.'.cc3798.com')",var_export($site,true),$installer);$installer=str_replace("'rh'=>['ruihao',3306]","'rh'=>['".$db."',".(int)($c['hostport']?:3306)."]",$installer);iw($base.'/installer.php',$installer);chmod($base.'/installer.php',0444);
 $invoke=function($mode,$ok=true)use($base,$dir,$digest){$pipes=[];$proc=proc_open(['php',$base.'/installer.php',$dir,$mode,$digest],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);if(($code===0)!==$ok)throw new RuntimeException($mode.' unexpected outcome: '.$err);if($ok&&strpos($out,'PLATFORM_MEMBER_')===false)throw new RuntimeException('No installer receipt');};
 $invoke('backup');$invoke('migrate');$invoke('migrate');if((int)$local->query('SELECT COUNT(*) FROM eb_database_upgrade_log')->fetchColumn()!==2)throw new RuntimeException('Migration replay duplicated logs');$invoke('install');$invoke('verify');
 if(file_get_contents($site.'/app/services/Fixture.php')!=='after'||file_get_contents($site.'/app/services/New.php')!=='new')throw new RuntimeException('Install did not switch exact scope');
 file_put_contents($site.'/app/services/Fixture.php','concurrent');$invoke('verify',false);file_put_contents($site.'/app/services/Fixture.php','after');$invoke('rollback');
 if(file_get_contents($site.'/app/services/Fixture.php')!=='before'||file_exists($site.'/app/services/New.php')||file_get_contents($site.'/public/index.html')!=='preserved H5')throw new RuntimeException('Rollback/protected H5 failed');
 if(!(int)$local->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=".$local->quote($db)." AND TABLE_NAME='eb_member_management_receipt'")->fetchColumn())throw new RuntimeException('Additive audit table lost');
 echo "INSTALLER_LOCAL_PASS backup, additive migration replay, switch, verify, concurrent drift rejection, rollback and preserved H5\n";
}finally{
 if($created)$pdo->exec('DROP DATABASE `'.$db.'`');
 if(is_dir($base)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($base);}
}

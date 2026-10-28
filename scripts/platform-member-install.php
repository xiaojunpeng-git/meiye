<?php
declare(strict_types=1);
/** Fixed, instance-scoped installation; additive schema precedes application switching. */
set_exception_handler(function(Throwable $e){file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
$dir=$argv[1]??'';$mode=$argv[2]??'';$digest=$argv[3]??'';
if(!preg_match('~^/www/backups/(rh|008|010)/platform-member-([a-f0-9]{8})(?:-retry[1-9][0-9]*)?$~D',$dir,$m)||!in_array($mode,['backup','migrate','install','verify','rollback'],true)||!preg_match('/^[a-f0-9]{64}$/D',$digest)||hash_file('sha256',$dir.'/payload/manifest.json')!==$digest)throw new RuntimeException('Fixed manifest required');
$manifest=json_decode(file_get_contents($dir.'/payload/manifest.json'),true);$site=$m[1];$root='/www/wwwroot/'.($site==='rh'?'rh.cc3798.com':$site.'.cc3798.com');
$database=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]][$site];
if($manifest['site']!==$site||substr($manifest['commit'],0,8)!==$m[2]||hash_file('sha256',$root.'/.env')!==$manifest['environment'])throw new RuntimeException('Wrong site/config');
$env=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($env['DATABASE']??$env['database']);if($c['database']!==$database[0]||(int)($c['hostport']??3306)!==$database[1])throw new RuntimeException('Wrong database');
// 010 keeps its application identity read/write-only. Only the two accepted additive
// migrations use the server-owned DBA socket identity; no grants or client secrets move.
if($mode==='migrate'&&$site==='010'){
 $adminFile='/root/.mysql57-008.cnf';
 if(!is_file($adminFile)||(fileperms($adminFile)&0777)!==0600||fileowner($adminFile)!==0)throw new RuntimeException('Server DBA configuration unavailable');
 $admin=parse_ini_file($adminFile,true,INI_SCANNER_RAW)['client']??[];
 if(($admin['user']??'')!=='root'||($admin['socket']??'')!=='/run/mysql57-008/mysql.sock')throw new RuntimeException('Wrong server DBA identity');
 $pdo=new PDO('mysql:unix_socket='.$admin['socket'].';dbname='.$database[0].';charset=utf8mb4',$admin['user'],$admin['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 if((int)$pdo->query('SELECT @@port')->fetchColumn()!==3307||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='mohe_010_app')throw new RuntimeException('Wrong migration database');
}else $pdo=new PDO('mysql:host='.$c['hostname'].';port='.$database[1].';dbname='.$database[0].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function mh($f){clearstatcache(true,$f);return is_file($f)?hash_file('sha256',$f):null;}
function dest($f){global$root,$manifest;if(strpos($f,'..')!==false||strpos($f,"\0")!==false)throw new RuntimeException('Unsafe path');$allowed=in_array($f,$manifest['backendAllowlist'],true)||$f==='public/system.html'||preg_match('~^public/(view_admin|view_cashier_v3)/[A-Za-z0-9_./-]+$~D',$f);if(!$allowed)throw new RuntimeException('Outside named scope');$p=$root.'/'.$f;if(is_link($p))throw new RuntimeException('Target symlink');$parent=dirname($p);while(!file_exists($parent))$parent=dirname($parent);$real=realpath($parent);if($real!==$root&&strpos($real,$root.'/')!==0)throw new RuntimeException('Escaped root');return$p;}
function put($source,$target){if(!is_dir(dirname($target)))mkdir(dirname($target),0755,true);$next=$target.'.pml-next';if(file_exists($next)||is_link($next))throw new RuntimeException('Pending target');if(!copy($source,$next))throw new RuntimeException('Copy failed');chmod($next,0644);chown($next,is_file($target)?fileowner($target):'www');chgrp($next,is_file($target)?filegroup($target):'www');if(!rename($next,$target))throw new RuntimeException('Atomic switch failed');}
$lock=fopen($dir.'/install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Concurrent installer');
// Guards include the existing member H5/PC entries and unchanged salary/organization files.
foreach($manifest['protected']as$f=>$h)if(mh($root.'/'.$f)!==$h)throw new RuntimeException('Protected baseline changed: '.$f);
$prepared=$dir.'/prepared.json';
if($mode==='backup'){
 if(file_exists($prepared))throw new RuntimeException('Backup exists');$rows=[];
 foreach($manifest['files']as$f=>$row){$target=dest($f);if(mh($target)!==$row['before']||mh($dir.'/payload/files/'.$f)!==$row['after'])throw new RuntimeException('Target/payload drift: '.$f);$backup=$dir.'/before/'.hash('sha256',$f);if($row['before']!==null){if(!is_dir(dirname($backup)))mkdir(dirname($backup),0700,true);if(!copy($target,$backup)||mh($backup)!==$row['before'])throw new RuntimeException('Backup failed');}$rows[$f]=$row+['backup'=>$backup];}
 // Config stays exclusively on its own server, mode 0600; never sent back to the agent.
 if(!copy($root.'/.env',$dir.'/environment.before'))throw new RuntimeException('Config backup failed');chmod($dir.'/environment.before',0600);
 $schema=[];foreach($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$t)$schema[$t]=$pdo->query('SHOW CREATE TABLE `'.str_replace('`','``',$t).'`')->fetch(PDO::FETCH_NUM)[1];file_put_contents($dir.'/database-schema.before.json',json_encode($schema));chmod($dir.'/database-schema.before.json',0600);
 $tableBackup=[];foreach($manifest['migrations']as$mi){$t=$mi['table'];$exists=$pdo->query('SHOW TABLES LIKE '.$pdo->quote($t))->fetchColumn();if((bool)$exists!==$mi['existedBefore'])throw new RuntimeException('Schema baseline changed');$tableBackup[$t]=$exists?['ddl'=>$schema[$t],'rows'=>$pdo->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC)]:null;}
 file_put_contents($dir.'/tables.before.json',json_encode($tableBackup));chmod($dir.'/tables.before.json',0600);file_put_contents($prepared,json_encode($rows));chmod($prepared,0600);
}elseif($mode==='migrate'){
 if(!is_file($prepared))throw new RuntimeException('Backup required');$receipts=[];
 foreach($manifest['migrations']as$mi){$sql=file_get_contents($dir.'/payload/sql/'.$mi['file']);if(hash('sha256',$sql)!==$mi['sha256'])throw new RuntimeException('SQL drift');$old=$pdo->prepare('SELECT sql_checksum FROM eb_database_upgrade_log WHERE upgrade_key=?');$old->execute([$mi['key']]);$h=$old->fetchColumn();if($h!==false&&$h!==$mi['sha256'])throw new RuntimeException('Migration identity conflict');$pdo->exec($sql);
  $cols=$pdo->query('SHOW COLUMNS FROM `'.$mi['table'].'`')->fetchAll(PDO::FETCH_COLUMN);foreach($mi['columns']as$col)if(!in_array($col,$cols,true))throw new RuntimeException('Missing migration column');
  if($h===false){$q=$pdo->prepare('INSERT INTO eb_database_upgrade_log(upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES(?,?,?,?,?,NOW(),?,?)');$q->execute([$mi['key'],$mi['title'],$mi['source'],$mi['sha256'],$manifest['commit'],'Codex/product-manager-authorized','Additive schema only; no historical member/financial rewrite']);}$receipts[]=['key'=>$mi['key'],'sha256'=>$mi['sha256'],'table'=>$mi['table']];
 }
 file_put_contents($dir.'/migration.json',json_encode(['site'=>$site,'commit'=>$manifest['commit'],'migrations'=>$receipts]));chmod($dir.'/migration.json',0600);
}else{
 if(!is_file($prepared))throw new RuntimeException('Prepared backup required');$rows=json_decode(file_get_contents($prepared),true);if(array_keys($rows)!==array_keys($manifest['files']))throw new RuntimeException('Backup scope mismatch');
 if($mode==='install'){if(!is_file($dir.'/migration.json')||json_decode(file_get_contents($dir.'/migration.json'),true)['commit']!==$manifest['commit'])throw new RuntimeException('Migration receipt required');}
 foreach($rows as$f=>$row){$allowed=$mode==='verify'?[$row['after']]:($mode==='install'?[$row['before']]:[$row['before'],$row['after']]);if(!in_array(mh(dest($f)),$allowed,true))throw new RuntimeException('Concurrent target drift: '.$f);if($row['before']!==null&&mh($row['backup'])!==$row['before'])throw new RuntimeException('Backup corrupt');if($mode==='install'&&mh($dir.'/payload/files/'.$f)!==$row['after'])throw new RuntimeException('Payload corrupt');}
 if($mode!=='verify')foreach($mode==='rollback'?array_reverse($rows,true):$rows as$f=>$row){$target=dest($f);if($mode==='rollback'){if($row['before']===null){if(is_file($target))unlink($target);}else put($row['backup'],$target);}else put($dir.'/payload/files/'.$f,$target);}
 foreach($rows as$f=>$row)if(mh(dest($f))!==$row[$mode==='rollback'?'before':'after'])throw new RuntimeException('Final fingerprint mismatch');
}
echo 'PLATFORM_MEMBER_'.strtoupper($mode).'_PASS',PHP_EOL;

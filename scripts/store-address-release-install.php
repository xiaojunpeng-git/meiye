<?php
/** Guarded static member installation. No backend, customer config, database or other frontend write. */
$site=$argv[1]??'';$dir=$argv[2]??'';$mode=$argv[3]??'';$digest=$argv[4]??'';
$sites=['rh'=>['rh.cc3798.com','ruihao',3306],'008'=>['008.cc3798.com','mohe_008',3307],'010'=>['010.cc3798.com','mohe_010_app',3307]];
if(!isset($sites[$site])||!preg_match('~^/www/backups/(rh|008|010)/store-address-[a-f0-9]{8}$~D',$dir,$m)||$m[1]!==$site||!in_array($mode,['backup','install','verify','rollback'],true)||!preg_match('/^[a-f0-9]{64}$/D',$digest))throw new RuntimeException('Explicit release required');
[$domain,$db,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;
if(hash_file('sha256',$dir.'/payload/manifest.json')!==$digest)throw new RuntimeException('Manifest drift');
$manifest=json_decode(file_get_contents($dir.'/payload/manifest.json'),true);
if($manifest['site']!==$site||hash_file('sha256',$root.'/.env')!==$manifest['environment'])throw new RuntimeException('Wrong site/config');
$ini=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($ini['DATABASE']??$ini['database']??[]);
if($c['database']!==$db||(int)($c['hostport']??3306)!==$port)throw new RuntimeException('Database mismatch');
function addressHash($f){clearstatcache(true,$f);return is_file($f)?hash_file('sha256',$f):null;}
function addressTarget($f){global $root;
 if(strpos($f,'..')!==false||strpos($f,"\0")!==false||!preg_match('~^public/(index\.html|static/(js/[^/]+\.js(?:\.gz)?|css/[^/]+\.css(?:\.gz)?|index\.[a-f0-9]+\.css)|pages/activity/static/poster/[A-Za-z0-9]+\.png)$~D',$f))throw new RuntimeException('Outside member scope: '.$f);
 $p=$root.'/'.$f;if(is_link($p))throw new RuntimeException('Symlink target');return $p;
}
function addressPut($source,$target){if(!is_dir(dirname($target)))mkdir(dirname($target),0755,true);$next=$target.'.address-next';if(file_exists($next)||is_link($next))throw new RuntimeException('Pending file');if(!copy($source,$next))throw new RuntimeException('Copy failed');chmod($next,0644);chown($next,'www');chgrp($next,'www');if(!rename($next,$target))throw new RuntimeException('Atomic switch failed');}
$lock=fopen($dir.'/install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Concurrent release');
foreach($manifest['protected']as $f=>$sha)if(addressHash($root.'/'.$f)!==$sha)throw new RuntimeException('Protected frontend drift');
$receipt=$dir.'/prepared.json';
if($mode==='backup'){
 if(file_exists($receipt))throw new RuntimeException('Already prepared');$rows=[];
 foreach($manifest['files']as $f=>$row){$target=addressTarget($f);if(addressHash($target)!==$row['before']||addressHash($dir.'/payload/'.$f)!==$row['after'])throw new RuntimeException('Baseline/payload drift: '.$f);$backup=$dir.'/before/'.hash('sha256',$f);if($row['before']!==null){if(!is_dir(dirname($backup)))mkdir(dirname($backup),0700,true);if(!copy($target,$backup)||addressHash($backup)!==$row['before'])throw new RuntimeException('Backup failed');}$rows[$f]=$row+['backup'=>$backup];}
 file_put_contents($receipt,json_encode($rows));chmod($receipt,0600);
}else{
 $rows=json_decode(file_get_contents($receipt),true);if(!is_array($rows)||array_keys($rows)!==array_keys($manifest['files']))throw new RuntimeException('Missing exact backup');
 foreach($rows as $f=>$row){$allowed=$mode==='verify'?[$row['after']]:($mode==='install'?[$row['before']]:[$row['before'],$row['after']]);if(!in_array(addressHash(addressTarget($f)),$allowed,true))throw new RuntimeException('Concurrent change');if($row['before']!==null&&addressHash($row['backup'])!==$row['before'])throw new RuntimeException('Backup corrupt');if($mode==='install'&&addressHash($dir.'/payload/'.$f)!==$row['after'])throw new RuntimeException('Payload corrupt');}
 if($mode!=='verify')foreach($rows as $f=>$row){$target=addressTarget($f);if($mode==='rollback'){if($row['before']===null){if(is_file($target))unlink($target);}else addressPut($row['backup'],$target);}else addressPut($dir.'/payload/'.$f,$target);}
 foreach($rows as $f=>$row)if(addressHash(addressTarget($f))!==$row[$mode==='rollback'?'before':'after'])throw new RuntimeException('Final hash mismatch');
}
echo 'ADDRESS_'.strtoupper($mode).'_PASS',PHP_EOL;

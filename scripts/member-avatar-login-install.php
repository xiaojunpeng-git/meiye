<?php
/** Fixed single-file 008 login repair; never touches user rows, schema or another site. */
$dir=$argv[1]??'';$mode=$argv[2]??'';$before='cebc2908227059a7cffd8f54de88e9d67241d6591d93eedaeb55cc872cb75b55';$after='c35429f3cd08697c341fe038c98728048ee7b978ade80b1ca215fc78586ce1ff';
if(!preg_match('~^/www/backups/008/member-avatar-login-[a-f0-9]{8}$~D',$dir)||!in_array($mode,['backup','install','verify','rollback'],true))throw new RuntimeException('Fixed 008 target required');
$root='/www/wwwroot/008.cc3798.com';$target=$root.'/app/services/wechat/WechatUserServices.php';$payload=$dir.'/candidate.php';$backup=$dir.'/before.php';
$ini=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($ini['DATABASE']??$ini['database']??[]);if($c['database']!=='mohe_008'||(int)$c['hostport']!==3307)throw new RuntimeException('Wrong database');
$lock=fopen($dir.'/install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Concurrent installer');
function avatarHash($p){clearstatcache(true,$p);return is_file($p)?hash_file('sha256',$p):null;}
if(is_link($target)||is_link($payload))throw new RuntimeException('Symlink forbidden');
if($mode==='backup'){
 if(file_exists($backup)||avatarHash($target)!==$before||avatarHash($payload)!==$after)throw new RuntimeException('Baseline/payload drift');
 if(!copy($target,$backup)||avatarHash($backup)!==$before)throw new RuntimeException('Backup failed');chmod($backup,0600);file_put_contents($dir.'/environment.sha256',hash_file('sha256',$root.'/.env'));
}else{
 if(avatarHash($backup)!==$before||trim(file_get_contents($dir.'/environment.sha256'))!==hash_file('sha256',$root.'/.env'))throw new RuntimeException('Backup/config drift');
 $expected=$mode==='verify'?$after:($mode==='install'?$before:$after);if(avatarHash($target)!==$expected)throw new RuntimeException('Target changed');
 if($mode!=='verify'){$source=$mode==='install'?$payload:$backup;if(avatarHash($source)!==($mode==='install'?$after:$before))throw new RuntimeException('Source corrupt');$next=$target.'.avatar-next';if(file_exists($next)||is_link($next))throw new RuntimeException('Pending stage');if(!copy($source,$next))throw new RuntimeException('Copy failed');chmod($next,0644);chown($next,fileowner($target));chgrp($next,filegroup($target));if(!rename($next,$target))throw new RuntimeException('Atomic switch failed');}
 if(avatarHash($target)!==($mode==='rollback'?$before:$after))throw new RuntimeException('Final fingerprint mismatch');
}
echo 'AVATAR_'.strtoupper($mode).'_PASS',PHP_EOL;

<?php
/** 文件级部署器：仅清单目标、逐文件备份、资源先于入口、失败只回滚本次且无并发变更的文件。 */
$dir=$argv[1]??'';$mode=$argv[2]??'';
if(!preg_match('~^/www/backups/(rh|008|010)/r37-card-labor-[a-f0-9]{8}$~',$dir,$m)||!in_array($mode,['install','rollback'],true))throw new RuntimeException('EXPLICIT_SCOPE_REQUIRED');
$site=$m[1];$domain=$site==='rh'?'rh.cc3798.com':$site.'.cc3798.com';$root='/www/wwwroot/'.$domain;
$manifest=json_decode(file_get_contents($dir.'/payload/manifest.json'),true);if($manifest['site']!==$site||$dir!=='/www/backups/'.$site.'/r37-card-labor-'.substr($manifest['commit'],0,8))throw new RuntimeException('INSTANCE_MISMATCH');
function digest33($p){return is_file($p)?hash_file('sha256',$p):null;}
function put33($src,$dst){if(!is_dir(dirname($dst)))mkdir(dirname($dst),0755,true);$tmp=$dst.'.r37-card-next';if(!copy($src,$tmp))throw new RuntimeException('COPY_FAILED');chmod($tmp,0644);chown($tmp,is_file($dst)?fileowner($dst):'www');chgrp($tmp,is_file($dst)?filegroup($dst):'www');if(!rename($tmp,$dst))throw new RuntimeException('SWITCH_FAILED');}
$record=$dir.'/prepared.json';
if($mode==='install'){
 if(is_file($record))throw new RuntimeException('ALREADY_PREPARED');$targets=[];
 foreach($manifest['targets']as $rel=>$x){if(strpos($rel,'..')!==false||$rel[0]==='/')throw new RuntimeException('UNSAFE_PATH');$dst=$root.'/'.$rel;$src=$dir.'/payload/'.$rel;
  if(digest33($src)!==$x['after']||digest33($dst)!==$x['before'])throw new RuntimeException('BASELINE_CHANGED_'.$rel);
  $old=$dir.'/before/'.hash('sha256',$rel);if($x['before']!==null){if(!is_dir(dirname($old)))mkdir(dirname($old),0700,true);if(!copy($dst,$old)||digest33($old)!==$x['before'])throw new RuntimeException('BACKUP_FAILED');}
  $targets[$rel]=$x+['backup'=>$old];
 }
 file_put_contents($record,json_encode($targets,JSON_PRETTY_PRINT));chmod($record,0600);
 try{foreach($targets as $rel=>$x)put33($dir.'/payload/'.$rel,$root.'/'.$rel);}catch(Throwable $e){foreach(array_reverse($targets,true)as $rel=>$x)if(digest33($root.'/'.$rel)===$x['after']){if($x['before']===null)unlink($root.'/'.$rel);else put33($x['backup'],$root.'/'.$rel);}throw $e;}
}else{
 $targets=json_decode(file_get_contents($record),true);
 foreach($targets as $rel=>$x)if(!in_array(digest33($root.'/'.$rel),[$x['before'],$x['after']],true))throw new RuntimeException('CONCURRENT_CHANGE_'.$rel);
 foreach(array_reverse($targets,true)as $rel=>$x){if($x['before']===null){if(is_file($root.'/'.$rel))unlink($root.'/'.$rel);}else put33($x['backup'],$root.'/'.$rel);}
}
echo 'R37_CARD_FILES_'.strtoupper($mode).'_PASS',PHP_EOL;

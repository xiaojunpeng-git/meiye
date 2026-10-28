<?php
/** R39静态文件CAS切换：只允许正式门店集成包资源，先完整备份，入口最后切换；回滚不覆盖并发发布。 */
$dir=$argv[1]??'';$mode=$argv[2]??'';
if(!preg_match('~^/www/backups/(rh|008|010)/r39-rank-scroll-[a-f0-9]{8}$~D',$dir,$match)||!in_array($mode,['install','rollback'],true))throw new RuntimeException('EXPLICIT_SCOPE_REQUIRED');
$site=$match[1];$root='/www/wwwroot/'.$site.'.cc3798.com';$manifest=json_decode(file_get_contents($dir.'/payload/manifest.json'),true);
if(($manifest['site']??'')!==$site||$dir!=='/www/backups/'.$site.'/r39-rank-scroll-'.substr($manifest['commit'],0,8))throw new RuntimeException('WRONG_INSTANCE');
function digest39($file){return is_file($file)?hash_file('sha256',$file):null;}
function put39($source,$destination){$temporary=$destination.'.r39-next';if(!is_dir(dirname($destination)))mkdir(dirname($destination),0755,true);if(!copy($source,$temporary))throw new RuntimeException('COPY_FAILED');chmod($temporary,0644);chown($temporary,'www');chgrp($temporary,'www');if(!rename($temporary,$destination))throw new RuntimeException('SWITCH_FAILED');}
$record=$dir.'/prepared.json';
if($mode==='install'){
 if(is_file($record))throw new RuntimeException('ALREADY_PREPARED');$targets=[];
 foreach($manifest['targets']as $path=>$value){
  if(!preg_match('~^public/view_cashier_v3/(?:index\.html|assets/[A-Za-z0-9_.-]+\.(?:js|css))$~D',$path))throw new RuntimeException('OUT_OF_SCOPE');
  if(digest39($root.'/'.$path)!==$value['before']||digest39($dir.'/payload/'.$path)!==$value['after'])throw new RuntimeException('BASELINE_CHANGED');
  $backup=$dir.'/before/'.hash('sha256',$path);if($value['before']!==null){if(!is_dir(dirname($backup)))mkdir(dirname($backup),0700,true);if(!copy($root.'/'.$path,$backup)||digest39($backup)!==$value['before'])throw new RuntimeException('BACKUP_FAILED');}
  $targets[$path]=$value+['backup'=>$backup];
 }
 file_put_contents($record,json_encode($targets,JSON_PRETTY_PRINT));chmod($record,0600);
 try{foreach($targets as $path=>$value)put39($dir.'/payload/'.$path,$root.'/'.$path);}catch(Throwable $e){foreach(array_reverse($targets,true)as $path=>$value)if(digest39($root.'/'.$path)===$value['after']){if($value['before']===null)unlink($root.'/'.$path);else put39($value['backup'],$root.'/'.$path);}throw $e;}
}else{
 $targets=json_decode(file_get_contents($record),true);foreach($targets as $path=>$value)if(!in_array(digest39($root.'/'.$path),[$value['before'],$value['after']],true))throw new RuntimeException('CONCURRENT_PUBLISH');
 foreach(array_reverse($targets,true)as $path=>$value){if($value['before']===null){if(is_file($root.'/'.$path))unlink($root.'/'.$path);}else put39($value['backup'],$root.'/'.$path);}
}
echo 'R39_STATIC_'.strtoupper($mode).'_PASS',PHP_EOL;

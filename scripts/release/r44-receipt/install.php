<?php
/** 小票发布只切正式门店静态包：固定清单、完整文件备份、CAS防并发，入口及压缩入口最后切换。 */
$dir=$argv[1]??'';$mode=$argv[2]??'';$expected=$argv[3]??'';
if(!preg_match('~^/www/backups/(rh|008|010)/r44-unified-receipt-[a-f0-9]{8}$~D',$dir,$m)||!in_array($mode,['install','rollback'],true)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||hash_file('sha256',$dir.'/payload/manifest.json')!==$expected)throw new RuntimeException('Fixed manifest required');
$root='/www/wwwroot/'.$m[1].'.cc3798.com';$manifest=json_decode(file_get_contents($dir.'/payload/manifest.json'),true);
if($manifest['site']!==$m[1]||substr($manifest['commit'],0,8)!==substr($dir,-8))throw new RuntimeException('Wrong instance');
function digest44($p){clearstatcache(true,$p);return is_file($p)?hash_file('sha256',$p):null;}
function target44($p){global $root;if(!preg_match('~^public/view_cashier_v3/(?:index\.html(?:\.(?:gz|br))?|assets/[A-Za-z0-9_.-]+\.(?:js|css)(?:\.(?:gz|br))?)$~D',$p))throw new RuntimeException('Outside static scope');$f=$root.'/'.$p;if(is_link($f))throw new RuntimeException('Target symlink');$parent=dirname($f);while(!file_exists($parent))$parent=dirname($parent);if(strpos(realpath($parent).'/',realpath($root).'/')!==0)throw new RuntimeException('Escaped root');return $f;}
function put44($from,$to){if(!is_dir(dirname($to)))mkdir(dirname($to),0755,true);$tmp=$to.'.r44-next';if(file_exists($tmp)||is_link($tmp)||!copy($from,$tmp))throw new RuntimeException('Stage failed');chmod($tmp,0644);chown($tmp,'www');chgrp($tmp,'www');if(!rename($tmp,$to))throw new RuntimeException('Switch failed');}
$lock=fopen($dir.'/install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Concurrent installer');
foreach($manifest['protected']as$p=>$h)if(digest44($root.'/'.$p)!==$h)throw new RuntimeException('Protected entry drift: '.$p);
$record=$dir.'/prepared.json';
if($mode==='install'){
 if(is_file($record))throw new RuntimeException('Already installed');$rows=[];
 foreach($manifest['targets']as$p=>$v){$f=target44($p);if(digest44($f)!==$v['before']||digest44($dir.'/payload/'.$p)!==$v['after'])throw new RuntimeException('Baseline/payload drift: '.$p);$backup=$dir.'/before/'.hash('sha256',$p);if($v['before']!==null){if(!is_dir(dirname($backup)))mkdir(dirname($backup),0700,true);if(!copy($f,$backup)||digest44($backup)!==$v['before'])throw new RuntimeException('Backup failed');}$rows[$p]=$v+['backup'=>$backup];}
 if(file_put_contents($record,json_encode($rows,JSON_PRETTY_PRINT))===false)throw new RuntimeException('Backup receipt failed');chmod($record,0600);
 try{foreach($rows as$p=>$v)put44($dir.'/payload/'.$p,target44($p));}catch(Throwable $e){foreach(array_reverse($rows,true)as$p=>$v)if(digest44(target44($p))===$v['after']){if($v['before']===null)unlink(target44($p));else put44($v['backup'],target44($p));}throw $e;}
 foreach($rows as$p=>$v)if(digest44(target44($p))!==$v['after'])throw new RuntimeException('Verification failed');
}else{
 $rows=json_decode(file_get_contents($record),true);if(!is_array($rows))throw new RuntimeException('Backup required');foreach($rows as$p=>$v)if(!in_array(digest44(target44($p)),[$v['before'],$v['after']],true))throw new RuntimeException('Concurrent deployment');
 foreach(array_reverse($rows,true)as$p=>$v){if($v['before']===null){if(is_file(target44($p)))unlink(target44($p));}else{if(digest44($v['backup'])!==$v['before'])throw new RuntimeException('Backup corrupt');put44($v['backup'],target44($p));}}
}
echo 'R44_'.strtoupper($mode).'_PASS',PHP_EOL;

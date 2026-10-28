<?php
declare(strict_types=1);
/** 第八轮文件发布器：只更新已验收两个后端及商家H5；不执行SQL迁移或业务写入。 */
$site=$argv[1]??'';$backup=$argv[2]??'';$phase=$argv[3]??'';
$targets=['rh'=>'ruihao','008'=>'mohe_008','010'=>'mohe_010_app'];
if(!isset($targets[$site])||!preg_match('#^/www/backups/'.preg_quote($site,'#').'/round8-[a-f0-9]{12}-[0-9]{14}$#D',$backup)||!in_array($phase,['prepare','install','activate','verify','rollback'],true))throw new RuntimeException('Explicit release required');
$root='/www/wwwroot/'.$site.'.cc3798.com';$manifest=json_decode(file_get_contents($backup.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if($manifest['site']!==$site)throw new RuntimeException('Wrong payload instance');
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage());exit(1);});
function round8Hash(string $p):?string{return is_file($p)?hash_file('sha256',$p):null;}
function round8Copy(string $from,string $to):void{
 if(!is_dir(dirname($to)))mkdir(dirname($to),0755,true);
 $tmp=$to.'.round8-release';if(!copy($from,$tmp))throw new RuntimeException('Copy failed');
 chmod($tmp,0644);chown($tmp,'www');chgrp($tmp,'www');if(!rename($tmp,$to))throw new RuntimeException('Atomic rename failed');
}
foreach($manifest['files'] as $p=>$hash){
 if(strpos($p,'..')!==false||!preg_match('#^(app/services/(mobile/warehouse/MobileWarehouseServices|query/metric/RegisteredMetricReadServices)\.php$|public/view_mobile_v3/)#D',$p)||round8Hash($backup.'/payload/files/'.$p)!==$hash)throw new RuntimeException('Payload outside fixed scope');
}
if($phase==='prepare'){
 if(is_file($backup.'/before.json'))throw new RuntimeException('Backup already exists');
 foreach($manifest['baseline_files'] as $p=>$hash)if(round8Hash($root.'/'.$p)!==$hash)throw new RuntimeException('Live baseline changed: '.$p);
 foreach($manifest['before'] as $p=>$hash){
  if(round8Hash($root.'/'.$p)!==$hash)throw new RuntimeException('Deployment baseline changed: '.$p);
  if($hash!==null){$to=$backup.'/before/'.$p;if(!is_dir(dirname($to)))mkdir(dirname($to),0700,true);if(!copy($root.'/'.$p,$to))throw new RuntimeException('Backup failed');chmod($to,0600);if(round8Hash($to)!==$hash)throw new RuntimeException('Backup mismatch');}
 }
 file_put_contents($backup.'/before.json',json_encode(['files'=>$manifest['before'],'source_commit'=>$manifest['source_commit']],JSON_THROW_ON_ERROR));chmod($backup.'/before.json',0600);
 echo json_encode(['result'=>'PREPARED','site'=>$site,'files'=>count($manifest['files']),'database_write'=>false]);exit;
}
$before=json_decode(file_get_contents($backup.'/before.json'),true,512,JSON_THROW_ON_ERROR);
if($phase==='rollback'){
 foreach($before['files'] as $p=>$hash){$current=round8Hash($root.'/'.$p);if($current!==$hash&&$current!==$manifest['files'][$p])throw new RuntimeException('Concurrent change prevents rollback: '.$p);}
 foreach($before['files'] as $p=>$hash){if($hash!==null)round8Copy($backup.'/before/'.$p,$root.'/'.$p);elseif(round8Hash($root.'/'.$p)===$manifest['files'][$p])unlink($root.'/'.$p);}
 echo json_encode(['result'=>'ROLLED_BACK','site'=>$site]);exit;
}
if($phase==='install'){
 foreach($manifest['baseline_files'] as $p=>$hash)if(round8Hash($root.'/'.$p)!==$hash)throw new RuntimeException('Prepared baseline changed: '.$p);
 foreach($before['files'] as $p=>$hash)if(round8Hash($root.'/'.$p)!==$hash)throw new RuntimeException('Candidate path changed: '.$p);
 foreach($manifest['files'] as $p=>$hash)if($p!=='public/view_mobile_v3/index.html')round8Copy($backup.'/payload/files/'.$p,$root.'/'.$p);
 echo json_encode(['result'=>'INSTALLED','site'=>$site]);exit;
}
if($phase==='activate'){
 round8Copy($backup.'/payload/files/public/view_mobile_v3/index.html',$root.'/public/view_mobile_v3/index.html');
 echo json_encode(['result'=>'ACTIVATED','site'=>$site]);exit;
}
foreach($manifest['files'] as $p=>$hash)if(round8Hash($root.'/'.$p)!==$hash)throw new RuntimeException('Installed hash mismatch: '.$p);
foreach($manifest['preserve'] as $p=>$hash)if(round8Hash($root.'/'.$p)!==$hash)throw new RuntimeException('Unaffected entry changed: '.$p);
echo json_encode(['result'=>'PASS','site'=>$site,'source_commit'=>$manifest['source_commit'],'files'=>count($manifest['files']),'database_write'=>false]);

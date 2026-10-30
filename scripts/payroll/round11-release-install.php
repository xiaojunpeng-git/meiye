<?php
declare(strict_types=1);
/** 第11轮发布事务边界：逐实例原件备份、定向元数据安装和受并发指纹保护的回滚。 */
$site=$argv[1]??'';$backup=$argv[2]??'';$phase=$argv[3]??'';
$targets=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]];
if(!isset($targets[$site])||!preg_match('#^/www/backups/'.preg_quote($site,'#').'/round11-[a-f0-9]{12}-[0-9]{14}$#D',$backup)
 ||!in_array($phase,['prepare','install','activate','verify','rollback'],true))throw new RuntimeException('Explicit target/phase required');
set_exception_handler(static function(Throwable$error):void{fwrite(STDERR,(string)$error);exit(1);});
$root='/www/wwwroot/'.$site.'.cc3798.com';chdir($root);require 'vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!==$targets[$site][0]||(int)config('database.connections.mysql.hostport')!==$targets[$site][1])throw new RuntimeException('Database mismatch');
$m=json_decode(file_get_contents($backup.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if($m['site']!==$site||!preg_match('/^[a-f0-9]{40}$/D',$m['source_commit']))throw new RuntimeException('Wrong payload');
function r11hash(string$p):?string{return is_file($p)?hash_file('sha256',$p):null;}
function r11json(string$p,$value):void{if(file_put_contents($p,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Evidence write failed');chmod($p,0600);}
function r11copy(string$from,string$to):void{
 if(!is_dir(dirname($to)))mkdir(dirname($to),0755,true);
 $tmp=$to.'.r11-release';if(!copy($from,$tmp))throw new RuntimeException('Copy failed');
 chmod($tmp,0644);chown($tmp,'www');chgrp($tmp,'www');if(!rename($tmp,$to))throw new RuntimeException('Atomic replace failed');
}
/** 仅本轮可变元数据；角色/岗位完整摘要同时保护其状态与已有权限。 */
function r11metadata():array{
 return ['catalog'=>Db::name('store_v3_permission_catalog')->where('id',1)->find(),
 'menu'=>Db::name('system_menus')->where('unique_auth','admin-report-basic_store_reconciliation')->order('id')->select()->toArray(),
 'audit'=>Db::name('store_v3_permission_audit')->where('action','INITIALIZE_BASIC_REPORT_NAVIGATION')->order('id')->select()->toArray(),
 'grants_sha256'=>hash('sha256',json_encode([Db::name('system_role')->order('id')->select()->toArray(),Db::name('job_position_channel_rule')->order('id')->select()->toArray()],JSON_THROW_ON_ERROR))];
}
$allowed=['app/controller/admin/v1/report/UnifiedReport.php','app/services/order/ValidCashOrderServices.php','app/services/organization/StorePermissionCatalogServices.php',
 'app/services/report/BasicReportCatalogServices.php','app/services/report/BasicReportHostServices.php','app/services/report/query/BasicBusinessQueryRegistrar.php','config/unified_query.php',
 'app/services/report/StoreReconciliationMatrix.php','app/services/report/StoreReconciliationReceiptReader.php','app/services/report/StoreReconciliationReportServices.php',
 'app/services/report/query/BasicStoreReconciliationQueryProvider.php','app/services/report/query/BasicStoreReconciliationQueryRegistrar.php','app/services/report/query/BasicStoreReconciliationWorkerContextResolver.php',
 'database/upgrades/2026-10-28-门店对账单/01-平台菜单.sql'];
foreach($m['files']as$p=>$hash){
 if(strpos($p,'..')!==false||(!in_array($p,$allowed,true)&&!preg_match('#^public/view_cashier_v3/(index\.html|assets/[a-zA-Z0-9._-]+\.(js|css|png))$#D',$p)))throw new RuntimeException('Unexpected file scope');
 if(r11hash($backup.'/payload/files/'.$p)!==$hash)throw new RuntimeException('Payload mismatch: '.$p);
}
if($phase==='prepare'){
 if(is_file($backup.'/metadata-before.json'))throw new RuntimeException('Already prepared');
 foreach($m['baseline_files']as$p=>$h)if(r11hash($root.'/'.$p)!==$h)throw new RuntimeException('Live baseline changed: '.$p);
 foreach($m['before']as$p=>$h)if($h!==null){$to=$backup.'/before/'.$p;if(!is_dir(dirname($to)))mkdir(dirname($to),0700,true);if(!copy($root.'/'.$p,$to)||r11hash($to)!==$h)throw new RuntimeException('Backup failed');chmod($to,0600);}
 r11json($backup.'/files-before.json',$m['before']);r11json($backup.'/metadata-before.json',r11metadata());
 echo json_encode(['result'=>'PREPARED','site'=>$site,'files'=>count($m['files']),'business_writes'=>0]);exit;
}
$before=json_decode(file_get_contents($backup.'/metadata-before.json'),true,512,JSON_THROW_ON_ERROR);
if($phase==='install'){
 foreach($m['baseline_files']as$p=>$h)if(r11hash($root.'/'.$p)!==$h)throw new RuntimeException('Prepared baseline changed: '.$p);
 if(r11metadata()!==$before)throw new RuntimeException('Prepared metadata changed');
 // 所有资源/新增依赖先写入；最后切换入口，旧静态资源保留供已经打开的页面使用。
 $paths=array_keys($m['files']);usort($paths,static function($a,$b){return($a==='public/view_cashier_v3/index.html')<=>($b==='public/view_cashier_v3/index.html');});
 foreach($paths as$p)r11copy($backup.'/payload/files/'.$p,$root.'/'.$p);
 echo json_encode(['result'=>'INSTALLED','site'=>$site]);exit;
}
if($phase==='activate'){
 foreach($m['files']as$p=>$h)if(r11hash($root.'/'.$p)!==$h)throw new RuntimeException('Installed code changed');
 if(r11metadata()!==$before)throw new RuntimeException('Metadata changed before activation');
 // 已补验的安装脚本在独立CLI启动中读取新代码；只安装一个菜单和一个目录节点。
 $command='BACKEND_ROOT='.escapeshellarg($root).' R11_METADATA_INSTALL=1 R11_EXPECTED_DATABASE='.escapeshellarg($targets[$site][0])
  .' /www/server/php/74/bin/php '.escapeshellarg($backup.'/round11-reconciliation-install.php');
 exec($command.' 2>&1',$output,$exit);
 // 即使子进程提交后因输出失败退出，也保存本次实际元数据，供精确回滚核对。
 r11json($backup.'/metadata-after.json',r11metadata());
 if($exit!==0)throw new RuntimeException('Metadata installer failed: '.implode("\n",$output));
 $result=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
 if(!$result['existing_grants_unchanged']||$result['business_rows_written']!==0)throw new RuntimeException('Unexpected activation result');
 if(r11metadata()['grants_sha256']!==$before['grants_sha256'])throw new RuntimeException('Existing grants changed');
 mohe\services\CacheService::redisHandler('system_menus')->clear();
 echo json_encode(['result'=>'ACTIVATED','site'=>$site,'metadata'=>$result,'business_writes'=>0]);exit;
}
if($phase==='rollback'){
 foreach($m['before']as$p=>$h)if(!in_array(r11hash($root.'/'.$p),[$h,$m['files'][$p]],true))throw new RuntimeException('Concurrent file change prevents rollback');
 $afterPath=$backup.'/metadata-after.json';
 if(is_file($afterPath)){
  $after=json_decode(file_get_contents($afterPath),true,512,JSON_THROW_ON_ERROR);
  Db::transaction(static function()use($before,$after):void{
   Db::name('store_v3_permission_catalog')->where('id',1)->lock(true)->find();
   if(r11metadata()!==$after)throw new RuntimeException('Concurrent metadata change prevents rollback');
   Db::name('store_v3_permission_catalog')->where('id',1)->update($before['catalog']);
   $oldIds=array_column($before['menu'],'id');foreach($after['menu']as$row)if(!in_array($row['id'],$oldIds,true))Db::name('system_menus')->where('id',$row['id'])->delete();
   $oldAuditIds=array_column($before['audit'],'id');foreach($after['audit']as$row)if(!in_array($row['id'],$oldAuditIds,true))Db::name('store_v3_permission_audit')->where('id',$row['id'])->delete();
   if(r11metadata()!==$before)throw new RuntimeException('Metadata rollback mismatch');
  });
 }
 foreach($m['before']as$p=>$h){if($h!==null)r11copy($backup.'/before/'.$p,$root.'/'.$p);elseif(r11hash($root.'/'.$p)===$m['files'][$p])unlink($root.'/'.$p);}
 mohe\services\CacheService::redisHandler('system_menus')->clear();
 echo json_encode(['result'=>'ROLLED_BACK','site'=>$site]);exit;
}
foreach($m['files']as$p=>$h)if(r11hash($root.'/'.$p)!==$h)throw new RuntimeException('Live file mismatch: '.$p);
foreach($m['preserve']as$p=>$h)if(r11hash($root.'/'.$p)!==$h)throw new RuntimeException('Preserved file changed: '.$p);
$after=r11metadata();$expected=json_decode(file_get_contents($backup.'/metadata-after.json'),true,512,JSON_THROW_ON_ERROR);
if($after!==$expected||$after['grants_sha256']!==$before['grants_sha256'])throw new RuntimeException('Metadata or grants drift');
$catalog=new app\services\organization\StorePermissionCatalogServices();$snapshot=$catalog->snapshot();
if(!in_array(-33022,array_column($snapshot['rules'],'id'),true)||count($after['menu'])!==1)throw new RuntimeException('New menu/catalog missing');
echo json_encode(['result'=>'PASS','site'=>$site,'source_commit'=>$m['source_commit'],'files'=>count($m['files']),'grants_unchanged'=>true,'business_writes'=>0]);

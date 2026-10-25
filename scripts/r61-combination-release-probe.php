<?php
/** Read-only inventory for the three named instances. Emit hashes/schema only, never configuration secrets. */
$site=$argv[1]??'';
$targets=['rh'=>['rh.cc3798.com','ruihao','3306'],'008'=>['008.cc3798.com','mohe_008','3307'],'010'=>['010.cc3798.com','mohe_010_app','3307']];
if(!isset($targets[$site]))throw new RuntimeException('Explicit instance required');
[$domain,$database,$port]=$targets[$site];$root='/www/wwwroot/'.$domain;
$ini=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($ini['DATABASE']??$ini['database']??[]);
if($c['database']!==$database||(string)$c['hostport']!==$port||$c['prefix']!=='eb_')throw new RuntimeException('Database identity mismatch');
$out=['site'=>$site,'database'=>$database,'backend'=>[],'entries'=>[],'schema'=>[]];
foreach(['app','route','config','mohe']as $dir)foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS))as $f)
 if($f->isFile())$out['backend'][substr($f->getPathname(),strlen($root)+1)]=hash_file('sha256',$f->getPathname());
ksort($out['backend']);
foreach(['public/system.html','public/index.html','public/view_cashier_v3/index.html','public/view_mobile_v3/index.html','.env']as $f)$out['entries'][$f]=is_file($root.'/'.$f)?hash_file('sha256',$root.'/'.$f):null;
$out['platformAssets']=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/view_admin',FilesystemIterator::SKIP_DOTS))as $f)
 if($f->isFile())$out['platformAssets'][substr($f->getPathname(),strlen($root.'/public/'))]=hash_file('sha256',$f->getPathname());
ksort($out['platformAssets']);
$pdo=new PDO('mysql:host='.$c['hostname'].';port='.$port.';dbname='.$database.';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
try{
 foreach(['combination_group_origin','combination_force_completion','combination_payment_origin','combination_order_fulfillment','combination_order_refund']as $t){
  $table='eb_'.$t;$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);
  $out['schema'][$table]=(int)$s->fetchColumn()?$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1]:null;
 }
 $out['upgrades']=$pdo->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
}finally{$pdo->rollBack();}
echo gzencode(json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

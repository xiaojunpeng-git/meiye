<?php
/** Synthetic catalog/media only; never import real customer data into the test DB. */
declare(strict_types=1);
require dirname(__DIR__,3).'/scripts/import/customer_008_material_library.php';

function mediaTest(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function mediaTestCleanup(string $dir):void
{
    if(!is_dir($dir))return;
    chmod($dir,0700);
    foreach(scandir($dir) as $name){if($name==='.'||$name==='..')continue;$path=$dir.'/'.$name;if(is_dir($path))mediaTestCleanup($path);else unlink($path);}
    rmdir($dir);
}

$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jxXcAAAAASUVORK5CYII=');
$bodies=[$png.'a',$png.'b',$png.'c'];$shas=array_map(static function($b){return hash('sha256',$b);},$bodies);
$report=['source_sha256'=>str_repeat('a',64),'source_categories_sha256'=>str_repeat('b',64),'target_all_sha256'=>'','target_categories_sha256'=>media008Sha([]),
    'categories'=>[['id'=>'1','pid'=>'0','type'=>'1','file_type'=>'1','relation_id'=>'0','name'=>'Root','enname'=>''],['id'=>'2','pid'=>'1','type'=>'1','file_type'=>'1','relation_id'=>'0','name'=>'Blue','enname'=>'']],
    'objects'=>[],'catalog'=>[]];
foreach([['a',[1],2,0],['b',[2,3],1,1],['c',[4],2,1],['d',[5],0,2]] as $fixture){
    [$key,$ids,$pid,$which]=$fixture;$image=['sha256'=>$shas[$which],'bytes'=>strlen($bodies[$which]),'mime'=>'image/png','width'=>1,'height'=>1];
    $report['catalog'][$key]=['source_ids'=>$ids,'source_pid'=>(string)$pid,'metadata'=>['att_id'=>(string)max($ids),'time'=>'100','real_name'=>$key.'.png']];
    $report['objects']['source:'.$key]=['kind'=>'source','key'=>$key,'ok'=>true,'read_mode'=>'qiniu','url'=>'https://myqiniu.cc3798.com/'.$key.'.png','image'=>$image];
}
$report['catalog']['missing']=['source_ids'=>[60]];
$report['objects']['source:missing']=['kind'=>'source','key'=>'missing','ok'=>false,'error'=>'missing_file'];
$report['objects']['target:25']=['kind'=>'target','key'=>'25','ok'=>true,'image'=>['sha256'=>$shas[0]]];
$plan=media008Plan($report);
mediaTest(count($plan['groups'])===4&&count($plan['assets'])===2&&count($plan['reuse'])===1,'Content/category dedup count wrong');
mediaTest($plan['reuse']===[25=>2]&&$plan['skipped']===[60],'Existing picture or excluded record mismatch');
mediaTest(count(array_filter($plan['groups'],static function($g)use($shas){return $g['sha256']===$shas[1];}))===2,'Cross-category reference was lost');
foreach(['cycle','missing_parent','wrong_scope','unapproved_missing','ambiguous_target'] as $case){
    $r=$report;
    if($case==='cycle')$r['categories'][0]['pid']='2';
    if($case==='missing_parent')$r['categories'][1]['pid']='99';
    if($case==='wrong_scope')$r['categories'][0]['type']='2';
    if($case==='unapproved_missing')$r['catalog']['missing']['source_ids']=[61];
    if($case==='ambiguous_target')$r['objects']['target:26']=['kind'=>'target','key'=>'26','ok'=>true,'image'=>['sha256'=>$shas[0]]];
    $caught=false;try{media008Plan($r);}catch(RuntimeException $e){$caught=true;}mediaTest($caught,$case.' was accepted');
}
echo "Material plan/content+category dedup/existing reuse/scope/tree/skip guards: PASS\n";
if(($argv[1]??'')!=='--mysql')exit(0);

$p=new PDO('mysql:host=127.0.0.1;port=13318;charset=utf8mb4','root','rights-test-local',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::ATTR_EMULATE_PREPARES=>false]);
$identity=$p->query('SELECT @@port port,VERSION() version')->fetch();mediaTest((int)$identity['port']===3306&&strpos($identity['version'],'5.7.')===0,'Not disposable MySQL5.7');
$database='m008_material_library_test';$p->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4');$p->exec('USE '.$database);
$dir=sys_get_temp_dir().'/008-material-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try{
    $p->exec("CREATE TABLE eb_system_attachment(att_id INT AUTO_INCREMENT PRIMARY KEY,type TINYINT DEFAULT 0,file_type TINYINT DEFAULT 1,relation_id INT DEFAULT 0,name VARCHAR(100) DEFAULT '',att_dir VARCHAR(200) DEFAULT '',satt_dir VARCHAR(200) DEFAULT '',att_size CHAR(30) DEFAULT '',att_type CHAR(30) DEFAULT '',pid INT DEFAULT 0,time INT DEFAULT 0,image_type TINYINT DEFAULT 1,module_type TINYINT DEFAULT 1,real_name VARCHAR(255) DEFAULT '',scan_token VARCHAR(32) DEFAULT '') ENGINE=InnoDB");
    $p->exec("CREATE TABLE eb_system_attachment_category(id INT AUTO_INCREMENT PRIMARY KEY,type TINYINT DEFAULT 0,file_type TINYINT DEFAULT 1,relation_id INT DEFAULT 0,pid INT DEFAULT 0,name VARCHAR(50) DEFAULT '',enname VARCHAR(50) DEFAULT '') ENGINE=InnoDB AUTO_INCREMENT=501");
    $p->exec("CREATE TABLE eb_database_upgrade_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,upgrade_key VARCHAR(100) UNIQUE,title VARCHAR(255),task_file VARCHAR(500),sql_checksum CHAR(64),code_version VARCHAR(100),executed_at DATETIME,executed_by VARCHAR(100),result_note VARCHAR(500)) ENGINE=InnoDB");
    $p->exec("INSERT INTO eb_system_attachment(att_id,att_dir,satt_dir,real_name,scan_token)VALUES(25,'https://example.test/existing.png','https://example.test/existing.png','Existing title','keep-token'),(26,'/keep.png','/keep.png','Other image','')");
    $p->exec("INSERT INTO eb_system_attachment(att_id,type,module_type,att_dir,satt_dir,real_name)VALUES(27,2,2,'/automatic.png','/automatic.png','Automatic protected')");
    $p->exec('ALTER TABLE eb_system_attachment AUTO_INCREMENT=1001');
    $before=$p->query('SELECT * FROM eb_system_attachment ORDER BY att_id')->fetchAll();
    $report['target_all_sha256']=media008Sha($before);$plan=media008Plan($report);
    mkdir($dir.'/stage',0700);mkdir($dir.'/public',0700);
    foreach($plan['assets'] as $sha=>$a){$index=array_search($sha,$shas,true);file_put_contents($dir.'/stage/'.$a['filename'],$bodies[$index]);}
    // No network transfer is attempted when every distinct staged hash already exists.
    media008Stage($plan,$dir.'/stage');
    mkdir($dir.'/dry',0700);$receipt=media008Apply($p,$plan,$dir.'/stage',$dir.'/public',$dir.'/dry',str_repeat('a',40),true);
    mediaTest($p->query('SELECT * FROM eb_system_attachment ORDER BY att_id')->fetchAll()===$before,'DB rehearsal left attachment changes');
    mediaTest((int)$p->query('SELECT COUNT(*) FROM eb_system_attachment_category')->fetchColumn()===0&&(int)$p->query('SELECT COUNT(*) FROM eb_database_upgrade_log')->fetchColumn()===0,'DB rehearsal left categories/audit');
    mediaTest(!is_dir($dir.'/public'.MEDIA008_NAMESPACE),'DB rehearsal published images');
    // A concurrent upload must fail before backup, publication or catalog writes.
    $p->exec("UPDATE eb_system_attachment SET real_name='Concurrent' WHERE att_id=26");mkdir($dir.'/drift',0700);$caught=false;
    try{media008Apply($p,$plan,$dir.'/stage',$dir.'/public',$dir.'/drift',str_repeat('a',40));}catch(RuntimeException $e){$caught=true;}
    mediaTest($caught&&!is_file($dir.'/drift/before.json'),'Concurrent target baseline was ignored');
    $p->exec("UPDATE eb_system_attachment SET real_name='Other image' WHERE att_id=26");
    mkdir($dir.'/failure',0700);$p->exec("CREATE TRIGGER fail_media_audit BEFORE INSERT ON eb_database_upgrade_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'");$caught=false;
    try{media008Apply($p,$plan,$dir.'/stage',$dir.'/public',$dir.'/failure',str_repeat('a',40));}catch(PDOException $e){$caught=true;}
    $p->exec('DROP TRIGGER fail_media_audit');mediaTest($caught&&$p->query('SELECT * FROM eb_system_attachment ORDER BY att_id')->fetchAll()===$before,'Audit failure left partial metadata');
    mkdir($dir.'/apply',0700);$receipt=media008Apply($p,$plan,$dir.'/stage',$dir.'/public',$dir.'/apply',str_repeat('a',40));
    mediaTest($receipt['published']['files_total']===2&&$receipt['published']['files_created']===0,'Retry retransferred or republished duplicate assets');
    mediaTest((int)$p->query('SELECT COUNT(*) FROM eb_system_attachment')->fetchColumn()===6,'Expected exactly three new references');
    mediaTest($receipt['categories'][1]!==1&&$receipt['categories'][2]!==2,'Old category IDs were reused');
    media008Verify($p,$plan,['attachments'=>$before,'categories'=>[]],$receipt);
    foreach($plan['assets'] as $a){$file=$dir.'/public'.$a['public_path'];mediaTest((fileperms($file)&0777)===0444,'Asset is not immutable');if(function_exists('posix_geteuid')&&posix_geteuid()!==0)mediaTest(!@unlink($file),'A picker-like unlink can destroy another category reference');}
    mediaTest((fileperms($dir.'/public'.MEDIA008_NAMESPACE)&0777)===0555,'Asset folder allows web deletion');
    $caught=false;try{media008Apply($p,$plan,$dir.'/stage',$dir.'/public',$dir.'/apply',str_repeat('a',40));}catch(RuntimeException $e){$caught=true;}mediaTest($caught,'Replayed batch was accepted');
    echo "MySQL5.7 category-ID mapping/atomic rollback/audit failure/replay/current rows preserved/dedup media/immutable shared files: PASS\n";
}finally{$p->exec('DROP DATABASE '.$database);mediaTestCleanup($dir);}

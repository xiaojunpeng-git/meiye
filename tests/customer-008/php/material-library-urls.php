<?php
/** Synthetic URL-only regression: no customer data or remote writes. */
declare(strict_types=1);
require dirname(__DIR__,3).'/scripts/import/customer_008_material_library_urls.php';

function mediaUrlTest(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
$path=MEDIA008_NAMESPACE.'/'.str_repeat('a',64).'.png';
mediaUrlTest(media008AbsoluteUrl($path)==='https://008.cc3798.com'.$path,'Wrong same-origin URL');
// Existing site_url may contain /admin; a fully qualified URL bypasses prefixing.
$site='https://008.cc3798.com/admin';$url=media008AbsoluteUrl($path);
$rendered=(strpos($url,$site)!==false || strstr($url,'http')!==false)?$url:$site.$url;
mediaUrlTest($rendered===$url && strpos($rendered,'/admin/uploads')===false,'Admin prefix regression');
foreach (['https://other.test/a.png','//other.test/a.png',MEDIA008_NAMESPACE.'/../x.png',MEDIA008_NAMESPACE.'/'.str_repeat('b',64).'.php','/uploads/other.png'] as $bad) {
    $caught=false;try { media008AbsoluteUrl($bad); } catch (RuntimeException $e) { $caught=true; }
    mediaUrlTest($caught,'Unsafe/non-batch path accepted');
}
echo "Absolute URL/site_url-admin compatibility/path guards: PASS\n";
if (($argv[1]??'')!=='--mysql') exit(0);

$p=new PDO('mysql:host=127.0.0.1;port=13318;charset=utf8mb4','root','rights-test-local',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::ATTR_EMULATE_PREPARES=>false]);
$identity=$p->query('SELECT @@port port,VERSION() version')->fetch();
mediaUrlTest((int)$identity['port']===3306 && strpos($identity['version'],'5.7.')===0,'Not disposable MySQL5.7');
$db='m008_material_url_test';$p->exec('CREATE DATABASE '.$db.' CHARACTER SET utf8mb4');$p->exec('USE '.$db);
$dir=sys_get_temp_dir().'/008-media-url-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try {
    $p->exec("CREATE TABLE eb_system_attachment(att_id INT PRIMARY KEY,type TINYINT DEFAULT 0,file_type TINYINT DEFAULT 1,relation_id INT DEFAULT 0,module_type TINYINT DEFAULT 1,image_type TINYINT DEFAULT 1,att_dir VARCHAR(200),satt_dir VARCHAR(200),name VARCHAR(100),pid INT DEFAULT 0,scan_token VARCHAR(32) DEFAULT '') ENGINE=InnoDB");
    $p->exec("CREATE TABLE eb_system_attachment_category(id INT PRIMARY KEY,name VARCHAR(50),pid INT) ENGINE=InnoDB");
    $p->exec("CREATE TABLE eb_database_upgrade_log(id INT AUTO_INCREMENT PRIMARY KEY,upgrade_key VARCHAR(100) UNIQUE,title VARCHAR(255),task_file VARCHAR(500),sql_checksum CHAR(64),code_version VARCHAR(100),executed_at DATETIME,executed_by VARCHAR(100),result_note VARCHAR(500)) ENGINE=InnoDB");
    $q=$p->prepare('INSERT INTO eb_system_attachment(att_id,att_dir,satt_dir,name,pid)VALUES(?,?,?,?,?)');
    $q->execute([25,'https://existing.test/keep.png','https://existing.test/keep.png','User picture',51]);
    $q->execute([100,$path,$path,'Imported name',52]);$q->execute([101,$path,$path,'Cross-category reference',53]);
    $q->execute([200,'/automatic.png','/automatic.png','Automatic',0]);$p->exec('UPDATE eb_system_attachment SET type=2,module_type=2 WHERE att_id=200');
    $p->exec("INSERT INTO eb_system_attachment_category VALUES(51,'Blue',0),(52,'Root',0),(53,'Child',52)");
    $rows=$p->query('SELECT * FROM eb_system_attachment ORDER BY att_id')->fetchAll();$cats=$p->query('SELECT * FROM eb_system_attachment_category ORDER BY id')->fetchAll();$paths=[100=>$path,101=>$path];
    // User edits, even unrelated columns, invalidate the fixed catalog before backup/write.
    $p->exec("UPDATE eb_system_attachment SET name='Concurrent' WHERE att_id=25");mkdir($dir.'/drift',0700);$caught=false;
    try { media008RepairUrls($p,$rows,$cats,$paths,$dir.'/drift',str_repeat('a',40)); } catch (RuntimeException $e) { $caught=true; }
    mediaUrlTest($caught && !is_file($dir.'/drift/before-urls.json'),'Concurrent edit was overwritten');
    $p->exec("UPDATE eb_system_attachment SET name='User picture' WHERE att_id=25");
    $p->exec("CREATE TRIGGER fail_url_audit BEFORE INSERT ON eb_database_upgrade_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'");mkdir($dir.'/fail',0700);$caught=false;
    try { media008RepairUrls($p,$rows,$cats,$paths,$dir.'/fail',str_repeat('a',40)); } catch (PDOException $e) { $caught=true; }
    $p->exec('DROP TRIGGER fail_url_audit');
    mediaUrlTest($caught && $p->query('SELECT * FROM eb_system_attachment ORDER BY att_id')->fetchAll()===$rows,'Audit failure did not roll back URLs');
    mkdir($dir.'/apply',0700);$result=media008RepairUrls($p,$rows,$cats,$paths,$dir.'/apply',str_repeat('a',40));
    $expected=$rows;foreach ($expected as &$row) if (isset($paths[$row['att_id']])) {$row['att_dir']=media008AbsoluteUrl($path);$row['satt_dir']=$row['att_dir'];}unset($row);
    mediaUrlTest($p->query('SELECT * FROM eb_system_attachment ORDER BY att_id')->fetchAll()===$expected,'Non-URL fields or protected rows changed');
    mediaUrlTest($p->query('SELECT * FROM eb_system_attachment_category ORDER BY id')->fetchAll()===$cats,'Categories changed');
    mediaUrlTest($result['rows']===2 && hash_file('sha256',$dir.'/apply/before-urls.json')===$result['backup_sha256'],'Backup/readback mismatch');
    mkdir($dir.'/replay',0700);$caught=false;try { media008RepairUrls($p,$expected,$cats,$paths,$dir.'/replay',str_repeat('a',40)); } catch (RuntimeException $e) { $caught=true; }
    mediaUrlTest($caught,'Repair replay accepted');
    echo "MySQL5.7 real URL writes/whole-row protection/categories unchanged/rollback/concurrency/replay/private backup: PASS\n";
} finally {
    $p->exec('DROP DATABASE '.$db);
    foreach (glob($dir.'/*') as $sub) {foreach (glob($sub.'/*') as $file) unlink($file);rmdir($sub);}rmdir($dir);
}

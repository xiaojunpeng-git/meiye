<?php
/** Synthetic configuration repair tests; never connect to a customer database. */
declare(strict_types=1);
require dirname(__DIR__,3).'/scripts/import/customer_008_store_display.php';

function sdTest(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function sdReject(callable $run,string $label): void
{
    $failed=false;try {$run();}catch (Throwable $e){$failed=true;}
    sdTest($failed,$label);
}

$rules=sd008Rules();
sdTest(array_column(array_filter($rules,function($r){return $r['is_use'];}),'name')===['user_param_store','user_locate_store'],'Old explicit/nearby rules drifted');
sdTest($rules[1]['is_change_store']===1 && $rules[1]['is_use']===0,'Ownership must not be assigned or switching forbidden');
sdTest(json_decode(sd008Json($rules),true)===$rules,'Rule JSON contract');

// Execute the real canonical resolver method with synthetic infrastructure.
// Only external cache, location and DAO dependencies are replaced; no second
// editable implementation of the selection algorithm is maintained here.
class SystemConfigService { public static function get(...$args) { return sd008Rules(); } }
class CacheService {
    public static $cache=[];
    public static function get($key) { return false; }
    public static function redisHandler($tag) { return new self(); }
    public function set($key,$value,$ttl) { self::$cache[$key]=$value; }
}
function sys_config($key,$default='') { return $key==='shop_operation_type'?2:$default; }
$source=file_get_contents(dirname(__DIR__,3).'/后端代码/app/services/store/SystemStoreServices.php');
$start=strpos($source,'    public function getStoreIdByEntryRules(');
$end=strpos($source,"\n    /**",$start);
sdTest($start!==false && $end!==false,'Resolver source boundary');
$method=substr($source,$start,$end-$start);
eval('class SdResolver {
    public $dao; public $cacheTag="synthetic";
    public function __construct(){ $this->dao=new class { public function count($where){return in_array((int)($where["store_id"]??$where["id"]??0),[108,130],true)?1:0;} }; }
    public function getDefaultEntryRulesParam($p){return array_merge(["latitude"=>"","longitude"=>"","store_id"=>0,"select_store_id"=>0,"ip"=>""],$p);}
    public function handleLocation($a,$b){return [];}
    public function getNearbyStore($where,$lat,$lng,$ip,$limit){return ["id"=>130];}
'.$method.'}');
$resolver=new SdResolver();
foreach ([0,17] as $uid) {
    sdTest($resolver->getStoreIdByEntryRules($uid,[],true)['store_id']===130,'Anonymous/member nearby fallback');
    $result=$resolver->getStoreIdByEntryRules($uid,['store_id'=>108],true);
    sdTest($result['store_id']===108 && $result['user_entry_name']==='user_param_store','Previously selected store lost');
    sdTest($resolver->getStoreIdByEntryRules($uid,['select_store_id'=>108],true)['store_id']===108,'Manual switching lost');
    sdTest($result['shop_operation_type']===2 && $result['user_entry_rules']===$rules,'API rule/mode shape');
}
echo "Real resolver with synthetic DAO/cache: explicit, manual, anonymous/member nearby, mode preserved: PASS\n";
if (($argv[1]??'')!=='--mysql') exit(0);

$p=new PDO('mysql:host=127.0.0.1;port=13318;charset=utf8mb4','root','rights-test-local',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::ATTR_EMULATE_PREPARES=>false,
]);
$identity=$p->query('SELECT @@port port,VERSION() version')->fetch();
sdTest((int)$identity['port']===3306 && strpos($identity['version'],'5.7.')===0,'Not isolated MySQL5.7');
$db='m008_store_display_test_'.bin2hex(random_bytes(4));$p->exec('CREATE DATABASE '.$db.' CHARACTER SET utf8mb4');$p->exec('USE '.$db);
$dir=sys_get_temp_dir().'/'.$db;mkdir($dir,0700);
try {
    $p->exec('CREATE TABLE eb_system_config(id INT PRIMARY KEY,is_store INT,menu_name VARCHAR(80),value TEXT,status INT,info VARCHAR(100)) ENGINE=InnoDB');
    $p->exec("INSERT INTO eb_system_config VALUES(469,0,'store_func_status','',1,'Enable stores'),(533,0,'shop_operation_type','2',1,'Mode'),(534,0,'user_entry_rules','',1,'Entry'),(700,0,'routine_appsecret','synthetic-not-a-secret',1,'Untouched')");
    $p->exec('CREATE TABLE eb_database_upgrade_log(id INT AUTO_INCREMENT PRIMARY KEY,upgrade_key VARCHAR(100) UNIQUE,title VARCHAR(255),task_file VARCHAR(500),sql_checksum CHAR(64),code_version VARCHAR(100),executed_at DATETIME,executed_by VARCHAR(100),result_note VARCHAR(500)) ENGINE=InnoDB');
    $before=sd008Rows($p);$all=$p->query('SELECT * FROM eb_system_config ORDER BY id')->fetchAll();$commit=str_repeat('a',40);
    $p->exec('ALTER TABLE eb_system_config ENGINE=MyISAM');
    sdReject(function()use($p,$before,$dir,$commit){sd008Apply($p,$before,$dir.'/engine',$commit);},'Nontransactional configuration accepted');
    sdTest(!is_file($dir.'/engine/before.json'),'Unsafe engine created backup or writes');
    $p->exec('ALTER TABLE eb_system_config ENGINE=InnoDB');
    $p->exec("UPDATE eb_system_config SET info='Concurrent metadata' WHERE id=469");
    sdReject(function()use($p,$before,$dir,$commit){sd008Apply($p,$before,$dir.'/drift',$commit);},'Concurrent metadata overwritten');
    sdTest(!is_file($dir.'/drift/before.json'),'Drift created backup or writes');
    $p->exec("UPDATE eb_system_config SET info='Enable stores' WHERE id=469");
    foreach (['unsafe','audit','apply'] as $sub) mkdir($dir.'/'.$sub,0700);
    chmod($dir.'/unsafe',0755);
    sdReject(function()use($p,$before,$dir,$commit){sd008Apply($p,$before,$dir.'/unsafe',$commit);},'Public backup allowed');
    sdTest(sd008Rows($p)===$before,'Backup failure changed config');
    $p->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON eb_database_upgrade_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'");
    sdReject(function()use($p,$before,$dir,$commit){sd008Apply($p,$before,$dir.'/audit',$commit);},'Audit failure not raised');
    sdTest($p->query('SELECT * FROM eb_system_config ORDER BY id')->fetchAll()===$all,'Audit failure did not fully roll back');
    $p->exec('DROP TRIGGER reject_audit');
    $receipt=sd008Apply($p,$before,$dir.'/apply',$commit);
    $expected=$all;foreach ($expected as &$row) {if ($row['id']==469)$row['value']='1';if ($row['id']==534)$row['value']=sd008Json($rules);}unset($row);
    sdTest($p->query('SELECT * FROM eb_system_config ORDER BY id')->fetchAll()===$expected,'Unexpected field/secret/mode mutation');
    sdTest($receipt['rows']===2 && strlen($p->query('SELECT result_note FROM eb_database_upgrade_log')->fetchColumn())<=500,'Audit shape or width');
    sdTest((fileperms($dir.'/apply/before.json')&0077)===0 && hash_file('sha256',$dir.'/apply/before.json')===$receipt['backup_sha256'],'Private backup or hash mismatch');
    sdReject(function()use($p,$dir,$commit){sd008Apply($p,sd008Rows($p),$dir.'/apply',$commit);},'Replay rewrote config');
    $snapshot=json_decode(file_get_contents($dir.'/apply/before.json'),true);
    $p->exec("UPDATE eb_system_config SET value='3' WHERE id=533");
    sdReject(function()use($p,$snapshot){sd008Rollback($p,$snapshot);},'Rollback overwrote later mode edit');
    $p->exec("UPDATE eb_system_config SET value='2' WHERE id=533");
    sd008Rollback($p,$snapshot);
    sdTest($p->query('SELECT * FROM eb_system_config ORDER BY id')->fetchAll()===$all,'Rollback did not restore exact original config');
    sdTest(json_decode($p->query('SELECT result_note FROM eb_database_upgrade_log')->fetchColumn(),true)['status']==='ROLLED_BACK','Rollback erased audit');
    echo "MySQL5.7: real two-row write, mode/metadata/unrelated preservation, private backup, audit-failure rollback, replay/drift guards, complete rollback: PASS\n";
} finally {
    $p->exec('DROP DATABASE '.$db);
    foreach (glob($dir.'/*') as $sub) { foreach (glob($sub.'/*') as $file) unlink($file); rmdir($sub); }rmdir($dir);
}

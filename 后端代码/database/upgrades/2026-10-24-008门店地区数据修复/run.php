<?php
declare(strict_types=1);

/** CLI-only instance-bound executor. Copy into the private per-instance backup
 * directory only after PM approval. No application files, other databases,
 * schema, customer configuration or global cache is changed here. */
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$expectedPlan = '1760affd35264781dfca668e424c7d2d1679a56fdc257738ef9693245c87eb3d';
$mode = $argv[1] ?? '';
$approval = $argv[2] ?? '';
$directory = '/www/backups/008.cc3798.com/20261024-region-data-1760affd';
if (realpath(__DIR__) !== $directory || !in_array($mode,['preflight','apply','restore'],true)) throw new RuntimeException('Wrong private executor location or mode');
if ($mode !== 'preflight' && $approval !== $expectedPlan) throw new RuntimeException('Explicit reviewed plan fingerprint required');
if (hash_file('sha256', __DIR__ . '/plan.json') !== $expectedPlan) throw new RuntimeException('Plan changed');
require __DIR__ . '/repair.php';
$plan = json_decode(file_get_contents(__DIR__ . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
$raw = gzdecode(file_get_contents(__DIR__ . '/standard-city.json.gz'));
if (hash('sha256',$raw) !== $plan['seedSha256']) throw new RuntimeException('Seed changed');
$seed = json_decode($raw,true,512,JSON_THROW_ON_ERROR);unset($raw);
$config = parse_ini_file('/www/wwwroot/008.cc3798.com/.env',true,INI_SCANNER_RAW);
$c = array_change_key_case($config['DATABASE'] ?? $config['database'] ?? []);
if (($c['database'] ?? '') !== 'mohe_008' || (int)($c['hostport'] ?? 0) !== 3307 || !in_array($c['hostname'],['localhost','127.0.0.1'],true)) throw new RuntimeException('Wrong instance database');
$pdo = new PDO('mysql:host=' . $c['hostname'] . ';port=3307;dbname=mohe_008;charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$engines = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_city_area','eb_system_store')")->fetchAll(PDO::FETCH_COLUMN);
if (count($engines)!==2 || array_filter($engines,static fn($e)=>$e!=='InnoDB')) throw new RuntimeException('Transactional tables required');
$save = static function (string $file,array $data): void {
    $raw = json_encode($data,JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents(__DIR__ . '/' . $file,$raw . PHP_EOL,LOCK_EX)===false) throw new RuntimeException('Audit write failed');
    chmod(__DIR__ . '/' . $file,0600);
};
if ($mode==='preflight') {
    $pdo->exec('START TRANSACTION READ ONLY');
    $count = (int)$pdo->query('SELECT COUNT(*) FROM eb_city_area')->fetchColumn();
    $stores = $pdo->query('SELECT id,name,province,city,area,street,address,detailed_address,is_show,is_del FROM eb_system_store WHERE id IN (127,129,130) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    // This read-only transaction was opened with SQL, not beginTransaction().
    // PHP 7.4 PDO does not track that SQL transaction for rollBack().
    $pdo->exec('ROLLBACK');
    if ($count!==0 || Member008RegionDataRepair::digest($stores)!==Member008RegionDataRepair::digest(array_column($plan['changes'],'before'))) throw new RuntimeException('Reviewed baseline changed');
    echo json_encode(['result'=>'PREFLIGHT_PASS','dictionaryRows'=>0,'stores'=>3,'planSha256'=>$expectedPlan]),PHP_EOL;exit;
}
if ($mode==='restore') {
    $snapshot = json_decode(file_get_contents(__DIR__ . '/before.json'),true,512,JSON_THROW_ON_ERROR);
    Member008RegionDataRepair::rollback($pdo,$snapshot);
    $result = ['result'=>'STORE_CODES_RESTORED','dictionaryRetained'=>true,'time'=>date(DATE_ATOM)];
} else {
    $result = Member008RegionDataRepair::apply($pdo,$plan,$seed,static function ($snapshot) use ($save): void {
        // Exclusive creation retains the very first before image; retries or
        // failures never replace the evidence needed for later recovery.
        $file = __DIR__ . '/before.json';
        $handle = fopen($file,'x');
        if (!$handle) throw new RuntimeException('Existing backup prevents a second repair attempt');
        chmod($file,0600);
        $bytes = json_encode($snapshot,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (fwrite($handle,$bytes)!==strlen($bytes) || !fflush($handle)) {fclose($handle);throw new RuntimeException('Backup write incomplete');}
        fclose($handle);
        if (hash_file('sha256',$file)!==hash('sha256',$bytes)) throw new RuntimeException('Backup verification failed');
    });
    $result['time']=date(DATE_ATOM);
}
$result['planSha256']=$expectedPlan;
$result['instance']='008';
$save($mode . '-result.json',$result);
echo json_encode($result,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),PHP_EOL;

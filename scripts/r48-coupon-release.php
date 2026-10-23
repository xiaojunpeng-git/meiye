<?php
declare(strict_types=1);

/** Instance-bound, authorized backup and additive migration. Never creates coupon claims or financial facts. */
if (PHP_SAPI !== 'cli') exit(1);
$targets = ['rh' => ['/www/wwwroot/rh.cc3798.com', 'ruihao', '3306'],
    '008' => ['/www/wwwroot/008.cc3798.com', 'mohe_008', '3307']];
$instance = $argv[1] ?? ''; $mode = $argv[2] ?? ''; $commit = $argv[3] ?? '';
if (!isset($targets[$instance]) || !preg_match('/^[a-f0-9]{40}$/', $commit)
    || !in_array($mode, ['probe', 'backup', 'migrate', 'verify', 'rollback-guard'], true)) exit(2);
[$root, $database, $port] = $targets[$instance];
$backup = '/www/backups/' . basename($root) . '/20261022-r48-' . substr($commit, 0, 8);
$tables = ['eb_coupon_allowance_account', 'eb_coupon_allowance_movement'];
$key = '20261022-048-reusable-coupon-allowance';
$sqlHash = '84c73d71ffae5964bc1ca139b451b37e187d65f4eb319119bd3a9b04e22a9c86';
umask(0077); chdir($root); require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', get_class($e) . ': ' . $e->getMessage() . "\n"); exit(1);
});
$config = config('database.connections.mysql');
if (($config['database'] ?? '') !== $database || (string)($config['hostport'] ?? '') !== $port
    || ($config['prefix'] ?? '') !== 'eb_' || !in_array($config['hostname'] ?? '', ['localhost', '127.0.0.1'], true)
    || think\facade\Db::query('SELECT DATABASE() db')[0]['db'] !== $database) throw new RuntimeException('Instance database mismatch');
$pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$exists = static function (string $table) use ($pdo): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$table]); return (int)$s->fetchColumn() === 1;
};
$log = static function () use ($pdo, $key) {
    $s = $pdo->prepare('SELECT upgrade_key,sql_checksum,code_version FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $s->execute([$key]); return $s->fetch();
};
$verifySchema = static function () use ($pdo, $tables, $exists): void {
    $columns = [
        ['coupon_user_id','member_id','total_cents','remaining_cents','version'],
        ['id','coupon_user_id','member_id','tenant_id','store_id','order_id','order_line_id','operation_id',
            'event_key','delta_cents','before_cents','after_cents','reversal_of','created_at'],
    ];
    foreach ($tables as $i => $table) {
        if (!$exists($table) || array_column($pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(), 'Field') !== $columns[$i]) throw new RuntimeException('Unexpected allowance schema: ' . $table);
        $indexes = $pdo->query('SHOW INDEX FROM ' . $table)->fetchAll();
        $expected = $i === 0 ? ['PRIMARY' => ['coupon_user_id']] : ['PRIMARY' => ['id'], 'uq_coupon_event' => ['coupon_user_id','event_key'], 'uq_reversal' => ['reversal_of']];
        foreach ($expected as $name => $fields) {
            $unique = array_values(array_filter($indexes, static fn(array $x): bool => $x['Key_name'] === $name && (int)$x['Non_unique'] === 0));
            if (array_column($unique, 'Column_name') !== $fields) throw new RuntimeException('Missing allowance idempotency index');
        }
    }
};
$emit = static function (array $value): void { echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n"; };

if ($mode === 'probe') {
    // The closed historical range provides a read-only regression reference.
    think\facade\Db::execute('SET TRANSACTION READ ONLY'); think\facade\Db::startTrans();
    try {
        $store = $instance === 'rh' ? 126 : (int)think\facade\Db::name('system_store')->where('is_del', 0)->order('id')->value('id');
        $range = ['start' => '2026-10-01', 'end' => '2026-10-17']; $checks = [];
        foreach ([['two','market_performance'],['two','market_detail'],['two','store_refund_ledger'],
            ['six','phase_six_salary_summary'],['six','phase_six_salary_detail']] as [$group,$code]) {
            $service = $group === 'two' ? new app\services\report\StoreUnifiedReportPhaseTwoServices() : new app\services\report\StoreUnifiedReportPhaseSixServices();
            $all = $service->query($code, [$store], $range, ['_internal_all' => true]);
            $paged = $service->query($code, [$store], $range, ['page' => 1, 'limit' => 20]);
            if (!isset($all['records'], $all['summary_row']) || $all['summary_row'] !== $paged['summary_row']) throw new RuntimeException('Historical report total mismatch');
            $checks[$code] = ['rows' => count($all['records']), 'summary' => $all['summary_row']];
        }
        // Exercise the actual coupon reader without emitting member identifiers.
        $uid = (int)think\facade\Db::name('store_coupon_user')->where('uid', '>', 0)->order('id')->value('uid');
        if ($uid) {
            $reader = new app\services\cashier\v3\member\CashierV3MemberDetailQueryServices();
            $method = new ReflectionMethod($reader, 'coupons'); $method->setAccessible(true);
            $coupons = $method->invoke($reader, $uid, '', '', '', '');
            if (!is_array($coupons)) throw new RuntimeException('Member coupon projection unavailable');
        }
        $emit(['result'=>'PASS','instance'=>$instance,'store'=>$store,'checks'=>$checks,'couponReader'=>$uid ? 'PASS' : 'EMPTY_INSTANCE']);
    } finally { think\facade\Db::rollback(); }
    exit;
}
if ($mode === 'rollback-guard') {
    // Old applications cannot interpret newly issued reusable coupons or ledger facts.
    $newCoupons = (int)$pdo->query('SELECT COUNT(*) FROM eb_store_coupon_issue WHERE coupon_type=3')->fetchColumn();
    $rows = 0; foreach ($tables as $table) if ($exists($table)) $rows += (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    if ($newCoupons || $rows) throw new RuntimeException('New reusable coupon data exists; old-code rollback requires reconciliation');
    $emit(['result'=>'PASS','newCouponRules'=>0,'allowanceRows'=>0]); exit;
}
if ($mode === 'backup') {
    if (is_dir($backup) || $exists($tables[0]) || $exists($tables[1]) || $log()) throw new RuntimeException('Existing backup/schema; inspect before retry');
    if (!mkdir($backup, 0700, true)) throw new RuntimeException('Cannot create separate backup');
    $size = (int)$pdo->query('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    if (disk_free_space($backup) < max(1073741824, $size * 2)) throw new RuntimeException('Insufficient backup disk space');
    $plain = $backup . '/database-before.sql';
    $bin = is_executable('/www/server/mysql/bin/mysqldump') ? '/www/server/mysql/bin/mysqldump' : '/usr/bin/mysqldump';
    $args = [$bin,'--host='.$config['hostname'],'--port='.$port,'--user='.$config['username'],
        '--single-transaction','--quick','--routines','--triggers','--events','--no-tablespaces',
        '--set-gtid-purged=OFF','--hex-blob','--default-character-set=utf8mb4',$database];
    // Credentials exist only in the dump child's environment, never command text or receipts.
    $env = array_merge($_ENV, ['PATH'=>getenv('PATH') ?: '/usr/bin:/bin','MYSQL_PWD'=>$config['password']]);
    $process = proc_open($args, [0=>['pipe','r'],1=>['file',$plain,'w'],2=>['file',$backup.'/dump-errors.log','w']], $pipes, $root, $env);
    if (!is_resource($process)) throw new RuntimeException('Cannot start backup');
    fclose($pipes[0]); if (proc_close($process) !== 0) throw new RuntimeException('Dump failed; protected server log retained');
    $in = fopen($plain, 'rb'); $out = gzopen($backup.'/database-before.sql.gz', 'wb1');
    if (!$in || !$out) throw new RuntimeException('Backup compression unavailable');
    while (!feof($in)) { $data=fread($in,1048576); if ($data===false || gzwrite($out,$data)!==strlen($data)) throw new RuntimeException('Backup compression failed'); }
    fclose($in); gzclose($out);
    $p = proc_open(['gzip','-t',$backup.'/database-before.sql.gz'], [0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Cannot verify backup');
    fclose($pipes[0]); if (proc_close($p)!==0) throw new RuntimeException('Backup gzip verification failed');
    unlink($plain);
    $receipt = ['instance'=>$instance,'database'=>$database,'port'=>$port,'commit'=>$commit,
        'sha256'=>hash_file('sha256',$backup.'/database-before.sql.gz'),'bytes'=>filesize($backup.'/database-before.sql.gz')];
    file_put_contents($backup.'/database-backup.json', json_encode($receipt, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    $emit(['result'=>'PASS','backup'=>$backup,'database'=>$receipt]); exit;
}
$receipt = json_decode((string)file_get_contents($backup.'/database-backup.json'),true,512,JSON_THROW_ON_ERROR);
if ($receipt['instance']!==$instance || $receipt['database']!==$database || $receipt['commit']!==$commit
    || hash_file('sha256',$backup.'/database-before.sql.gz')!==$receipt['sha256']) throw new RuntimeException('Separate backup identity/hash mismatch');
if ($mode === 'migrate') {
    $file=$backup.'/stage/schema.sql';
    if (!is_file($file) || hash_file('sha256',$file)!==$sqlHash) throw new RuntimeException('SQL fingerprint mismatch');
    if ((int)$pdo->query("SELECT GET_LOCK('r48_coupon_allowance_migration',10)")->fetchColumn()!==1) throw new RuntimeException('Migration lock unavailable');
    try {
        $old=$log();
        if (!$old) {
            // Additive DDL can be resumed after an interruption; never drop or rewrite either table.
            $pdo->exec((string)file_get_contents($file)); $verifySchema();
            $s=$pdo->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) VALUES (?,?,?,?,?,NOW(),?,?,NOW())');
            $s->execute([$key,'多次抵用券额度与抵用流水','后端代码/database/upgrades/2026-10-22-多次抵用券/01-schema.sql',$sqlHash,$commit,'Codex','R48 independent backup: '.$backup]);
        }
    } finally { $pdo->query("SELECT RELEASE_LOCK('r48_coupon_allowance_migration')"); }
}
$verifySchema(); $logged=$log();
if (!$logged || $logged['sql_checksum']!==$sqlHash || $logged['code_version']!==$commit) throw new RuntimeException('Migration log fingerprint mismatch');
// Validate any real user-created allowance facts without changing them.
$badAccounts=(int)$pdo->query('SELECT COUNT(*) FROM eb_coupon_allowance_account a LEFT JOIN (SELECT coupon_user_id,SUM(delta_cents) delta FROM eb_coupon_allowance_movement GROUP BY coupon_user_id) m ON m.coupon_user_id=a.coupon_user_id WHERE a.total_cents<0 OR a.remaining_cents<0 OR a.remaining_cents>a.total_cents OR MOD(a.total_cents,100)<>0 OR MOD(a.remaining_cents,100)<>0 OR a.remaining_cents<>a.total_cents+COALESCE(m.delta,0)')->fetchColumn();
$badMovements=(int)$pdo->query('SELECT COUNT(*) FROM eb_coupon_allowance_movement WHERE before_cents+delta_cents<>after_cents OR MOD(delta_cents,100)<>0 OR before_cents<0 OR after_cents<0')->fetchColumn();
if ($badAccounts || $badMovements) throw new RuntimeException('Allowance ledger conservation mismatch');
$emit(['result'=>'PASS','instance'=>$instance,'database'=>$database,'migration'=>$logged,
    'accounts'=>(int)$pdo->query('SELECT COUNT(*) FROM eb_coupon_allowance_account')->fetchColumn(),
    'movements'=>(int)$pdo->query('SELECT COUNT(*) FROM eb_coupon_allowance_movement')->fetchColumn(),
    'wholeYuanAndConservation'=>'PASS']);

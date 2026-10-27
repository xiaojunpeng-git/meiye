<?php
declare(strict_types=1);

/** Authorized, instance-bound backup and additive default-off configuration migration. */
if (PHP_SAPI !== 'cli') exit(1);
$targets = ['rh' => ['/www/wwwroot/rh.cc3798.com', 'ruihao', '3306'],
    '008' => ['/www/wwwroot/008.cc3798.com', 'mohe_008', '3307']];
$instance = $argv[1] ?? ''; $mode = $argv[2] ?? ''; $commit = $argv[3] ?? '';
date_default_timezone_set('Asia/Shanghai');
if (!isset($targets[$instance]) || !preg_match('/^[a-f0-9]{40}$/D', $commit)
    || !in_array($mode, ['backup', 'migrate', 'verify', 'rollback-guard', 'probe'], true)) exit(2);
[$root, $database, $port] = $targets[$instance];
$backup = '/www/backups/' . basename($root) . '/20261023-r49-' . substr($commit, 0, 8);
$key = '20261023-049-ordinary-card-sale-edit';
$menu = 'cashier_ordinary_card_sale_edit_switch';
$sqlHash = 'a24e6314f949714f39e83c78574d797f2b40716bc5f58a7e596d5679484ca206';
umask(0077); chdir($root); require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', get_class($e) . ': ' . $e->getMessage() . "\n"); exit(1);
});
$config = config('database.connections.mysql');
if ($config['database'] !== $database || (string)$config['hostport'] !== $port || $config['prefix'] !== 'eb_'
    || !in_array($config['hostname'], ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Instance mismatch');
$pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$emit = static function (array $v): void { echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n"; };
$switch = static function () use ($pdo, $menu): array {
    $s = $pdo->prepare('SELECT id,menu_name,value,is_store FROM eb_system_config WHERE menu_name=? AND is_store=0');
    $s->execute([$menu]); return $s->fetchAll();
};
$log = static function () use ($pdo, $key) {
    $s = $pdo->prepare('SELECT upgrade_key,sql_checksum,code_version FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $s->execute([$key]); return $s->fetch();
};
if ($mode === 'probe') {
    // Historical report queries are strictly read-only and provide a stable
    // cross-release business reference. No live checkout/refund is submitted.
    think\facade\Db::execute('SET TRANSACTION READ ONLY'); think\facade\Db::startTrans();
    try {
        $store = $instance === 'rh' ? 126 : (int)think\facade\Db::name('system_store')->where('is_del', 0)->order('id')->value('id');
        $range = ['start' => '2026-10-01', 'end' => '2026-10-17']; $checks = [];
        foreach ([['two', 'market_performance'], ['two', 'market_detail'], ['two', 'store_refund_ledger'],
            ['six', 'phase_six_salary_summary'], ['six', 'phase_six_salary_detail']] as [$group, $code]) {
            $service = $group === 'two' ? new app\services\report\StoreUnifiedReportPhaseTwoServices()
                : new app\services\report\StoreUnifiedReportPhaseSixServices();
            $all = $service->query($code, [$store], $range, ['_internal_all' => true]);
            $paged = $service->query($code, [$store], $range, ['page' => 1, 'limit' => 20]);
            if (!isset($all['records'], $all['summary_row']) || $all['summary_row'] !== $paged['summary_row']) throw new RuntimeException('Historical report mismatch');
            $checks[$code] = ['rows' => count($all['records']), 'summary' => $all['summary_row']];
        }
        // Catalogue exposure must match the instance switch; strip only newly
        // attached original-value proofs when comparing unchanged business rows.
        $staff = (array)think\facade\Db::name('system_store_staff')->where('store_id', $store)->where('status', 1)->where('is_del', 0)->find();
        $catalogCheck = 'NO_ACTIVE_STAFF';
        if ($staff) {
            $org = (string)think\facade\Db::name('organization_store')->where('store_id', $store)->value('org_id');
            $op = new app\services\cashier\v3\CashierV3OperatorScope($store, (int)$staff['id'], $org, '0');
            $scope = new app\services\cashier\v3\CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], $store,
                '0', $org, [$store], 'stores', [], false, '', 'r49-readonly', [], $staff);
            $items = (new app\services\cashier\v3\cashier\CashierV3SaleCatalogServices())->catalog($op, $scope)['items'];
            $normalize = static function (array $value) use (&$normalize): array {
                foreach ($value as $k => $v) {
                    if (in_array($k, ['ordinarySaleBaseline', 'ordinaryCardSaleEditable'], true)
                        || preg_match('/(?:version|revision)$/i', str_replace(['_', '-'], '', (string)$k))) unset($value[$k]);
                    elseif (is_array($v)) $value[$k] = $normalize($v);
                }
                return $value;
            };
            $items = $normalize($items);
            $catalogCheck = ['count' => count($items), 'businessHash' => hash('sha256', json_encode($items))];
        }
        $emit(['result' => 'PASS', 'instance' => $instance, 'store' => $store, 'checks' => $checks,
            'catalog' => $catalogCheck, 'switch' => $switch()]);
    } finally { think\facade\Db::rollback(); }
    exit;
}
if ($mode === 'backup') {
    if (is_dir($backup) || $log() || $switch() !== []) throw new RuntimeException('Prior backup/migration/config exists; inspect first');
    if (!mkdir($backup, 0700, true)) throw new RuntimeException('Cannot create separate backup');
    $size = (int)$pdo->query('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    if (disk_free_space($backup) < max(1073741824, $size * 2)) throw new RuntimeException('Insufficient backup space');
    $plain = $backup . '/database-before.sql';
    $bin = is_executable('/www/server/mysql/bin/mysqldump') ? '/www/server/mysql/bin/mysqldump' : '/usr/bin/mysqldump';
    $args = [$bin, '--host=' . $config['hostname'], '--port=' . $port, '--user=' . $config['username'],
        '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--no-tablespaces', '--set-gtid-purged=OFF',
        '--hex-blob', '--default-character-set=utf8mb4', $database];
    // Password is inherited only by the dump process, never printed or put in argv.
    $env = array_merge($_ENV, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'MYSQL_PWD' => $config['password']]);
    $p = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', $plain, 'w'], 2 => ['file', $backup . '/dump-errors.log', 'w']], $pipes, $root, $env);
    if (!is_resource($p)) throw new RuntimeException('Cannot start dump');
    fclose($pipes[0]); if (proc_close($p) !== 0) throw new RuntimeException('Backup failed; protected log retained');
    $in = fopen($plain, 'rb'); $out = gzopen($backup . '/database-before.sql.gz', 'wb1');
    if (!$in || !$out) throw new RuntimeException('Cannot compress backup');
    while (!feof($in)) { $data = fread($in, 1048576); if ($data === false || gzwrite($out, $data) !== strlen($data)) throw new RuntimeException('Compression failed'); }
    fclose($in); gzclose($out);
    $p = proc_open(['gzip', '-t', $backup . '/database-before.sql.gz'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Cannot check backup');
    fclose($pipes[0]); if (proc_close($p) !== 0) throw new RuntimeException('Backup integrity failed');
    unlink($plain);
    $receipt = ['instance' => $instance, 'database' => $database, 'port' => $port, 'commit' => $commit,
        'cardReceiptMaxId' => (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM eb_cashier_v3_card_purchase_receipt')->fetchColumn(),
        'sha256' => hash_file('sha256', $backup . '/database-before.sql.gz'), 'bytes' => filesize($backup . '/database-before.sql.gz')];
    file_put_contents($backup . '/database-backup.json', json_encode($receipt, JSON_THROW_ON_ERROR));
    $emit(['result' => 'PASS', 'backup' => $backup, 'database' => $receipt]); exit;
}
$receipt = json_decode((string)file_get_contents($backup . '/database-backup.json'), true, 512, JSON_THROW_ON_ERROR);
if ($receipt['instance'] !== $instance || $receipt['database'] !== $database || $receipt['commit'] !== $commit
    || hash_file('sha256', $backup . '/database-before.sql.gz') !== $receipt['sha256']) throw new RuntimeException('Backup identity/hash mismatch');
if ($mode === 'rollback-guard') {
    // Returning to a server that cannot interpret adjusted ordinary cards is
    // forbidden after any such rights have actually been issued.
    $q = $pdo->prepare("SELECT c.id FROM eb_cashier_v3_card_purchase_receipt r JOIN eb_store_order_cart_info c ON c.oid=r.legacy_order_id WHERE r.id>? AND c.cart_info LIKE '%ordinarySaleBaseline%' LIMIT 1");
    $q->execute([(int)$receipt['cardReceiptMaxId']]);
    $rows = $q->fetch() ? 1 : 0;
    if ($rows) throw new RuntimeException('New card snapshots exist; reconcile instead of old-code rollback');
    $emit(['result' => 'PASS', 'adjustedCardRows' => 0]); exit;
}
if ($mode === 'migrate') {
    $file = $backup . '/stage/schema.sql';
    if (!is_file($file) || hash_file('sha256', $file) !== $sqlHash) throw new RuntimeException('SQL fingerprint mismatch');
    if ((int)$pdo->query("SELECT GET_LOCK('r49_ordinary_card_config',10)")->fetchColumn() !== 1) throw new RuntimeException('Migration lock unavailable');
    try {
        $pdo->beginTransaction();
        if (!$log()) {
            $pdo->exec((string)file_get_contents($file));
            $rows = $switch();
            if (count($rows) !== 1 || (string)$rows[0]['value'] !== '0') throw new RuntimeException('New switch must default off');
            $s = $pdo->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) VALUES (?,?,?,?,?,NOW(),?,?,NOW())');
            $s->execute([$key, '普通卡销售可修改默认关闭配置', '后端代码/database/upgrades/2026-10-22-普通卡销售可修改/01-正式升级.sql',
                $sqlHash, $commit, 'Codex', 'R49 separate backup: ' . $backup]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('r49_ordinary_card_config')"); }
    mohe\services\SystemConfigService::clear();
}
$logged = $log(); $rows = $switch();
if (!$logged || $logged['sql_checksum'] !== $sqlHash || $logged['code_version'] !== $commit || count($rows) !== 1
    || !in_array((string)$rows[0]['value'], ['0', '1'], true)) throw new RuntimeException('Migration verification mismatch');
$formCheck = 'NOT_SWITCHED_YET';
if (class_exists(app\services\cashier\v3\card\CashierV3OrdinaryCardSaleAdjustment::class)) {
    // Inspect generated field presence only; never disclose the form's other settings.
    $form = json_encode(app()->make(app\services\system\config\SystemConfigServices::class)->shopBaseFormBuild());
    foreach (['cashier_custom_card_entry_switch', 'cashier_debt_pay_switch', 'cashier_operator_gift_switch',
        'cashier_ordinary_card_sale_edit_switch'] as $field) {
        if (strpos($form, $field) === false) throw new RuntimeException('Generated settings field missing: ' . $field);
    }
    $formCheck = 'PASS';
}
$emit(['result' => 'PASS', 'instance' => $instance, 'migration' => $logged, 'switch' => $rows, 'settingsForm' => $formCheck]);

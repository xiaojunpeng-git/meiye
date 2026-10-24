<?php
declare(strict_types=1);

// Explicit per-instance backup and authorized metadata migration. No order,
// card, wallet or financial fact is written. Rollback never overwrites later
// administrator edits and cannot remove protection after a real conversion.
$directory = $argv[1] ?? ''; $mode = $argv[2] ?? ''; $manifestHash = $argv[3] ?? '';
if (PHP_SAPI !== 'cli' || !preg_match('~^/www/backups/(rh|008|010)/r53-card-balance-([a-f0-9]{8})$~D', $directory, $match)
    || !in_array($mode, ['backup', 'migrate', 'rollback'], true) || !preg_match('/^[a-f0-9]{64}$/D', $manifestHash)) exit(2);
umask(0077); date_default_timezone_set('Asia/Shanghai');
set_exception_handler(static function (Throwable $error): void { file_put_contents('php://stderr', $error->getMessage() . PHP_EOL); exit(1); });
$manifestFile = $directory . '/payload/manifest.json';
if (hash_file('sha256', $manifestFile) !== $manifestHash) throw new RuntimeException('Manifest changed');
$manifest = json_decode((string)file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
$site = $match[1]; $domain = $site === 'rh' ? 'rh.cc3798.com' : $site . '.cc3798.com';
$root = '/www/wwwroot/' . $domain;
$database = ['rh' => 'ruihao', '008' => 'mohe_008', '010' => 'mohe_010_app'][$site];
$port = $site === 'rh' ? '3306' : '3307';
if ($manifest['site'] !== $site || substr($manifest['commit'], 0, 8) !== $match[2]) throw new RuntimeException('Instance mismatch');
$env = parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
$config = array_change_key_case($env['DATABASE'] ?? $env['database'] ?? []);
if ($config['database'] !== $database || (string)$config['hostport'] !== $port || $config['prefix'] !== 'eb_'
    || !in_array($config['hostname'], ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Database target mismatch');
$pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function r53Canonical($value) {
    if (!is_array($value)) return $value === null ? null : (string)$value;
    foreach ($value as &$entry) $entry = r53Canonical($entry);
    unset($entry);
    if (array_keys($value) !== range(0, count($value) - 1)) ksort($value);
    return $value;
}
function r53MetadataHash(array $rows): string { return hash('sha256', json_encode(r53Canonical($rows), JSON_THROW_ON_ERROR)); }
function r53Metadata(PDO $pdo): array {
    return ['catalog' => $pdo->query('SELECT * FROM eb_store_v3_permission_catalog WHERE id=1')->fetchAll(),
        'channels' => $pdo->query('SELECT * FROM eb_job_position_channel_rule ORDER BY id')->fetchAll()];
}
function r53Save(string $file, array $value): void {
    if (file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot save protected release receipt');
    chmod($file, 0600);
}
function r53AssertMetadataDelta(array $before, array $after, int $changed): void {
    if (count($before['catalog']) !== 1 || count($after['catalog']) !== 1
        || count($before['channels']) !== count($after['channels'])) throw new RuntimeException('Metadata row identity changed');
    $oldCatalog = $before['catalog'][0]; $newCatalog = $after['catalog'][0];
    $oldRules = json_decode($oldCatalog['rules_json'], true); $newRules = json_decode($newCatalog['rules_json'], true);
    $hadLeaf = array_filter($oldRules, static fn(array $row): bool => (int)$row['id'] === 301051);
    if (!$hadLeaf) {
        if (count($newRules) !== count($oldRules) + 1 || array_slice($newRules, 0, count($oldRules)) !== $oldRules
            || (int)$newRules[count($oldRules)]['id'] !== 301051
            || (int)$newCatalog['revision'] !== (int)$oldCatalog['revision'] + 1) throw new RuntimeException('Catalog changed beyond leaf append');
    } elseif (r53MetadataHash([$oldCatalog]) !== r53MetadataHash([$newCatalog])) throw new RuntimeException('Existing leaf metadata was overwritten');
    $oldRows = array_column($before['channels'], null, 'id'); $count = 0;
    foreach ($after['channels'] as $row) {
        if (!isset($oldRows[$row['id']])) throw new RuntimeException('Unexpected new position row');
        $old = $oldRows[$row['id']];
        if ((string)$row['rules'] === (string)$old['rules']) {
            if (r53MetadataHash([$old]) !== r53MetadataHash([$row])) throw new RuntimeException('Unrelated channel metadata changed');
            continue;
        }
        if ($old['channel'] !== 'store_v3' || (int)$old['status'] !== 1
            || (string)$row['rules'] !== rtrim((string)$old['rules'], ',') . ',301051'
            || (int)$row['version'] !== (int)$old['version'] + 1) throw new RuntimeException('Unexpected permission widening');
        foreach (['rules', 'version', 'update_time'] as $key) $row[$key] = $old[$key];
        if (r53MetadataHash([$row]) !== r53MetadataHash([$old])) throw new RuntimeException('Position identity changed');
        $count++;
    }
    if ($count !== $changed) throw new RuntimeException('Permission change count mismatch');
}
if ($mode === 'backup') {
    if (is_file($directory . '/database-backup.json')) throw new RuntimeException('Database backup already exists');
    $before = r53Metadata($pdo);
    r53Save($directory . '/metadata-before.json', $before);
    $maximumOperation = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM eb_cashier_v3_card_operation')->fetchColumn();
    $size = (int)$pdo->query('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    if (disk_free_space($directory) < max(1073741824, $size * 2)) throw new RuntimeException('Insufficient independent backup space');
    $bin = is_executable('/www/server/mysql/bin/mysqldump') ? '/www/server/mysql/bin/mysqldump' : '/usr/bin/mysqldump';
    $plain = $directory . '/database-before.sql';
    $args = [$bin, '--host=' . $config['hostname'], '--port=' . $port, '--user=' . $config['username'],
        '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--no-tablespaces', '--set-gtid-purged=OFF',
        '--hex-blob', '--default-character-set=utf8mb4', $database];
    // The password is private child environment, never argv, logs or tool output.
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', $plain, 'w'], 2 => ['file', $directory . '/dump-errors.log', 'w']],
        $pipes, $root, array_merge($_ENV, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'MYSQL_PWD' => $config['password']]));
    if (!is_resource($process)) throw new RuntimeException('Cannot start database backup');
    fclose($pipes[0]); if (proc_close($process) !== 0) throw new RuntimeException('Database backup failed; protected log retained');
    $input = fopen($plain, 'rb'); $output = gzopen($directory . '/database-before.sql.gz', 'wb1');
    if (!$input || !$output) throw new RuntimeException('Cannot compress independent backup');
    while (!feof($input)) {
        $data = fread($input, 1048576);
        if ($data === false || gzwrite($output, $data) !== strlen($data)) throw new RuntimeException('Backup compression failed');
    }
    fclose($input); gzclose($output);
    $process = proc_open(['gzip', '-t', $directory . '/database-before.sql.gz'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot verify compressed backup');
    fclose($pipes[0]); if (proc_close($process) !== 0) throw new RuntimeException('Backup integrity failure');
    if (r53MetadataHash(r53Metadata($pdo)) !== r53MetadataHash($before)) throw new RuntimeException('Permission configuration changed during backup');
    unlink($plain);
    r53Save($directory . '/database-backup.json', ['site' => $site, 'database' => $database, 'port' => $port,
        'commit' => $manifest['commit'], 'sha256' => hash_file('sha256', $directory . '/database-before.sql.gz'),
        'bytes' => filesize($directory . '/database-before.sql.gz'), 'metadataHash' => r53MetadataHash($before),
        'maxCardOperationId' => $maximumOperation]);
    echo json_encode(['result' => 'PASS', 'site' => $site, 'backup' => $directory, 'fullDatabaseBackup' => true]), PHP_EOL;
    exit;
}
$receipt = json_decode((string)file_get_contents($directory . '/database-backup.json'), true, 512, JSON_THROW_ON_ERROR);
if ($receipt['site'] !== $site || $receipt['database'] !== $database || $receipt['commit'] !== $manifest['commit']
    || hash_file('sha256', $directory . '/database-before.sql.gz') !== $receipt['sha256']) throw new RuntimeException('Independent backup identity/hash mismatch');
$before = json_decode((string)file_get_contents($directory . '/metadata-before.json'), true, 512, JSON_THROW_ON_ERROR);
if (r53MetadataHash($before) !== $receipt['metadataHash']) throw new RuntimeException('Metadata backup changed');
require $root . '/vendor/autoload.php'; (new \think\App($root . '/'))->initialize();
$connection = config('database.connections.mysql');
if ($connection['database'] !== $database || (string)$connection['hostport'] !== $port) throw new RuntimeException('Application database mismatch');
$readLocked = static function (): array {
    return ['catalog' => \think\facade\Db::name('store_v3_permission_catalog')->where('id', 1)->lock(true)->select()->toArray(),
        'channels' => \think\facade\Db::name('job_position_channel_rule')->order('id asc')->lock(true)->select()->toArray()];
};
if ($mode === 'migrate') {
    if (is_file($directory . '/metadata-after.json')) throw new RuntimeException('Metadata migration already recorded');
    $migration = \think\facade\Db::transaction(static function () use ($before, $readLocked, $manifest): array {
        if (r53MetadataHash($readLocked()) !== r53MetadataHash($before)) throw new RuntimeException('Permission metadata changed before migration');
        $catalog = new \app\services\organization\StorePermissionCatalogServices();
        $catalog->initializeCardToBalancePermission();
        $changed = $catalog->grantCardToBalanceToExistingCashierPositions();
        if (!\think\facade\Db::name('database_upgrade_log')->where('upgrade_key', '20261024-r53-card-to-balance')->count()) {
            \think\facade\Db::name('database_upgrade_log')->insert(['upgrade_key' => '20261024-r53-card-to-balance',
                'title' => '卡转余额权限、既有收银岗位及关联索引', 'task_file' => '2026-10-24-卡转余额',
                'sql_checksum' => hash_file('sha256', __DIR__ . '/payload/formal/database/upgrades/2026-10-24-卡转余额/03-补交关联锁定索引.php'),
                'code_version' => $manifest['commit'], 'executed_at' => date('Y-m-d H:i:s'), 'executed_by' => 'codex-authorized-r53',
                'result_note' => 'explicit metadata/grants/index migration; historical orders and balances unchanged']);
        }
        $after = $readLocked();
        r53AssertMetadataDelta($before, $after, $changed);
        return ['changedPositions' => $changed, 'after' => $after];
    });
    r53Save($directory . '/metadata-after.json', $migration['after']);
    if (r53MetadataHash(r53Metadata($pdo)) !== r53MetadataHash($migration['after'])) throw new RuntimeException('Permission metadata changed after migration');
    echo json_encode(['result' => 'PASS', 'site' => $site, 'changedPositions' => $migration['changedPositions'],
        'metadataHash' => r53MetadataHash($migration['after']), 'financialWrites' => 0]), PHP_EOL;
    exit;
}
// The catalog mutex is also held by financial commands. Withdraw only our
// unchanged metadata after every in-flight conversion commits or rolls back.
\think\facade\Db::transaction(static function () use ($directory, $receipt, $before, $readLocked): void {
    $current = $readLocked();
    $expected = is_file($directory . '/metadata-after.json')
        ? json_decode((string)file_get_contents($directory . '/metadata-after.json'), true, 512, JSON_THROW_ON_ERROR) : $before;
    if (r53MetadataHash($current) !== r53MetadataHash($expected)) throw new RuntimeException('Later permission edits prevent automatic rollback');
    $created = \think\facade\Db::query('SELECT id FROM eb_cashier_v3_card_operation FORCE INDEX (PRIMARY) WHERE id>? AND operation_type=? LIMIT 1 FOR UPDATE',
        [$receipt['maxCardOperationId'], 'card_to_balance']);
    if ($created) throw new RuntimeException('Real conversion exists: preserve guards and reconcile; automatic rollback forbidden');
    if (r53MetadataHash($current) !== r53MetadataHash($before)) {
        $oldCatalog = $before['catalog'][0];
        \think\facade\Db::name('store_v3_permission_catalog')->where('id', 1)->update([
            'rules_json' => $oldCatalog['rules_json'], 'revision' => (int)$current['catalog'][0]['revision'] + 1, 'updated_at' => time()]);
        $oldRows = array_column($before['channels'], null, 'id');
        foreach ($current['channels'] as $row) {
            if ((string)$row['rules'] !== (string)$oldRows[$row['id']]['rules']) {
                \think\facade\Db::name('job_position_channel_rule')->where('id', $row['id'])->update([
                    'rules' => $oldRows[$row['id']]['rules'], 'version' => (int)$row['version'] + 1, 'update_time' => time()]);
            }
        }
        \think\facade\Db::name('store_v3_permission_audit')->insert(['operator_id' => 0, 'action' => 'R53_RELEASE_ROLLBACK',
            'before_json' => json_encode($current, JSON_UNESCAPED_UNICODE), 'after_json' => json_encode($before, JSON_UNESCAPED_UNICODE), 'created_at' => time()]);
        \think\facade\Db::name('database_upgrade_log')->where('upgrade_key', '20261024-r53-card-to-balance')
            ->update(['result_note' => 'rolled back safely; audit retained; no conversion facts existed']);
    }
});
r53Save($directory . '/database-rollback-safe.json', ['site' => $site, 'financialRowsRestored' => false, 'result' => 'PASS']);
echo 'R53_METADATA_ROLLBACK_SAFE', PHP_EOL;

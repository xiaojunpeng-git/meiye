<?php

/** R41 per-instance threshold history migration; backups and guards never cross customers. */
if (PHP_SAPI !== 'cli') exit(1);

$targets = [
    'rh' => ['rh.cc3798.com', 'ruihao', '3306'],
    '008' => ['008.cc3798.com', 'mohe_008', '3307'],
];
$instance = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$commit = $argv[3] ?? '';
$sqlHash = $argv[4] ?? '';
if (!isset($targets[$instance]) || !in_array($mode, ['preflight', 'backup', 'migrate', 'verify'], true)
    || !preg_match('/^[a-f0-9]{40}$/D', $commit) || !preg_match('/^[a-f0-9]{64}$/D', $sqlHash)) exit(2);

[$domain, $database, $port] = $targets[$instance];
$root = '/www/wwwroot/' . $domain;
$backup = '/www/backups/' . $domain . '/20261017-r41-' . substr($commit, 0, 8);
$sql = $backup . '/stage/02-正式升级.sql';
$upgradeKey = '20261017-041-deal-people-order-threshold';
$manifestFile = $backup . '/database-backup.json';

chdir($root);
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
$config = config('database.connections.mysql');
if (($config['database'] ?? '') !== $database || (string)($config['hostport'] ?? '') !== $port
    || !in_array($config['hostname'] ?? '', ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Wrong customer database');
}
$pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
$state = static function () use ($pdo, $upgradeKey): array {
    $table = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
        . "AND TABLE_NAME='eb_cashier_v3_deal_people_threshold_policy'")->fetchColumn();
    $query = $pdo->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $query->execute([$upgradeKey]);
    $log = (int)$query->fetchColumn();
    $seed = $table ? $pdo->query("SELECT version,a_threshold_cents,other_threshold_cents,effective_at "
        . "FROM eb_cashier_v3_deal_people_threshold_policy WHERE tenant_id='0' ORDER BY version ASC LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC) : false;
    return ['table' => $table, 'upgrade_log' => $log, 'seed' => $seed ?: null];
};
$isBefore = static function (array $value): bool {
    return $value['table'] === 0 && $value['upgrade_log'] === 0;
};
$isAfter = static function (array $value): bool {
    $seed = $value['seed'];
    return $value['table'] === 1 && $value['upgrade_log'] === 1 && is_array($seed)
        && (int)$seed['version'] === 0 && (int)$seed['a_threshold_cents'] === 100000
        && (int)$seed['other_threshold_cents'] === 50000 && (int)$seed['effective_at'] === 0;
};
$run = static function (array $command, string $output, array $extraEnv = []) use ($backup): void {
    $errorFile = $backup . '/migration-command-stderr.tmp';
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'],
        2 => ['file', $errorFile, 'w']], $pipes, null, array_merge($_ENV, [
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ], $extraEnv));
    if (!is_resource($process)) throw new RuntimeException('Cannot start backup command');
    fclose($pipes[0]);
    $status = proc_close($process);
    $message = is_file($errorFile) ? trim((string)file_get_contents($errorFile)) : '';
    @unlink($errorFile);
    if ($status !== 0) throw new RuntimeException('Backup command failed: ' . substr($message, 0, 240));
};

if ($mode === 'preflight') {
    $before = $state();
    if (!$isBefore($before) || is_dir($backup)) throw new RuntimeException('Migration baseline changed');
    echo json_encode(['instance' => $instance, 'database' => $database, 'port' => $port,
        'state' => $before, 'backup' => $backup], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if ($mode === 'backup') {
    if (!$isBefore($state()) || is_dir($backup)) throw new RuntimeException('Migration baseline changed');
    if (!mkdir($backup, 0700, true)) throw new RuntimeException('Cannot create independent backup');
    $plain = $backup . '/database-before.sql';
    // Credentials stay in the child environment; they are never printed or placed in argv.
    $run(['mysqldump', '--host=' . $config['hostname'], '--port=' . $port,
        '--user=' . $config['username'], '--single-transaction', '--quick', '--routines',
        '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4', $database],
        $plain, ['MYSQL_PWD' => $config['password']]);
    $input = fopen($plain, 'rb');
    $output = gzopen($backup . '/database-before.sql.gz', 'wb6');
    if (!$input || !$output) throw new RuntimeException('Cannot compress database backup');
    while (!feof($input)) {
        $block = fread($input, 1024 * 1024);
        if ($block === false || gzwrite($output, $block) === false) {
            throw new RuntimeException('Database backup compression failed');
        }
    }
    fclose($input);
    gzclose($output);
    unlink($plain);
    $run(['gzip', '-t', $backup . '/database-before.sql.gz'], '/dev/null');
    $manifest = ['instance' => $instance, 'database' => $database, 'commit' => $commit,
        'sql_sha256' => $sqlHash, 'backup_sha256' => hash_file('sha256', $backup . '/database-before.sql.gz'),
        'backup_bytes' => filesize($backup . '/database-before.sql.gz')];
    file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($manifestFile, 0600);
    echo json_encode(['instance' => $instance, 'backup' => $backup,
        'backup_sha256' => $manifest['backup_sha256'], 'backup_bytes' => $manifest['backup_bytes']],
        JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if (!is_file($manifestFile)) throw new RuntimeException('Independent backup is missing');
$manifest = json_decode((string)file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['instance'] ?? '') !== $instance || ($manifest['database'] ?? '') !== $database
    || ($manifest['commit'] ?? '') !== $commit || ($manifest['sql_sha256'] ?? '') !== $sqlHash
    || hash_file('sha256', $backup . '/database-before.sql.gz') !== ($manifest['backup_sha256'] ?? '')) {
    throw new RuntimeException('Independent backup changed');
}

if ($mode === 'migrate') {
    if (!$isBefore($state()) || !is_file($sql) || hash_file('sha256', $sql) !== $sqlHash) {
        throw new RuntimeException('Migration payload or baseline changed');
    }
    // MySQL DDL cannot roll back transactionally; preserve the full backup on any partial failure.
    $statement = $pdo->query((string)file_get_contents($sql));
    do { if ($statement->columnCount()) $statement->fetchAll(PDO::FETCH_ASSOC); } while ($statement->nextRowset());
    $statement->closeCursor();
    $afterDdl = $state();
    if ($afterDdl['table'] !== 1 || !is_array($afterDdl['seed'])) {
        throw new RuntimeException('Migration schema postcheck failed');
    }
    $insert = $pdo->prepare('INSERT INTO eb_database_upgrade_log '
        . '(upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) '
        . 'VALUES (?,?,?,?,?,NOW(),?,?,NOW())');
    $insert->execute([$upgradeKey, '成交人数原单门槛历史版本',
        '后端代码/database/upgrades/2026-10-17-成交人数原单门槛/02-正式升级.sql',
        $sqlHash, $commit, 'Codex', 'R41 independent backup ' . $backup]);
}
$after = $state();
if (!$isAfter($after)) throw new RuntimeException('R41 migration verification failed');
echo json_encode(['instance' => $instance, 'database' => $database, 'state' => $after],
    JSON_THROW_ON_ERROR), PHP_EOL;

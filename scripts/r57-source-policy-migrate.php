<?php
/** Additive settings-only migration. Back up the entire affected table before
 * DDL; never touch orders, balances, source history or customer credentials.
 */
if (PHP_SAPI !== 'cli') exit(1);
[$unused, $directory, $mode, $expected] = array_pad($argv, 4, '');
if (!preg_match('~^/www/backups/(rh|008|010)/r57-source-policy-([a-f0-9]{8})$~D', $directory, $m)
    || !in_array($mode, ['backup', 'migrate', 'verify'], true)
    || !preg_match('/^[a-f0-9]{64}$/D', $expected)
    || hash_file('sha256', $directory . '/payload/manifest.json') !== $expected) throw new RuntimeException('Fixed manifest required');
$site = $m[1]; $root = '/www/wwwroot/' . ($site === 'rh' ? 'rh.cc3798.com' : $site . '.cc3798.com');
$manifest = json_decode(file_get_contents($directory . '/payload/manifest.json'), true);
if ($manifest['site'] !== $site || substr($manifest['commit'], 0, 8) !== $m[2]) throw new RuntimeException('Wrong instance');
$ini = parse_ini_file($root . '/.env', true, INI_SCANNER_RAW); $cfg = array_change_key_case($ini['DATABASE'] ?? $ini['database'] ?? []);
$database = ['rh' => 'ruihao', '008' => 'mohe_008', '010' => 'mohe_010_app'][$site];
$port = $site === 'rh' ? '3306' : '3307';
if ($cfg['database'] !== $database || (string)$cfg['hostport'] !== $port || $cfg['prefix'] !== 'eb_') throw new RuntimeException('Wrong database');
$pdo = new PDO('mysql:host=' . $cfg['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$table = 'eb_member_commerce_settings'; $key = '20261025-r57-business-source-policy';
$new = ['source_enabled', 'inherit_previous', 'repayment_primary_id', 'repayment_secondary_id'];
$rows = static function () use ($pdo, $table): array { return $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); };
$schema = static function () use ($pdo, $table): array { return $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM); };
$backupFile = $directory . '/settings-before.json';
if ($mode === 'backup') {
    if (is_file($backupFile) || !is_file($directory . '/prepared.json')) throw new RuntimeException('File backup required; database backup must be unique');
    $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
    try { $backup = ['site' => $site, 'database' => $database, 'commit' => $manifest['commit'], 'schema' => $schema()[1], 'rows' => $rows()]; }
    finally { $pdo->rollBack(); }
    foreach ($backup['rows'] as $row) foreach ($new as $column) if (array_key_exists($column, $row)) throw new RuntimeException('Migration baseline changed');
    $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($backupFile, $json) === false) throw new RuntimeException('Backup write failed');
    chmod($backupFile, 0600);
    file_put_contents($directory . '/settings-before.sha256', hash('sha256', $json));
    if (hash_file('sha256', $backupFile) !== hash('sha256', $json)) throw new RuntimeException('Backup readback failed');
    echo 'R57_SETTINGS_BACKUP_PASS', PHP_EOL; exit;
}
if (!is_file($backupFile) || hash_file('sha256', $backupFile) !== trim(file_get_contents($directory . '/settings-before.sha256'))) throw new RuntimeException('Settings backup corrupt');
$backup = json_decode(file_get_contents($backupFile), true);
if ($backup['site'] !== $site || $backup['database'] !== $database || $backup['commit'] !== $manifest['commit']) throw new RuntimeException('Backup identity mismatch');
$sqlPath = $directory . '/payload/migration.sql';
if (hash_file('sha256', $sqlPath) !== $manifest['migrationSHA256']) throw new RuntimeException('Migration drift');
if ($mode === 'migrate') {
    if ($rows() !== $backup['rows'] || $schema()[1] !== $backup['schema']) throw new RuntimeException('Settings changed since backup');
    foreach (file($sqlPath, FILE_IGNORE_NEW_LINES) as $statement) {
        if (trim($statement) === '' || strpos(ltrim($statement), '--') === 0) continue;
        $pdo->query($statement)->closeCursor();
    }
}
$after = $rows(); $stripped = [];
foreach ($after as $row) {
    foreach (['source_enabled' => 1, 'inherit_previous' => 0, 'repayment_primary_id' => 0, 'repayment_secondary_id' => 0] as $column => $value) {
        if (!array_key_exists($column, $row) || (int)$row[$column] !== $value) throw new RuntimeException('Unexpected initial policy');
        unset($row[$column]);
    }
    $stripped[] = $row;
}
if ($stripped !== $backup['rows']) throw new RuntimeException('Existing settings changed');
if ($mode === 'migrate') {
    $check = $pdo->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?'); $check->execute([$key]);
    if ((int)$check->fetchColumn() !== 0) throw new RuntimeException('Unexpected upgrade log');
    $insert = $pdo->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) VALUES (?,?,?,?,?,NOW(),?,?,NOW())');
    $insert->execute([$key, '业务来源三模式', 'database/upgrades/2026-10-25-业务来源策略/01-schema.sql', $manifest['migrationSHA256'], $manifest['commit'], 'Codex R57', 'Four additive configuration columns; default manual, historical facts unchanged']);
    file_put_contents($directory . '/migration.json', json_encode(['site' => $site, 'commit' => $manifest['commit'], 'upgradeKey' => $key, 'mode' => 'manual', 'backupSHA256' => hash_file('sha256', $backupFile), 'sqlSHA256' => $manifest['migrationSHA256']], JSON_PRETTY_PRINT));
}
echo 'R57_SETTINGS_' . strtoupper($mode) . '_PASS', PHP_EOL;

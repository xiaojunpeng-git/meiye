<?php
/** Initialize an empty configuration singleton, not historical business data.
 * The PM-approved initial mode is enabled/manual; unused fixed IDs stay zero.
 */
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '/www/backups/010/r57-source-policy-232dd50b') exit(2);
$directory = $argv[1];
$manifestSHA = '5b9250455bb2d6b30ae2fa5af9bd04d5469868f2e2f2c258b526c1d4fddaf753';
if (hash_file('sha256', $directory . '/payload/manifest.json') !== $manifestSHA) throw new RuntimeException('Manifest mismatch');
$manifest = json_decode(file_get_contents($directory . '/payload/manifest.json'), true);
$backup = json_decode(file_get_contents($directory . '/settings-before.json'), true);
if ($manifest['site'] !== '010' || $backup['site'] !== '010' || $backup['database'] !== 'mohe_010_app'
    || $backup['commit'] !== $manifest['commit'] || $backup['rows'] !== []
    || hash_file('sha256', $directory . '/settings-before.json') !== trim(file_get_contents($directory . '/settings-before.sha256'))) throw new RuntimeException('Verified empty configuration backup required');
$ini = parse_ini_file('/www/wwwroot/010.cc3798.com/.env', true, INI_SCANNER_RAW);
$cfg = array_change_key_case($ini['DATABASE'] ?? $ini['database'] ?? []);
if ($cfg['database'] !== 'mohe_010_app' || (string)$cfg['hostport'] !== '3307' || $cfg['prefix'] !== 'eb_') throw new RuntimeException('Wrong instance');
$pdo = new PDO('mysql:host=' . $cfg['hostname'] . ';port=3307;dbname=mohe_010_app;charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
try {
    if ($pdo->query('SELECT * FROM eb_member_commerce_settings ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) !== []) throw new RuntimeException('Configuration was populated concurrently; no overwrite permitted');
    $created = time();
    $row = ['id' => 1, 'purchase_primary_id' => 0, 'purchase_secondary_id' => 0, 'recharge_primary_id' => 0, 'recharge_secondary_id' => 0,
        'version' => 1, 'updated_by' => 0, 'updated_at' => $created, 'source_enabled' => 1, 'inherit_previous' => 0,
        'repayment_primary_id' => 0, 'repayment_secondary_id' => 0];
    $insert = $pdo->prepare('INSERT INTO eb_member_commerce_settings (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
    $insert->execute(array_values($row));
    $audit = $pdo->prepare('INSERT INTO eb_member_commerce_settings_audit (command_key,admin_id,input_hash,before_json,after_json,created_at) VALUES (?,?,?,?,?,?)');
    $audit->execute(['R57_INIT_010_' . substr($manifest['commit'], 0, 16), 0, hash('sha256', json_encode($row)), '{}', json_encode($row), $created]);
    $log = $pdo->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) VALUES (?,?,?,?,?,NOW(),?,?,NOW())');
    $log->execute(['20261025-r57-business-source-policy-initialize', '业务来源空配置初始化', 'scripts/r57-source-policy-release-010-initialize.php', hash_file('sha256', $directory . '/payload/migration.sql'),
        $manifest['commit'], 'Codex R57', 'Previously empty singleton initialized to enabled/manual; fixed source IDs zero; no existing rows overwritten']);
    $pdo->commit();
    $receipt = ['result' => 'PASS', 'site' => '010', 'commit' => $manifest['commit'], 'previousRows' => 0, 'configurationRowsCreated' => 1,
        'mode' => 'manual', 'fixedSourceIds' => 0, 'existingBusinessRowsChanged' => 0, 'auditKey' => '20261025-r57-business-source-policy-initialize'];
    file_put_contents($directory . '/configuration-initialized.json', json_encode($receipt, JSON_PRETTY_PRINT));
    echo json_encode($receipt), PHP_EOL;
} catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }

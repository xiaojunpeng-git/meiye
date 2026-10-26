<?php
declare(strict_types=1);

// Deployment-only adapter for the existing DB-service credential. Apply only
// the two accepted indexes to the exact 010 schema; never widen app grants.
$directory = $argv[1] ?? ''; $manifestHash = $argv[2] ?? '';
if (PHP_SAPI !== 'cli' || $directory !== '/www/backups/010/r53-card-balance-5fd82252'
    || hash_file('sha256', $directory . '/payload/manifest.json') !== $manifestHash) exit(2);
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', get_class($error) . ': index migration failed; no credentials logged' . PHP_EOL); exit(1);
});
$manifest = json_decode((string)file_get_contents($directory . '/payload/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$waiver = json_decode((string)file_get_contents($directory . '/database-backup-waiver.json'), true, 512, JSON_THROW_ON_ERROR);
if ($manifest['site'] !== '010' || $waiver['site'] !== '010' || !$waiver['explicitUserWaiver']
    || $manifest['commit'] !== $waiver['commit']) throw new RuntimeException('Instance/waiver mismatch');
$credentials = parse_ini_file('/root/.mysql57-008.cnf', true, INI_SCANNER_RAW)['client'];
$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_app;charset=utf8mb4',
    $credentials['user'], $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$identity = $pdo->query('SELECT DATABASE() AS db, @@port AS port')->fetch();
if ($identity['db'] !== 'mohe_010_app' || (int)$identity['port'] !== 3307) throw new RuntimeException('Database mismatch');
$indexes = [
    ['eb_store_order', 'idx_card_conversion_repay_origin', ['debt_repay_origin_order_id', 'is_debt_repay', 'id']],
    ['eb_store_debt_repay', 'idx_card_conversion_repay_order', ['repay_order_id', 'debt_id', 'id']],
];
$created = [];
foreach ($indexes as [$table, $index, $columns]) {
    $rows = array_values(array_filter($pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(),
        static fn(array $row): bool => $row['Key_name'] === $index));
    usort($rows, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
    if ($rows) {
        if (array_column($rows, 'Column_name') !== $columns || (int)$rows[0]['Non_unique'] !== 1) throw new RuntimeException('Index conflict');
        continue;
    }
    $pdo->exec('ALTER TABLE `' . $table . '` ADD INDEX `' . $index . '` (`' . implode('`,`', $columns) . '`)');
    $created[] = $table . '.' . $index;
}
echo json_encode(['status' => 'PASS', 'site' => '010', 'created' => $created,
    'appPrivilegesChanged' => false, 'fullDatabaseBackup' => false, 'explicitUserWaiver' => true]), PHP_EOL;

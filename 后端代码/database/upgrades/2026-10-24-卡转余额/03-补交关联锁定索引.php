<?php
declare(strict_types=1);

// Explicit per-instance DDL after backup/authorization. Exact legacy debt links
// need indexed current reads: a locking full scan would block unrelated sales.
// No business row is modified; DDL is not transactional. Rollback only these
// newly created indexes if required, never restore historical business rows.
if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv, true)) {
    throw new RuntimeException('CLI --apply required after instance backup and authorization');
}
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = new \think\App($root . '/');
$app->env->load($root . '/.env');
$app->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', $error->getMessage() . PHP_EOL); exit(1);
});
$prefix = (string)\think\facade\Db::connect()->getConfig('prefix');
if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) throw new RuntimeException('Unsafe table prefix');
$indexes = [
    ['store_order', 'idx_card_conversion_repay_origin', ['debt_repay_origin_order_id', 'is_debt_repay', 'id']],
    ['store_debt_repay', 'idx_card_conversion_repay_order', ['repay_order_id', 'debt_id', 'id']],
];
$before = [];
foreach ($indexes as [$name, $index, $columns]) {
    $table = $prefix . $name;
    $before[$table] = \think\facade\Db::query('SHOW CREATE TABLE `' . $table . '`');
}
$backup = $root . '/runtime/r53-transfer-indexes-before-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
if (file_put_contents($backup, json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    throw new RuntimeException('Cannot save pre-DDL schema evidence');
}
$created = [];
foreach ($indexes as [$name, $index, $columns]) {
    $table = $prefix . $name;
    $existing = array_values(array_filter(\think\facade\Db::query('SHOW INDEX FROM `' . $table . '`'),
        static fn(array $row): bool => (string)$row['Key_name'] === $index));
    usort($existing, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
    if ($existing) {
        if (array_column($existing, 'Column_name') !== $columns || (int)$existing[0]['Non_unique'] !== 1) {
            throw new RuntimeException('Index definition conflict: ' . $table . '.' . $index);
        }
        continue;
    }
    \think\facade\Db::execute('ALTER TABLE `' . $table . '` ADD INDEX `' . $index . '` (`' . implode('`,`', $columns) . '`)');
    $created[] = $table . '.' . $index;
}
echo json_encode(['status' => 'PASS', 'created' => $created, 'schemaBackup' => $backup], JSON_UNESCAPED_UNICODE) . PHP_EOL;

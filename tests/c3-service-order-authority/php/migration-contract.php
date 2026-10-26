<?php
declare(strict_types=1);

$dir = getenv('C3_MIGRATION_DIR') ?: dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-07-29-C3服务单权益占用权威源';
$required = [
    '00-升级清单.md','01-升级前检查.sql','02-正式升级.sql','03-升级后验证.sql',
    '04-回滚或应急说明.md','05-部分创表恢复.sql','SHA256SUMS.txt',
];
$passed = 0;
$failed = 0;
function c3MigrationOk(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS {$name}\n"; return; }
    $failed++; echo "FAIL {$name}\n";
}
foreach ($required as $file) {
    c3MigrationOk('migration file exists ' . $file, is_file($dir . '/' . $file));
}
$manifest = is_file($dir . '/SHA256SUMS.txt') ? file($dir . '/SHA256SUMS.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$seen = [];
foreach ($manifest as $line) {
    if (preg_match('/^([a-f0-9]{64})  (.+)$/D', $line, $matches) !== 1) { continue; }
    $seen[$matches[2]] = $matches[1];
}
foreach (array_slice($required, 0, 6) as $file) {
    c3MigrationOk('sha256 matches ' . $file,
        isset($seen[$file]) && hash_file('sha256', $dir . '/' . $file) === $seen[$file]);
}
$apply = file_get_contents($dir . '/02-正式升级.sql');
$post = file_get_contents($dir . '/03-升级后验证.sql');
c3MigrationOk('upgrade key is stable', strpos($apply, '20260729-005-c3-service-order-authority') !== false);
c3MigrationOk('mysql56 has no json column type', preg_match('/`[^`]+`\s+json\b/i', $apply) !== 1);
c3MigrationOk('postcheck rejects bad active occupation', strpos($post, "status='ACTIVE' AND occupied_times=0") !== false);
echo "C3_MIGRATION_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

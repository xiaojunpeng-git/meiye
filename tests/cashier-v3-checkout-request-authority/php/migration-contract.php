<?php
declare(strict_types=1);

$dir = getenv('CHECKOUT_SOURCE_MIGRATION_DIR')
    ?: dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-07-29-收银V3结账请求来源权威';
$required = [
    '00-升级清单.md',
    '01-升级前检查.sql',
    '02-正式升级.sql',
    '03-升级后验证.sql',
    '04-回滚或应急说明.md',
    '05-部分创表恢复.sql',
    'SHA256SUMS.txt',
];
$passed = 0;
$failed = 0;
function sourceMigrationOk(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

foreach ($required as $file) {
    sourceMigrationOk('migration file exists ' . $file, is_file($dir . '/' . $file));
}
$manifest = is_file($dir . '/SHA256SUMS.txt')
    ? file($dir . '/SHA256SUMS.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];
$hashes = [];
foreach ($manifest as $line) {
    if (preg_match('/^([a-f0-9]{64})  (.+)$/D', $line, $match) === 1) {
        $hashes[$match[2]] = $match[1];
    }
}
foreach (array_slice($required, 0, 6) as $file) {
    sourceMigrationOk('sha256 matches ' . $file,
        isset($hashes[$file]) && hash_file('sha256', $dir . '/' . $file) === $hashes[$file]);
}
$pre = (string)file_get_contents($dir . '/01-升级前检查.sql');
$apply = (string)file_get_contents($dir . '/02-正式升级.sql');
$post = (string)file_get_contents($dir . '/03-升级后验证.sql');
$recovery = (string)file_get_contents($dir . '/05-部分创表恢复.sql');
sourceMigrationOk('upgrade key is stable',
    strpos($apply, '20260729-006-cashier-v3-checkout-source-authority') !== false);
sourceMigrationOk('precheck requires checkout request authority',
    strpos($pre, 'eb_cashier_v3_checkout_request') !== false
        && strpos($pre, 'request_version') !== false);
sourceMigrationOk('source table has exact source uniqueness',
    strpos($apply, 'UNIQUE KEY `uk_request_source` (`request_id`,`source_kind`,`source_id`)') !== false);
sourceMigrationOk('source rows carry scope role binding version and fingerprint',
    strpos($apply, '`tenant_id`') !== false
        && strpos($apply, '`store_id`') !== false
        && strpos($apply, '`bound_request_version`') !== false
        && strpos($apply, '`source_role`') !== false
        && strpos($apply, '`source_fingerprint`') !== false);
sourceMigrationOk('MySQL 5.6 migration has no JSON or window syntax',
    preg_match('/`[^`]+`\s+json\b/i', $apply) !== 1
        && stripos($apply, 'ROW_NUMBER(') === false
        && stripos($apply, 'SKIP LOCKED') === false);
sourceMigrationOk('postcheck rejects cross-scope and future binding versions',
    strpos($post, 'source_ref.tenant_id<>request_row.tenant_id') !== false
        && strpos($post, 'source_ref.store_id<>request_row.store_id') !== false
        && strpos($post, 'source_ref.bound_request_version>request_row.request_version') !== false);
sourceMigrationOk('partial DDL recovery is read-only',
    stripos($recovery, 'DROP TABLE') === false
        && stripos($recovery, 'ALTER TABLE') === false
        && stripos($recovery, 'CREATE TABLE') === false);

echo "CHECKOUT_SOURCE_MIGRATION passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

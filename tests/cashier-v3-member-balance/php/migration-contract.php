<?php
declare(strict_types=1);

$dir = getenv('CHECKOUT_BALANCE_MIGRATION_DIR')
    ?: '/workspace/后端代码/database/upgrades/2026-07-29-收银V3会员余额权威';
$files = [
    '00-升级清单.md', '01-升级前检查.sql', '02-正式升级.sql',
    '03-升级后验证.sql', '04-回滚或应急说明.md',
    '05-部分升级恢复审计.sql', 'SHA256SUMS.txt',
];
$passed = 0;
$failed = 0;
function mbaMigrationAssert(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

foreach ($files as $file) {
    mbaMigrationAssert('migration file exists: ' . $file, is_file($dir . '/' . $file));
}
$pre = (string)file_get_contents($dir . '/01-升级前检查.sql');
$apply = (string)file_get_contents($dir . '/02-正式升级.sql');
$post = (string)file_get_contents($dir . '/03-升级后验证.sql');
$recovery = (string)file_get_contents($dir . '/05-部分升级恢复审计.sql');

mbaMigrationAssert(
    'all SQL files use the same upgrade key',
    strpos($pre, '20260729-009-cashier-v3-member-balance-authority') !== false
        && strpos($apply, '20260729-009-cashier-v3-member-balance-authority') !== false
        && strpos($post, '20260729-009-cashier-v3-member-balance-authority') !== false
        && strpos($recovery, '20260729-009-cashier-v3-member-balance-authority') !== false
);
mbaMigrationAssert(
    'apply adds a real-row positive version and exact trigger',
    strpos($apply, 'ADD COLUMN `balance_version` bigint(20) unsigned NOT NULL DEFAULT') !== false
        && strpos($apply, 'CREATE TRIGGER `eb_user_balance_version_bu`') !== false
        && strpos($apply, 'OLD.`balance_version` + 1') !== false
);
mbaMigrationAssert(
    'apply provides atomic ledger idempotency columns and unique key',
    strpos($apply, '`ben_change_amount` decimal(12,2)') !== false
        && strpos($apply, '`give_change_amount` decimal(12,2)') !== false
        && strpos($apply, '`idempotency_fingerprint` char(64)') !== false
        && strpos($apply, 'UNIQUE KEY `uk_balance_idempotency_key`') !== false
);
mbaMigrationAssert(
    'migration never creates a shadow balance version table',
    strpos($apply, 'CREATE TABLE') === false
        && strpos($apply, 'cashier_v3_member_balance_version') === false
);
mbaMigrationAssert(
    'pre/post/recovery are fail-closed and preserve reconciliation evidence',
    strpos($pre, 'STOP_CASHIER_V3_MEMBER_BALANCE_PRECHECK_FAILED') !== false
        && strpos($post, 'STOP_CASHIER_V3_MEMBER_BALANCE_POSTCHECK_FAILED') !== false
        && strpos($recovery, 'STOP_MEMBER_BALANCE_PARTIAL_UPGRADE_HETEROGENEOUS') !== false
        && strpos($post, 'balance_reconciliation_required_count') !== false
);

echo "CHECKOUT_BALANCE_MIGRATION_CONTRACT passed={$passed} failed={$failed}\n";
if ($failed > 0) {
    exit(1);
}

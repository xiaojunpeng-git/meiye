<?php
declare(strict_types=1);

$dir = getenv('CHECKOUT_RESOURCE_PLAN_MIGRATION_DIR')
    ?: dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-07-29-收银V3结账资源预锁计划';
$required = [
    '00-升级清单.md',
    '01-升级前检查.sql',
    '02-正式升级.sql',
    '03-升级后验证.sql',
    '04-回滚或应急说明.md',
    '05-部分创表恢复审计.sql',
    'SHA256SUMS.txt',
];
$passed = 0;
$failed = 0;
function resourceMigrationOk(string $name, bool $condition): void
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
    resourceMigrationOk('migration file exists ' . $file, is_file($dir . '/' . $file));
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
    resourceMigrationOk('sha256 matches ' . $file,
        isset($hashes[$file]) && hash_file('sha256', $dir . '/' . $file) === $hashes[$file]);
}
$pre = (string)file_get_contents($dir . '/01-升级前检查.sql');
$apply = (string)file_get_contents($dir . '/02-正式升级.sql');
$post = (string)file_get_contents($dir . '/03-升级后验证.sql');
$recovery = (string)file_get_contents($dir . '/05-部分创表恢复审计.sql');
resourceMigrationOk('upgrade key is stable',
    strpos($apply, '20260729-010-cashier-v3-checkout-resource-plan') !== false);
resourceMigrationOk('precheck requires checkout request version and status',
    strpos($pre, 'eb_cashier_v3_checkout_request') !== false
        && strpos($pre, 'request_version') !== false
        && strpos($pre, 'request_status') !== false);
resourceMigrationOk('header is unique per request version',
    strpos($apply, 'UNIQUE KEY `uk_request_bound_version` (`request_id`,`bound_request_version`)') !== false);
resourceMigrationOk('row is unique per physical resource and request version',
    strpos($apply, 'UNIQUE KEY `uk_plan_physical_resource` (`plan_id`,`resource_kind`,`resource_id`)') !== false
        && strpos($apply, 'UNIQUE KEY `uk_request_version_resource` (`request_id`,`bound_request_version`,`resource_kind`,`resource_id`)') !== false);
resourceMigrationOk('row persists complete verified contract',
    foreachToken($apply, [
        '`scope_type`', '`scope_id`', '`lock_order`', '`expected_version`', '`roles_json`',
        '`access_mode`', '`provider_contract_version`', '`authority_fingerprint`', '`row_fingerprint`',
    ]));
resourceMigrationOk('MySQL 5.6 migration has no native JSON or window syntax',
    preg_match('/`[^`]+`\s+json\b/i', $apply) !== 1
        && stripos($apply, 'ROW_NUMBER(') === false
        && stripos($apply, 'SKIP LOCKED') === false);
resourceMigrationOk('postcheck requires exact active bound request version',
    strpos($post, "plan_header.plan_status='active'") !== false
        && strpos($post, 'plan_header.bound_request_version<>request_row.request_version') !== false);
resourceMigrationOk('postcheck protects ten-thousand limits and aggregate counts',
    strpos($post, 'plan_header.resource_count>10000') !== false
        && strpos($post, 'plan_header.role_count>10000') !== false
        && strpos($post, '@checkout_plan_count_drift') !== false);
resourceMigrationOk('partial DDL recovery is read-only',
    stripos($recovery, 'DROP TABLE') === false
        && stripos($recovery, 'ALTER TABLE') === false
        && stripos($recovery, 'CREATE TABLE') === false);

echo "CHECKOUT_RESOURCE_PLAN_MIGRATION passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

function foreachToken(string $source, array $tokens): bool
{
    foreach ($tokens as $token) {
        if (strpos($source, $token) === false) {
            return false;
        }
    }
    return true;
}

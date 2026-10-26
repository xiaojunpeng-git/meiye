<?php
declare(strict_types=1);

$dir = getenv('CHECKOUT_FACT_MIGRATION_DIR')
    ?: dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-07-29-收银V3统一结账事实底座';
$required = [
    '00-升级清单.md','01-升级前检查.sql','02-正式升级.sql',
    '03-升级后验证.sql','04-回滚或应急说明.md','05-部分创表恢复.sql',
];
$passed = 0;
$failed = 0;
function factMigrationOk(string $name, bool $condition): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
}

function factMigrationColumnCount(string $sql, string $column): int
{
    return preg_match_all(
        '/^\\s*`' . preg_quote($column, '/') . '`\\s+[a-z]/mi',
        $sql,
        $matches
    );
}

foreach ($required as $file) {
    factMigrationOk('migration file exists ' . $file, is_file($dir . '/' . $file));
}
$apply = (string)file_get_contents($dir . '/02-正式升级.sql');
$pre = (string)file_get_contents($dir . '/01-升级前检查.sql');
$post = (string)file_get_contents($dir . '/03-升级后验证.sql');
$recovery = (string)file_get_contents($dir . '/05-部分创表恢复.sql');

factMigrationOk('upgrade key is stable', strpos($apply, '20260729-007-cashier-v3-checkout-facts-v1') !== false);
factMigrationOk('four business-grain tables are created',
    substr_count($apply, 'CREATE TABLE IF NOT EXISTS') === 4
        && strpos($apply, 'eb_cashier_v3_sale_fact') !== false
        && strpos($apply, 'eb_cashier_v3_payment_fact') !== false
        && strpos($apply, 'eb_cashier_v3_balance_fact') !== false
        && strpos($apply, 'eb_cashier_v3_performance_fact') !== false);
factMigrationOk('no JSON or floating money columns exist',
    preg_match('/`[^`]+`\s+json\b/i', $apply) !== 1
        && preg_match('/`[^`]*(amount|delta)[^`]*`\s+(float|double|decimal)\b/i', $apply) !== 1);
factMigrationOk('every table has natural idempotency and immutable identity',
    substr_count($apply, 'UNIQUE KEY `uk_tenant_natural` (`tenant_id`,`natural_key`)') === 4
        && factMigrationColumnCount($apply, 'command_idempotency_key') === 4
        && factMigrationColumnCount($apply, 'immutable_fingerprint') === 4
        && factMigrationColumnCount($apply, 'reversal_of') === 4);
factMigrationOk('common scope snapshots source and four times are explicit',
    factMigrationColumnCount($apply, 'tenant_name_snapshot') === 4
        && factMigrationColumnCount($apply, 'organization_path_snapshot') === 4
        && factMigrationColumnCount($apply, 'store_name_snapshot') === 4
        && factMigrationColumnCount($apply, 'member_name_snapshot') === 4
        && factMigrationColumnCount($apply, 'operator_name_snapshot') === 4
        && factMigrationColumnCount($apply, 'business_date') === 4
        && factMigrationColumnCount($apply, 'business_timezone') === 4
        && factMigrationColumnCount($apply, 'occurred_at') === 4
        && factMigrationColumnCount($apply, 'settled_at') === 4
        && factMigrationColumnCount($apply, 'recorded_at') === 4
        && factMigrationColumnCount($apply, 'checkout_request_id') === 4
        && factMigrationColumnCount($apply, 'order_id') === 4
        && factMigrationColumnCount($apply, 'source_line_id') === 4);
factMigrationOk('performance has person type weight and rule snapshots',
    strpos($apply, '`employee_type_snapshot`') !== false
        && strpos($apply, '`employee_type_authority_version`') !== false
        && strpos($apply, '`allocation_weight_numerator`') !== false
        && strpos($apply, '`allocation_weight_denominator`') !== false
        && strpos($apply, '`rule_version_snapshot`') !== false);
factMigrationOk('precheck requires checkout event and employee authority dependencies',
    strpos($pre, 'eb_cashier_v3_checkout_request') !== false
        && strpos($pre, 'eb_cashier_v3_business_event') !== false
        && strpos($pre, 'employment_type_code') !== false);
factMigrationOk('postcheck rejects old card and verifies materialized actual performance',
    strpos($post, "payment_method='old_card_entry'") !== false
        && strpos($post, 'actual_amount<>cash_amount-external_amount') !== false
        && strpos($post, 'cf_orphan_reversals') !== false);
factMigrationOk('partial recovery is read-only and fail closed',
    stripos($recovery, 'CREATE TABLE') === false
        && stripos($recovery, 'ALTER TABLE') === false
        && stripos($recovery, 'DROP TABLE') === false
        && strpos($recovery, 'PARTIAL_CREATE_RECOVERY_READY') !== false
        && strpos($recovery, 'STOP_CHECKOUT_FACT_PARTIAL_HETEROGENEOUS_SCHEMA') !== false);
factMigrationOk('migration avoids MySQL 8 only syntax',
    stripos($apply, 'CHECK (') === false
        && stripos($apply, 'ROW_NUMBER(') === false
        && stripos($apply, 'SKIP LOCKED') === false
        && stripos($apply, 'WITH RECURSIVE') === false);

echo "CHECKOUT_FACT_MIGRATION_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

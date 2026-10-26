<?php

$migrationDir = getenv('C2_PROVIDER_MIGRATION_DIR');
$migrationDir = is_string($migrationDir) && $migrationDir !== ''
    ? rtrim($migrationDir, '/')
    : __DIR__ . '/../../../后端代码/database/upgrades/2026-07-29-C2权益完成依赖提供者';

$passed = 0;
$failed = 0;
function c2pmAssert(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
    } else {
        $failed++;
        echo "FAIL {$name}\n";
    }
}

$pre = (string)file_get_contents($migrationDir . '/01-升级前检查.sql');
$apply = (string)file_get_contents($migrationDir . '/02-正式升级.sql');
$post = (string)file_get_contents($migrationDir . '/03-升级后验证.sql');
$recovery = (string)file_get_contents($migrationDir . '/05-部分创表恢复.sql');

foreach ([
    'eb_cashier_v3_entitlement_debt_guard',
    'eb_cashier_v3_entitlement_debt_guard_mutation',
    'eb_cashier_v3_staff_profile_version',
    'eb_cashier_v3_entitlement_occupation_version',
] as $table) {
    c2pmAssert('apply creates ' . $table, strpos($apply, 'CREATE TABLE IF NOT EXISTS `' . $table . '`') !== false);
}
c2pmAssert(
    'guard has tenant plus origin order unique identity',
    strpos($apply, 'UNIQUE KEY `uk_tenant_origin_order` (`tenant_id`,`origin_order_id`)') !== false
);
c2pmAssert(
    'guard mutation has tenant plus mutation key idempotency',
    strpos($apply, 'UNIQUE KEY `uk_tenant_mutation_key` (`tenant_id`,`mutation_key`)') !== false
        && strpos($apply, '`guard_version_before` bigint(20) unsigned') !== false
        && strpos($apply, '`guard_version_after` bigint(20) unsigned') !== false
);
c2pmAssert(
    'reservation authority requires cart leading index',
    strpos($pre, "first_column='cart_info_id'") !== false
);
c2pmAssert(
    'precheck rejects partial target DDL',
    strpos($pre, '@c2p_target_count IN (0,4)') !== false
        && strpos($pre, 'STOP_C2_ENTITLEMENT_PROVIDER_PRECHECK_FAILED') !== false
);
c2pmAssert(
    'postcheck validates exact unique identities and positive data',
    strpos($post, 'uk_tenant_origin_order|tenant_id,origin_order_id') !== false
        && strpos($post, 'uk_tenant_mutation_key|tenant_id,mutation_key') !== false
        && strpos($post, 'uk_tenant_staff|tenant_id,staff_id') !== false
        && strpos($post, 'uk_tenant_kind_source|tenant_id,source_kind,source_id') !== false
        && strpos($post, 'guard_version_after<>guard_version_before+1') !== false
);
c2pmAssert(
    'partial recovery is read-only and rejects unsafe states',
    stripos($recovery, 'CREATE TABLE') === false
        && stripos($recovery, 'DROP TABLE') === false
        && stripos($recovery, 'TRUNCATE') === false
        && strpos($recovery, 'PARTIAL_DDL_RECOVERY_READY') !== false
        && strpos($recovery, 'STOP_C2_PROVIDER_PARTIAL_NONEMPTY') !== false
        && strpos($recovery, 'STOP_C2_PROVIDER_PARTIAL_HETEROGENEOUS') !== false
);
c2pmAssert(
    'migration stays MySQL 5.6 compatible',
    stripos($apply, ' JSON ') === false
        && stripos($apply, ' CHECK ') === false
        && stripos($apply, ' SKIP LOCKED') === false
        && stripos($apply, ' NOWAIT') === false
        && stripos($apply, 'WITH RECURSIVE') === false
);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
if ($failed > 0) {
    exit(1);
}
echo "C2_ENTITLEMENT_PROVIDER_MIGRATION_CONTRACT=PASS\n";

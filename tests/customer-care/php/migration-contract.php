<?php

$migration = getenv('CUSTOMER_CARE_MIGRATION_DIR');
$migration = is_string($migration) && trim($migration) !== ''
    ? rtrim($migration, '/')
    : dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-07-29-客情任务与记录内核';
$apply = (string)file_get_contents($migration . '/02-正式升级.sql');
$precheck = (string)file_get_contents($migration . '/01-升级前检查.sql');
$postcheck = (string)file_get_contents($migration . '/03-升级后验证.sql');
$recovery = (string)file_get_contents($migration . '/05-部分创表恢复.sql');
$passed = 0;
$failed = 0;

function migrationOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
}

$upgradeKey = '20260729-001-customer-care-core';
$collisions = [];
$upgradeRoot = dirname($migration);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $upgradeRoot,
    FilesystemIterator::SKIP_DOTS
));
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (strpos($path, $migration . DIRECTORY_SEPARATOR) === 0 || !$file->isFile()) {
        continue;
    }
    if (!in_array(strtolower($file->getExtension()), ['sql', 'md', 'txt'], true)) {
        continue;
    }
    if (preg_match('/^--\s*upgrade_key:\s*' . preg_quote($upgradeKey, '/') . '\s*$/m', (string)file_get_contents($path))) {
        $collisions[] = $path;
    }
}
migrationOk('upgrade key has no canonical collision', $collisions === [], implode(',', $collisions));

preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)`/i', $apply, $tableMatches);
migrationOk('migration creates exactly three care tables', $tableMatches[1] === [
    'eb_customer_care_task',
    'eb_customer_care_record',
    'eb_customer_care_operation',
]);
migrationOk('all care tables are InnoDB utf8mb4',
    substr_count($apply, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') === 3);

preg_match_all('/^\s+`[a-z_]+`\s+[a-z]+(?:\([0-9,]+\))?/mi', $apply, $columnMatches);
migrationOk('formal migration has the verified 128 columns', count($columnMatches[0]) === 128,
    'actual=' . count($columnMatches[0]));
migrationOk('binary key columns use ascii_bin', substr_count($apply, 'COLLATE ascii_bin') === 39,
    'actual=' . substr_count($apply, 'COLLATE ascii_bin'));
migrationOk('natural and idempotency unique keys are explicit',
    strpos($apply, 'UNIQUE KEY `uk_tenant_task_key` (`tenant_id`,`task_key`)') !== false
        && strpos($apply, 'UNIQUE KEY `uk_tenant_create_idem` (`tenant_id`,`create_idempotency_key`)') !== false
        && strpos($apply, 'UNIQUE KEY `uk_tenant_record_key` (`tenant_id`,`record_key`)') !== false
        && strpos($apply, 'UNIQUE KEY `uk_tenant_record_idem` (`tenant_id`,`command_idempotency_key`)') !== false
        && strpos($apply, 'UNIQUE KEY `uk_tenant_task_record` (`tenant_id`,`task_id`)') !== false
        && strpos($apply, 'UNIQUE KEY `uk_tenant_next_task` (`tenant_id`,`next_task_id`)') !== false
        && strpos($apply, 'UNIQUE KEY `uk_tenant_operation_key` (`tenant_id`,`operation_key`)') !== false
        && strpos($apply, 'UNIQUE KEY `uk_tenant_operation_idem` (`tenant_id`,`command_idempotency_key`)') !== false);
migrationOk('organization and operation dimensions are explicit columns',
    substr_count($apply, '`organization_path` varchar(191)') === 3
        && substr_count($apply, '`business_store_id` int(11) unsigned') === 3
        && substr_count($apply, '`operation_store_id` int(11) unsigned') === 2
        && substr_count($apply, '`operation_organization_path` varchar(191)') === 2);
migrationOk('standalone records use nullable task id while linked tasks remain unique',
    strpos($apply, '`task_id` bigint(20) unsigned NULL DEFAULT NULL') !== false
        && strpos($apply, 'UNIQUE KEY `uk_tenant_task_record` (`tenant_id`,`task_id`)') !== false);
migrationOk('record type follower related business and followed time are explicit',
    strpos($apply, '`record_type` varchar(32)') !== false
        && strpos($apply, '`followed_at` int(11) unsigned') !== false
        && strpos($apply, '`follower_staff_id` int(11) unsigned') !== false
        && strpos($apply, '`follower_employee_id` int(11) unsigned') !== false
        && strpos($apply, '`follower_name_snapshot` varchar(64)') !== false
        && strpos($apply, '`related_business_type` varchar(32)') !== false
        && strpos($apply, '`related_business_id` varchar(64)') !== false
        && strpos($apply, '`related_business_label_snapshot` varchar(128)') !== false);
migrationOk('formal record and operation freeze the optional next task result',
    strpos($apply, '`requires_next_followup` tinyint(3) unsigned') !== false
        && strpos($apply, '`next_task_id` bigint(20) unsigned NULL') !== false
        && strpos($apply, '`next_planned_at` int(11) unsigned') !== false
        && strpos($apply, '`next_owner_staff_id` int(11) unsigned') !== false
        && strpos($apply, '`next_owner_employee_id` int(11) unsigned') !== false
        && strpos($apply, '`next_owner_name_snapshot` varchar(64)') !== false
        && strpos($apply, '`next_task_id_after` bigint(20) unsigned') !== false
        && strpos($apply, '`next_task_version_after` bigint(20) unsigned') !== false);
migrationOk('planned instant has store owner and organization indexes',
    strpos($apply, '`idx_store_status_plan`') !== false
        && strpos($apply, '`idx_owner_status_plan`') !== false
        && strpos($apply, '`idx_org_path_plan`') !== false);

$withoutComments = preg_replace('/^--.*$/m', '', $apply);
$forbidden = [
    'json type' => '/\bjson\s+(?:null|not|null|default)/i',
    'generated column' => '/\bgenerated\b/i',
    'cte' => '/\bwith\s+[a-z_][a-z0-9_]*\s+as\s*\(/i',
    'window function' => '/\bover\s*\(/i',
    'check constraint' => '/\bcheck\s*\(/i',
    'foreign key' => '/\bforeign\s+key\b/i',
    'destructive ddl' => '/\b(drop|truncate|alter)\s+(table\s+)?/i',
];
foreach ($forbidden as $label => $pattern) {
    migrationOk('MySQL 5.6 forbids ' . $label, !preg_match($pattern, $withoutComments));
}
migrationOk('formal migration does not register itself prematurely',
    stripos($withoutComments, 'INSERT INTO `eb_database_upgrade_log`') === false
        && stripos($withoutComments, 'INSERT INTO eb_database_upgrade_log') === false);

migrationOk('precheck blocks used key and any pre-existing target table',
    strpos($precheck, "upgrade_key=''{$upgradeKey}''") !== false
        && strpos($precheck, '@care_target_table_count=0') !== false
        && strpos($precheck, 'PRECHECK_OK') !== false);
migrationOk('precheck requires C1 receipt and unified active employee assignment foundations',
    strpos($precheck, '20260727-001-cashier-v3-command-idem') !== false
        && strpos($precheck, '20260719-009-employee-org-leader') !== false
        && strpos($precheck, '@care_c1_receipt_column_count=17') !== false
        && strpos($precheck, '@care_c1_receipt_unique_contract_count=1') !== false
        && strpos($precheck, '@care_employee_column_contract_count=9') !== false
        && strpos($precheck, '@care_employee_id_column_contract_count=2') !== false
        && strpos($precheck, '@care_employee_index_contract_count=3') !== false
        && strpos($precheck, '@care_invalid_active_assignment_count=0') !== false);
migrationOk('postcheck freezes engine column index and semantic counts',
    strpos($postcheck, '@care_table_count=3') !== false
        && strpos($postcheck, '@care_column_count=128') !== false
        && strpos($postcheck, '@care_ascii_contract_count=39') !== false
        && strpos($postcheck, '@care_nullable_task_contract=1') !== false
        && strpos($postcheck, '@care_nullable_next_task_contract=1') !== false
        && strpos($postcheck, '@care_index_count=26') !== false
        && strpos($postcheck, '@care_unique_contract_count=8') !== false
        && strpos($postcheck, 'POSTCHECK_OK') !== false);
migrationOk('postcheck revalidates shared receipt and employee dependencies',
    strpos($postcheck, '@care_c1_upgrade_key_used=1') !== false
        && strpos($postcheck, '@care_employee_upgrade_key_used=1') !== false
        && strpos($postcheck, '@care_c1_receipt_column_count=17') !== false
        && strpos($postcheck, '@care_employee_column_contract_count=9') !== false
        && strpos($postcheck, '@care_employee_id_column_contract_count=2') !== false
        && strpos($postcheck, '@care_invalid_active_assignment_count=0') !== false);
migrationOk('postcheck rejects fake task states',
    strpos($postcheck, "status NOT IN ('UNSTARTED','IN_PROGRESS','COMPLETED','VOIDED')") !== false
        && strpos($postcheck, "task_status_before IN ('DELETED','REASSIGNED')") !== false);
migrationOk('postcheck validates record time and append-only status contract',
    strpos($postcheck, "status NOT IN ('NORMAL','VOIDED')") !== false
        && strpos($postcheck, 'occurred_at=0') !== false
        && strpos($postcheck, 'settled_at=0') !== false
        && strpos($postcheck, 'recorded_at=0') !== false);
migrationOk('partial recovery is non-destructive and accepts only exact one-two table residue',
    strpos($recovery, 'actual_column_hash=expected_column_hash') !== false
        && strpos($recovery, 'actual_index_hash=expected_index_hash') !== false
        && strpos($recovery, 'STOP_PARTIAL_DDL_UPGRADE_LOG_INVALID') !== false
        && strpos($recovery, 'STOP_PARTIAL_DDL_UPGRADE_REGISTERED') !== false
        && strpos($recovery, 'STOP_PARTIAL_DDL_DEPENDENCY_INVALID') !== false
        && strpos($recovery, 'STOP_PARTIAL_DDL_ZERO_TABLES') !== false
        && strpos($recovery, 'STOP_PARTIAL_DDL_FULL_INSTALL') !== false
        && strpos($recovery, 'STOP_PARTIAL_DDL_HETEROGENEOUS') !== false
        && strpos($recovery, 'STOP_PARTIAL_DDL_NONEMPTY') !== false
        && strpos($recovery, 'PARTIAL_DDL_RECOVERY_READY') !== false
        && !preg_match('/DROP\s+TABLE\s+`?eb_customer_care_/i', $recovery));

$sumFile = $migration . '/SHA256SUMS.txt';
$sumBody = is_file($sumFile) ? trim((string)file_get_contents($sumFile)) : '';
$sumOk = $sumBody !== '';
$sumDetails = [];
$sumEntries = [];
foreach ($sumBody === '' ? [] : preg_split('/\r?\n/', $sumBody) as $line) {
    if (!preg_match('/^([a-f0-9]{64})  (.+)$/D', $line, $matched)) {
        $sumOk = false;
        $sumDetails[] = 'bad line=' . $line;
        continue;
    }
    $sumEntries[] = $matched[2];
    $target = $migration . '/' . $matched[2];
    if (!is_file($target) || !hash_equals($matched[1], hash_file('sha256', $target))) {
        $sumOk = false;
        $sumDetails[] = 'mismatch=' . $matched[2];
    }
}
migrationOk('SHA256SUMS lists every migration artifact exactly once', $sumEntries === [
    '00-升级清单.md',
    '01-升级前检查.sql',
    '02-正式升级.sql',
    '03-升级后验证.sql',
    '04-回滚或应急说明.md',
    '05-部分创表恢复.sql',
], implode(',', $sumEntries));
migrationOk('SHA256SUMS matches every migration artifact', $sumOk, implode(',', $sumDetails));

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
if ($failed > 0) {
    exit(1);
}
echo "CUSTOMER_CARE_MIGRATION_CONTRACT=PASS\n";

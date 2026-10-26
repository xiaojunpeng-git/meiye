<?php

$root = '/backend/database/upgrades/2026-07-29-员工人员类型权威源';
$passed = 0;
$failed = 0;

function etamAssert(string $name, bool $condition): void
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

$files = [
    '00-升级清单.md',
    '01-升级前检查.sql',
    '02-正式升级.sql',
    '03-升级后验证.sql',
    '04-回滚或应急说明.md',
    '05-部分升级恢复审计.sql',
];
$allExist = true;
foreach ($files as $file) {
    $allExist = $allExist && is_file($root . '/' . $file);
}
etamAssert('canonical migration package is complete', $allExist && is_file($root . '/SHA256SUMS.txt'));

$pre = (string)file_get_contents($root . '/01-升级前检查.sql');
$apply = (string)file_get_contents($root . '/02-正式升级.sql');
$post = (string)file_get_contents($root . '/03-升级后验证.sql');
$recovery = (string)file_get_contents($root . '/05-部分升级恢复审计.sql');
$combined = $pre . $apply . $post . $recovery;

etamAssert(
    'upgrade key is globally fixed',
    substr_count($combined, '20260729-005-employee-employment-type-authority') >= 4
);
etamAssert(
    'migration creates nullable code and zero version for unclassified staff',
    strpos($apply, 'employment_type_code varchar(16)') !== false
        && strpos($apply, 'NULL DEFAULT NULL') !== false
        && strpos($apply, 'employment_type_version bigint(20) unsigned NOT NULL DEFAULT 0') !== false
);
etamAssert(
    'migration never backfills historical employee types',
    strpos($apply, 'UPDATE eb_employee') === false
        && strpos($apply, "SET employee.employment_type_code='internal'") === false
        && strpos($apply, '不回填或改写旧员工档案') !== false
);
etamAssert(
    'permission is hidden platform child and not automatically granted to roles',
    strpos($apply, "'setting-staff-employment-type'") !== false
        && strpos($apply, "parent.unique_auth='setting-staff-index'") !== false
        && strpos($apply, "0,0,0,1,'',CAST(parent.id AS CHAR),2") !== false
        && strpos($apply, 'UPDATE eb_system_role') === false
);
etamAssert(
    'postcheck permits unclassified history while enforcing the type invariant',
    strpos($post, "employment_type_code IN ('internal','partner','outsourced') AND employment_type_version>0") !== false
        && strpos($post, 'unclassified_employee_count') !== false
);
etamAssert(
    'partial recovery is read-only and rejects unsafe data',
    strpos($recovery, 'PARTIAL_UPGRADE_RECOVERY_READY') !== false
        && strpos($recovery, 'STOP_EMPLOYEE_TYPE_PARTIAL_UNSAFE_DATA') !== false
        && strpos($recovery, 'ALTER TABLE') === false
        && strpos($recovery, 'UPDATE eb_') === false
        && strpos($recovery, 'DELETE FROM') === false
);

$checksumLines = file($root . '/SHA256SUMS.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$checksumOk = count($checksumLines) === count($files);
foreach ($checksumLines as $line) {
    if (!preg_match('/^([a-f0-9]{64})  (.+)$/', $line, $matches)) {
        $checksumOk = false;
        continue;
    }
    $path = $root . '/' . $matches[2];
    $checksumOk = $checksumOk && is_file($path) && hash_file('sha256', $path) === $matches[1];
}
etamAssert('migration checksum manifest matches canonical files', $checksumOk);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
if ($failed > 0) {
    exit(1);
}
echo "EMPLOYEE_TYPE_AUTHORITY_MIGRATION_CONTRACT=PASS\n";

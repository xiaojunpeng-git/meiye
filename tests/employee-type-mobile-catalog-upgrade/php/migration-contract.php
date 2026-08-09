<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$migration = $root . '/后端代码/database/upgrades/2026-08-05-员工类型全量内部归类与手机端权限目录';
$files = [
    '00-升级清单.md',
    '01-升级前检查.sql',
    '02-正式升级.sql',
    '03-升级后验证.sql',
    '04-回滚或应急说明.md',
];
$failed = 0;
$assert = static function (string $id, bool $ok) use (&$failed): void {
    if ($ok) { echo "PASS {$id}\n"; return; }
    $failed++; fwrite(STDERR, "FAIL {$id}\n");
};

foreach ($files as $file) {
    $assert('ETMC-FILE-' . $file, is_file($migration . '/' . $file));
}
$assert('ETMC-FILE-SHA256SUMS', is_file($migration . '/SHA256SUMS.txt'));

$pre = (string)@file_get_contents($migration . '/01-升级前检查.sql');
$apply = (string)@file_get_contents($migration . '/02-正式升级.sql');
$post = (string)@file_get_contents($migration . '/03-升级后验证.sql');
$catalog = '401100,401001,401002,401003,401004,401005,401006,401007,401008,401200,401300';
$assert('ETMC-UPGRADE-KEY', substr_count($pre . $apply . $post, '20260805-002-employee-type-mobile-catalog-normalization') >= 3);
$assert('ETMC-ACTIVE-UNCLASSIFIED-ONLY',
    str_contains($apply, 'employee.status=1')
    && str_contains($apply, 'employee.is_del=0')
    && str_contains($apply, 'employee.employment_type_code IS NULL')
    && str_contains($apply, 'employee.employment_type_version=0')
    && str_contains($apply, "employee.employment_type_code='internal'")
    && str_contains($apply, 'employee.employment_type_version=1')
);
$assert('ETMC-PRESERVES-EXPLICIT-TYPES',
    !str_contains($apply, "employment_type_code='partner'")
    && !str_contains($apply, "employment_type_code='outsourced'")
);
$assert('ETMC-MOBILE-CATALOG-RULES',
    substr_count($pre . $apply . $post, $catalog) >= 3
    && str_contains($apply, "'mobile'")
    && str_contains($apply, 'ON DUPLICATE KEY UPDATE')
    && str_contains($apply, 'COALESCE(eb_job_position_channel_rule.rules')
);
$assert('ETMC-DOES-NOT-TOUCH-EMPLOYEE-MOBILE-PROJECTIONS',
    !str_contains($apply, 'employee_mobile_auth')
    && !str_contains($apply, 'staff_channel_entry')
);
$assert('ETMC-POSTCHECK-FAILS-CLOSED',
    str_contains($post, '@etmc_remaining_active_unclassified=0')
    && str_contains($post, '@etmc_mobile_rule_mismatch_count=0')
    && str_contains($post, 'STOP_EMPLOYEE_TYPE_MOBILE_CATALOG_POSTCHECK_FAILED')
);

$manifest = file($migration . '/SHA256SUMS.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$checksumOk = count($manifest) === count($files);
foreach ($manifest as $line) {
    if (!preg_match('/^([a-f0-9]{64})  (.+)$/', $line, $matches)) {
        $checksumOk = false;
        continue;
    }
    $path = $migration . '/' . $matches[2];
    $checksumOk = $checksumOk && is_file($path) && hash_file('sha256', $path) === $matches[1];
}
$assert('ETMC-SHA256SUMS', $checksumOk);
exit($failed === 0 ? 0 : 1);

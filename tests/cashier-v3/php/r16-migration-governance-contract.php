<?php

$root = dirname(__DIR__, 3);
$upgradeRoot = $root . '/后端代码/database/upgrades';
$packages = [
    '2026-08-10-报表渠道事实快照',
    '2026-08-10-收银V3提单结账内部引用',
    '2026-08-10-顾客生命周期与首次疗程归因',
    '2026-08-11-在职门店员工身份关联修复',
    '2026-08-11-收银V3卡操作权益余额结算',
    '2026-08-11-收银V3销售行优惠券快照',
];

$keys = [];
foreach ($packages as $package) {
    $source = file_get_contents($upgradeRoot . '/' . $package . '/02-正式升级.sql');
    if (!preg_match('/^-- upgrade_key: ([^\s]+)$/m', (string)$source, $matches)) {
        fwrite(STDERR, "FAIL migration key missing: {$package}\n");
        exit(1);
    }
    $keys[] = $matches[1];
}

$lifecyclePrecheck = file_get_contents($upgradeRoot . '/2026-08-10-顾客生命周期与首次疗程归因/01-升级前检查.sql');
$creditPrecheck = file_get_contents($upgradeRoot . '/2026-08-11-收银V3卡操作权益余额结算/01-升级前检查.sql');
$lifecycleManifest = file($upgradeRoot . '/2026-08-10-顾客生命周期与首次疗程归因/SHA256SUMS.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$lifecyclePrecheckHash = hash_file('sha256', $upgradeRoot . '/2026-08-10-顾客生命周期与首次疗程归因/01-升级前检查.sql');

$lifecycleManifestMatches = false;
foreach ($lifecycleManifest as $line) {
    if (strpos($line, $lifecyclePrecheckHash . '  01-升级前检查.sql') === 0) {
        $lifecycleManifestMatches = true;
        break;
    }
}

$ok = count($keys) === count(array_unique($keys))
    && strpos((string)$lifecyclePrecheck, '20260810-001-report-channel-fact-snapshot') !== false
    && strpos((string)$lifecyclePrecheck, 'eb_cashier_v3_balance_fact') !== false
    && strpos((string)$lifecyclePrecheck, 'eb_cashier_v3_performance_fact') !== false
    && strpos((string)$creditPrecheck, '20260729-010-cashier-v3-checkout-resource-plan') !== false
    && strpos((string)$creditPrecheck, '20260730-021-cashier-v3-card-operation-authority-v1') !== false
    && strpos((string)$creditPrecheck, 'STOP_CASHIER_CARD_OPERATION_ENTITLEMENT_CREDIT_PRECHECK_FAILED') !== false
    && $lifecycleManifestMatches;

echo $ok ? "R16_MIGRATION_GOVERNANCE=PASS\n" : "R16_MIGRATION_GOVERNANCE=FAIL\n";
exit($ok ? 0 : 1);

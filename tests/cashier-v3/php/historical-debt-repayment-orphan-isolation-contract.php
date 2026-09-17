<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$dir = $root . '/后端代码/database/upgrades/2026-09-17-瑞昊历史欠款补交孤儿台账隔离';
$script = $dir . '/01-执行.php';
$doc = $dir . '/00-升级说明.md';
$verify = $dir . '/02-执行后验证.sql';
foreach ([$script, $doc, $verify] as $file) if (!is_file($file)) throw new RuntimeException('orphan isolation package missing: ' . $file);
$source = (string)file_get_contents($script);
foreach ([
    'repayment_order_still_exists_or_missing_link', 'successful_v3_repayment_exists', 'debt_repaid_not_zero',
    'candidate_stale_or_ineligible', 'CREATE TABLE IF NOT EXISTS eb_mig_historical_debt_repay_orphan_backup',
    'INSERT IGNORE INTO eb_mig_historical_debt_repay_orphan_backup', 'DELETE FROM eb_store_debt_repay',
    '--allow-ruihao', 'No argument is read-only',
] as $needle) if (strpos($source, $needle) === false) throw new RuntimeException('orphan isolation contract missing: ' . $needle);
if (strpos($source, 'eb_cashier_v3_payment_fact') !== false) throw new RuntimeException('orphan isolation must not write payment facts');
foreach (['不能计为收款', '先将原始台账', '不会补入任何业绩'] as $needle) {
    if (strpos((string)file_get_contents($doc), $needle) === false) throw new RuntimeException('orphan isolation documentation incomplete: ' . $needle);
}
foreach (['source_rows_remaining', 'accidental_payment_facts'] as $needle) {
    if (strpos((string)file_get_contents($verify), $needle) === false) throw new RuntimeException('orphan isolation verification incomplete: ' . $needle);
}
echo "HISTORICAL_DEBT_REPAYMENT_ORPHAN_ISOLATION_CONTRACT_OK\n";

<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$dir = $root . '/后端代码/database/upgrades/2026-09-17-瑞昊历史欠款补交收款事实回填';
$script = $dir . '/01-执行.php';
$doc = $dir . '/00-升级说明.md';
$verify = $dir . '/02-执行后验证.sql';
foreach ([$script, $doc, $verify] as $file) {
    if (!is_file($file)) throw new RuntimeException('historical debt coverage package missing: ' . $file);
}
$source = (string)file_get_contents($script);
foreach ([
    "return \$legacyPayType === 'cash' ? 'other_collection' : null",
    'debt_repaid_total_mismatch',
    'unsupported_legacy_payment_method',
    'event_without_payment_fact',
    'source_document_type',
    'debt_repayment',
    'historical_debt_repayment:payment:',
    'payment_collected',
    'forward',
    'effective',
    'not_emitted_historical_backfill',
    'candidate_stale_or_ineligible',
    '--allow-ruihao',
] as $needle) {
    if (strpos($source, $needle) === false) throw new RuntimeException('missing historical debt coverage contract: ' . $needle);
}
if (strpos($source, 'cashier_v3_payment_sale_allocation_fact') !== false) {
    throw new RuntimeException('historical debt backfill must not create sale allocation facts');
}
$documentation = (string)file_get_contents($doc);
foreach (['现金业绩', '不生成销售事实', '不写入'] as $needle) {
    if (strpos($documentation, $needle) === false) throw new RuntimeException('historical debt coverage documentation incomplete: ' . $needle);
}
$verification = (string)file_get_contents($verify);
foreach (['event_without_fact', 'invented_sale_allocation_rows'] as $needle) {
    if (strpos($verification, $needle) === false) throw new RuntimeException('historical debt coverage verification missing: ' . $needle);
}
echo "HISTORICAL_DEBT_REPAYMENT_COVERAGE_CONTRACT_OK\n";

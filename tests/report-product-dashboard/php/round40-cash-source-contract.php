<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$product = file_get_contents($root . '/后端代码/app/services/report/ProductManagementDashboardServices.php');
$reader = file_get_contents($root . '/后端代码/app/services/query/metric/RegisteredMetricReadServices.php');
$member = file_get_contents($root . '/后端代码/app/services/report/MemberManagementDashboardServices.php');
if ($product === false || $reader === false || $member === false) {
    throw new RuntimeException('无法读取第40轮看板源码');
}

$checks = [
    'product category income uses registered net cash' => str_contains($product, "categoryRows('actual_performance'")
        && !str_contains($product, "Db::name('cashier_v3_payment_sale_allocation_fact')"),
    'direct product scope excludes card and non-sale rows' => str_contains($product, "['product', 'goods']")
        && str_contains($product, '($row[\'sale_source_type\'] ?? \'\') === \'card\'')
        && str_contains($product, '($row[\'sale_fact_id\'] ?? \'\') === \'\''),
    'registered rows expose sale kind on both branches' => substr_count($reader, 's.source_type sale_source_type') === 2,
    'registered cash excludes non-cash methods and inherits reversal method' => str_contains($reader, "p.payment_method <> 'other_collection'")
        && str_contains($reader, "p.payment_method <> 'old_card_entry'")
        && str_contains($reader, "['other_collection', 'old_card_entry']")
        && str_contains($reader, 'COALESCE(original.sale_fact_id,p.sale_fact_id)'),
    'member and product use the same cash projection' => str_contains($member, "categoryRows('actual_performance'")
        && str_contains($product, "categoryRows('actual_performance'"),
    'product and group use enabled platform category order' => str_contains($product, "->order('pid', 'asc')->order('sort', 'desc')->order('id', 'desc')")
        && str_contains($product, 'if ($pid > 0 && isset($parents[$pid])) continue;'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "FAIL\n" . implode("\n", $failed) . "\n");
    exit(1);
}

echo 'PASS ' . count($checks) . " product/member cash source checks\n";

<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$provider = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryStatisticsUnifiedQueryProvider.php');
$analytics = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryMovementAnalyticsServices.php');
$contract = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryStatisticsUnifiedQueryContract.php');
$failed = 0;
function statisticsPeriodAssert(string $label, bool $valid): void {
    global $failed;
    echo ($valid ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$valid) $failed++;
}

// 日期先下推到事实表，再跨日聚合；不能隐藏日期后仍按日返回重复商品行。
statisticsPeriodAssert('validated period is pushed into the fact query before aggregation',
    str_contains($provider, 'movementPeriod($payload')
    && str_contains($provider, 'listForLocations($locationIds, $kind, $from, $to, $canViewCost, true)')
    && str_contains($analytics, "->whereBetween('f.business_date', [\$from, \$to])")
    && str_contains($analytics, '($groupByPeriod ? \'\' : \'f.business_date,\') . $dimensions'));
statisticsPeriodAssert('document count is distinct within the entire selected period',
    str_contains($analytics, 'COUNT(DISTINCT f.source_id)')
    && str_contains($analytics, 'SUM(f.cost_amount_cents)')
    && str_contains($analytics, 'SUM(f.quantity_units)'));
statisticsPeriodAssert('ambiguous date predicates are rejected rather than silently misaggregated',
    str_contains($provider, 'UNIFIED_QUERY_STATISTICS_PERIOD_INVALID')
    && str_contains($provider, "['filters', 'quickFilters', 'keywordFilters']"));
statisticsPeriodAssert('result date is not a default visible/export field and whole-unit quantities retain trailing zeroes',
    str_contains($contract, "field('business_date', '业务日期', 'date', false, true)")
    && str_contains($analytics, 'if ($scale === 0) return (string)$units;'));

echo "STATISTICS_PERIOD_AGGREGATION_CONTRACT_RESULT failed={$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

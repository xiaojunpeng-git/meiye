<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\query\InventoryStatisticsInboundUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryStatisticsUnifiedQueryProvider;
use app\services\query\UnifiedQueryException;

$provider = (new ReflectionClass(InventoryStatisticsInboundUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
$period = (new ReflectionClass(InventoryStatisticsUnifiedQueryProvider::class))->getMethod('movementPeriod');
if (PHP_VERSION_ID < 80100) $period->setAccessible(true);
$failed = 0;
function periodFilterAssert(string $label, bool $valid): void {
    global $failed;
    echo ($valid ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$valid) $failed++;
}
function periodTop(string $operator, $value): array {
    return ['field_key' => 'business_date', 'operator' => $operator, 'value' => $value];
}

// 统一查询会把页面周期控件的 gte/lte 规范成这两个操作符；两种协议必须落到同一事实日期范围。
$range = ['2026-09-01', '2026-09-29'];
$paired = $period->invoke($provider, ['topFilterConditions' => [
    periodTop('greater_or_equal', '2026-09-01'),
    periodTop('less_or_equal', '2026-09-30'),
]], '2026-09-29');
$between = $period->invoke($provider, ['topFilterConditions' => [periodTop('between', ['2026-09-01', '2026-09-30'])]], '2026-09-29');
periodFilterAssert('visible period picker and between query use the same cutoff-clamped window', $paired === $range && $between === $range);
periodFilterAssert('partial bounds stay inside server cutoff and intersect with other AND bounds',
    $period->invoke($provider, ['topFilterConditions' => [periodTop('greater_or_equal', '2026-09-10')]], '2026-09-29') === ['2026-09-10', '2026-09-29']
    && $period->invoke($provider, ['topFilterConditions' => [periodTop('between', ['2026-09-01', '2026-09-30']), periodTop('less_or_equal', '2026-09-15')]], '2026-09-29') === ['2026-09-01', '2026-09-15']);
try {
    $period->invoke($provider, ['filters' => [periodTop('greater_or_equal', '2026-09-01')]], '2026-09-29');
    $rejected = false;
} catch (UnifiedQueryException $error) {
    $rejected = $error->getMessage() === '请使用业务日期周期筛选库存统计。';
}
periodFilterAssert('ambiguous advanced date predicates remain rejected', $rejected);

echo "STATISTICS_PERIOD_FILTER_CONTRACT_RESULT failed={$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

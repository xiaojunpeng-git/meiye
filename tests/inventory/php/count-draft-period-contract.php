<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\query\InventoryCountUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryOperationalUnifiedQueryProvider;

/** 草稿只跳过完成日期周期，其他服务端状态及权限筛选仍须保持原查询契约。 */
function draftPeriodAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$provider = (new ReflectionClass(InventoryCountUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(InventoryOperationalUnifiedQueryProvider::class, 'includeDraftsOutsideCompletionPeriod');
$method->setAccessible(true);
$rows = [
    ['id' => 1, 'status_name' => 'CONFIRMED', 'count_date' => '2026-09-28'],
    ['id' => 2, 'status_name' => 'CONFIRMED', 'count_date' => '2026-09-29'],
    ['id' => 'draft-3', 'draft_id' => 3, 'status_name' => 'DRAFT', 'count_date' => ''],
];
$payload = ['topFilterConditions' => [
    ['field_key' => 'count_date', 'operator' => 'between', 'value' => ['2026-09-29', '2026-09-29']],
    ['field_key' => 'status_name', 'operator' => 'equal', 'value' => 'DRAFT'],
]];
[$visible, $remaining] = $method->invoke($provider, $rows, $payload);
draftPeriodAssert(array_column($visible, 'id') === [2, 'draft-3'], 'draft remains alongside completed rows in the default period');
draftPeriodAssert(count($remaining['topFilterConditions']) === 1
    && $remaining['topFilterConditions'][0]['field_key'] === 'status_name', 'status filter remains for unified execution');
draftPeriodAssert($visible[1]['count_date'] === '', 'draft never receives a fabricated completion date');
echo "COUNT_DRAFT_PERIOD_CONTRACT_OK\n";

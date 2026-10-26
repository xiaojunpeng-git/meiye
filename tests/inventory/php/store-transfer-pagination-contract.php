<?php
declare(strict_types=1);

/** 调拨列表按单据分页：权限内的完整总数、末页余数与空筛选都由统一查询负责。 */
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\query\InventoryOperationalUnifiedQueryRegistrar;
use app\services\query\StructuredExpressionEvaluator;
use app\services\query\StructuredExpressionValidator;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPageRegistry;

$registry = UnifiedQueryPageRegistry::fromRegistrars([], [new InventoryOperationalUnifiedQueryRegistrar()]);
$execution = new UnifiedQueryExecutionServices($registry, new StructuredExpressionValidator($registry), new StructuredExpressionEvaluator());
$rows = [];
for ($id = 1; $id <= 206; $id++) {
    $rows[] = [
        'record_id' => 'transfer:' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
        'tenant_id' => 'local', 'store_id' => $id === 206 ? 2 : 1,
        'business_date' => '2026-10-24', 'order_sn' => 'DB' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
        'status_name' => $id <= 25 ? '在途' : '已收货',
    ];
}
$context = ['tenant_id' => 'local', 'store_id' => 1, 'operator_id' => 1, 'permissions' => ['inventory.batch.view'], 'query_cutoff_date' => '2026-10-24', 'data_as_of' => 1];
$scope = static fn (array $row): bool => $row['tenant_id'] === 'local' && $row['store_id'] === 1;

foreach ([1 => [100, 'DB001'], 2 => [100, 'DB101'], 3 => [5, 'DB201']] as $page => [$count, $firstOrder]) {
    $result = $execution->execute('inventory_transfer', $rows, [], ['page' => $page, 'limit' => 100], $context, $scope);
    if ($result['pagination']['total'] !== 205 || count($result['rows']) !== $count || $result['rows'][0]['order_sn'] !== $firstOrder) {
        throw new RuntimeException("调拨第 {$page} 页数量、顺序或完整总数错误");
    }
}

$inTransit = $execution->execute('inventory_transfer', $rows, [], [
    'page' => 1, 'limit' => 100,
    'topFilterConditions' => [['field' => 'status_name', 'operator' => 'eq', 'value' => '在途']],
], $context, $scope);
if ($inTransit['pagination']['total'] !== 25 || count($inTransit['rows']) !== 25) {
    throw new RuntimeException('筛选后的完整总数或当前页记录数错误');
}

$empty = $execution->execute('inventory_transfer', $rows, [], [
    'page' => 1, 'limit' => 100,
    'topFilterConditions' => [['field' => 'status_name', 'operator' => 'eq', 'value' => '草稿']],
], $context, $scope);
if ($empty['pagination']['total'] !== 0 || count($empty['rows']) !== 0) {
    throw new RuntimeException('空筛选仍返回调拨记录或错误总数');
}

echo "STORE_TRANSFER_PAGINATION_CONTRACT_OK\n";

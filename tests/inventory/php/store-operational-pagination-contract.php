<?php
declare(strict_types=1);

/** 门店普通单据列表共用库存统一查询：每页只返回 100 张，完整总数仍按权限和筛选计算。 */
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
        'record_id' => 'document:' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
        'tenant_id' => 'local', 'store_id' => $id === 206 ? 2 : 1,
        'business_date' => '2026-10-24', 'order_sn' => 'DOC' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
        'status_name' => $id <= 25 ? '处理中' : '已完成',
    ];
}
$context = ['tenant_id' => 'local', 'store_id' => 1, 'operator_id' => 1, 'permissions' => ['inventory.batch.view'], 'query_cutoff_date' => '2026-10-24', 'data_as_of' => 1];
$scope = static fn (array $row): bool => $row['tenant_id'] === 'local' && $row['store_id'] === 1;
$pages = ['inventory_inbound', 'inventory_outbound', 'inventory_count', 'inventory_request', 'inventory_salon_usage', 'inventory_import'];

foreach ($pages as $pageCode) {
    foreach ([1 => [100, 'DOC001'], 2 => [100, 'DOC101'], 3 => [5, 'DOC201']] as $page => [$count, $firstOrder]) {
        $result = $execution->execute($pageCode, $rows, [], ['page' => $page, 'limit' => 100], $context, $scope);
        if ($result['pagination']['total'] !== 205 || count($result['rows']) !== $count || $result['rows'][0]['order_sn'] !== $firstOrder) {
            throw new RuntimeException("{$pageCode} 第 {$page} 页数量、顺序或完整总数错误");
        }
    }
    $filtered = $execution->execute($pageCode, $rows, [], [
        'page' => 1, 'limit' => 100,
        'topFilterConditions' => [['field' => 'status_name', 'operator' => 'eq', 'value' => '处理中']],
    ], $context, $scope);
    if ($filtered['pagination']['total'] !== 25 || count($filtered['rows']) !== 25) {
        throw new RuntimeException("{$pageCode} 筛选结果总数错误");
    }
    $empty = $execution->execute($pageCode, $rows, [], [
        'page' => 1, 'limit' => 100,
        'topFilterConditions' => [['field' => 'status_name', 'operator' => 'eq', 'value' => '不存在']],
    ], $context, $scope);
    if ($empty['pagination']['total'] !== 0 || count($empty['rows']) !== 0) {
        throw new RuntimeException("{$pageCode} 空结果页或总数错误");
    }
}

echo "STORE_OPERATIONAL_PAGINATION_CONTRACT_OK pages=" . count($pages) . "\n";

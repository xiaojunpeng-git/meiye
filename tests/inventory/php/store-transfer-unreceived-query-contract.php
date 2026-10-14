<?php
declare(strict_types=1);

/** 门店未到货快捷查询沿用统一查询过滤与权限，不能把调出单或其他门店单据混入结果。 */
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\query\InventoryOperationalUnifiedQueryRegistrar;
use app\services\query\StructuredExpressionEvaluator;
use app\services\query\StructuredExpressionValidator;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPageRegistry;

$registry = UnifiedQueryPageRegistry::fromRegistrars([], [new InventoryOperationalUnifiedQueryRegistrar()]);
$execution = new UnifiedQueryExecutionServices($registry, new StructuredExpressionValidator($registry), new StructuredExpressionEvaluator());
$rows = [
    ['record_id' => 'transfer:1', 'tenant_id' => '0', 'store_id' => 9, 'business_date' => '2026-10-01', 'order_sn' => 'IN-OLD', 'status_name' => '在途', 'can_receive' => true],
    ['record_id' => 'transfer:2', 'tenant_id' => '0', 'store_id' => 9, 'business_date' => '2026-10-14', 'order_sn' => 'OUT', 'status_name' => '在途', 'can_receive' => false],
    ['record_id' => 'transfer:3', 'tenant_id' => '0', 'store_id' => 9, 'business_date' => '2026-10-14', 'order_sn' => 'DONE', 'status_name' => '已收货', 'can_receive' => false],
    ['record_id' => 'transfer:4', 'tenant_id' => '0', 'store_id' => 10, 'business_date' => '2026-10-01', 'order_sn' => 'FOREIGN', 'status_name' => '在途', 'can_receive' => true],
];
$context = ['tenant_id' => '0', 'store_id' => 9, 'operator_id' => 1, 'permissions' => ['inventory.batch.view'], 'query_cutoff_date' => '2026-10-14', 'data_as_of' => 1];
$result = $execution->execute('inventory_transfer', $rows, [], [
    'topFilters' => [
        ['field' => 'status_name', 'operator' => 'eq', 'value' => '在途'],
        ['field' => 'can_receive', 'operator' => 'eq', 'value' => true],
    ],
    'visibleFields' => ['order_sn', 'status_name'], 'limit' => 20,
], $context, static fn (array $row): bool => $row['tenant_id'] === '0' && $row['store_id'] === 9);
if ($result['pagination']['total'] !== 1 || $result['rows'][0]['order_sn'] !== 'IN-OLD') {
    throw new RuntimeException('未到货查询混入调出、已收货或其他门店单据');
}
echo "STORE_TRANSFER_UNRECEIVED_QUERY_CONTRACT_OK\n";

<?php
declare(strict_types=1);

/**
 * R38 本地事务回滚测试：验证原本没有销售人的补交单可首次分配，
 * 所有新增业绩、事件和操作记录在断言后统一回滚。
 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3SupplementSalespersonAdjustmentServices;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

$fixture = (array)Db::name('cashier_v3_debt_repayment')->alias('r')
    ->where('r.status', 'succeeded')
    ->whereExists(function ($query): void {
        $query->table('eb_cashier_v3_performance_fact')->alias('actual')
            ->whereRaw('actual.tenant_id=r.tenant_id AND actual.order_id=r.repayment_id')
            ->where('actual.source_document_type', 'debt_repayment')
            ->where('actual.performance_type', 'actual_performance_recorded')
            ->where('actual.fact_direction', 'forward')->where('actual.status', 'effective');
    })
    ->whereNotExists(function ($query): void {
        $query->table('eb_cashier_v3_performance_fact')->alias('sales')
            ->whereRaw('sales.tenant_id=r.tenant_id AND sales.order_id=r.repayment_id')
            ->where('sales.source_document_type', 'debt_repayment')
            ->where('sales.performance_type', 'sales_performance_allocated')
            ->where('sales.fact_direction', 'forward')->where('sales.status', 'effective');
    })->order('r.id', 'desc')->find();
if (!$fixture) {
    echo "SKIP no supplement without salesperson fixture\n";
    exit(0);
}

$storeId = (int)$fixture['store_id'];
$tenantId = (string)$fixture['tenant_id'];
$organizationId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
$operatorId = (int)Db::name('system_store_staff')->where('store_id', $storeId)
    ->where('status', 1)->where('is_del', 0)->order('id', 'asc')->value('id');
if ($operatorId <= 0) throw new RuntimeException('active operator fixture missing');

$operator = new CashierV3OperatorScope($storeId, $operatorId, $organizationId, $tenantId);
$scope = new CashierV3DataScopeContext(
    $operatorId, 1, $storeId, $tenantId, $organizationId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r38-test',
    ['cashier.v3.order.staff_adjust', 'cashier.v3.order_center'], ['id' => $operatorId, 'name' => 'R38回滚测试']
);
$service = new CashierV3SupplementSalespersonAdjustmentServices();
$entry = $service->entry(['recordId' => (string)$fixture['repayment_id']], $operator, $scope);
$candidate = (array)($entry['salespeople'][0] ?? []);
if ((int)($candidate['staffId'] ?? 0) <= 0) throw new RuntimeException('eligible salesperson fixture missing');

$key = 'r38-first-supplement-salesperson-' . bin2hex(random_bytes(8));
$recorder = new CashierV3BusinessEventRecorder();
$before = (int)Db::name('cashier_v3_performance_fact')->where('order_id', $fixture['repayment_id'])->count();
Db::startTrans();
try {
    $result = $service->executeInTx([
        'operator_scope' => $operator,
        'data_scope' => $scope,
        'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution('adjust-supplement-personnel', $key, $operator, $scope, 'r38-test'),
        'event_contract' => CashierV3ActionManifest::eventContractFor('adjust-supplement-personnel'),
        'idempotency_key' => $key,
        'payload' => [
            'recordId' => (string)$fixture['repayment_id'],
            'recordVersion' => (int)$entry['recordVersion'],
            'personnel' => [[
                'staffId' => (int)$candidate['staffId'],
                'allocationWeight' => 100,
                'isPreSale' => false,
                'performanceAmountManual' => false,
            ]],
            'reason' => 'R38首次分配销售人回滚测试',
        ],
    ]);
    $created = (int)Db::name('cashier_v3_performance_fact')
        ->where('order_id', $fixture['repayment_id'])
        ->where('performance_type', 'sales_performance_allocated')
        ->where('fact_direction', 'forward')->where('status', 'effective')->count();
    if (($result['message'] ?? '') !== '补交销售人已更新。' || $created !== 1) {
        throw new RuntimeException('first supplement salesperson allocation did not produce one effective fact');
    }
    echo "PASS first supplement salesperson allocation uses actual-performance template\n";
} finally {
    Db::rollback();
}

$after = (int)Db::name('cashier_v3_performance_fact')->where('order_id', $fixture['repayment_id'])->count();
if ($after !== $before) throw new RuntimeException('rollback did not restore supplement performance facts');
echo "PASS all test writes rolled back\n";

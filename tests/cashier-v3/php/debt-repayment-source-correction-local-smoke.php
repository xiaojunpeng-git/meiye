<?php
declare(strict_types=1);

/**
 * 本地真实库回滚冒烟：历史空来源补交更正必须同时落到主单、事实和详情审计。
 * 不保留业务写入；用户最终选择来源时再通过正式页面保存。
 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3OrderAttributionCorrectionServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
$tenant = '0';
$repaymentId = 'DRP-8cce8400d73cfd2d5075f3b105d370d6842b5dad';
$before = (array)Db::name('cashier_v3_debt_repayment')->where('repayment_id', $repaymentId)->find();
if (!$before || (string)$before['status'] !== 'succeeded') throw new RuntimeException('补交样例不存在');
$storeId = (int)$before['store_id'];
$staff = (array)Db::name('system_store_staff')->where('store_id', $storeId)->where('status', 1)->find();
$orgId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
$source = (array)Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)
    ->where('require_secondary', 0)->where('id', '<>', (int)$before['business_source_primary_id'])->find();
if (!$staff || $orgId === '' || !$source) throw new RuntimeException('缺少本地测试权限或来源目录');
$operator = new CashierV3OperatorScope($storeId, (int)$staff['id'], $orgId, $tenant);
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], $storeId, $tenant,
    $orgId, [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false, '',
    'local-repayment-source-smoke', ['cashier.v3.order_center'], $staff);
$oldLabel = trim((string)$before['business_source_secondary_name_snapshot'])
    ?: (trim((string)$before['business_source_primary_name_snapshot'])
        ?: trim((string)$before['business_source_label_snapshot']));
$key = 'R22-REPAYMENT-SOURCE-ROLLBACK-' . bin2hex(random_bytes(8));
$service = new CashierV3OrderAttributionCorrectionServices();
$recorder = new CashierV3BusinessEventRecorder();
$action = 'correct-debt-repayment-source';
if (!CashierV3Bootstrap::dispatcher()->handlers()->hasCommand($action)
    || !CashierV3Bootstrap::dispatcher()->policies()->has($action)) {
    throw new RuntimeException('补交来源更正命令未在正式网关注册');
}
// 充值补交与销售补交共用订单中心入口，两个权威 ID 前缀都必须可定位。
$identity = new ReflectionMethod($service, 'identity');
if (PHP_VERSION_ID < 80100) $identity->setAccessible(true);
if ($identity->invoke($service, ['recordType' => 'supplement',
    'recordId' => 'v3-supplement:RRP-' . str_repeat('a', 40)]) !== ['supplement', 'RRP-' . str_repeat('a', 40)]) {
    throw new RuntimeException('充值补交来源更正标识未被接受');
}
Db::startTrans();
try {
    $execution = $recorder->newExecution($action, $key, $operator, $scope, 'R22-local-source-smoke');
    $result = $service->executeInTx([
        'operator_scope' => $operator, 'data_scope' => $scope,
        'event_recorder' => $recorder, 'event_execution' => $execution,
        'event_contract' => CashierV3ActionManifest::eventContractFor($action),
        'action' => $action, 'idempotency_key' => $key,
        'payload' => [
            'recordType' => 'supplement', 'recordId' => $repaymentId, 'field' => 'source',
            'expectedValue' => $oldLabel, 'expectedPrimarySourceId' => (int)$before['business_source_primary_id'],
            'expectedSecondarySourceId' => (int)$before['business_source_secondary_id'],
            'primarySourceId' => (int)$source['id'], 'secondarySourceId' => 0,
        ],
    ]);
    $after = (array)Db::name('cashier_v3_debt_repayment')->where('repayment_id', $repaymentId)->find();
    if ((int)$after['business_source_primary_id'] !== (int)$source['id']) {
        throw new RuntimeException('补交主单来源未更正');
    }
    foreach (['cashier_v3_payment_fact', 'cashier_v3_balance_fact', 'cashier_v3_performance_fact'] as $table) {
        if (Db::name($table)->where('order_id', $repaymentId)
            ->where('business_source_primary_id', '<>', (int)$source['id'])->count()) {
            throw new RuntimeException($table . ' 来源与补交主单不一致');
        }
    }
    $history = $service->history(['recordType' => 'supplement', 'recordId' => $repaymentId], $operator, $scope);
    if (!array_filter($history['changes'], static function (array $change) use ($result): bool {
        return $change['field'] === 'source' && $change['recordId'] === $result['recordId'];
    })) throw new RuntimeException('订单详情读不到来源变更');
    Db::rollback();
} catch (Throwable $error) {
    Db::rollback();
    throw $error;
}
$retained = (array)Db::name('cashier_v3_debt_repayment')->where('repayment_id', $repaymentId)->find();
if ((int)$retained['business_source_primary_id'] !== (int)$before['business_source_primary_id']) {
    throw new RuntimeException('回滚后本地补交记录被改动');
}
echo "REPAYMENT_SOURCE_CORRECTION_ROLLBACK_OK\n";

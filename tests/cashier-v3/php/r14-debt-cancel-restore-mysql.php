<?php
/** R14 本地事务回滚测试：取消、恢复、再取消的事实与待还金额同进同退。 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3DebtCancellationServices;
use app\services\cashier\v3\order\CashierV3DebtRestorationServices;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->initialize();
function checkR14(bool $ok, string $message): void
{
    if (!$ok) throw new \RuntimeException($message);
    echo "PASS $message\n";
}

$debt = (array)Db::name('store_debt')->where('status', 0)->where('total_debt', '>', 0)
    ->whereNotIn('id', Db::name(CashierV3DebtCancellationServices::TABLE)->column('debt_id'))
    ->order('id desc')->find();
if (!$debt) throw new \RuntimeException('本地未找到可回滚测试的未取消欠款');
$store = (int)$debt['store_id'];
$id = (int)$debt['id'];
$org = (string)Db::name('organization_store')->where('store_id', $store)->value('org_id');
$operator = new CashierV3OperatorScope($store, 1, $org, '0');
$scope = new CashierV3DataScopeContext(1, 1, $store, '0', $org, [$store],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', '1',
    ['cashier.v3.order.void', 'cashier.v3.order_center'], ['id' => 1, 'name' => 'R14回滚测试']);
$recorder = new CashierV3BusinessEventRecorder();
$cancel = new CashierV3DebtCancellationServices();
$restore = new CashierV3DebtRestorationServices();
$prefix = 'r14-' . bin2hex(random_bytes(8));
$counts = [];
foreach (['cashier_v3_payment_fact', 'cashier_v3_performance_fact', 'cashier_v3_balance_fact'] as $table) {
    $counts[$table] = Db::name($table)->count();
}

function commandR14(string $action, string $key, array $payload, $operator, $scope, $recorder): array
{
    return ['operator_scope' => $operator, 'data_scope' => $scope,
        'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution($action, $key, $operator, $scope, 'r14-test'),
        'event_contract' => CashierV3ActionManifest::eventContractFor($action),
        'idempotency_key' => $key, 'payload' => $payload];
}

Db::startTrans();
try {
    // 测试只改事务内的目标金额，不触碰来源订单或真实权益。
    Db::name('store_debt')->where('id', $id)->update(['total_debt' => '450.00', 'repaid_debt' => '0.00']);
    $first = commandR14('cancel-order-center-debt', $prefix . '-cancel1',
        ['debtId' => $id, 'remainingAmount' => '450.00', 'reason' => 'R14首次取消'], $operator, $scope, $recorder);
    $cancel->executeInTx($first);
    $firstAudit = (array)Db::name(CashierV3DebtCancellationServices::TABLE)
        ->where('debt_id', $id)->order('id desc')->find();
    checkR14((int)Db::name('store_debt')->where('id', $id)->value('status') === 3
        && (int)$firstAudit['cancelled_debt_cents'] === 45000, '取消450元并保留不可变审计');
    checkR14(CashierV3CardRuleEntitlementAuthorityServices::sharedTimesAfterCancellation(10, 10, 100000, 45000) === 5,
        '1000元10次取消450元，可用5次且原始10次未写改');
    $debtAfterCancel = (new CashierV3MemberDebtProjectionServices())->amountForMember((int)$debt['uid']);
    $restoration = commandR14('restore-order-center-debt', $prefix . '-restore1',
        ['debtId' => $id, 'cancellationId' => $firstAudit['cancellation_id']], $operator, $scope, $recorder);
    $restore->executeInTx($restoration);
    checkR14((int)Db::name('store_debt')->where('id', $id)->value('status') === 0
        && (int)Db::name(CashierV3DebtRestorationServices::TABLE)
            ->where('cancellation_id', $firstAudit['cancellation_id'])->value('restored_debt_cents') === 45000,
        '恢复原待还450元并记独立反向事实');
    checkR14(bcsub((new CashierV3MemberDebtProjectionServices())->amountForMember((int)$debt['uid']),
        $debtAfterCancel, 2) === '450.00', '恢复后会员待还金额回升450元');
    $restoredList = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'debt', 'keyword' => $debt['debt_no'], 'dataScope' => 'all',
    ], $operator, $scope);
    $restoredRows = array_values(array_filter($restoredList['records'], static function (array $row) use ($id): bool {
        return $row['debtId'] === $id;
    }));
    checkR14(count($restoredRows) === 1 && $restoredRows[0]['canCancel']
        && !$restoredRows[0]['canRestore'] && $restoredRows[0]['remainingAmount'] === '450.00',
        '恢复后列表切回取消按钮，金额仍可补交');
    checkR14($restore->executeInTx($restoration)['data']['debtRestoration']['replayed'], '同一恢复请求幂等');
    $stale = commandR14('restore-order-center-debt', $prefix . '-stale',
        ['debtId' => $id, 'cancellationId' => $firstAudit['cancellation_id']], $operator, $scope, $recorder);
    $rejected = false;
    try { $restore->executeInTx($stale); } catch (CashierV3CommandException $e) { $rejected = true; }
    checkR14($rejected, '不同请求不得重复恢复');
    $second = commandR14('cancel-order-center-debt', $prefix . '-cancel2',
        ['debtId' => $id, 'remainingAmount' => '450.00', 'reason' => 'R14再次取消'], $operator, $scope, $recorder);
    $cancel->executeInTx($second);
    $secondAudit = (array)Db::name(CashierV3DebtCancellationServices::TABLE)
        ->where('debt_id', $id)->order('id desc')->find();
    checkR14((string)$secondAudit['cancellation_id'] !== (string)$firstAudit['cancellation_id']
        && (int)Db::name(CashierV3DebtCancellationServices::TABLE)->where('debt_id', $id)->count() === 2,
        '再取消追加第二轮事实，不覆盖第一轮');
    $history = $restore->history(['debtId' => $id], $operator, $scope)['changes'];
    checkR14(array_column($history, 'type') === ['cancel', 'restore', 'cancel']
        && $history[0]['reason'] === 'R14再次取消', '详情按轮次倒序展示取消与恢复');
    $query = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'debt', 'keyword' => $debt['debt_no'], 'dataScope' => 'all',
    ], $operator, $scope);
    $rows = array_values(array_filter($query['records'], static function (array $row) use ($id): bool {
        return $row['debtId'] === $id;
    }));
    checkR14(count($rows) === 1 && $rows[0]['canRestore'] && !$rows[0]['canCancel']
        && $rows[0]['cancellationId'] === $secondAudit['cancellation_id'],
        '多轮审计不重复列表行，按钮指向当前取消轮次');
    $other = new CashierV3OperatorScope($store + 9999, 1, $org, '0');
    $rejected = false;
    try { $restore->executeInTx(commandR14('restore-order-center-debt', $prefix . '-foreign',
        ['debtId' => $id, 'cancellationId' => $secondAudit['cancellation_id']], $other, $scope, $recorder)); }
    catch (CashierV3CommandException $e) { $rejected = true; }
    checkR14($rejected, '跨门店恢复被拒绝');
    foreach ($counts as $table => $count) {
        checkR14(Db::name($table)->count() === $count, '没有伪造收款/业绩/余额：' . $table);
    }
} finally {
    Db::rollback();
}
checkR14((array)Db::name('store_debt')->where('id', $id)->find() === $debt
    && (int)Db::name(CashierV3DebtCancellationServices::TABLE)->where('debt_id', $id)->count() === 0,
    '测试业务写入全部回滚');
echo "R14_DEBT_CANCEL_RESTORE=PASS\n";

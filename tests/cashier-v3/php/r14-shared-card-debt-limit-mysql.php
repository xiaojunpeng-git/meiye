<?php
/** R14 真实共享卡回滚测试：债务终态改变，不得释放未实收部分的整卡次数。 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices as Rules;
use app\services\cashier\v3\card\CashierV3CardUpgradeDebtPolicy;
use app\services\cashier\v3\card\CashierV3CardOperationAuthorityServices;
use app\services\cashier\v3\card\CashierV3CardOperationKernel;
use app\services\cashier\v3\card\CashierV3MultiCardUpgradeSettlementServices;
use app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use app\services\cashier\v3\order\CashierV3DebtCancellationServices;
use app\services\cashier\v3\order\CashierV3DebtRestorationServices;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->initialize();
function checkR14Shared(bool $ok, string $label): void
{
    if (!$ok) throw new \RuntimeException($label);
    echo "PASS $label\n";
}

$resolver = new CashierV3CardOriginDebtResolver();
$fixture = null;
foreach (Db::name('cashier_v3_card_rule_state')->where('rule_type', 'choice_count')->select()->toArray() as $state) {
    $holder = (array)Db::name('user_card_holder')->where('id', $state['card_holder_id'])->find();
    $order = (array)Db::name('store_order')->where('id', $holder['oid'] ?? 0)->find();
    if (!$order) continue;
    $pending = $resolver->pendingV3CardDebt($order, (int)$order['uid']);
    $line = (array)Db::name('cashier_v3_sales_order_line')
        ->where('order_id', $state['sales_order_id'])
        ->where('order_line_id', $state['sales_order_line_id'])->find();
    $authority = (array)Db::name('cashier_v3_debt_authority')->alias('a')
        ->join('store_debt d', 'd.id=a.debt_id')
        ->where('a.sales_order_id', $state['sales_order_id'])
        ->field('d.*')->find();
    if (!$authority || (int)$authority['status'] !== 0 || !$line || $pending === null) continue;
    $pendingCents = (int)bcmul($pending, '100', 0);
    if ($pendingCents > 0 && $pendingCents < (int)$line['sale_amount_cents']) {
        $component = (array)Db::name('cashier_v3_card_rule_component')
            ->where('rule_state_id', $state['id'])->order('id asc')->find();
        $detail = (array)Db::name('store_order_cart_info')->where('id', $component['legacy_detail_id'] ?? 0)->find();
        if ($detail) { $fixture = [$state, $holder, $order, $authority, $line, $detail, $pending]; break; }
    }
}
if (!$fixture) throw new \RuntimeException('local partial-debt choice-count fixture missing');
[$state, $holder, $order, $debt, $line, $detail, $pending] = $fixture;
$rules = new Rules();
$upgradeDebtPolicy = new CashierV3CardUpgradeDebtPolicy();
$cardOperations = new CashierV3CardOperationAuthorityServices();
$lockedCardSource = new \ReflectionMethod(CashierV3CardOperationAuthorityServices::class, 'loadSourceCardForUpdate');
$lockedCardSource->setAccessible(true);
$physicalCardValue = new \ReflectionMethod(CashierV3CardOperationAuthorityServices::class, 'remainingValueCents');
$physicalCardValue->setAccessible(true);
$multiCardUpgrade = new CashierV3MultiCardUpgradeSettlementServices();
$lockedMultiCardSource = new \ReflectionMethod(CashierV3MultiCardUpgradeSettlementServices::class, 'lockSource');
$lockedMultiCardSource->setAccessible(true);
$lockedProjectRights = new \ReflectionMethod(CashierV3CardOperationAuthorityServices::class, 'loadSelectedProjectRightsForUpdate');
$lockedProjectRights->setAccessible(true);
$expected = Rules::sharedTimesAfterCancellation((int)$state['shared_total_times'],
    (int)$state['shared_remaining_times'], (int)$line['sale_amount_cents'], (int)bcmul($pending, '100', 0));
checkR14Shared($expected < (int)$state['shared_remaining_times'] && $expected > 0,
    '真实部分欠款会限制共享卡次数');
$org = (string)Db::name('organization_store')->where('store_id', $debt['store_id'])->value('org_id');
$operator = new CashierV3OperatorScope((int)$debt['store_id'], 1, $org, (string)$state['tenant_id']);
$scope = new CashierV3DataScopeContext(1, 1, (int)$debt['store_id'], (string)$state['tenant_id'],
    $org, [(int)$debt['store_id']], CashierV3DataScopeContext::MODE_STORES, [], false, '', '1',
    ['cashier.v3.order.void', 'cashier.v3.order_center'], ['id' => 1, 'name' => 'R14回滚测试']);
$recorder = new CashierV3BusinessEventRecorder();
$prefix = 'r14-shared-' . bin2hex(random_bytes(8));
$command = static function (string $action, string $key, array $payload) use ($operator, $scope, $recorder): array {
    return ['operator_scope' => $operator, 'data_scope' => $scope, 'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution($action, $key, $operator, $scope, 'r14-test'),
        'event_contract' => CashierV3ActionManifest::eventContractFor($action),
        'idempotency_key' => $key, 'payload' => $payload];
};
$original = (array)Db::name('store_debt')->where('id', $debt['id'])->find();
Db::startTrans();
try {
    $upgradeBlocked = false;
    try {
        $upgradeDebtPolicy->cancelledCentsAfterPendingGuard($order, (string)$state['tenant_id']);
    } catch (CashierV3CommandException $error) {
        $upgradeBlocked = strpos($error->getMessage(), '先补交原卡的欠款或者取消原卡的欠款') !== false;
    }
    checkR14Shared($upgradeBlocked, '原卡待还欠款时升级提示先补交或取消');
    $singleUpgradeBlocked = false;
    try {
        $lockedCardSource->invoke($cardOperations, (int)$holder['id'], $operator,
            CashierV3CardOperationKernel::TYPE_CARD_UPGRADE, []);
    } catch (CashierV3CommandException $error) {
        $singleUpgradeBlocked = strpos($error->getMessage(), '先补交原卡的欠款或者取消原卡的欠款') !== false;
    }
    checkR14Shared($singleUpgradeBlocked, '单卡升级最终来源读取拦截待还欠款');
    $projectUpgradeBlocked = false;
    try {
        $lockedCardSource->invoke($cardOperations, (int)$holder['id'], $operator,
            CashierV3CardOperationKernel::TYPE_PROJECT_UPGRADE,
            ['projectLines' => [['sourceDetailId' => (int)$detail['id'], 'quantity' => 1]]]);
    } catch (CashierV3CommandException $error) {
        $projectUpgradeBlocked = strpos($error->getMessage(), '先补交原卡的欠款或者取消原卡的欠款') !== false;
    }
    checkR14Shared($projectUpgradeBlocked, '项目升级最终来源读取拦截待还欠款');
    $multiUpgradeBlocked = false;
    try {
        $lockedMultiCardSource->invoke($multiCardUpgrade, (int)$holder['id'],
            ['member_id' => (int)$holder['uid']], $operator, $scope);
    } catch (CashierV3CommandException $error) {
        $multiUpgradeBlocked = strpos($error->getMessage(), '先补交原卡的欠款或者取消原卡的欠款') !== false;
    }
    checkR14Shared($multiUpgradeBlocked, '多卡升级逐张原卡拦截待还欠款');
    checkR14Shared($rules->debtLimitForDetail($detail, $order) === $expected,
        '待还时零金额子项目也受整卡次数限制');
    $component = (array)Db::name('cashier_v3_card_rule_component')
        ->where('legacy_detail_id', $detail['id'])->find();
    $overLimitRejected = false;
    try {
        $rules->applyDeductionsInTx([[
            'holder_id' => (int)$holder['id'], 'source_detail_id' => (int)$detail['id'],
            'project_id' => (int)$component['project_product_id'],
            'deduct_physical_times' => $expected + 1,
            'expected_physical_remaining_times' => (int)$state['shared_remaining_times'],
        ]], ['tenant_id' => $state['tenant_id'], 'store_id' => $holder['store_id'],
            'member_id' => $holder['uid'], 'occurred_at' => time()]);
    } catch (CashierV3CommandException $error) {
        $overLimitRejected = strpos(json_encode($error->getDetail()), 'card_rule_shared_times_insufficient') !== false;
    }
    checkR14Shared($overLimitRejected, '最终锁定核销拒绝超过待还额度的次数');
    (new CashierV3DebtCancellationServices())->executeInTx($command('cancel-order-center-debt',
        $prefix . '-cancel', ['debtId' => (int)$debt['id'], 'remainingAmount' => $pending,
            'reason' => 'R14共享卡回滚测试']));
    checkR14Shared($rules->debtLimitForDetail($detail, $order) === $expected,
        '取消后可用次数不增，物理余次不变');
    $projectRights = $lockedProjectRights->invoke($cardOperations, (int)$order['id'], $order,
        CashierV3CardOperationKernel::TYPE_PROJECT_REPLACEMENT,
        ['projectLines' => [['sourceDetailId' => (int)$detail['id'], 'quantity' => 1]]], $operator);
    checkR14Shared(count($projectRights) === 1
        && (int)$projectRights[0]['debtLimitedRemainingTimes'] === $expected
        && (int)$projectRights[0]['sharedDebtLimitedRemainingTimes'] === $expected,
        '项目操作来源带入整卡债务限制后的可用次数');
    $entitlementSources = app()->make(CashierV3EntitlementProjectionServices::class)
        ->reservationSources((int)$holder['uid'], $operator, $scope);
    $projectedCard = null;
    foreach ($entitlementSources as $source) {
        if ((int)($source['id'] ?? 0) === (int)$holder['id']) { $projectedCard = $source; break; }
    }
    checkR14Shared($projectedCard !== null
        && (int)$projectedCard['debtLimitedRemainingTimes'] === $expected
        && (int)$projectedCard['remainingTimes'] === (int)$state['shared_remaining_times'],
        '使用权益及预约共用投影展示受限余次且保留物理余次');
    $cancelledCents = $upgradeDebtPolicy->cancelledCentsAfterPendingGuard($order, (string)$state['tenant_id']);
    checkR14Shared($cancelledCents === (int)bcmul($pending, '100', 0),
        '取消原卡欠款后允许升级但识别不可抵扣金额');
    $upgradeSource = $lockedCardSource->invoke($cardOperations, (int)$holder['id'], $operator,
        CashierV3CardOperationKernel::TYPE_CARD_UPGRADE, []);
    $physicalValue = $physicalCardValue->invoke($cardOperations, (int)$order['id']);
    checkR14Shared((int)$upgradeSource['remainingValueCents'] === max(0, $physicalValue - $cancelledCents),
        '单卡升级最终来源金额扣除已取消欠款');
    checkR14Shared((int)bcmul((string)$projectedCard['remainingAmount'], '100', 0)
        === (int)$upgradeSource['remainingValueCents'],
        '权益选择器升级预览金额与最终单卡抵扣完全一致');
    $multiSource = $lockedMultiCardSource->invoke($multiCardUpgrade, (int)$holder['id'],
        ['member_id' => (int)$holder['uid']], $operator, $scope);
    checkR14Shared((int)$multiSource['remainingValueCents'] === max(0, $physicalValue - $cancelledCents),
        '多卡升级最终来源金额扣除已取消欠款');
    checkR14Shared((int)$multiSource['remainingValueCents']
        === (int)bcmul((string)$projectedCard['remainingAmount'], '100', 0),
        '权益选择器预览金额与最终多卡抵扣完全一致');
    checkR14Shared(CashierV3CardUpgradeDebtPolicy::creditAfterCancellation(
        (int)$line['sale_amount_cents'], $cancelledCents
    ) === (int)$line['sale_amount_cents'] - $cancelledCents,
        '已取消金额不会进入新卡抵扣');
    $audit = (array)Db::name(CashierV3DebtCancellationServices::TABLE)
        ->where('debt_id', $debt['id'])->order('id desc')->find();
    (new CashierV3DebtRestorationServices())->executeInTx($command('restore-order-center-debt',
        $prefix . '-restore', ['debtId' => (int)$debt['id'], 'cancellationId' => $audit['cancellation_id']]));
    checkR14Shared($rules->debtLimitForDetail($detail, $order) === $expected,
        '恢复但未还款时整卡上限仍相同');
    $restoredUpgradeBlocked = false;
    try {
        $upgradeDebtPolicy->cancelledCentsAfterPendingGuard($order, (string)$state['tenant_id']);
    } catch (CashierV3CommandException $error) {
        $restoredUpgradeBlocked = strpos($error->getMessage(), '先补交原卡的欠款或者取消原卡的欠款') !== false;
    }
    checkR14Shared($restoredUpgradeBlocked, '恢复未补交欠款后再次阻止升级');
    $transferredMemberId = (int)$holder['uid'] + 100000000;
    Db::name('user_card_holder')->where('id', (int)$holder['id'])->update(['uid' => $transferredMemberId]);
    Db::name('cashier_v3_card_state')->where('card_holder_id', (int)$holder['id'])
        ->update(['current_member_id' => $transferredMemberId]);
    $memberCards = new \ReflectionMethod(CashierV3MemberDetailQueryServices::class, 'cardsForMembers');
    $memberCards->setAccessible(true);
    $transferredCards = $memberCards->invoke(new CashierV3MemberDetailQueryServices(),
        [$transferredMemberId], (string)$state['tenant_id'], '', 'active', '', '');
    $transferredCard = null;
    foreach ($transferredCards[$transferredMemberId] ?? [] as $card) {
        if ((int)($card['id'] ?? 0) === (int)$holder['id']) { $transferredCard = $card; break; }
    }
    checkR14Shared($transferredCard !== null
        && (int)$transferredCard['debtLimitedRemainingTimes'] === $expected
        && (string)$transferredCard['cardRuleType'] === 'choice_count',
        '整卡转让后新持卡人仍可见原卡及原订单债务限制');
    checkR14Shared((int)Db::name('cashier_v3_card_rule_state')->where('id', $state['id'])
        ->value('shared_remaining_times') === (int)$state['shared_remaining_times'],
        '取消与恢复均不改物理次数');
} finally {
    Db::rollback();
}
checkR14Shared((array)Db::name('store_debt')->where('id', $debt['id'])->find() === $original,
    '共享卡测试业务写入全部回滚');
echo "R14_SHARED_CARD_DEBT_LIMIT=PASS\n";

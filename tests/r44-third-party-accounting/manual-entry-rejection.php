<?php
declare(strict_types=1);
/**
 * 本地人工入口防伪造回归：调用真实准备、锁定提交及充值服务，不触发支付供应商。
 * 唯一临时写入是测试事务中的会员准备草稿，结束完整回滚；三个被拒请求均须零业务写入。
 * 反射仅取得正式容器已装配的依赖和生成有效商品快照，不替换校验、仓储或事实写入器。
 */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

use think\facade\Db;
use app\services\member\MemberCommerceScope;
use app\services\member\MemberCommerceServices;
use app\services\member\MemberCommerceSettingsServices;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\member\CashierV3RechargeModule;
use app\services\cashier\v3\settlement\CashierV3SaleOnlyCheckoutSubmissionServices;

if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Local mysql/ruihao only');
}
$checks = 0;
$uid = 913081;
$store = 118;
$prefix = 'R44-REJECT-' . bin2hex(random_bytes(8));
$requestId = '';

function check(bool $valid, string $label): void
{
    global $checks;
    if (!$valid) throw new RuntimeException($label);
    ++$checks;
    echo "PASS $label\n";
}

/** A validation failure for a different reason must never count as actor rejection. */
function rejectExactly(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (CashierV3CommandException $e) {
        check(($e->getDetail()['reason'] ?? '') === 'third_party_accounting_requires_member_order',
            $label . ' rejects specifically third_party actor: ' . ($e->getDetail()['reason'] ?? 'missing'));
        return;
    }
    throw new RuntimeException($label . ' unexpectedly accepted manual third_party');
}

/** Same tenant/store/permission identity isolates actor validation from scope/ownership errors. */
function manualScope(CashierV3DataScopeContext $scope): CashierV3DataScopeContext
{
    return new CashierV3DataScopeContext($scope->operatorId(), $scope->employeeId(),
        $scope->forcedStoreId(), $scope->tenantId(), $scope->organizationId(),
        $scope->visibleStoreIds(), $scope->authorizationMode(), $scope->employeeDataScope(),
        $scope->isSuperAdmin(), $scope->superAdminBasis(), $scope->permissionVersion(),
        $scope->grantedFeatures(), ['actor_type' => 'cashier', 'staff_name' => '本地防伪造测试']);
}

/** Real financial/rights tables and the locked member row are observed before each rejected call. */
function financialState(int $uid): array
{
    $tables = Db::query("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"
        . " AND (TABLE_NAME LIKE 'eb_cashier_v3_%fact' OR TABLE_NAME IN ("
        . "'eb_cashier_v3_sales_order','eb_cashier_v3_sales_order_line','eb_cashier_v3_payment_collection',"
        . "'eb_cashier_v3_business_event','eb_user_recharge','eb_user_bill','eb_member_card','eb_member_card_item'))"
        . ' ORDER BY TABLE_NAME');
    $result = [];
    foreach ($tables as $table) {
        $name = (string)$table['name'];
        if (!preg_match('/^eb_[a-z0-9_]+$/D', $name)) throw new RuntimeException('Unexpected table name');
        $result[$name] = (int)Db::table($name)->count();
    }
    $result['member_balance'] = Db::name('user')->where('uid', $uid)
        ->field('now_money,ben_money,give_money,balance_version')->find();
    return $result;
}

Db::startTrans();
try {
    $member = MemberCommerceScope::create($uid, $store);
    Db::name('user')->where('uid', $uid)->lock(true)->find();
    $manual = manualScope($member['data']);
    check($manual->operatorProfile()['actor_type'] === 'cashier', 'manual actor is server cashier scope');
    $initial = financialState($uid);
    check(count($initial) >= 10, 'observe real financial facts and balance tables');

    $handler = CashierV3Bootstrap::dispatcher()->handlers()->requireCommand('submit-checkout');
    $dependencies = (new ReflectionFunction($handler))->getStaticVariables();
    $preparation = $dependencies['checkoutPreparation'];
    $submissionPreparation = $dependencies['submissionPreparation'];
    $workspaceProperty = new ReflectionProperty($preparation, 'workspace');
    $workspaceProperty->setAccessible(true);
    $submission = new CashierV3SaleOnlyCheckoutSubmissionServices($workspaceProperty->getValue($preparation));

    $build = new ReflectionMethod(MemberCommerceServices::class, 'buildInTx');
    $build->setAccessible(true);
    $built = $build->invoke(new MemberCommerceServices(), $uid,
        ['store_id' => $store, 'items' => [['product_id' => 625093, 'quantity' => 1]], 'salespeople' => []],
        $member, false);
    check((int)$built['amountCents'] > 0, 'real product produces positive whole-yuan payment');
    $lines = $built['lines'];
    $lines[] = ['lineRole' => 'payment', 'paymentMethod' => 'third_party',
        'amount' => (string)intdiv((int)$built['amountCents'], 100), 'externalTransactionNo' => $prefix];
    $key = MemberCommerceServices::checkoutKey($prefix);
    $stateId = 'R44-' . substr(hash('sha256', $prefix), 0, 32);
    $recorder = new CashierV3BusinessEventRecorder();
    $scope = ['action' => 'submit-checkout', 'idempotency_key' => $key,
        'state_context_id' => $stateId, 'operator_scope' => $member['operator'], 'data_scope' => $manual,
        'contexts' => [], 'locked_versions' => [], 'direct_snapshot_submission' => true,
        'server_resource_discovery' => ['contractVersion' => 'cashier-v3-server-resource-discovery-v1',
            'resources' => [], 'fingerprint' => hash('sha256', '[]')],
        'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution('submit-checkout', $key, $member['operator'], $manual, $stateId),
        'event_contract' => CashierV3ActionManifest::eventContractFor('submit-checkout'),
        'payload' => ['checkoutSnapshot' => ['memberId' => $uid, 'businessDate' => date('Y-m-d'),
            'occurredAt' => time(), 'source' => (new MemberCommerceSettingsServices())->source('purchase'),
            'lines' => $lines, 'orderNote' => $prefix]]];

    $draftCount = Db::name('cashier_v3_checkout_request')->count();
    rejectExactly(static function () use ($preparation, $scope): void { $preparation->prepareInTx($scope); }, 'snapshot preparation');
    check(financialState($uid) === $initial, 'rejected preparation writes no facts, collections, rights or balance');
    check(Db::name('cashier_v3_checkout_request')->count() === $draftCount, 'rejected preparation creates no checkout draft');

    // Construct a genuine persisted/locked draft, then change only actor capability.
    // This intentionally simulates a caller skipping preparation; no successful member settlement is repeated.
    $memberDraftScope = $scope;
    $memberDraftScope['data_scope'] = $member['data'];
    $prepared = $preparation->prepareInTx($memberDraftScope);
    $requestId = (string)$prepared['checkoutRequestId'];
    $promoteScope = $memberDraftScope;
    $promoteScope['action'] = 'finalize-checkout-snapshot';
    $promoteScope['idempotency_key'] = preg_replace('/^CHECKOUT-/D', 'CHECKOUT_PREPARE-', $key);
    $promoteScope['payload'] = ['checkoutRequestId' => $requestId,
        'checkoutRequestVersion' => (int)$prepared['checkoutRequestVersion'],
        'preparationRequestId' => $promoteScope['idempotency_key']];
    $promoted = $submissionPreparation->prepareInTx($promoteScope);
    $submitScope = $scope;
    $submitScope['payload'] = ['checkoutRequestId' => $requestId,
        'checkoutRequestVersion' => (int)$promoted['checkoutRequestVersion'],
        'preparationRequestId' => $promoteScope['idempotency_key']];
    $submitScope['checkout_resource_plan'] = ['requestId' => $requestId,
        'boundRequestVersion' => (int)$promoted['checkoutRequestVersion'], 'tenantId' => $manual->tenantId(),
        'storeId' => $store, 'resourcePlanFingerprint' => $promoted['resourcePlanFingerprint'], 'resources' => []];
    check(financialState($uid) === $initial, 'legitimate draft fixture has no settled business effects');
    $draftBefore = Db::name('cashier_v3_checkout_request')->where('request_id', $requestId)->find();
    rejectExactly(static function () use ($submission, $submitScope): void { $submission->submitInTx($submitScope); }, 'locked final submission');
    check(financialState($uid) === $initial, 'rejected locked submit writes no facts, collections, rights or balance');
    check(Db::name('cashier_v3_checkout_request')->where('request_id', $requestId)->find() === $draftBefore,
        'rejected locked submit does not consume or alter prepared request');

    $rechargeScope = ['action' => 'submit-recharge', 'idempotency_key' => $key,
        'operator_scope' => $member['operator'], 'data_scope' => $manual,
        'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution('submit-recharge', $key, $member['operator'], $manual, $stateId),
        'event_contract' => CashierV3ActionManifest::eventContractFor('submit-recharge'),
        'business_source' => (new MemberCommerceSettingsServices())->source('recharge'),
        'payload' => ['memberId' => $uid, 'rechargeMode' => 'custom', 'rechargePackageId' => 0,
            'principalAmount' => '100', 'bonusAmount' => '0',
            'balanceVersion' => (int)Db::name('user')->where('uid', $uid)->value('balance_version'),
            'businessDate' => date('Y-m-d'), 'rechargeRemark' => $prefix,
            'paymentMethod' => 'third_party', 'collectionReference' => $prefix]];
    rejectExactly(static function () use ($rechargeScope): void { (new CashierV3RechargeModule())->submitInTx($rechargeScope); }, 'manual recharge');
    check(financialState($uid) === $initial, 'rejected recharge writes no recharge, facts, events or balance');
} catch (Throwable $e) {
    echo 'FAIL ' . get_class($e) . ' ' . $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine()
        . ' ' . (method_exists($e, 'getDetail') ? json_encode($e->getDetail()) : '') . "\n";
    $failed = true;
} finally {
    Db::rollback();
}
if (!empty($failed)) exit(1);
check($requestId !== '' && !Db::name('cashier_v3_checkout_request')->where('request_id', $requestId)->find(),
    'sole prepared fixture removed by transaction rollback');
check(financialState($uid) === $initial, 'post-rollback balance and all observed financial tables unchanged');
echo "R44_MANUAL_ENTRY_REJECTION_CHECKS=$checks\n";

<?php
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\order\CashierV3RechargeGiftReversalServices;
use think\facade\Config;
use think\facade\Db;

$backend = '/var/www/html';
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.hostname' => getenv('DB_HOST') ?: 'mysql', 'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql',
    'database.hostport' => getenv('DB_PORT') ?: '3306', 'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801', 'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801',
    'database.username' => getenv('DB_USERNAME') ?: 'root', 'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123', 'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'recharge_gift_reversal_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');
$rechargeId = (int)(getenv('RECHARGE_GIFT_TEST_RECHARGE_ID') ?: 97);
$authority = (array)Db::name('cashier_v3_recharge_gift_authority')->where('recharge_id', $rechargeId)->find();
if (!$authority) throw new RuntimeException('RECHARGE_GIFT_TEST_AUTHORITY_MISSING');
$items = Db::name('cashier_v3_recharge_gift_item')->where('gift_id', (string)$authority['gift_id'])->order('item_no', 'asc')->select()->toArray();
$project = null; $coupon = null;
foreach ($items as $item) {
    if ((string)$item['gift_kind'] === 'project') $project = $item;
    if ((string)$item['gift_kind'] === 'coupon') $coupon = $item;
}
if (!$project || !$coupon) throw new RuntimeException('RECHARGE_GIFT_TEST_FIXTURE_INCOMPLETE');
$couponIds = json_decode((string)$coupon['coupon_user_ids_json'], true);
if (!is_array($couponIds) || count($couponIds) !== 1) throw new RuntimeException('RECHARGE_GIFT_TEST_COUPON_FIXTURE_INVALID');
$couponUserId = (int)$couponIds[0];
$couponIssueId = (int)$coupon['coupon_issue_id'];
$source = [
    'rechargeId' => $rechargeId,
    'orderNo' => (string)$authority['recharge_order_no_snapshot'],
    'memberId' => (int)$authority['member_id'],
    'storeId' => (int)$authority['store_id'],
];
$organizationId = (string)Db::name('organization_store')->where('store_id', (int)$source['storeId'])->value('org_id');
if ($organizationId === '' || $organizationId === '0') throw new RuntimeException('RECHARGE_GIFT_TEST_ORGANIZATION_MISSING');
$operator = new CashierV3OperatorScope((int)$source['storeId'], 1, $organizationId, (string)$authority['tenant_id']);
$scope = new CashierV3DataScopeContext(
    1, 1, (int)$source['storeId'], (string)$authority['tenant_id'], $organizationId,
    null, CashierV3DataScopeContext::MODE_ALL, [], true, 'integration', '1', [],
    ['id' => 1, 'staff_name' => 'integration operator']
);
$service = new CashierV3RechargeGiftReversalServices();
$passed = 0; $failed = 0;
$ok = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) { $passed++; echo "PASS {$name}\n"; return; }
    $failed++; echo "FAIL {$name}\n";
};

Db::startTrans();
try {
    $beforeAuthority = (array)Db::name('cashier_v3_recharge_gift_authority')->where('id', (int)$authority['id'])->find();
    $beforeCoupon = (array)Db::name('store_coupon_user')->where('id', $couponUserId)->find();
    $prepared = $service->prepare($source, 'refund-recharge-order', $scope);
    $ok('refund does not prepare gift revocation', empty($prepared['authority']) && empty($prepared['items']));
    $ok('refund leaves gift projections unchanged',
        (array)Db::name('cashier_v3_recharge_gift_authority')->where('id', (int)$authority['id'])->find() === $beforeAuthority
        && (array)Db::name('store_coupon_user')->where('id', $couponUserId)->find() === $beforeCoupon);
    Db::rollback();
} catch (Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}

Db::startTrans();
try {
    // Recharge 97 predates the exact mapping migration. This fixture exists
    // only inside the rollback transaction; production history is not backfilled.
    $claimId = (int)Db::name('store_coupon_issue_user')->insertGetId([
        'uid' => (int)$source['memberId'], 'issue_coupon_id' => $couponIssueId, 'add_time' => time(),
    ]);
    Db::name('cashier_v3_recharge_gift_coupon_issue_mapping')->insert([
        'mapping_id' => 'RGCM-INTEGRATION-GIFT-VOID', 'tenant_id' => (string)$authority['tenant_id'],
        'store_id' => (int)$source['storeId'], 'member_id' => (int)$source['memberId'],
        'recharge_id' => $rechargeId, 'gift_id' => (string)$authority['gift_id'],
        'gift_item_id' => (string)$coupon['item_id'], 'item_sequence' => 1,
        'coupon_issue_id' => $couponIssueId, 'coupon_user_id' => $couponUserId,
        'coupon_issue_user_id' => $claimId, 'status' => 'issued', 'void_operation_id' => '',
        'voided_at' => 0, 'created_at' => time(), 'updated_at' => time(),
    ]);
    $issueId = $couponIssueId;
    Db::name('store_coupon_issue')->where('id', $issueId)->update(['total_count' => 100, 'remain_count' => 50]);
    $prepared = $service->prepare($source, 'void-recharge-order', $scope);
    $ok('void proves every project and coupon unused', (int)$prepared['revokedItemCount'] === 2);
    $operationId = 'RLO-INTEGRATION-GIFT-VOID';
    $commandKey = 'integration-recharge-gift-void';
    $recorder = new CashierV3BusinessEventRecorder();
    $execution = $recorder->newExecution('void-recharge-order', $commandKey, $operator, $scope, 'integration-gift-void');
    $contract = [
        'required_event_types' => ['recharge.voided'], 'allowed_event_types' => ['recharge.voided', 'gift.voided'],
        'event_rules' => [
            'recharge.voided' => ['min_count' => 1, 'max_count' => 1, 'aggregate_type' => 'recharge_order', 'source_type' => 'void-recharge-order'],
            'gift.voided' => ['min_count' => 0, 'max_count' => 100, 'aggregate_type' => 'recharge_gift', 'source_type' => 'void-recharge-order'],
        ],
        'eventless_reason' => '', 'activation_blocked_until_event_contract' => false,
        'consumers' => ['recharge.voided' => [], 'gift.voided' => []],
    ];
    $service->apply($source, 'void-recharge-order', $prepared, $operationId, $commandKey,
        $recorder, $execution, $contract, $operator, $scope, time(), 'integration rollback');
    $ok('void marks authority and every item voided',
        (string)Db::name('cashier_v3_recharge_gift_authority')->where('id', (int)$authority['id'])->value('status') === 'voided'
        && (int)Db::name('cashier_v3_recharge_gift_item')->where('gift_id', (string)$authority['gift_id'])->where('status', 'voided')->count() === 2);
    $ok('void disables project compatibility projection',
        (int)Db::name('user_card_holder')->where('id', (int)$project['card_holder_id'])->value('is_del') === 1
        && (int)Db::name('store_order')->where('id', (int)$project['legacy_order_id'])->value('terminal_action') === 2);
    $revokedCoupon = (array)Db::name('store_coupon_user')->where('id', $couponUserId)->find();
    $ok('void revokes unused coupon and restores finite inventory once',
        (int)$revokedCoupon['status'] === 2 && (int)$revokedCoupon['is_fail'] === 1
        && (int)Db::name('store_coupon_issue')->where('id', $issueId)->value('remain_count') === 51);
    $ok('void appends exact gift fact reversals and dedicated audits',
        (int)Db::name('cashier_v3_gift_fact')->where('command_idempotency_key', $commandKey)->where('status', 'voided')->count() === 2
        && (int)Db::name('cashier_v3_recharge_gift_reversal')->where('operation_id', $operationId)->count() === 2
        && (int)Db::name('cashier_v3_business_event')->where('command_idempotency_key', $commandKey)->where('event_type', 'gift.voided')->count() === 2);
    $ok('void closes exact coupon claim mapping without deleting claim history',
        (string)Db::name('cashier_v3_recharge_gift_coupon_issue_mapping')->where('coupon_issue_user_id', $claimId)->value('status') === 'voided'
        && (int)Db::name('store_coupon_issue_user')->where('id', $claimId)->count() === 1);
    Db::rollback();
} catch (Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}

$rejectWithoutWrites = static function (string $name, callable $mutate, string $expectedReason) use ($service, $source, $scope, $authority, &$ok): void {
    Db::startTrans();
    try {
        $mutate();
        $rejected = false;
        try {
            $service->prepare($source, 'void-recharge-order', $scope);
        } catch (CashierV3CommandException $exception) {
            $rejected = (string)($exception->getDetail()['reason'] ?? '') === $expectedReason;
        }
        $zeroWrites = (string)Db::name('cashier_v3_recharge_gift_authority')->where('id', (int)$authority['id'])->value('status') === 'issued'
            && (int)Db::name('cashier_v3_recharge_gift_reversal')->where('recharge_id', (int)$source['rechargeId'])->count() === 0;
        $ok($name, $rejected && $zeroWrites);
        Db::rollback();
    } catch (Throwable $throwable) {
        Db::rollback();
        throw $throwable;
    }
};

$rejectWithoutWrites('used coupon rejects whole void with zero lifecycle writes', static function () use (
    $couponUserId, $couponIssueId, $coupon, $authority, $source, $rechargeId
): void {
    $claimId = (int)Db::name('store_coupon_issue_user')->insertGetId([
        'uid' => (int)$source['memberId'], 'issue_coupon_id' => $couponIssueId, 'add_time' => time(),
    ]);
    Db::name('cashier_v3_recharge_gift_coupon_issue_mapping')->insert([
        'mapping_id' => 'RGCM-INTEGRATION-GIFT-USED', 'tenant_id' => (string)$authority['tenant_id'],
        'store_id' => (int)$source['storeId'], 'member_id' => (int)$source['memberId'],
        'recharge_id' => $rechargeId, 'gift_id' => (string)$authority['gift_id'],
        'gift_item_id' => (string)$coupon['item_id'], 'item_sequence' => 1,
        'coupon_issue_id' => $couponIssueId, 'coupon_user_id' => $couponUserId,
        'coupon_issue_user_id' => $claimId, 'status' => 'issued', 'void_operation_id' => '',
        'voided_at' => 0, 'created_at' => time(), 'updated_at' => time(),
    ]);
    Db::name('store_coupon_user')->where('id', $couponUserId)->update(['use_time' => time(), 'status' => 1, 'oid' => 123]);
}, 'recharge_gift_coupon_already_changed');
$rejectWithoutWrites('split project rejects whole void with zero lifecycle writes', static function () use ($project): void {
    Db::name('store_order_cart_info')->where('id', (int)$project['benefit_detail_id'])->update(['split_status' => 1]);
}, 'recharge_gift_project_already_changed');

$ok('all integration mutations rolled back',
    (string)Db::name('cashier_v3_recharge_gift_authority')->where('id', (int)$authority['id'])->value('status') === 'issued'
    && (int)Db::name('user_card_holder')->where('id', (int)$project['card_holder_id'])->value('is_del') === 0
    && (int)Db::name('store_coupon_user')->where('id', $couponUserId)->value('status') === 0);
echo "RECHARGE_GIFT_REVERSAL_MYSQL passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

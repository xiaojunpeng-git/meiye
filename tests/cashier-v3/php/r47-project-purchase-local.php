<?php
declare(strict_types=1);

/** Store/member project fulfillment through the real gateway. All synthetic data rolls back. */
$backend = getenv('BACKEND_ROOT') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\card\CashierV3OrdinaryCardSaleAdjustment as Rule;
use app\services\cashier\v3\hang\CashierV3HangCheckoutBindingServices;
use app\services\cashier\v3\order\CashierV3OrderLifecycleServices;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;

$app = new think\App($backend . '/'); $app->env->load($backend . '/.env'); $app->initialize();
// Isolated release tests use a process-only enabled switch input, without
// changing the PM's local setting or depending on cross-process config caches.
if (getenv('R49_LOCAL_ONLY') === '1' && getenv('R49_CONFIG_FIXTURE') === 'enabled') {
    app()->instance('sysConfig', new class(app('sysConfig')) {
        private $delegate;
        public function __construct($delegate) { $this->delegate = $delegate; }
        public function get($name) { return $name === 'cashier_ordinary_card_sale_edit_switch' ? '1' : $this->delegate->get($name); }
        public function __call($name, $args) { return $this->delegate->$name(...$args); }
    });
}
set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', get_class($e) . ': ' . $e->getMessage()
        . ($e instanceof CashierV3CommandException ? json_encode($e->getDetail()) : '')
        . "\n" . $e->getTraceAsString()); exit(1);
});
$config = Db::connect()->getConfig();
if (getenv('R49_LOCAL_ONLY') !== '1' || $config['hostname'] !== 'mysql' || $config['database'] !== 'ruihao') {
    throw new RuntimeException('Explicit local rollback environment required');
}
date_default_timezone_set('Asia/Shanghai');
$tag = 'R47-P-' . bin2hex(random_bytes(7));
$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $passed++; echo 'PASS ' . $label . "\n";
};
$key = static function (): string {
    $u = bin2hex(random_bytes(16));
    return 'CHECKOUT-' . substr($u, 0, 8) . '-' . substr($u, 8, 4) . '-4' . substr($u, 13, 3)
        . '-8' . substr($u, 17, 3) . '-' . substr($u, 20, 12);
};
$before = (int)Db::name('cashier_v3_sales_order')->count();
Db::startTrans();
try {
    $store = 23;
    $organization = (string)Db::name('organization_store')->where('store_id', $store)->value('org_id');
    $employee = (int)Db::name('employee')->insertGetId(['name' => $tag, 'status' => 1, 'is_del' => 0,
        'employment_type_code' => 'internal', 'employment_type_version' => 1]);
    $staffId = (int)Db::name('system_store_staff')->insertGetId(['store_id' => $store, 'employee_id' => $employee,
        'staff_name' => $tag, 'account' => $tag, 'level' => 0, 'status' => 1, 'is_del' => 0]);
    $position = (int)Db::name('position')->insertGetId(['name' => $tag, 'status' => 1, 'use_store' => 1]);
    Db::name('staff_job_position')->insert(['employee_id' => $employee, 'staff_id' => $staffId,
        'store_id' => $store, 'position_id' => $position, 'request_token' => $tag, 'status' => 1]);
    Db::name('job_position_channel_rule')->insert(['position_id' => $position,
        'channel' => 'store_v3', 'rules' => '301010,301012,301006,301032', 'status' => 1]);
    $staff = (array)Db::name('system_store_staff')->where('id', $staffId)->find();
    $operator = new CashierV3OperatorScope($store, $staffId, $organization, '0');
    $scope = CashierV3Bootstrap::dispatcher()->dataScopeFactory()->build($store, $staffId, $staff, '0', $organization);
    $members = [];
    foreach (['A', 'B'] as $suffix) {
        $id = (int)Db::name('user')->insertGetId(['account' => $tag . $suffix, 'nickname' => $tag . $suffix,
            'real_name' => $tag . $suffix, 'belong_store_id' => $store, 'status' => 1, 'is_del' => 0]);
        Db::name('store_user')->insert(['uid' => $id, 'store_id' => $store, 'status' => 1]);
        $members[] = $id;
    }
    $r47AutoComplete = true;
    app()->instance('sysConfig', new class(app('sysConfig'), $r47AutoComplete) {
        private $delegate; private $mode;
        public function __construct($delegate, &$mode) { $this->delegate = $delegate; $this->mode =& $mode; }
        public function get($name) { return $name === 'cashier_project_auto_complete_switch' ? ($this->mode ? '1' : '0') : $this->delegate->get($name); }
        public function __call($name, $args) { return $this->delegate->$name(...$args); }
    });
    $catalog = new CashierV3SaleCatalogServices();
    $items = $catalog->catalog($operator, $scope)['items'];
    $project = null;
    foreach ($items as $item) {
        if (($item['kindCode'] ?? '') === 'project' && (float)($item['price'] ?? 0) > 0) { $project = $item; break; }
    }
    if (!$project) throw new RuntimeException('Local project fixture unavailable');
    $price = (int)round((float)$project['price'] * 100);
    $sourceConfig = new \app\services\cashier\v3\config\CashierV3BusinessConfigServices();
    $sourceSnapshot = null;
    foreach ($sourceConfig->existingSources() as $source) {
        if ((int)($source['status'] ?? 0) !== 1) continue;
        try {
            $sourceConfig->assertCheckoutSourceAllowed((int)$source['id']);
            $child = (int)($source['require_secondary'] ?? 0) === 1
                ? (int)($source['children'][0]['id'] ?? 0) : 0;
            $sourceSnapshot = $sourceConfig->resolveSourceSnapshot((int)$source['id'], $child);
            break;
        } catch (\Throwable $e) { /* Try another existing eligible test source. */ }
    }
    if ($sourceSnapshot === null) throw new RuntimeException('Local checkout source fixture unavailable');
    $sessionKey = $key();
    $session = ['client_session_id' => str_replace('CHECKOUT-', 'SESSION-', $sessionKey),
        'state_context_id' => '', 'operator_ip' => '127.0.0.1'];
    $handler = CashierV3Bootstrap::dispatcher()->handlers()->requireCommand('submit-checkout');
    $submit = static function (string $requestKey, array $input, ?callable $executor = null) use (&$session, $operator, $scope, $handler): array {
        $result = CashierV3Bootstrap::gateway()->execute('submit-checkout',
            ['idempotencyKey' => $requestKey, 'contexts' => []], ['checkoutSnapshot' => $input],
            $operator, $session, $executor ?? $handler, $scope, CashierV3ActionManifest::requireAction('submit-checkout'));
        if (($result['status'] ?? '') !== 'success') throw new RuntimeException(json_encode($result));
        $session['state_context_id'] = $result['state_context_id'];
        return $result;
    };

    $make = static function (int $member, bool $auto, int $quantity = 1) use ($sourceSnapshot, $project, $price): array {
        return ['memberId' => $member, 'projectAutoComplete' => $auto, 'source' => $sourceSnapshot,
            'occurredAt' => time(), 'businessDate' => date('Y-m-d'), 'lines' => [[
                'lineId' => 'bought-project', 'lineRole' => 'sale', 'kindCode' => 'project',
                'itemId' => (int)$project['id'], 'quantity' => $quantity,
                'lineAmountCents' => $price * $quantity, 'originalLineAmountCents' => $price * $quantity,
                'serviceObject' => 'self', 'craftsmen' => [],
            ], ['lineId' => 'cash', 'lineRole' => 'payment', 'quantity' => 1, 'method' => 'cash', 'amount' => (string)($price * $quantity / 100)]]];
    };
    $orderFor = static function (string $id): array { return (array)Db::name('cashier_v3_sales_order')->where('command_idempotency_key', $id)->find(); };
    $serviceCount = static function (array $order): int { return (int)Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id', $order['checkout_request_id'])->count(); };
    $rightFor = static function (array $order): array {
        $line = Db::name('cashier_v3_sales_order_line')->where('order_id', $order['order_id'])->where('item_type', 'project')->find();
        $oid = (int)Db::name('store_order')->where('order_id', 'v3p' . substr(hash('sha256', $line['order_line_id']), 0, 28))->value('id');
        return ['holder' => (array)Db::name('user_card_holder')->where('oid', $oid)->find(),
            'detail' => (array)Db::name('store_order_cart_info')->where('oid', $oid)->find()];
    };
    $immediateKey = $key(); $submit($immediateKey, $make($members[0], true)); $immediate = $orderFor($immediateKey);
    $check($serviceCount($immediate) === 1 && !$rightFor($immediate)['holder'], 'default-on buys and completes without issuing duplicate rights');
    $r47AutoComplete = false;
    $deferredKey = $key(); $snapshot = $make($members[0], false, 2);
    $submit($deferredKey, $snapshot); $deferred = $orderFor($deferredKey); $right = $rightFor($deferred);
    $check((int)$deferred['project_auto_complete'] === 0 && $serviceCount($deferred) === 0, 'disabled switch freezes deferred mode and produces no service');
    $check((int)$right['detail']['write_surplus_times'] >= 2 && (int)$right['holder']['uid'] === $members[0], 'purchased project rights belong to selected member');
    $check((int)Db::name('cashier_v3_payment_fact')->where('order_id', $deferred['order_id'])->sum('amount_cents') === $price * 2, 'deferred purchase still collects exactly the sale amount');
    $check(Db::name('cashier_v3_performance_fact')->where('order_id', $deferred['order_id'])->where('performance_type', 'labor_performance')->count() === 0, 'deferred purchase produces no labor performance');
    $holderCount = Db::name('user_card_holder')->where('uid', $members[0])->count();
    $submit($deferredKey, $snapshot);
    $check(Db::name('user_card_holder')->where('uid', $members[0])->count() === $holderCount, 'same checkout replay issues no duplicate project rights');
    $guestDenied = false;
    try { $submit($key(), $make(0, false)); } catch (CashierV3CommandException $e) { $guestDenied = $e->getResultCode() === 'CASHIER_PROJECT_MEMBER_REQUIRED'; }
    $check($guestDenied, 'guest cannot buy ownerless deferred project rights');
    $tamperDenied = false;
    try { $submit($key(), $make($members[0], true)); } catch (CashierV3CommandException $e) { $tamperDenied = $e->getResultCode() === 'CASHIER_PROJECT_MODE_CHANGED'; }
    $check($tamperDenied, 'browser cannot override disabled authoritative switch');
    $faultKey = $key(); $count = Db::name('user_card_holder')->where('uid', $members[0])->count();
    try {
        $submit($faultKey, $make($members[0], false), static function (array $execution) use ($handler): array {
            $handler($execution); throw new RuntimeException('R47 injected late failure');
        }); throw new RuntimeException('Expected late failure');
    } catch (RuntimeException $e) { if ($e->getMessage() !== 'R47 injected late failure') throw $e; }
    $check(!$orderFor($faultKey) && Db::name('user_card_holder')->where('uid', $members[0])->count() === $count, 'late failure rolls back sale, collection and new rights together');
    // A store redemption uses the same issued holder/detail; no second payment is made.
    $use = ['source' => $sourceSnapshot, 'memberId' => $members[0], 'occurredAt' => time(), 'businessDate' => date('Y-m-d'), 'lines' => [[
        'lineId' => 'use-project', 'lineRole' => 'entitlement_service', 'quantity' => 1,
        'entitlementInstanceType' => 'card_holder', 'entitlementInstanceId' => (int)$right['holder']['id'],
        'entitlementSourceDetailId' => (int)$right['detail']['id'], 'projectId' => (int)$right['detail']['product_id'],
        'actualAmount' => (string)(int)floor(($price * 2 / 100) / (int)$right['detail']['write_times']),
        'serviceObject' => '本人', 'friendCountsAsCustomer' => true, 'name' => $project['name'],
        'entitlementSourceName' => $project['name'], 'fullCardNo' => $right['holder']['card_no'],
        'craftsmen' => [['staffId' => $staffId, 'id' => $staffId, 'name' => $tag, 'isMain' => true]],
    ]]];
    $useKey = $key(); $submit($useKey, $use);
    $check((int)Db::name('store_order_cart_info')->where('id', $right['detail']['id'])->value('write_surplus_times') === (int)$right['detail']['write_surplus_times'] - 1, 'deferred purchased project can be redeemed normally');
    $check(Db::name('cashier_v3_payment_fact')->where('command_idempotency_key', $useKey)->count() === 0, 'later redemption never duplicates original collection');
    $denyRecorder = new CashierV3BusinessEventRecorder(); $denyKey = $key(); $usedVoidDenied = false;
    try {
        (new CashierV3OrderLifecycleServices())->executeInTx('void-sales-order', [
            'operator_scope' => $operator, 'data_scope' => $scope, 'state_context_id' => $session['state_context_id'],
            'event_recorder' => $denyRecorder,
            'event_execution' => $denyRecorder->newExecution('void-sales-order', $denyKey, $operator, $scope, $tag),
            'event_contract' => CashierV3ActionManifest::eventContractFor('void-sales-order'), 'idempotency_key' => $denyKey,
            'payload' => ['orderId' => $deferred['order_id'], 'reason' => 'R47 used rights guard']]);
    } catch (CashierV3CommandException $e) { $usedVoidDenied = true; }
    $check($usedVoidDenied && Db::name('cashier_v3_sales_order')->where('order_id', $deferred['order_id'])->value('order_status') === 'settled',
        'used purchased rights retain existing void protection without changing the sale');
    // Mixed checkout must complete the old right but leave the new purchase unused.
    $mixed = $make($members[0], false); $mixed['lines'][] = $use['lines'][0]; $mixed['lines'][2]['lineId'] = 'use-old-in-mixed';
    $mixedKey = $key(); $submit($mixedKey, $mixed); $mixedOrder = $orderFor($mixedKey);
    $check($serviceCount($mixedOrder) === 1 && $rightFor($mixedOrder)['holder'], 'mixed checkout completes old right and issues new unused right');
    // A later enabled switch must not reinterpret an already purchased deferred order.
    $r47AutoComplete = true;
    $recorder = new CashierV3BusinessEventRecorder(); $lifecycle = new CashierV3OrderLifecycleServices();
    $voidKey = $key(); $lifecycle->executeInTx('void-sales-order', [
        'operator_scope' => $operator, 'data_scope' => $scope, 'state_context_id' => $session['state_context_id'],
        'event_recorder' => $recorder, 'event_execution' => $recorder->newExecution('void-sales-order', $voidKey, $operator, $scope, $tag),
        'event_contract' => CashierV3ActionManifest::eventContractFor('void-sales-order'), 'idempotency_key' => $voidKey,
        'payload' => ['orderId' => $mixedOrder['order_id'], 'reason' => 'R47 rollback test']]);
    $mixedRight = $rightFor($mixedOrder);
    $check((int)$mixedRight['holder']['is_del'] === 1
        && (int)Db::name('cashier_v3_payment_fact')->where('order_id', $mixedOrder['order_id'])->sum('amount_cents') === 0,
        'whole-order void retracts unused deferred rights and reverses collection after switch changes');
    // Authenticated member self-service must remain deferred even while the store switch is on.
    $memberScope = \app\services\member\MemberCommerceScope::create($members[1], $store);
    $memberKey = $key(); $memberInput = $make($members[1], true);
    $memberInput['lines'][1]['method'] = 'third_party';
    // Use the exact internal callback boundary used by MemberCommerceServices;
    // member actors are not store employees and must not enter staff authentication.
    $memberState = 'MSC-' . substr(hash('sha256', $memberKey), 0, 32);
    $memberRecorder = new CashierV3BusinessEventRecorder();
    $memberResult = $handler(['action' => 'submit-checkout', 'idempotency_key' => $memberKey,
        'state_context_id' => $memberState, 'operator_scope' => $memberScope['operator'], 'data_scope' => $memberScope['data'],
        'contexts' => [], 'locked_versions' => [], 'direct_snapshot_submission' => true,
        'server_resource_discovery' => ['contractVersion' => 'cashier-v3-server-resource-discovery-v1',
            'resources' => [], 'fingerprint' => hash('sha256', '[]')],
        'event_recorder' => $memberRecorder,
        'event_execution' => $memberRecorder->newExecution('submit-checkout', $memberKey, $memberScope['operator'], $memberScope['data'], $memberState),
        'event_contract' => CashierV3ActionManifest::eventContractFor('submit-checkout'),
        'payload' => ['checkoutSnapshot' => $memberInput]]);
    $memberOrder = $orderFor($memberKey);
    $check(!empty($memberResult['data']['checkoutSubmission']) && $memberOrder['source_document_type'] === 'member_checkout'
        && $serviceCount($memberOrder) === 0 && $rightFor($memberOrder)['holder'],
        'member self-service stays deferred independently of store switch');
    echo 'R47_PROJECT_PURCHASE passed=' . $passed . "\n";
} finally { Db::rollback(); }
$check((int)Db::name('cashier_v3_sales_order')->count() === $before, 'all synthetic business writes rolled back');

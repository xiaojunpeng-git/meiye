<?php
declare(strict_types=1);

/** Real gateway cross-checkouts, local fixtures only, all business writes roll back. */
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
$tag = 'R49-X-' . bin2hex(random_bytes(7));
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
    $items = (new CashierV3SaleCatalogServices())->catalog($operator, $scope)['items'];
    $item = null;
    foreach ($items as $candidate) {
        if (($candidate['cardRuleType'] ?? '') === 'normal' && $candidate['name'] === '仪器放松') {
            $item = $candidate; break;
        }
    }
    if (!$item) throw new RuntimeException('Ordinary card local fixture unavailable');
    $purchase = $item['cardPurchaseSnapshot'];
    $base = $purchase['ordinarySaleBaseline']['definition']['purchase'];
    $purchase['components'][0]['configuredAmountCents'] =
        ($purchase['ordinarySaleBaseline']['definition']['componentSaleAmountsCents'][0]
            ?? $base['components'][0]['configuredPriceCents'] * $base['components'][0]['writeTimes']) + 200;
    $purchase['components'][0]['writeTimes'] += 2;
    $price = Rule::minimumPrice($purchase, Rule::validate($purchase, [], $store, (int)$item['id']));
    // A complete final snapshot includes its confirmed source. Reuse the real
    // local directory rather than omitting this unrelated settlement prerequisite.
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
    $makeSnapshot = static function (int $member, array $card, int $amount) use ($item, $sourceSnapshot): array {
        return ['memberId' => $member, 'source' => $sourceSnapshot, 'occurredAt' => time(), 'businessDate' => date('Y-m-d'), 'lines' => [[
            'lineId' => 'ordinary-card', 'lineRole' => 'sale', 'itemId' => (int)$item['id'], 'quantity' => 1,
            'lineAmountCents' => $amount, 'originalLineAmountCents' => $amount,
            'cardPurchaseSnapshot' => $card,
        ], ['lineId' => 'cash', 'lineRole' => 'payment', 'quantity' => 1, 'method' => 'cash', 'amount' => (string)($amount / 100)]]];
    };
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
    $firstKey = $key();
    $firstSnapshot = $makeSnapshot($members[0], $purchase, $price);
    $first = $submit($firstKey, $firstSnapshot);
    $check(Db::name('cashier_v3_sales_order')->where('command_idempotency_key', $firstKey)->count() === 1,
        'production gateway writes the modified ordinary card sale');
    $check((int)Db::name('cashier_v3_payment_fact')->where('command_idempotency_key', $firstKey)->sum('amount_cents') === $price,
        'production payment facts equal the displayed card amount');
    $replay = $submit($firstKey, $firstSnapshot);
    $check(!empty($replay['replay']) && Db::name('user_card_holder')->where('uid', $members[0])->count() === 1,
        'same checkout key does not duplicate collection or issuance');
    $replayGuard = new ReflectionMethod(CashierV3Bootstrap::gateway(), 'snapshotCheckoutReplayScopeFailure');
    $replayGuard->setAccessible(true);
    $check($replayGuard->invoke(CashierV3Bootstrap::gateway(), ['idempotency_key' => 'missing-request'], $operator, $scope) !== '',
        'empty-context replay cannot fabricate a missing committed checkout');
    $wrongOperator = new CashierV3OperatorScope($store, $staffId + 1, $organization, '0');
    $check($replayGuard->invoke(CashierV3Bootstrap::gateway(), ['idempotency_key' => $firstKey], $wrongOperator, $scope) !== '',
        'checkout replay rejects a different operator identity');
    $workspace = new CashierV3CashierWorkspaceServices(new CashierV3CashierReadinessGuard());
    $ws = CashierV3CheckoutWorkspaceIdentity::id($store, $session['state_context_id']);
    $sample = (array)Db::name('cashier_v3_hang_order')->where('hang_mode', 'local_draft')->find();
    $hang = $sample; unset($hang['id']);
    foreach (['hang_order_id' => 'HGO' . sha1($tag), 'hang_order_no' => $tag, 'natural_key' => $tag,
        'command_idempotency_key' => $tag, 'preparation_request_id' => $tag, 'preparation_token' => hash('sha256', $tag),
        'tenant_id' => '0', 'store_id' => $store, 'operator_id' => $staffId, 'organization_id' => $organization,
        'member_id' => $members[0], 'hang_status' => 'pending_checkout', 'checkout_request_id' => '',
        'settled_at' => 0, 'line_count' => 1] as $field => $value) $hang[$field] = $value;
    Db::name('cashier_v3_hang_order')->insert($hang);
    $bind = static function () use ($workspace, $ws, &$session, $operator, $hang, $members): void {
        $workspace->restoreLocalHangDraftShellInTx($ws, $session['state_context_id'], $operator, $hang['hang_order_id'], $members[0]);
    };
    $bind();
    $check($workspace->checkoutDraftMetadata($ws, $session['state_context_id'], $operator, $members[1])['resumed_hang_order_id'] === '',
        'switching member never inherits previous member hang');
    $check($workspace->checkoutDraftMetadata($ws, $session['state_context_id'], $operator, 0)['resumed_hang_order_id'] === '',
        'switching to guest never inherits previous member hang');
    $secondKey = $key();
    $submit($secondKey, $makeSnapshot($members[1], $purchase, $price));
    $check(Db::name('cashier_v3_sales_order')->where('command_idempotency_key', $secondKey)->count() === 1
        && Db::name('cashier_v3_hang_order')->where('hang_order_id', $hang['hang_order_id'])->count() === 1,
        'cross-member final checkout succeeds without consuming the other member hang');
    $bind();
    $faultKey = $key();
    try {
        $submit($faultKey, $makeSnapshot($members[0], $purchase, $price), static function (array $execution) use ($handler): array {
            $handler($execution); throw new RuntimeException('R49 injected late failure');
        });
        throw new RuntimeException('Expected rollback');
    } catch (RuntimeException $e) { if ($e->getMessage() !== 'R49 injected late failure') throw $e; }
    $check(Db::name('cashier_v3_sales_order')->where('command_idempotency_key', $faultKey)->count() === 0
        && Db::name('cashier_v3_payment_fact')->where('command_idempotency_key', $faultKey)->count() === 0
        && Db::name('cashier_v3_hang_order')->where('hang_order_id', $hang['hang_order_id'])->count() === 1,
        'late failure rolls back sale payment issuance and source-hang cleanup together');
    $bad = $purchase; $bad['components'][0]['writeTimes'] = $base['components'][0]['writeTimes'] - 1;
    $invalidKey = $key(); $denied = false;
    try { $submit($invalidKey, $makeSnapshot($members[0], $bad, $price)); }
    catch (CashierV3CommandException $e) { $denied = true; }
    $check($denied && Db::name('cashier_v3_sales_order')->where('command_idempotency_key', $invalidKey)->count() === 0,
        'tampered below-minimum times cannot bypass final transaction guard');
    $thirdKey = $key();
    $submit($thirdKey, $makeSnapshot($members[0], $purchase, $price));
    $check(Db::name('cashier_v3_hang_order')->where('hang_order_id', $hang['hang_order_id'])->count() === 0,
        'same-member resumed checkout consumes its own hang exactly once');
    $denied = false;
    try {
        (new CashierV3HangCheckoutBindingServices())->completeFromCheckoutInTx(['request' => [
            'request_id' => 'R49-duplicate', 'member_id' => $members[0], 'resumed_hang_order_id' => $hang['hang_order_id']]],
            'R49-duplicate', $operator, $scope);
    } catch (CashierV3CommandException $e) { $denied = true; }
    $check($denied, 'consumed hang still fails closed against duplicate checkout');
    $successfulKeys = [$firstKey, $secondKey, $thirdKey];
    foreach (['unchanged', 'times-only', 'amount-only', 'quantity-two', 'multi-project', 'card-total-only', 'card-total-and-times'] as $case) {
        $card = $base;
        $card['ordinarySaleBaseline'] = $purchase['ordinarySaleBaseline'];
        $currentItem = $item;
        if (in_array($case, ['multi-project', 'card-total-only', 'card-total-and-times'], true)) {
            foreach ($items as $candidate) {
                if (($candidate['cardRuleType'] ?? '') === 'normal'
                    && count($candidate['cardPurchaseSnapshot']['components'] ?? []) > 1) {
                    $currentItem = $candidate; $card = $candidate['cardPurchaseSnapshot']; break;
                }
            }
            if (count($card['components']) < 2) throw new RuntimeException('Multi-project fixture missing');
        }
        $original = $card['ordinarySaleBaseline']['definition']['purchase']['components'];
        if ($case !== 'unchanged') {
            foreach ($card['components'] as $i => &$component) {
                $component['configuredAmountCents'] = ($card['ordinarySaleBaseline']['definition']['componentSaleAmountsCents'][$i]
                    ?? $original[$i]['configuredPriceCents'] * $original[$i]['writeTimes'])
                    + (in_array($case, ['times-only', 'card-total-only', 'card-total-and-times'], true) ? 0 : 200);
                $component['writeTimes'] += in_array($case, ['amount-only', 'card-total-only'], true) ? 0 : 1;
            }
            unset($component);
        }
        $delta = Rule::validate($card, [], $store, (int)$currentItem['id']);
        $unit = Rule::minimumPrice($card, $delta) ?? $card['ordinarySaleBaseline']['definition']['priceCents'];
        // The new editor changes only one card total; signed row amounts stay
        // weights. Real checkout, payment, allocation and void must reconcile it.
        if (in_array($case, ['card-total-only', 'card-total-and-times'], true)) $unit += 10000;
        $quantity = $case === 'quantity-two' ? 2 : 1;
        $snapshot = $makeSnapshot($members[0], $card, $unit * $quantity);
        $snapshot['lines'][0]['itemId'] = (int)$currentItem['id'];
        $snapshot['lines'][0]['quantity'] = $quantity;
        $caseKey = $key(); $submit($caseKey, $snapshot); $successfulKeys[] = $caseKey;
        $check(Db::name('cashier_v3_card_purchase_receipt')->where('command_idempotency_key', $caseKey)->count() === $quantity
            && (int)Db::name('cashier_v3_payment_fact')->where('command_idempotency_key', $caseKey)->sum('amount_cents') === $unit * $quantity,
            'real final checkout reconciles sale payment and rights: ' . $case);
        if (in_array($case, ['card-total-only', 'card-total-and-times'], true)) {
            $orderId = Db::name('cashier_v3_sales_order')->where('command_idempotency_key', $caseKey)->value('order_id');
            $check((int)Db::name('cashier_v3_card_sale_item_allocation_fact')->where('order_id', $orderId)->where('status', 'effective')->sum('sale_amount_cents') === $unit * $quantity,
                'backend allocation conserves the single edited card total: ' . $case);
            $receipts = Db::name('cashier_v3_card_purchase_receipt')->where('sales_order_id', $orderId)->select()->toArray();
            $legacyIds = array_column($receipts, 'legacy_order_id');
            $orders = Db::name('store_order')->whereIn('id', $legacyIds)->select()->toArray();
            // Match the live entitlement reader: the card's outer sale row is not a project right.
            $details = Db::name('store_order_cart_info')->whereIn('oid', $legacyIds)->where('cart_type', 2)->where('product_type', 6)->select()->toArray();
            $pools = (new \app\services\cashier\v3\card\CashierV3CardSaleActualAmountServices())->wholeYuanForOrders($orders, $details);
            $check(array_sum($pools) === $unit * $quantity && count($pools) === count($details)
                && count(array_filter($pools, static fn($amount) => $amount >= 0 && $amount % 100 === 0)) === count($details),
                'issued entitlement pools conserve the single edited total in whole yuan: ' . $case);
        }
    }
    $recorder = new CashierV3BusinessEventRecorder();
    $lifecycle = new CashierV3OrderLifecycleServices();
    foreach ($successfulKeys as $successfulKey) {
        $order = Db::name('cashier_v3_sales_order')->where('command_idempotency_key', $successfulKey)->find();
        $voidKey = $tag . '-void-' . substr(sha1($successfulKey), 0, 12);
        $action = 'void-sales-order';
        $lifecycle->executeInTx($action, ['operator_scope' => $operator, 'data_scope' => $scope,
            'state_context_id' => $session['state_context_id'], 'event_recorder' => $recorder,
            'event_execution' => $recorder->newExecution($action, $voidKey, $operator, $scope, $tag),
            'event_contract' => CashierV3ActionManifest::eventContractFor($action), 'idempotency_key' => $voidKey,
            'payload' => ['orderId' => $order['order_id'], 'reason' => 'R49 local rollback cross-check']]);
        $holders = Db::name('cashier_v3_card_purchase_receipt')->where('sales_order_id', $order['order_id'])->column('card_holder_id');
        $check(Db::name('cashier_v3_order_lifecycle_operation')->where('command_idempotency_key', $voidKey)
            ->where('status', 'succeeded')->count() === 1
            && Db::name('user_card_holder')->whereIn('id', $holders)->where('is_del', 0)->count() === 0
            && (int)Db::name('cashier_v3_payment_fact')->where('order_id', $order['order_id'])->sum('amount_cents') === 0,
            'full order void reverses payment facts and all issued card rights');
    }
    echo 'R49_CROSS_CHECKOUT passed=' . $passed . "\n";
} finally { Db::rollback(); }
$check((int)Db::name('cashier_v3_sales_order')->count() === $before
    && Db::name('user')->where('account', 'like', $tag . '%')->count() === 0,
    'all synthetic checkout data rolled back; user orders untouched');

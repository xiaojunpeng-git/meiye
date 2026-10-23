<?php
declare(strict_types=1);

// Local-only setup and transactional issuance/void integration. Never touch a customer instance.
$backend = getenv('BACKEND_ROOT') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env'); $app->initialize();
// Per-process test input only; never toggle the PM's real local configuration.
if (getenv('R49_LOCAL_ONLY') === '1' && getenv('R49_CONFIG_FIXTURE') === 'enabled') {
    app()->instance('sysConfig', new class(app('sysConfig')) {
        private $delegate;
        public function __construct($delegate) { $this->delegate = $delegate; }
        public function get($name) { return $name === 'cashier_ordinary_card_sale_edit_switch' ? '1' : $this->delegate->get($name); }
        public function __call($name, $args) { return $this->delegate->$name(...$args); }
    });
}
use think\facade\Db;
use app\services\cashier\v3\card\CashierV3OrdinaryCardSaleAdjustment as Rule;
use app\services\cashier\v3\card\CashierV3CardPurchaseIssuanceServices as Issuer;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices as Catalog;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\cashier\v3\order\CashierV3SalesOrderReversalServices;
set_exception_handler(static function (Throwable $error) {
    file_put_contents('php://stderr', $error->getMessage() . "\n" . $error->getTraceAsString()); exit(1);
});
$connection = Db::connect()->getConfig();
if (getenv('R49_LOCAL_ONLY') !== '1' || ($connection['hostname'] ?? '') !== 'mysql'
    || ($connection['database'] ?? '') !== 'ruihao') throw new RuntimeException('R49 local database guard');
$key = 'cashier_ordinary_card_sale_edit_switch';
if (getenv('R49_SETUP') === 'enable') {
    $before = Db::name('system_config')->where('menu_name', $key)->where('is_store', 0)->value('value');
    Db::execute(file_get_contents($backend . '/database/upgrades/2026-10-22-普通卡销售可修改/01-正式升级.sql'));
    Db::name('system_config')->where('menu_name', $key)->where('is_store', 0)->update(['value' => '1']);
    \mohe\services\SystemConfigService::clear();
    echo json_encode(['localOnly' => true, 'switchBefore' => $before, 'enabled' => Rule::enabled()]) . "\n";
    exit;
}
if (getenv('R49_SETUP') === 'verify-ui-hang') {
    $uid = (int)Db::name('user')->where('account', 'R48-ALLOWANCE-UI')->value('uid');
    $header = Db::name('cashier_v3_hang_order')->where('member_id', $uid)->order('id desc')->find();
    $local = json_decode((string)($header['local_draft_snapshot_json'] ?? ''), true);
    $purchase = $local['lines'][0]['cardPurchaseSnapshot'] ?? [];
    $row = $purchase['components'][0] ?? [];
    if (($row['configuredAmountCents'] ?? null) !== 650000 || ($row['writeTimes'] ?? null) !== 20) {
        throw new RuntimeException('R49 UI hang row snapshot did not preserve total and times');
    }
    $signatureMethod = new ReflectionMethod(Rule::class, 'signature'); $signatureMethod->setAccessible(true);
    $definition = $purchase['ordinarySaleBaseline']['definition'];
    if (!hash_equals($signatureMethod->invoke(null, $definition), $purchase['ordinarySaleBaseline']['signature'])) {
        echo json_encode(['signatureMismatch' => true, 'definitionTypes' => array_map('gettype', $definition),
            'storeMatches' => (int)$definition['storeId'] === (int)$header['store_id']]) . "\n";
    }
    $increase = Rule::validate($purchase, [], (int)$header['store_id'], (int)$definition['skuId']);
    echo json_encode(['localOnly' => true, 'hangId' => $header['hang_order_id'], 'amountCents' => 650000,
        'times' => 20, 'cardPriceCents' => Rule::minimumPrice($purchase, $increase), 'signatureValid' => true]) . "\n";
    exit;
}
if (getenv('R49_SETUP') === 'verify-transport') {
    $fixture = json_decode((string)getenv('R49_TRANSPORT_JSON'), true);
    $purchase = $fixture['purchase'];
    $definition = $purchase['ordinarySaleBaseline']['definition'];
    $increase = Rule::validate($purchase, [], (int)$definition['storeId'], (int)$definition['skuId']);
    Rule::assertSaleAmount($purchase, $increase, 1, (int)$fixture['amountCents'], 0);
    echo "PASS live instance verifies final unique business snapshot without versions\n";
    exit;
}
if (!Rule::enabled()) throw new RuntimeException('Enable the local R49 switch before integration');
$exportTransport = getenv('R49_SETUP') === 'export-transport';
$check = static function (bool $ok, string $label) use ($exportTransport): void {
    if (!$ok) throw new RuntimeException($label);
    if (!$exportTransport) echo 'PASS ' . $label . "\n";
};
// Increasing times must keep the existing whole-yuan allocator, including its final-use tail.
$allocations = [];
for ($used = 0; $used < 7; $used++) {
    $money = \app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator::allocateForSnapshot(
        '500.00', 7, $used, 1, ['amountCalculationVersion' => 'whole-yuan-floor-final-remainder-v1']);
    $allocations[] = (int)bcmul($money, '100', 0);
}
$check($allocations === [7100, 7100, 7100, 7100, 7100, 7100, 7400]
    && array_sum($allocations) === 50000, 'increased times use integer writeoff with conserved final-use remainder');
$store = (int)Db::name('system_store')->where('name', '扬州欧尚店')->value('id');
$operator = new CashierV3OperatorScope($store, 1, '', '0');
$scope = new CashierV3DataScopeContext(1, 1, $store, '0', '', [$store], 'stores', [], false, '', 'r49', [], []);
$catalog = new Catalog();
$items = $catalog->catalog($operator, $scope)['items'];
$target = null;
foreach ($items as $item) {
    $requestedProduct = (int)getenv('R49_PRODUCT_ID');
    if (($item['cardRuleType'] ?? '') === 'normal' && ($requestedProduct > 0
        ? (int)$item['productId'] === $requestedProduct
        : count($item['cardPurchaseSnapshot']['components'] ?? []) >= 2)) {
        $target = $item; break;
    }
}
if (!$target) throw new RuntimeException('Local multi-project ordinary card fixture missing');
$purchase = $target['cardPurchaseSnapshot'];
$base = $purchase['ordinarySaleBaseline']['definition']['purchase'];
$originalTimes = $purchase['components'][0]['writeTimes'];
$originalAmount = $purchase['components'][0]['configuredPriceCents'] * $originalTimes;
$increaseCents = (int)(getenv('R49_INCREASE_CENTS') ?: 20000);
if ($increaseCents <= 0 || $increaseCents % 100 !== 0) throw new RuntimeException('Fixture increase must be whole yuan');
$purchase['components'][0]['writeTimes'] += 2;
$purchase['components'][0]['configuredAmountCents'] = $originalAmount + $increaseCents;
$amount = Rule::minimumPrice($purchase, Rule::validate($purchase, $base, $store, (int)$target['id']));
$check($amount === $purchase['ordinarySaleBaseline']['definition']['priceCents'] + $increaseCents, 'real catalogue row increase adds to independent card price');
if ($exportTransport) {
    echo json_encode(['purchase' => $purchase, 'amountCents' => $amount]);
    exit;
}
$tag = 'R49-' . bin2hex(random_bytes(6)); $now = time();
Db::startTrans();
try {
    $uid = (int)Db::name('user')->insertGetId(['account' => $tag, 'nickname' => $tag, 'real_name' => $tag,
        'status' => 1, 'is_del' => 0, 'belong_store_id' => $store, 'add_time' => $now]);
    $header = ['tenant_id' => '0', 'order_id' => $tag, 'order_no' => $tag, 'checkout_request_id' => $tag,
        'member_id' => $uid, 'store_id' => $store, 'operator_id' => 1, 'business_date' => date('Y-m-d')];
    $template = (array)Db::name('cashier_v3_sales_order_line')->where('item_type', 'card')->where('line_direction', 'forward')->find();
    if (!$template) throw new RuntimeException('Local card sale line fixture missing');
    unset($template['id']);
    $line = array_replace($template, ['tenant_id' => '0', 'order_id' => $tag, 'order_line_id' => $tag . '-line', 'natural_key' => $tag,
        'checkout_line_id' => $tag . '-checkout-line', 'item_id' => $target['productId'], 'catalog_sku_id' => $target['id'],
        'member_id' => $uid, 'store_id' => $store, 'quantity' => 1,
        'name_snapshot' => $target['name'], 'original_amount_cents' => $amount, 'discount_amount_cents' => 0,
        'sale_amount_cents' => $amount, 'coupon_payment_cents' => 0,
        'card_purchase_snapshot_json' => json_encode($purchase, JSON_UNESCAPED_UNICODE)]);
    // Plan DTOs have display-only fields; persist only the real table's template columns.
    Db::name('cashier_v3_sales_order_line')->insert(array_intersect_key($line, $template));
    $plan = (new ReflectionClass(CashierV3SalesOrderPlanV1::class))->newInstanceWithoutConstructor();
    foreach (['header' => $header, 'lines' => [$line]] as $name => $value) {
        $property = new ReflectionProperty($plan, $name); $property->setAccessible(true); $property->setValue($plan, $value);
    }
    $aggregate = ['request' => ['workspace_id' => $tag, 'tenant_id' => '0', 'store_id' => $store,
        'member_id' => $uid, 'request_id' => $tag], 'lines' => [[
        'line_role' => 'sale', 'line_id' => $line['checkout_line_id'], 'authority_key' => 'sale:' . $tag]]];
    $issuer = new Issuer();
    $result = $issuer->issueInTx($aggregate, $plan, ['orderId' => $tag], $tag, $now, $operator, $scope);
    $receipt = $result['receipts'][0];
    $detail = Db::name('store_order_cart_info')->where('id', $receipt['benefitDetailIds'][0])->find();
    $check((int)$detail['write_times'] === $originalTimes + 2, 'formal issuer saves increased times');
    $check((int)bcmul($detail['pay_price'], '100', 0) === $originalAmount + $increaseCents, 'formal issuer saves row total without multiplying again');
    $rule = Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detail['id'])->find();
    $check((int)$rule['remaining_times'] === $originalTimes + 2, 'rule authority matches issued remaining times');
    $check((int)json_decode($rule['component_snapshot_json'], true)['configuredAmountCents'] === $originalAmount + $increaseCents, 'rule authority freezes adjusted total');
    $again = $issuer->issueInTx($aggregate, $plan, ['orderId' => $tag], $tag, $now, $operator, $scope);
    $check($again['replayed'] === true && $again['receipts'][0]['holderId'] === $receipt['holderId'], 'issuance idempotency does not duplicate rights');
    $reversal = new CashierV3SalesOrderReversalServices();
    $source = ['sourceId' => $tag, 'storeId' => $store, 'memberId' => $uid];
    $dbReceipt = (array)Db::name('cashier_v3_card_purchase_receipt')->where('receipt_id', $receipt['receiptId'])->find();
    $lock = new ReflectionMethod($reversal, 'lockUnusedCard'); $lock->setAccessible(true);
    $locked = $lock->invoke($reversal, $dbReceipt, $source, $scope);
    $revoke = new ReflectionMethod($reversal, 'revokeCard'); $revoke->setAccessible(true);
    $revoke->invoke($reversal, $locked, $source, 'void-sales-order', $tag . '-void', $tag, $operator, $scope, $now);
    $check((int)Db::name('user_card_holder')->where('id', $receipt['holderId'])->value('is_del') === 1, 'existing void revokes adjusted card');
    $check(Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detail['id'])->value('status') === 'revoked', 'void revokes increased project rights');
} finally { Db::rollback(); }
$check(!Db::name('user')->where('account', $tag)->find(), 'all synthetic business fixtures rolled back');

<?php
declare(strict_types=1);

// Local-only, rollback-only business test. It never calls Tianque or spends money.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('Local MySQL only');

use app\services\member\MemberCommerceServices;
use think\facade\Db;

/** Verify the one authoritative payment fact rather than relying on the UI label. */
function assertLocal(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$testKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (!$testKey || !openssl_pkey_export($testKey, $privatePem)) throw new RuntimeException('Test key unavailable');
$keyPath = tempnam('/tmp', 'sxf-local-');
if ($keyPath === false) throw new RuntimeException('Temporary key path unavailable');
file_put_contents($keyPath, $privatePem);
chmod($keyPath, 0600);
unset($privatePem);
$app->env->set('SUIXINGPAY_PRIVATE_KEY_FILE', $keyPath);

// Override config only inside this PHP process. The merchant and signing key
// are synthetic; no database config, Redis cache or remote endpoint is changed.
$overrides = [
    'fuyou_pay_status' => '0', 'suixingpay_pay_status' => '1',
    'suixingpay_org_id' => '01234567', 'suixingpay_mno' => '399000000000001',
    'suixingpay_gateway_url' => 'https://openapi.tianquetech.com',
    'suixingpay_platform_public_key' => 'LOCAL_TEST_PUBLIC_KEY',
    'suixingpay_sub_appid' => 'wxce4f61fc9c4eaaa6',
];
app()->instance('sysConfig', new class($overrides) {
    private array $values;
    public function __construct(array $values) { $this->values = $values; }
    public function get(string $name) {
        return array_key_exists($name, $this->values)
            ? $this->values[$name] : \mohe\services\SystemConfigService::get($name);
    }
});

$memberId = 913081;
$orderId = '';
$transactionId = 'LOCAL_SXF_' . bin2hex(random_bytes(8));
Db::startTrans();
try {
    Db::name('user')->where('uid', $memberId)->update(['belong_store_id' => 118]);
    $service = new MemberCommerceServices();
    $input = ['store_id' => 118, 'items' => [['product_id' => 290541, 'quantity' => 1]],
        'salespeople' => [], 'payment_mode' => 'fuyou', 'external_method' => 'wechat',
        'idempotency_key' => 'local-sxf-' . bin2hex(random_bytes(9))];
    $quote = $service->quote($memberId, $input);
    assertLocal(($quote['onlineProvider'] ?? '') === 'suixingpay'
        && (int)($quote['amountCents'] ?? 0) === 14800, 'Wrong quote or provider');
    $input['fingerprint'] = $quote['fingerprint'];
    $order = $service->create($memberId, $input, 'routine');
    $orderId = (string)$order['id'];
    assertLocal(($order['status'] ?? '') === 'pending' && (int)$order['externalCents'] === 14800,
        'Online order was not frozen as pending');
    $stored = Db::name('member_commerce_order')->where('public_id', $orderId)->find();
    $snapshot = json_decode((string)$stored['snapshot_json'], true);
    assertLocal(($snapshot['onlineProvider'] ?? '') === 'suixingpay'
        && ($snapshot['onlineMerchantNo'] ?? '') === '399000000000001', 'Merchant not frozen in order');

    // The provider callback/service query verifies the signed response before
    // invoking paid(); this test starts at that confirmed-success boundary.
    try {
        $service->paid($orderId, 14800, $transactionId, 'wechat', 'suixingpay', '399000000000002');
        throw new RuntimeException('Wrong merchant was accepted');
    } catch (\think\exception\ValidateException $expected) {
        // The merchant frozen in the one order snapshot protects settlement.
    }
    $service->paid($orderId, 14800, $transactionId, 'wechat', 'suixingpay', '399000000000001');
    $service->paid($orderId, 14800, $transactionId, 'wechat', 'suixingpay', '399000000000001');
    try {
        $service->paid($orderId, 14800, $transactionId . '_OTHER', 'wechat', 'suixingpay', '399000000000001');
        throw new RuntimeException('Different paid transaction was accepted');
    } catch (\think\exception\ValidateException $expected) {
        // A second provider transaction may not take over a paid order.
    }
    assertLocal($service->detail($memberId, $orderId)['status'] === 'paid', 'Order not paid');
    $collections = Db::name('cashier_v3_payment_collection')
        ->where('external_transaction_no_snapshot', $transactionId)->select()->toArray();
    assertLocal(count($collections) === 1, 'Duplicate or missing payment collection');
    assertLocal(($collections[0]['payment_method'] ?? '') === 'wechat', 'Collection not WeChat');
    assertLocal(strpos((string)($collections[0]['remark_snapshot'] ?? ''), '399000000000001') !== false,
        'Merchant number missing from collection remark');
    $paidOrder = Db::name('member_commerce_order')->where('public_id', $orderId)->find();
    $facts = Db::name('cashier_v3_payment_fact')->where('order_id', (string)$paidOrder['sales_order_id'])
        ->where('payment_method', 'wechat')->select()->toArray();
    assertLocal(count($facts) === 1 && ($facts[0]['payment_method'] ?? '') === 'wechat',
        'Duplicate or missing authoritative WeChat payment fact');
    echo "PASS local SuixingPay member order, one WeChat fact and merchant remark\n";
} finally {
    Db::rollback();
    @unlink($keyPath);
}
assertLocal($orderId === '' || Db::name('member_commerce_order')->where('public_id', $orderId)->count() === 0,
    'Rollback retained the test order');
assertLocal(Db::name('cashier_v3_payment_collection')->where('external_transaction_no_snapshot', $transactionId)->count() === 0,
    'Rollback retained the test payment collection');
assertLocal(Db::name('cashier_v3_payment_fact')->where('order_id', (string)($paidOrder['sales_order_id'] ?? ''))
    ->where('payment_method', 'wechat')->count() === 0,
    'Rollback retained the test payment fact');

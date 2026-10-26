<?php
declare(strict_types=1);

/** Same-checkout sale snapshots enrich new service facts without reattribution. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\cashier\v3\checkout\persistence\ThinkPhpCashierV3EntitlementCompletionWriter;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
$matched = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
    ->join('cashier_v3_sales_order so', 'so.tenant_id=sf.tenant_id AND so.checkout_request_id=sf.checkout_request_id AND so.store_id=sf.store_id AND so.member_id=sf.member_id')
    ->where('so.order_status', 'settled')->where('so.order_direction', 'forward')
    ->where('so.business_source_primary_id', '>', 0)
    ->field('sf.tenant_id,sf.store_id,sf.member_id,sf.checkout_request_id,so.business_source_primary_id,so.business_source_primary_name_snapshot,so.business_source_secondary_id,so.business_source_secondary_name_snapshot,so.business_source_label_snapshot')
    ->find();
if (!$matched) throw new RuntimeException('A same-checkout sale fixture is required');

$writer = new ThinkPhpCashierV3EntitlementCompletionWriter();
$method = new ReflectionMethod($writer, 'attachSameCheckoutSaleSourceInTx');
$method->setAccessible(true);
$row = ['service_fact_id' => 'test', 'immutable_fingerprint' => str_repeat('a', 64)];
$context = [
    'tenant_id' => (string)$matched['tenant_id'],
    'store_id' => (int)$matched['store_id'],
    'member_id' => (int)$matched['member_id'],
    'checkout_request_id' => (string)$matched['checkout_request_id'],
];
$enriched = $method->invoke($writer, [$row], $context)[0];
foreach ([
    'business_source_primary_id', 'business_source_primary_name_snapshot',
    'business_source_secondary_id', 'business_source_secondary_name_snapshot',
    'business_source_label_snapshot',
] as $field) {
    if ((string)$enriched[$field] !== (string)$matched[$field]) {
        throw new RuntimeException('Same-checkout snapshot mismatch: ' . $field);
    }
}
if ($enriched['immutable_fingerprint'] !== $row['immutable_fingerprint']) {
    throw new RuntimeException('Legacy immutable fingerprint changed');
}
$context['checkout_request_id'] = 'no-same-checkout-sale:' . bin2hex(random_bytes(8));
if ($method->invoke($writer, [$row], $context) !== [$row]) {
    throw new RuntimeException('Unrelated sale source leaked into pure service');
}
echo "same-checkout service source integration: PASS\n";

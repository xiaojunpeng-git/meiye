<?php
declare(strict_types=1);

/**
 * Creates one clearly marked local-only member and six durable gateway refunds.
 * The fixture is deliberately isolated from copied customer identities; reruns
 * preserve the first six gateway receipts instead of duplicating them; later
 * browser-only acceptance refunds on this synthetic member remain untouched.
 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql'
    || (string)config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('R26 review fixture must use the local ruihao Docker database.');
}
$phone = '19900002607';
$marker = 'R26本地退款验收样本';
$existing = (array)Db::name('user')->where('phone', $phone)->find();
function ensureR26FixtureDocumentSnapshots(int $memberId, string $marker): void
{
    // Keep the six durable refunds, repairing only their synthetic source orders.
    Db::startTrans();
    try {
        foreach ([50000, 30000, 20000] as $index => $price) {
            $orderId = 'CSO-R26-LOCAL-' . ($index + 1);
            $order = Db::name('cashier_v3_sales_order')->where('order_id', $orderId)
                ->where('member_id', $memberId)->lock(true)->find();
            if (!$order) throw new RuntimeException('Fixture source order missing: ' . $orderId);
            if (!Db::name('cashier_v3_payment_collection_batch')->where('sales_order_id', $orderId)->find()) {
                $fingerprint = (string)$order['immutable_fingerprint'];
                $key = (string)$order['command_idempotency_key'];
                Db::name('cashier_v3_payment_collection_batch')->insert([
                    'batch_id' => 'CPB-R26-LOCAL-' . ($index + 1),
                    'natural_key' => 'r26-review-batch-' . ($index + 1),
                    'contract_version' => 'r26-local-fixture',
                    'command_idempotency_key' => $key, 'immutable_fingerprint' => $fingerprint,
                    'tenant_id' => '0', 'organization_id' => (string)$order['organization_id'],
                    'store_id' => 23, 'member_id' => $memberId,
                    'member_name_snapshot' => $marker, 'operator_id' => (int)$order['operator_id'],
                    'sales_order_id' => $orderId, 'sales_order_no_snapshot' => (string)$order['order_no'],
                    'sales_order_fingerprint' => $fingerprint,
                    'checkout_request_id' => 'R26-LOCAL-CHECKOUT-' . ($index + 1),
                    'checkout_request_version' => 1, 'checkout_prepare_idempotency_key' => $key,
                    'checkout_authority_fingerprint' => $fingerprint,
                    'checkout_aggregate_fingerprint' => $fingerprint,
                    'collection_count' => 0, 'collected_amount_cents' => $price,
                    'cash_performance_amount_cents' => $price, 'receivable_amount_cents' => $price,
                    'business_date' => (string)$order['business_date'], 'business_timezone' => 'Asia/Shanghai',
                    'occurred_at' => (int)$order['occurred_at'], 'settled_at' => (int)$order['settled_at'],
                    'recorded_at' => (int)$order['recorded_at'],
                    'source_document_type' => 'cashier_snapshot', 'source_document_id' => $orderId,
                    'batch_status' => 'settled', 'batch_version' => 1, 'batch_direction' => 'forward',
                    'add_time' => (int)$order['add_time'], 'update_time' => (int)$order['update_time'],
                ]);
            }
            $requestId = 'R26-LOCAL-CHECKOUT-' . ($index + 1);
            if (!Db::name('cashier_v3_checkout_request')->where('request_id', $requestId)->find()) {
                // The reader requires the original settlement request, even for
                // a synthetic sale. These rows belong solely to this local fixture.
                Db::name('cashier_v3_checkout_request')->insert([
                    'request_id' => $requestId, 'tenant_id' => '0',
                    'organization_id' => (string)$order['organization_id'],
                    'store_id' => 23, 'member_id' => $memberId,
                    'member_name_snapshot' => $marker, 'operator_id' => (int)$order['operator_id'],
                    'request_version' => 1, 'request_status' => 'committed', 'composition' => 'cash',
                    'business_date' => (string)$order['business_date'],
                    'business_timezone' => 'Asia/Shanghai',
                    'operation_occurred_at' => (int)$order['occurred_at'],
                    'recorded_at' => (int)$order['recorded_at'],
                    'source_document_type' => 'cashier_snapshot', 'source_document_id' => $orderId,
                    'sales_amount_cents' => $price, 'receivable_amount_cents' => $price,
                    'selected_payment_amount_cents' => $price, 'cash_performance_amount_cents' => $price,
                    'authority_fingerprint' => (string)$order['checkout_authority_fingerprint'],
                    'aggregate_fingerprint' => (string)$order['checkout_aggregate_fingerprint'],
                    'creation_idempotency_key' => 'r26-review-request-' . ($index + 1),
                    'add_time' => (int)$order['add_time'], 'update_time' => (int)$order['update_time'],
                ]);
            }
        }
        Db::commit();
    } catch (Throwable $error) {
        Db::rollback();
        throw $error;
    }
}
if ($existing) {
    if ((string)$existing['real_name'] !== $marker) throw new RuntimeException('Fixture phone is occupied.');
    ensureR26FixtureDocumentSnapshots((int)$existing['uid'], $marker);
    $ops = Db::name(CashierV3UnifiedRefundServices::TABLE)->where('member_id', (int)$existing['uid'])
        ->field('operation_id,source_order_no_snapshot,refund_amount_cents,balance_credit_cents,void_target')
        ->order('id asc')->select()->toArray();
    // Later browser-only acceptance refunds may append to this synthetic member.
    // Never regenerate the original six or reject the fixture just because UI tests exist.
    if (count($ops) < 6) throw new RuntimeException('Existing fixture has incomplete refund records.');
    echo json_encode(['memberId' => (int)$existing['uid'], 'phone' => $phone, 'records' => $ops], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    exit;
}
$staff = (array)Db::name('system_store_staff')->alias('s')
    ->join('organization_store os', 'os.store_id=s.store_id')
    ->where('s.store_id', 23)->where('s.level', 0)->where('s.status', 1)->where('s.is_del', 0)
    ->field('s.*,os.org_id')->find();
if (!$staff) throw new RuntimeException('Local store administrator is unavailable.');
$operator = new CashierV3OperatorScope(23, (int)$staff['id'], (string)$staff['org_id'], '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], 23, '0',
    (string)$staff['org_id'], [23], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r26-review', ['cashier.v3.order_center', 'cashier.v3.order.refund'], $staff);
$dispatcher = CashierV3Bootstrap::dispatcher();
$now = time();
$date = date('Y-m-d', $now);
$fingerprint = hash('sha256', 'r26-local-review-2026-10-07');

function r26Key(): string {
    $hex = bin2hex(random_bytes(16));
    return 'CMD-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4'
        . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
}

Db::startTrans();
try {
    $memberId = (int)Db::name('user')->insertGetId([
        'real_name' => $marker, 'nickname' => $marker, 'phone' => $phone,
        'belong_store_id' => 23, 'status' => 1, 'is_del' => 0, 'delete_time' => 0,
        'now_money' => '0.00', 'ben_money' => '0.00', 'give_money' => '0.00',
        'balance_version' => 1, 'add_time' => $now,
    ]);
    $cards = [
        ['name' => 'R26测试十次卡', 'price' => '1000.00', 'times' => 10, 'refund' => '450.00', 'balance' => '700.00', 'void' => false],
        ['name' => 'R26测试六次卡', 'price' => '600.00', 'times' => 6, 'refund' => '200.00', 'balance' => '200.00', 'void' => true],
        ['name' => 'R26测试三次卡', 'price' => '300.00', 'times' => 3, 'refund' => '100.00', 'balance' => '0.00', 'void' => false],
    ];
    $jobs = [];
    foreach ($cards as $index => $item) {
        $orderNo = 'R26-LOCAL-CARD-' . ($index + 1);
        $orderId = (int)Db::name('store_order')->insertGetId([
            'order_id' => $orderNo, 'uid' => $memberId, 'store_id' => 23,
            'paid' => 1, 'pay_price' => $item['price'], 'total_price' => $item['price'],
            'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00',
            'product_type' => 5, 'refund_status' => 0, 'terminal_action' => 0,
            'is_del' => 0, 'is_system_del' => 0, 'is_user_del' => 0,
            'add_time' => $now, 'pay_time' => $now,
            'unique' => md5('r26-review-order-' . ($index + 1)),
        ]);
        $holderId = (int)Db::name('user_card_holder')->insertGetId([
            'uid' => $memberId, 'oid' => $orderId, 'card_name' => $item['name'],
            'card_no' => 'R26A00' . ($index + 1), 'store_id' => 23,
            'write_times' => $item['times'], 'write_surplus_times' => $item['times'],
            'write_end' => $now + 365 * 86400, 'is_del' => 0, 'add_time' => $now,
        ]);
        $componentId = (int)Db::name('store_order_cart_info')->insertGetId([
            'uid' => $memberId, 'oid' => $orderId, 'cart_id' => 'r26-review-' . ($index + 1),
            'cart_type' => 2, 'product_type' => 6, 'pay_price' => $item['price'],
            'write_times' => $item['times'], 'write_surplus_times' => $item['times'],
            'write_end' => $now + 365 * 86400, 'add_time' => $now,
            'unique' => md5('r26-review-card-' . ($index + 1)),
            'cart_info' => json_encode(['productInfo' => ['store_name' => $item['name']]], JSON_UNESCAPED_UNICODE),
        ]);
        $jobs[] = ['mode' => 'card', 'memberId' => $memberId, 'holderId' => $holderId,
            'componentId' => $componentId, 'lineId' => '', 'refundAmount' => $item['refund'],
            'balanceAmount' => $item['balance'], 'balanceKind' => 'bonus',
            'voidTarget' => $item['void'], 'validThrough' => 0,
            'reason' => $marker . '卡项第' . ($index + 1) . '笔'];
    }
    $documents = [
        ['name' => 'R26测试购买项目A', 'type' => 'project', 'price' => 50000, 'refund' => '120.00', 'balance' => '120.00', 'kind' => 'bonus', 'void' => false],
        ['name' => 'R26测试购买产品B', 'type' => 'product', 'price' => 30000, 'refund' => '80.00', 'balance' => '40.00', 'kind' => 'bonus', 'void' => true],
        ['name' => 'R26测试购买项目C', 'type' => 'project', 'price' => 20000, 'refund' => '50.00', 'balance' => '0.00', 'kind' => 'bonus', 'void' => false],
    ];
    foreach ($documents as $index => $item) {
        $no = 'R26-LOCAL-DOC-' . ($index + 1);
        $orderId = 'CSO-R26-LOCAL-' . ($index + 1);
        $lineId = 'CSL-R26-LOCAL-' . ($index + 1);
        $key = r26Key();
        Db::name('cashier_v3_sales_order')->insert([
            'order_id' => $orderId, 'order_no' => $no, 'natural_key' => 'r26-review-order-' . ($index + 1),
            'contract_version' => 'r26-local-fixture', 'command_idempotency_key' => $key,
            'immutable_fingerprint' => $fingerprint, 'tenant_id' => '0',
            'organization_id' => (string)$staff['org_id'], 'store_id' => 23, 'operator_id' => (int)$staff['id'],
            'member_id' => $memberId, 'member_name_snapshot' => $marker,
            'checkout_request_id' => 'R26-LOCAL-CHECKOUT-' . ($index + 1),
            'checkout_request_version' => 1, 'checkout_prepare_idempotency_key' => $key,
            'checkout_authority_fingerprint' => $fingerprint, 'checkout_aggregate_fingerprint' => $fingerprint,
            'checkout_source_set_fingerprint' => $fingerprint, 'composition' => 'cash',
            'business_date' => $date, 'business_timezone' => 'Asia/Shanghai',
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'source_document_type' => 'cashier_snapshot', 'source_document_id' => $orderId,
            'order_status' => 'settled', 'order_version' => 1, 'order_direction' => 'forward',
            'sale_amount_cents' => $item['price'], 'add_time' => $now, 'update_time' => $now,
        ]);
        Db::name('cashier_v3_sales_order_line')->insert([
            'order_line_id' => $lineId, 'natural_key' => 'r26-review-line-' . ($index + 1),
            'command_idempotency_key' => $key, 'immutable_fingerprint' => $fingerprint,
            'order_id' => $orderId, 'tenant_id' => '0', 'store_id' => 23, 'member_id' => $memberId,
            'checkout_request_id' => 'R26-LOCAL-CHECKOUT-' . ($index + 1),
            'checkout_request_version' => 1, 'checkout_line_id' => $lineId,
            'checkout_line_fingerprint' => $fingerprint, 'line_no' => 1,
            'item_type' => $item['type'], 'item_id' => (string)($index + 1), 'item_version' => 1,
            'item_name_snapshot' => $item['name'], 'craftsmen_snapshot_json' => '[]',
            'quantity' => 1, 'original_amount_cents' => $item['price'], 'discount_amount_cents' => 0,
            'sale_amount_cents' => $item['price'], 'line_status' => 'settled', 'line_version' => 1,
            'line_direction' => 'forward', 'add_time' => $now, 'update_time' => $now,
        ]);
        $jobs[] = ['mode' => 'document', 'memberId' => $memberId, 'holderId' => 0,
            'componentId' => 0, 'lineId' => $lineId, 'refundAmount' => $item['refund'],
            'balanceAmount' => $item['balance'], 'balanceKind' => $item['kind'],
            'voidTarget' => $item['void'], 'validThrough' => 0,
            'reason' => $marker . '单据第' . ($index + 1) . '笔'];
    }
    $receipts = [];
    foreach ($jobs as $payload) {
        $key = r26Key();
        $result = $dispatcher->gateway()->execute('refund-card-or-line',
            ['idempotencyKey' => $key, 'contexts' => []], $payload, $operator,
            ['client_session_id' => 'SESSION-' . substr($key, 4), 'operator_profile' => $staff],
            $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
            CashierV3ActionManifest::requireAction('refund-card-or-line'));
        if (($result['status'] ?? '') !== 'success') throw new RuntimeException('Gateway refund failed.');
        $receipts[] = $result['data']['unifiedRefund'];
    }
    if (count($receipts) !== 6 || (int)Db::name(CashierV3UnifiedRefundServices::TABLE)
        ->where('member_id', $memberId)->count() !== 6) throw new RuntimeException('Six refunds did not reconcile.');
    Db::commit();
    ensureR26FixtureDocumentSnapshots($memberId, $marker);
    echo json_encode(['memberId' => $memberId, 'phone' => $phone, 'records' => $receipts], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $error) {
    Db::rollback();
    throw $error;
}

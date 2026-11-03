<?php
declare(strict_types=1);

/**
 * Browser-only local fixtures: fabricate report facts without invoking payment,
 * fulfillment, rewards or queues. Nothing is copied from a business member/order.
 * A manifest of exact primary keys permits removal only of this script's rows.
 * Usage in local app container: R44_FIXTURE_ACTION=seed|status|clean php < this file.
 */
putenv('R61_MODE=library');
require getenv('R44_FIXTURE_HELPER') ?: dirname(__DIR__) . '/combination-r61/local-acceptance.php';

use think\facade\Db;

$backend = getenv('BACKEND_ROOT') ?: '/var/www/html';
$manifestPath = $backend . '/runtime/r44-promotion-browser-fixture.json';
$action = getenv('R44_FIXTURE_ACTION') ?: 'status';
if (!in_array($action, ['seed', 'status', 'clean'], true)) throw new RuntimeException('Invalid fixture action');

// The imported helper already rejects any database other than Docker mysql/ruihao.
$manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
if ($action === 'status') {
    echo json_encode($manifest ?: ['exists' => false], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit;
}

if ($action === 'clean') {
    if (!$manifest) { echo "No R44 browser fixture to remove\n"; exit; }
    if (($manifest['local_only'] ?? false) !== true || strpos((string)($manifest['tag'] ?? ''), 'R44-LOCAL-') !== 0)
        throw new RuntimeException('Invalid fixture manifest');
    $tag = $manifest['tag'];
    $ids = $manifest['rows'];
    Db::startTrans();
    try {
        // Refuse cleanup if any recorded business root is no longer our fabricated row.
        foreach ([['store_order', 'id', 'order_id'], ['store_pink', 'id', 'order_id'],
            ['store_product', 'id', 'store_name'], ['store_combination', 'id', 'title'],
            ['user', 'uid', 'nickname'], ['system_store', 'id', 'name']] as $spec) {
            foreach (Db::name($spec[0])->whereIn($spec[1], $ids[$spec[0]] ?? [0])->column($spec[2]) as $name) {
                if (strpos((string)$name, $tag) !== 0) throw new RuntimeException('Fixture identity changed: ' . $spec[0]);
            }
        }
        foreach (['store_order_terminal_operation', 'combination_promotion_order', 'store_pink',
            'store_order', 'store_combination', 'store_product', 'user', 'system_store'] as $table) {
            $pk = $table === 'user' ? 'uid' : ($table === 'combination_promotion_order' ? 'order_id' : 'id');
            Db::name($table)->whereIn($pk, $ids[$table] ?? [0])->delete();
        }
        Db::commit();
        unlink($manifestPath);
        echo 'Removed only fixture manifest ' . $tag . "\n";
    } catch (Throwable $error) { Db::rollback(); throw $error; }
    exit;
}

if ($manifest) throw new RuntimeException('Fixture already exists; status or clean first');
$tag = 'R44-LOCAL-' . bin2hex(random_bytes(4));
$rows = [];
$remember = static function (string $table, array $values) use (&$rows): int {
    $id = seed($table, $values);
    $rows[$table][] = $id;
    return $id;
};
$names = ['东区门店', '西区门店'];
$paidAt = strtotime(date('Y-m-d') . ' 12:00:00');
Db::startTrans();
try {
    $stores = [];
    foreach ($names as $name) $stores[] = $remember('system_store', ['name' => $tag . '验收虚构·' . $name, 'is_del' => 0, 'is_show' => 0]);
    $promoters = [];
    foreach (['推广甲', '推广乙', '推广丙'] as $index => $name) {
        $store = $index === 1 ? $stores[1] : $stores[0];
        $promoters[] = $remember('user', ['nickname' => $tag . '验收虚构·' . $name, 'real_name' => 'R44验收虚构·' . $name,
            'status' => 1, 'is_del' => 0, 'spread_open' => 1, 'is_promoter' => 1, 'belong_store_id' => $store,
            'phone' => '', 'bar_code' => (string)random_int(800000000, 899999999)]);
    }
    $activities = [];
    foreach (['已成团活动', '拼团中活动', '失败退款活动'] as $title) {
        $product = $remember('store_product', ['store_name' => $tag . '验收虚构·' . $title, 'product_type' => 6, 'price' => 100,
            'stock' => 0, 'is_show' => 0, 'is_verify' => 0, 'is_del' => 0]);
        $activities[] = $remember('store_combination', ['title' => $tag . '验收虚构·' . $title, 'product_id' => $product,
            'product_type' => 6, 'people' => 30, 'is_show' => 0, 'is_del' => 0, 'virtual' => 0]);
    }
    $groups = [];
    $sequence = 0;
    $createOrder = static function (int $activity, int $promoter, int $amount, int $status, int $refund = 0, bool $pendingRefund = false)
        use (&$groups, &$sequence, $tag, $paidAt, $stores, $promoters, $activities, $remember, &$rows): int {
        ++$sequence;
        $storeIndex = $promoter === 1 ? 1 : 0;
        $orderNo = $tag . '-' . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT);
        $buyer = $remember('user', ['nickname' => $tag . '验收虚构·买家' . $sequence,
            'real_name' => 'R44验收虚构·买家' . $sequence, 'status' => 1, 'is_del' => 0, 'phone' => '',
            'belong_store_id' => $stores[$storeIndex], 'bar_code' => (string)random_int(800000000, 899999999)]);
        $oid = $remember('store_order', ['uid' => $buyer, 'store_id' => $stores[$storeIndex], 'order_id' => $orderNo,
            'unique' => $orderNo, 'type' => 3, 'product_type' => 6, 'activity_id' => $activities[$activity],
            'total_num' => 1, 'total_price' => $amount, 'pay_price' => $amount, 'paid' => 1, 'pay_type' => 'r44_fixture',
            'pay_time' => $paidAt + $sequence, 'add_time' => $paidAt + $sequence, 'shipping_type' => 2,
            'is_del' => $status === 2 ? 0 : 1, 'refund_status' => $refund > 0 && !$pendingRefund ? ($refund === $amount ? 2 : 3) : 0,
            'refund_price' => $refund > 0 && !$pendingRefund ? $refund : 0]);
        $pid = $remember('store_pink', ['uid' => $buyer, 'nickname' => $tag . '验收虚构·买家' . $sequence,
            'order_id' => $orderNo, 'order_id_key' => $oid, 'cid' => $activities[$activity],
            'k_id' => $groups[$activity] ?? 0, 'people' => 30, 'total_num' => 1, 'total_price' => $amount,
            'price' => $amount, 'add_time' => $paidAt, 'stop_time' => strtotime('+30 days', $paidAt), 'status' => $status, 'is_virtual' => 0]);
        if (!isset($groups[$activity])) $groups[$activity] = $pid;
        Db::name('store_order')->where('id', $oid)->update(['pink_id' => $groups[$activity]]);
        // These are explicitly synthetic report facts, not evidence of a payment or gift.
        Db::name('combination_promotion_order')->insert(['order_id' => $oid, 'activity_id' => $activities[$activity],
            'share_uid' => $promoters[$promoter], 'store_id' => $stores[$storeIndex],
            'promoter_name' => ['R44验收虚构·推广甲', 'R44验收虚构·推广乙', 'R44验收虚构·推广丙'][$promoter],
            'store_name' => 'R44验收虚构·' . ($storeIndex ? '西区门店' : '东区门店'),
            'activity_name' => 'R44验收虚构·' . ['已成团活动', '拼团中活动', '失败退款活动'][$activity],
            'attribution_reason' => 'local_browser_fixture', 'created_at' => $paidAt]);
        $rows['combination_promotion_order'][] = $oid;
        if ($refund > 0) $remember('store_order_terminal_operation', ['store_order_id' => $oid, 'business_type' => 1,
            'action_type' => 1, 'state' => $pendingRefund ? 2 : 4, 'refund_amount' => $refund,
            'operation_no' => $orderNo, 'request_token' => $orderNo, 'execution_owner' => 'local-browser-fixture',
            'lease_until' => strtotime('+30 days', $paidAt)]);
        return $oid;
    };
    $createOrder(0, 0, 400, 2);
    $createOrder(0, 1, 600, 2);
    $createOrder(1, 0, 200, 1);
    $createOrder(1, 2, 300, 1);
    $createOrder(2, 1, 500, 3, 200);
    $createOrder(2, 2, 100, 3, 100);
    $createOrder(2, 2, 100, 3, 100, true);
    for ($i = 0; $i < 18; ++$i) $createOrder(0, $i % 3, 10, 2);
    $manifest = ['local_only' => true, 'tag' => $tag, 'created_at' => date('c'), 'payment_date' => date('Y-m-d', $paidAt),
        'activity_ids' => $activities, 'promoter_ids' => $promoters, 'store_ids' => $stores, 'group_ids' => array_values($groups),
        'expected' => ['formed_amount' => '1180.00', 'pending_amount' => '900.00', 'total_amount' => '2080.00',
            'refund_pending_amount' => '400.00', 'paid_amount' => '2380.00', 'refund_amount' => '300.00',
            'order_count' => 25, 'group_count' => 3, 'formed_count' => 1, 'pending_count' => 1, 'failed_count' => 1,
            'member_rank' => ['推广乙' => '960.00', '推广甲' => '660.00', '推广丙' => '460.00'],
            'store_rank' => ['东区门店' => '1120.00', '西区门店' => '960.00'],
            'activity_rank' => ['已成团活动' => '1180.00', '拼团中活动' => '500.00', '失败退款活动' => '400.00']], 'rows' => $rows];
    if (file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false)
        throw new RuntimeException('Cannot persist cleanup manifest');
    Db::commit();
    echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $error) {
    Db::rollback();
    if (is_file($manifestPath)) unlink($manifestPath);
    throw $error;
}

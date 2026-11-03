<?php
declare(strict_types=1);

/** Only local Docker MySQL is used; synthetic group/order facts are rolled back. */
putenv('R61_MODE=library');
require dirname(__DIR__) . '/combination-r61/local-acceptance.php';

use app\services\activity\combination\CombinationGroupListServices;
use think\facade\Db;

$service = new CombinationGroupListServices();
$admin = ['id' => 1, 'level' => 0, 'roles' => [], 'admin_type' => 1];
Db::startTrans();
try {
    $leader = fixture(6, 2, 'yue');
    $member = fixture(6, 2, 'yue');
    joinFixture($leader, $member);
    Db::name('store_pink')->where('id', $leader['pinkId'])->update(['status' => 2]);
    $filters = ['group_id' => $leader['pinkId']];
    $read = static function () use ($service, $filters, $admin): array {
        $rows = $service->page($filters, $admin);
        check($rows['count'] === 1 && count($rows['list']) === 1, 'one group and one visible row');
        return $rows['list'][0];
    };
    $row = $read();
    check($row['refund_state'] === 'none' && $row['status_name'] === '拼团成功', 'unrefunded group keeps formed status');

    Db::name('store_order')->where('id', $leader['oid'])->update(['refund_status' => 2, 'refund_price' => 100]);
    $row = $read();
    check($row['refund_state'] === 'partially_refunded' && $row['status_name'] === '部分退款', 'one settled order does not complete group');

    Db::name('store_order')->where('id', $member['oid'])->update(['refund_status' => 1]);
    seed('combination_order_refund', ['order_id' => $member['oid'], 'refund_no' => 'R108' . $member['oid'],
        'state' => 'pending', 'amount_cents' => 10000, 'created_at' => time(), 'updated_at' => time()]);
    $row = $read();
    check($row['refund_state'] === 'processing' && $row['status_name'] === '退款处理中', 'pending receipt remains visible');

    Db::name('store_order')->where('id', $member['oid'])->update(['refund_status' => 2, 'refund_price' => 100]);
    Db::name('combination_order_refund')->where('order_id', $member['oid'])->update(['state' => 'success']);
    $row = $read();
    check($row['refund_state'] === 'fully_refunded' && $row['status_name'] === '整团已退款', 'both settled orders close refund action');
    $export = $service->page($filters, $admin, true);
    check($export['export'][0]['status_name'] === '整团已退款', 'export status matches list');
    echo "R108 REFUND DISPLAY LOCAL COMPLETE\n";
} finally {
    Db::rollback();
}

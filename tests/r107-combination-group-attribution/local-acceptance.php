<?php
declare(strict_types=1);

/**
 * 仅在本地 Docker 数据库中运行。真实拼团订单、归属快照和大屏查询一起测试，
 * 所有夹具在同一事务回滚，不调用支付或写入任何线上实例。
 */
putenv('R61_MODE=library');
require dirname(__DIR__) . '/combination-r61/local-acceptance.php';

use app\services\activity\combination\CombinationPromotionServices;
use think\facade\Db;

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->__toString() . "\n");
    exit(1);
});

$service = new CombinationPromotionServices();
$admin = ['id' => 1, 'level' => 0, 'roles' => [], 'admin_type' => 1];
Db::startTrans();
try {
    $shareStore = seed('system_store', ['name' => 'R107分享店', 'is_show' => 1, 'is_del' => 0]);
    $leaderStore = seed('system_store', ['name' => 'R107团长店', 'is_show' => 1, 'is_del' => 0]);
    $otherStore = seed('system_store', ['name' => 'R107另一个分享店', 'is_show' => 1, 'is_del' => 0]);
    $promoter = seed('user', ['nickname' => 'R107分享人', 'status' => 1, 'is_del' => 0,
        'spread_open' => 1, 'is_promoter' => 0, 'belong_store_id' => $shareStore]);
    $other = seed('user', ['nickname' => 'R107团员链接分享人', 'status' => 1, 'is_del' => 0,
        'spread_open' => 1, 'is_promoter' => 1, 'belong_store_id' => $otherStore]);
    $root = fixture(6, 2, 'yue');
    $join = fixture(6, 2, 'yue');
    joinFixture($root, $join);
    Db::name('user')->where('uid', $root['uid'])->update(['belong_store_id' => $leaderStore]);
    $service->registerOrderInTx($root['oid'], $promoter);
    $service->registerOrderInTx($join['oid'], $other);
    $rootSnap = Db::name('combination_promotion_order')->where('order_id', $root['oid'])->find();
    $joinSnap = Db::name('combination_promotion_order')->where('order_id', $join['oid'])->find();
    check((int)$rootSnap['share_uid'] === $promoter && (int)$rootSnap['store_id'] === $shareStore,
        'qualified share works while standard distributor permission is off');
    check((int)$joinSnap['share_uid'] === $promoter && (int)$joinSnap['store_id'] === $shareStore,
        'joining through another link inherits the leader group attribution');
    $filters = ['activity_ids' => [$root['cid']]];
    $view = $service->dashboard($filters, $admin);
    check($view['metrics']['order_count'] === 2 && $view['metrics']['total_amount'] === '200.00',
        'group contains exactly two paid orders');
    check(count($view['rankings']['members']) === 1 && (int)$view['rankings']['members'][0]['id'] === $promoter
        && $view['rankings']['members'][0]['total_amount'] === '200.00',
        'whole group appears under the opening share member only');
    check(count($view['rankings']['stores']) === 1 && (int)$view['rankings']['stores'][0]['id'] === $shareStore,
        'whole group appears under the share member store only');
    $details = $service->details($filters + ['store_ids' => [$shareStore]], $admin);
    check($details['count'] === 2 && $details['summary']['paid_amount'] === '200.00'
        && array_map('intval', array_column($details['list'], 'store_id')) === [$shareStore, $shareStore],
        'detail list and summary use the group store');
    $before = $joinSnap;
    Db::name('user')->where('uid', $promoter)->update(['belong_store_id' => $otherStore]);
    $service->registerOrderInTx($join['oid'], $other);
    check(Db::name('combination_promotion_order')->where('order_id', $join['oid'])->find() === $before,
        'retry and subsequent member transfer never rewrite an order snapshot');

    $noShareRoot = fixture(6, 2, 'yue');
    $noShareJoin = fixture(6, 2, 'yue');
    joinFixture($noShareRoot, $noShareJoin);
    Db::name('user')->where('uid', $noShareRoot['uid'])->update(['belong_store_id' => $leaderStore]);
    $service->registerOrderInTx($noShareRoot['oid'], 0);
    // 团长开团后调店，后续团员仍必须继承开团时冻结的门店。
    Db::name('user')->where('uid', $noShareRoot['uid'])->update(['belong_store_id' => $otherStore]);
    $service->registerOrderInTx($noShareJoin['oid'], $other);
    $noShareSnap = Db::name('combination_promotion_order')->where('order_id', $noShareJoin['oid'])->find();
    check((int)$noShareSnap['share_uid'] === 0 && (int)$noShareSnap['store_id'] === $leaderStore,
        'without an opening share the whole group uses the leader member store');
    $noShareView = $service->dashboard(['activity_ids' => [$noShareRoot['cid']]], $admin);
    check($noShareView['rankings']['members'] === []
        && count($noShareView['rankings']['stores']) === 1
        && (int)$noShareView['rankings']['stores'][0]['id'] === $leaderStore
        && $noShareView['rankings']['stores'][0]['order_count'] === 2,
        'leader store gets both orders without fabricating a promoter');
    echo "R107 GROUP ATTRIBUTION LOCAL COMPLETE\n";
} finally {
    Db::rollback();
}

// 导出必须在独立只读快照执行，不能借用上方尚未回滚的夹具写事务。
$existing = $service->details([], $admin);
$export = $service->details([], $admin, null, true);
check($existing['count'] === $export['count'] && $existing['summary'] === $export['summary']
    && count($export['export']) === $existing['count'],
    'existing local orders keep the same list, summary, and export scope');

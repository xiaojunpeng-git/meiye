<?php
declare(strict_types=1);

// 本地集成测试在外层事务内调用真实调店服务，最终回滚，避免污染会员归属和审计历史。
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->initialize();

use app\services\user\UserBelongStoreServices;
use think\facade\Db;

function r22Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$member = Db::name('user')
    ->where('belong_store_id', '>', 0)
    ->whereNull('delete_time')
    ->order('uid desc')
    ->find();
r22Assert((bool)$member, '缺少可用于回滚测试的在用会员');

$targetStore = Db::name('system_store')
    ->where(['is_del' => 0, 'is_show' => 1])
    ->where('id', '<>', (int)$member['belong_store_id'])
    ->field('id,name')
    ->order('id asc')
    ->find();
r22Assert((bool)$targetStore, '缺少不同于会员当前归属的启用门店');

$uid = (int)$member['uid'];
$targetStoreId = (int)$targetStore['id'];
$reason = 'R22会员调店事务回滚自测';
$storeUserBefore = Db::name('store_user')->where('uid', $uid)->order('id asc')->select()->toArray();
$historyBefore = (int)Db::name('user_belong_store')->where(['uid' => $uid, 'type' => 'admin_transfer'])->count();

Db::startTrans();
try {
    /** @var UserBelongStoreServices $services */
    $services = app()->make(UserBelongStoreServices::class);
    $first = $services->transferUserStore($uid, $targetStoreId, $reason, 1);
    r22Assert($first['changed'] === true, '首次调店必须写入新归属');

    $after = Db::name('user')->where('uid', $uid)->find();
    r22Assert((int)$after['belong_store_id'] === $targetStoreId, '会员主表归属门店未更新');
    $afterWithoutStore = $after;
    $beforeWithoutStore = $member;
    unset($afterWithoutStore['belong_store_id'], $beforeWithoutStore['belong_store_id']);
    r22Assert($afterWithoutStore == $beforeWithoutStore, '调店修改了会员主表的其他字段');

    $history = Db::name('user_belong_store')->where('id', (int)$first['history_id'])->find();
    r22Assert((int)$history['from_store_id'] === (int)$member['belong_store_id'], '审计记录缺少原门店');
    r22Assert((int)$history['store_id'] === $targetStoreId, '审计记录目标门店错误');
    r22Assert((string)$history['reason'] === $reason, '审计记录原因错误');
    r22Assert((string)$history['type'] === 'admin_transfer', '审计记录类型错误');
    r22Assert((string)$history['from_store_name'] !== '', '审计记录缺少原门店名称快照');
    r22Assert((string)$history['to_store_name'] === (string)$targetStore['name'], '审计记录目标门店名称快照错误');
    r22Assert((string)$history['operator_name'] !== '', '审计记录缺少操作人名称快照');

    $historyPage = $services->getTransferHistory($uid);
    $detailRow = array_values(array_filter($historyPage['list'], static function (array $row) use ($first): bool {
        return (int)$row['id'] === (int)$first['history_id'];
    }));
    r22Assert(count($detailRow) === 1, '调店详情接口未返回本次审计记录');
    r22Assert((string)$detailRow[0]['reason'] === $reason, '调店详情原因与审计事实不一致');
    r22Assert((string)$detailRow[0]['add_time'] !== '', '调店详情未格式化操作时间');

    // 相同目标重复提交必须幂等，不得增加第二条历史或改写店员绑定。
    $second = $services->transferUserStore($uid, $targetStoreId, $reason, 1);
    r22Assert($second['changed'] === false, '相同目标门店重复提交未幂等返回');
    $historyAfter = (int)Db::name('user_belong_store')->where(['uid' => $uid, 'type' => 'admin_transfer'])->count();
    r22Assert($historyAfter === $historyBefore + 1, '重复提交产生了额外调店历史');
    $storeUserAfter = Db::name('store_user')->where('uid', $uid)->order('id asc')->select()->toArray();
    r22Assert($storeUserAfter == $storeUserBefore, '调店意外修改了会员店员绑定');

    Db::rollback();
} catch (Throwable $e) {
    Db::rollback();
    throw $e;
}

$restored = Db::name('user')->where('uid', $uid)->find();
$historyRestored = (int)Db::name('user_belong_store')->where(['uid' => $uid, 'type' => 'admin_transfer'])->count();
r22Assert((int)$restored['belong_store_id'] === (int)$member['belong_store_id'], '回滚后会员归属未恢复');
r22Assert($historyRestored === $historyBefore, '回滚后仍残留调店历史');

echo json_encode([
    'status' => 'PASS',
    'uid' => $uid,
    'from_store_id' => (int)$member['belong_store_id'],
    'tested_target_store_id' => $targetStoreId,
    'persistent_changes' => 0,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

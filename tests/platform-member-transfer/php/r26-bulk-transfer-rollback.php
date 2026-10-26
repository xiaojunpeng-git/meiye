<?php
declare(strict_types=1);

/** 本地真实MySQL事务测试：名单、单人服务、归属事实和队列故障均回滚，不转移真实会员。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\user\UserStoreTransferBatchServices;
use app\services\user\UserBelongStoreServices;
use app\jobs\user\UserStoreTransferBatchJob;
use think\facade\Db;

set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL); exit(1); });
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local ruihao only.');
$checks = 0;
$assert = static function (bool $okay, string $message) use (&$checks): void { ++$checks; if (!$okay) throw new RuntimeException($message); };
$reject = static function (callable $call, string $contains) use ($assert): void {
    try { $call(); throw new RuntimeException('Expected rejection: ' . $contains); }
    catch (\think\exception\ValidateException $error) { $assert(strpos($error->getMessage(), $contains) !== false, $error->getMessage()); }
};
$admin = (int)Db::name('system_admin')->where('status', 1)->where('is_del', 0)->value('id');
$assert($admin > 0, 'No local admin.');
$real = (array)Db::name('user')->where('uid', 1030289)->find();
$assert($real !== [], 'No local card-bearing member fixture.');
$memberId = (int)$real['uid'];
$businessSnapshot = static function () use ($memberId): array {
    $result = [];
    foreach (['store_user' => 'uid', 'user_card_holder' => 'uid', 'store_order' => 'uid',
        'store_debt' => 'uid', 'user_money' => 'uid', 'cashier_v3_payment_fact' => 'member_id',
        'cashier_v3_performance_fact' => 'member_id', 'cashier_v3_entitlement_writeoff_fact' => 'member_id'] as $table => $column) {
        $result[$table] = Db::name($table)->where($column, $memberId)->order('id')->select()->toArray();
    }
    return $result;
};
$originalBusiness = $businessSnapshot();
$tag = 'R26-' . bin2hex(random_bytes(6));
$batchNos = [];
$source = 0;

Db::startTrans();
try {
    $storeTemplate = (array)Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->find();
    unset($storeTemplate['id']);
    $stores = [];
    foreach (['source', 'target', 'other'] as $name) {
        $row = $storeTemplate; $row['name'] = $tag . '-' . $name;
        $stores[$name] = (int)Db::name('system_store')->insertGetId($row);
    }
    $source = $stores['source']; $target = $stores['target'];
    // 所有克隆仅存在于本地回滚事务，不经业务入口发送通知或创建支付。
    $newMember = static function (int $index, bool $deleted = false) use ($real, $tag, $source): int {
        $row = $real; unset($row['uid']);
        $row['real_name'] = $tag . '-' . $index; $row['nickname'] = $row['real_name'];
        $row['phone'] = '190' . str_pad((string)$index, 8, '0', STR_PAD_LEFT);
        $row['belong_store_id'] = $source; $row['is_del'] = $deleted ? 1 : 0; $row['delete_time'] = null;
        $row['status'] = $index === 1 ? 0 : 1;
        $row['ben_money'] = '100.00'; $row['give_money'] = '50.00'; $row['now_money'] = '150.00';
        return (int)Db::name('user')->insertGetId($row);
    };
    $uids = [];
    for ($i = 1; $i <= 54; ++$i) $uids[] = $newMember($i);
    $deleted = $newMember(90, true);
    $cancelled = $newMember(91);
    Db::name('user')->where('uid', $cancelled)->update(['delete_time' => date('Y-m-d H:i:s')]);
    Db::name('user')->where('uid', $memberId)->update(['belong_store_id' => $source]);
    $beforeMember = (array)Db::name('user')->where('uid', $memberId)->find();

    // 覆盖写后故障保存点；enqueue在测试内不投递，避免其他进程读取未提交夹具。
    $service = new class extends UserStoreTransferBatchServices {
        public $failUid = 0;
        public $scheduled = [];
        public function enqueue(string $batchNo): void { $this->scheduled[] = $batchNo; }
        protected function transferMember(array $item, array $batch): array {
            $result = parent::transferMember($item, $batch);
            if ((int)$item['uid'] === $this->failUid) throw new InvalidArgumentException('模拟单人写入后失败');
            return $result;
        }
    };
    $preview = $service->preview($source, $admin);
    $assert($preview['count'] === 55, 'All members must include inactive and exclude deleted/cancelled.');
    $assert(!in_array($source, array_map('intval', array_column($preview['stores'], 'id')), true), 'Source appears as target.');
    $key = 'test-' . $tag;
    $reject(static function () use ($service, $source, $target, $key, $admin): void { $service->create($source, $target, '人数旧值', 54, $key, $admin); }, '人数已变化');
    $assert((int)Db::name(UserStoreTransferBatchServices::TABLE)->where('source_store_id', $source)->count() === 0, 'Stale count left a batch.');
    $reject(static function () use ($service, $source, $key, $admin): void { $service->create($source, $source, '同店测试', 55, $key, $admin); }, '不同的目标');
    $batch = $service->create($source, $target, '本地批量转移回滚', 55, $key, $admin);
    $no = $batch['batch_no']; $batchNos[] = $no;
    $assert((int)$batch['total_count'] === 55 && $batch['status'] === 'queued', 'Batch did not freeze all 55 members.');
    $assert($batch['exception_total'] === 0 && $batch['exception_page'] === 1 && $batch['exceptions'] === [], 'Empty exception pagination is invalid.');
    $again = $service->create($source, $target, '本地批量转移回滚', 55, $key, $admin);
    $assert($again['batch_no'] === $no, 'Creation replay made another batch.');
    $reject(static function () use ($service, $source, $target, $key, $admin): void { $service->create($source, $target, '不同原因', 55, $key, $admin); }, '已用于其他');
    $reject(static function () use ($service, $source, $target, $admin): void { $service->create($source, $target, '重复源门店', 55, 'another-key-test', $admin); }, '未结束');
    $late = $newMember(100);
    Db::name('user')->where('uid', $uids[1])->update(['belong_store_id' => $stores['other']]);
    Db::name('user')->where('uid', $uids[2])->update(['is_del' => 1]);
    // 即便当前又回到源店，只要确认后归属历史变化，也不能覆盖后续业务选择。
    Db::name('user_belong_store')->insert(['group' => 2, 'type' => 'admin_transfer', 'is_store' => 1,
        'uid' => $uids[3], 'store_id' => $source, 'from_store_id' => $stores['other'], 'staff_id' => 0,
        'admin_id' => $admin, 'reason' => '模拟确认后重新调回', 'from_store_name' => 'fixture',
        'to_store_name' => 'fixture', 'operator_name' => 'fixture', 'add_time' => time()]);
    $service->failUid = $uids[4];
    $failedBefore = (array)Db::name('user')->where('uid', $service->failUid)->find();
    $assert($service->processChunk($no) === true, 'First chunk did not leave remaining work.');
    $first = $service->status($no, $admin);
    $assert((int)$first['success_count'] + (int)$first['skipped_count'] + (int)$first['failed_count'] === 50,
        'Worker must process exactly 50 on the first chunk.');
    $assert($service->processChunk($no) === false, 'Second chunk should finish the snapshot.');
    $done = $service->status($no, $admin);
    $assert((int)$done['success_count'] === 51 && (int)$done['skipped_count'] === 3
        && (int)$done['failed_count'] === 1 && $done['status'] === 'completed_with_exceptions', 'Partial outcomes or totals are incorrect.');
    $assert((int)Db::name('user')->where('uid', $late)->value('belong_store_id') === $source, 'Late new member was swept into the batch.');
    $assert((int)Db::name('user')->where('uid', $uids[0])->value('belong_store_id') === $target, 'Inactive member was omitted.');
    $assert((int)Db::name('user')->where('uid', $deleted)->value('belong_store_id') === $source, 'Deleted member was transferred.');
    $assert((int)Db::name('user')->where('uid', $cancelled)->value('belong_store_id') === $source, 'Cancelled member was transferred.');
    $assert(Db::name('user')->where('uid', $service->failUid)->find() == $failedBefore, 'Failed individual kept partial user writes.');
    $assert((int)Db::name('user_belong_store')->where('uid', $service->failUid)->where('store_id', $target)->count() === 0,
        'Failed individual kept an assignment history.');
    $after = (array)Db::name('user')->where('uid', $memberId)->find();
    $assert((int)$after['belong_store_id'] === $target, 'Card-bearing member was not transferred.');
    unset($after['belong_store_id'], $beforeMember['belong_store_id']);
    $assert($after == $beforeMember, 'Other member profile fields changed.');
    $assert($businessSnapshot() === $originalBusiness, 'Cards, balance, orders, debt, performance or store visibility changed.');
    $history = app()->make(UserBelongStoreServices::class)->getTransferHistory($memberId);
    $assert(strpos((string)$history['list'][0]['reason'], '批量转移：') === 0, 'Existing transfer detail does not show batch origin.');
    $assert($service->processChunk($no) === false && $service->status($no, $admin)['success_count'] == 51, 'Repeated worker changed finished outcomes.');

    $service->failUid = 0;
    $resumed = $service->resume($no, $admin);
    $assert($resumed['status'] === 'queued' && count($service->scheduled) === 1, 'Resume did not requeue the original batch.');
    $service->processChunk($no);
    $resumed = $service->status($no, $admin);
    $assert((int)$resumed['success_count'] === 52 && (int)$resumed['skipped_count'] === 3 && (int)$resumed['failed_count'] === 0,
        'Resume did not retry only the failed member.');
    $assert((int)Db::name('user_store_transfer_batch_item')->where('batch_no', $no)->where('uid', $uids[4])->value('history_id') > 0,
        'Retry lost the history-to-batch link.');
    $oldHistoryCount = (int)Db::name('user_belong_store')->where('uid', $memberId)->where('store_id', $target)->count();
    $service->create($source, $target, '本地批量转移回滚', 55, $key, $admin);
    $assert((int)Db::name('user_belong_store')->where('uid', $memberId)->where('store_id', $target)->count() === $oldHistoryCount, 'Completed creation replay transferred twice.');
    $assert($service->preview($source, $admin)['batch']['batch_no'] === $no, 'Refresh did not recover the saved batch.');
    $otherAdmin = (int)Db::name('system_admin')->where('status', 1)->where('is_del', 0)->where('id', '<>', $admin)->value('id');
    if ($otherAdmin) $reject(static function () use ($service, $no, $otherAdmin): void { $service->status($no, $otherAdmin); }, '不属于');

    // 已完成批次后新确认名单可包含先前被跳过但仍在原店的会员；target停用必须阻止整批并保留恢复点。
    $remaining = $service->preview($source, $admin)['count'];
    $next = $service->create($source, $target, '第二批验证后台任务', $remaining, 'second-' . $tag, $admin);
    $nextNo = $next['batch_no']; $batchNos[] = $nextNo;
    Db::name('system_store')->where('id', $target)->update(['is_show' => 0]);
    (new UserStoreTransferBatchJob())->doJob($nextNo);
    $assert($service->status($nextNo, $admin)['status'] === 'blocked'
        && (int)$service->status($nextNo, $admin)['success_count'] === 0, 'Unavailable target did not block the queued job.');
    Db::name('system_store')->where('id', $target)->update(['is_show' => 1]);
    $service->resume($nextNo, $admin);
    (new UserStoreTransferBatchJob())->doJob($nextNo);
    $assert($service->status($nextNo, $admin)['status'] === 'completed', 'Real queue job did not finish the remaining small batch.');
    $assert($businessSnapshot() === $originalBusiness, 'Retry changed unrelated business data.');

    // 全部43条归属变化时，异常列表必须按20/20/3稳定分页，越界页回落到末页。
    $pageUids = [];
    for ($i = 201; $i <= 243; ++$i) $pageUids[] = $newMember($i);
    $pages = $service->create($source, $target, '分页边界回滚验证', 43, 'pages-' . $tag, $admin);
    $pageNo = $pages['batch_no']; $batchNos[] = $pageNo;
    Db::name('user')->whereIn('uid', $pageUids)->update(['belong_store_id' => $stores['other']]);
    $service->processChunk($pageNo);
    $page1 = $service->status($pageNo, $admin, 1);
    $page2 = $service->status($pageNo, $admin, 2);
    $page3 = $service->status($pageNo, $admin, 99);
    $assert($page1['exception_total'] === 43 && count($page1['exceptions']) === 20 && count($page2['exceptions']) === 20
        && count($page3['exceptions']) === 3 && $page3['exception_page'] === 3, 'Exception pagination did not return 20/20/3.');
    $allPageIds = array_merge(array_column($page1['exceptions'], 'uid'), array_column($page2['exceptions'], 'uid'), array_column($page3['exceptions'], 'uid'));
    $assert(array_map('intval', $allPageIds) === $pageUids, 'Exception pages lost, duplicated or reordered members.');

    // 未知异常不能留下本批任何写入；标记受阻后仍沿原名单恢复。
    $crashUid = $newMember(301);
    $crash = $service->create($source, $target, '未知故障回滚验证', 1, 'crash-' . $tag, $admin);
    $crashNo = $crash['batch_no']; $batchNos[] = $crashNo;
    $crashingService = new class extends UserStoreTransferBatchServices {
        protected function transferMember(array $item, array $batch): array {
            parent::transferMember($item, $batch);
            throw new RuntimeException('模拟底层写入后故障');
        }
    };
    try { $crashingService->processChunk($crashNo); throw new LogicException('Missing unknown error'); }
    catch (RuntimeException $error) { $assert($error->getMessage() === '模拟底层写入后故障', 'Unexpected error did not propagate.'); }
    $assert((int)Db::name('user')->where('uid', $crashUid)->value('belong_store_id') === $source
        && (int)Db::name('user_belong_store')->where('uid', $crashUid)->count() === 0, 'Unknown fault retained partial user/history writes.');
    $assert($service->status($crashNo, $admin)['status'] === 'queued'
        && (int)$service->status($crashNo, $admin)['success_count'] === 0, 'Unknown fault retained partial batch progress.');
    $service->block($crashNo, '本地故障模拟');
    $service->resume($crashNo, $admin);
    (new UserStoreTransferBatchJob())->doJob($crashNo);
    $assert($service->status($crashNo, $admin)['status'] === 'completed', 'Unknown fault recovery failed.');
} finally {
    Db::rollback();
}
$assert(Db::name('user')->where('uid', $memberId)->find() == $real, 'Rollback did not restore real member.');
$assert($businessSnapshot() === $originalBusiness, 'Rollback left unrelated business changes.');
$assert((int)Db::name(UserStoreTransferBatchServices::TABLE)->whereIn('batch_no', $batchNos)->count() === 0, 'Rollback left batch fixtures.');
$assert((int)Db::name('system_store')->where('id', $source)->count() === 0, 'Rollback left store fixtures.');
echo 'R26_BULK_TRANSFER=PASS checks=' . $checks . PHP_EOL;

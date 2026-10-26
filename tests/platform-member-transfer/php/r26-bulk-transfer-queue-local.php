<?php
declare(strict_types=1);

/** 仅本地隔离夹具：真实队列重复投递55人批次，验证跨消息执行与幂等，最后精确清理夹具。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\user\UserStoreTransferBatchServices;

set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); });
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local ruihao only.');
$tag = 'R26-QUEUE-' . bin2hex(random_bytes(6));
$uids = []; $stores = []; $no = '';
$service = new UserStoreTransferBatchServices();
$admin = (int)Db::name('system_admin')->where('status', 1)->where('is_del', 0)->value('id');
$check = static function (bool $okay, string $message): void { if (!$okay) throw new RuntimeException($message); };
try {
    Db::transaction(function () use (&$uids, &$stores, $tag): void {
        $template = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->find();
        unset($template['id']);
        foreach (['source', 'target'] as $name) {
            $row = $template; $row['name'] = $tag . '-' . $name;
            $stores[] = (int)Db::name('system_store')->insertGetId($row);
        }
        $template = Db::name('user')->where('uid', 1030289)->find();
        unset($template['uid']);
        for ($i = 0; $i < 55; ++$i) {
            $row = $template;
            $row['real_name'] = $tag . '-' . $i; $row['nickname'] = $row['real_name'];
            $row['phone'] = ''; $row['belong_store_id'] = $stores[0]; $row['is_del'] = 0; $row['delete_time'] = null;
            $row['now_money'] = 0; $row['ben_money'] = 0; $row['give_money'] = 0;
            $uids[] = (int)Db::name('user')->insertGetId($row);
        }
    });
    $batch = $service->create($stores[0], $stores[1], '本地队列隔离验证', 55, $tag, $admin);
    $no = $batch['batch_no'];
    // 重复消息由真实多个worker竞争同一批次锁，不能重复修改或重复记历史。
    $service->enqueue($no); $service->enqueue($no);
    $deadline = microtime(true) + 45;
    do {
        usleep(300000);
        $status = $service->status($no, $admin);
        if (in_array($status['status'], ['completed', 'completed_with_exceptions', 'blocked'], true)) break;
    } while (microtime(true) < $deadline);
    $check($status['status'] === 'completed' && (int)$status['success_count'] === 55, 'Queue did not finish: ' . json_encode($status, JSON_UNESCAPED_UNICODE));
    $check((int)Db::name('user')->whereIn('uid', $uids)->where('belong_store_id', $stores[1])->count() === 55, 'Queue lost members.');
    $check((int)Db::name('user_belong_store')->whereIn('uid', $uids)->count() === 55, 'Duplicate jobs created duplicate history.');
    $check((int)Db::name('cashier_v3_report_member_store_assignment_period')->whereIn('member_id', $uids)->count() === 55, 'Assignment facts lost or duplicated.');
    echo 'R26_REAL_QUEUE=PASS members=55 messages=duplicate history=55 periods=55' . PHP_EOL;
} finally {
    // 先锁批次再清理，若worker尚在运行则等待它提交，避免清理过程中被继续写入。
    Db::transaction(function () use ($uids, $stores, $no): void {
        if ($no !== '') {
            Db::name(UserStoreTransferBatchServices::TABLE)->where('batch_no', $no)->lock(true)->find();
            Db::name(UserStoreTransferBatchServices::ITEMS)->where('batch_no', $no)->delete();
            Db::name(UserStoreTransferBatchServices::TABLE)->where('batch_no', $no)->delete();
        }
        if ($uids) {
            Db::name('cashier_v3_report_member_store_assignment_period')->whereIn('member_id', $uids)->delete();
            Db::name('user_belong_store')->whereIn('uid', $uids)->delete();
            Db::name('user')->whereIn('uid', $uids)->delete();
        }
        if ($stores) Db::name('system_store')->whereIn('id', $stores)->delete();
    });
}
$check((int)Db::name('user')->whereIn('uid', $uids)->count() === 0, 'Synthetic members not cleaned.');
$check((int)Db::name('system_store')->whereIn('id', $stores)->count() === 0, 'Synthetic stores not cleaned.');
echo 'R26_QUEUE_FIXTURE_CLEANUP=PASS' . PHP_EOL;

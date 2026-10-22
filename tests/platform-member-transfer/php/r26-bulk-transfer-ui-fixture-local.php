<?php
declare(strict_types=1);

/** 本地浏览器联调专用夹具；seed只建合成资料，verify-cleanup严格核验后清理指定UID。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\user\UserStoreTransferBatchServices;
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); });
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local only.');
$file = '/tmp/r26-member-transfer-ui.json';
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    if (file_exists($file)) throw new RuntimeException('Previous fixture must be verified and cleaned first.');
    $fixture = Db::transaction(static function (): array {
        $store = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->find(); unset($store['id']);
        $ids = [];
        foreach (['R26批量调店验收原店', 'R26批量调店验收目标店'] as $name) {
            $store['name'] = $name; $ids[] = (int)Db::name('system_store')->insertGetId($store);
        }
        $template = Db::name('user')->where('uid', 1030289)->find(); unset($template['uid']);
        $uids = [];
        for ($i = 0; $i < 3; ++$i) {
            $row = $template; $row['real_name'] = 'R26浏览器测试会员' . $i; $row['nickname'] = $row['real_name'];
            $row['phone'] = ''; $row['belong_store_id'] = $ids[0]; $row['is_del'] = 0; $row['delete_time'] = null;
            $row['ben_money'] = 0; $row['give_money'] = 0; $row['now_money'] = 0;
            $uids[] = (int)Db::name('user')->insertGetId($row);
        }
        return ['stores' => $ids, 'uids' => $uids];
    });
    file_put_contents($file, json_encode($fixture));
    echo 'R26_UI_FIXTURE=READY members=3' . PHP_EOL;
} elseif ($mode === 'verify-cleanup') {
    if (!file_exists($file)) throw new RuntimeException('No fixture.');
    $fixture = json_decode(file_get_contents($file), true);
    $uids = $fixture['uids']; $stores = $fixture['stores'];
    $batch = Db::name(UserStoreTransferBatchServices::TABLE)->where('source_store_id', $stores[0])->find();
    if (!$batch || $batch['status'] !== 'completed' || (int)$batch['success_count'] !== 3) throw new RuntimeException('UI batch not completed; keep fixture for diagnosis.');
    if ((int)Db::name('user')->whereIn('uid', $uids)->where('belong_store_id', $stores[1])->count() !== 3
        || (int)Db::name('user_belong_store')->whereIn('uid', $uids)->where('type', 'admin_transfer')->count() !== 3) throw new RuntimeException('UI facts mismatch.');
    foreach (['store_user' => 'uid', 'user_money' => 'uid', 'user_card_holder' => 'uid', 'store_order' => 'uid'] as $table => $column) {
        if (Db::name($table)->whereIn($column, $uids)->count()) throw new RuntimeException('Unexpected related business write: ' . $table);
    }
    Db::transaction(static function () use ($uids, $stores, $batch): void {
        Db::name(UserStoreTransferBatchServices::TABLE)->where('batch_no', $batch['batch_no'])->lock(true)->find();
        Db::name(UserStoreTransferBatchServices::ITEMS)->where('batch_no', $batch['batch_no'])->delete();
        Db::name(UserStoreTransferBatchServices::TABLE)->where('batch_no', $batch['batch_no'])->delete();
        Db::name('cashier_v3_report_member_store_assignment_period')->whereIn('member_id', $uids)->delete();
        Db::name('user_belong_store')->whereIn('uid', $uids)->delete();
        Db::name('user')->whereIn('uid', $uids)->delete();
        Db::name('system_store')->whereIn('id', $stores)->delete();
    });
    unlink($file);
    echo 'R26_BROWSER_API_QUEUE=PASS members=3 unrelated_business_writes=0 fixture_cleanup=PASS' . PHP_EOL;
} else {
    throw new RuntimeException('Expected seed or verify-cleanup.');
}

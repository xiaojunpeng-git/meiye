<?php
/**
 * 仅在本地回归库运行：事务内把有效任职的历史门店端个人入口设为关闭，
 * 验证岗位仍授予登录/功能；再设为离岗，验证两处立即拒绝，最终回滚全部改动。
 */
require '/var/www/html/vendor/autoload.php';

$app = new think\App('/var/www/html/');
$app->initialize();

use app\services\employee\EmployeeInternalLoginServices;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\organization\StaffJobPositionServices;
use think\facade\Db;

$login = app()->make(EmployeeInternalLoginServices::class);
$features = app()->make(CashierV3FeatureResolver::class);
$positions = app()->make(StaffJobPositionServices::class);

// 旧调用者即使仍提交 store_v3 个人开关，也不得重新写活这条授权路径。
try {
    $positions->normalizeChannelEntries([['channel' => 'store_v3', 'status' => 0]]);
    throw new RuntimeException('门店端个人入口写入未被拒绝');
} catch (\mohe\exceptions\AdminException $expected) {
    // 只有手机端个人入口仍可保存。
}
if ($positions->normalizeChannelEntries([['channel' => 'mobile', 'status' => 0]]) !== ['mobile' => 0]) {
    throw new RuntimeException('商家端个人入口兼容能力意外改变');
}

// 只选本地已有的有效任职和有效岗位，不创建员工、岗位或真实业务记录。
$candidateRows = Db::name('staff_job_position')->alias('job')
    ->join('system_store_staff staff', 'staff.id = job.staff_id')
    ->join('position position', 'position.id = job.position_id')
    ->join('job_position_channel_rule rule', 'rule.position_id = job.position_id')
    ->where('job.status', 1)->where('job.is_del', 0)->where('job.end_time', 0)
    ->where('staff.status', 1)->where('staff.is_del', 0)
    ->where('position.status', 1)->where('position.use_store', 1)
    ->where('rule.channel', 'store_v3')->where('rule.status', 1)
    ->where('rule.rules', '<>', '')
    ->field('staff.id AS staff_id,staff.employee_id,staff.store_id')
    ->limit(100)->select()->toArray();
$candidate = null;
foreach ($candidateRows as $row) {
    $activeTenures = (int)Db::name('system_store_staff')
        ->where('employee_id', (int)$row['employee_id'])
        ->where('status', 1)->where('is_del', 0)->where('store_id', '>', 0)->count();
    if ($activeTenures === 1
        && $login->staffHasChannelEntry((int)$row['staff_id'], 'store_v3')
        && $features->resolveGrantedFeatures($row)) {
        $candidate = $row;
        break;
    }
}
if (!$candidate) {
    throw new RuntimeException('本地回归库没有有效门店端岗位任职，无法执行事务性验证');
}

$staffId = (int)$candidate['staff_id'];
$employeeId = (int)$candidate['employee_id'];
Db::startTrans();
try {
    $entry = Db::name('staff_channel_entry')
        ->where('staff_id', $staffId)->where('channel', 'store_v3')->lock(true)->find();
    if ($entry) {
        Db::name('staff_channel_entry')->where('id', (int)$entry['id'])
            ->update(['status' => 0, 'is_del' => 0]);
    } else {
        Db::name('staff_channel_entry')->insert([
            'staff_id' => $staffId,
            'employee_id' => $employeeId,
            'store_id' => (int)$candidate['store_id'],
            'channel' => 'store_v3',
            'status' => 0,
            'is_del' => 0,
            'request_token' => 'local-rollback-' . bin2hex(random_bytes(8)),
            'operator_id' => 0,
            'operator_name' => 'local-contract',
            'add_time' => time(),
            'update_time' => time(),
        ]);
    }
    $direct = $login->resolveUniqueStoreV3Staff($employeeId);
    if ((int)($direct['id'] ?? 0) !== $staffId
        || !$login->staffHasChannelEntry($staffId, 'store_v3')
        || !$features->resolveGrantedFeatures($candidate)) {
        throw new RuntimeException('历史个人入口关闭仍否决了有效岗位');
    }

    Db::name('system_store_staff')->where('id', $staffId)->update(['status' => 0]);
    $eligible = $login->listEligibleStoreV3Staff($employeeId);
    $stillEligible = array_filter($eligible, static function (array $row) use ($staffId): bool {
        return (int)$row['id'] === $staffId;
    });
    if ($stillEligible || $login->resolveUniqueStoreV3Staff($employeeId)
        || $features->resolveGrantedFeatures($candidate)) {
        throw new RuntimeException('离岗任职仍能登录或获得门店端功能');
    }
    echo 'PASS store_v3_legacy_entry_ignored_and_off_duty_denied staff_id=' . $staffId . PHP_EOL;
} finally {
    Db::rollback();
}

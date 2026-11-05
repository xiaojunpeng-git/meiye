<?php
declare(strict_types=1);

/**
 * 瑞昊一次性班次补齐：把肥西水晶城已确认的“全天”模板作为输入，给其余可见门店各建独立模板。
 * 仅新增缺失模板，保留原班次和员工已安排的日期快照；写入经过排班命令及其幂等审计。
 * 默认只读预检，只有 RH_ROSTER_APPLY=1 时执行。脚本通过目标站点根目录的 PHP STDIN 运行。
 */
$root = getcwd();
if (basename($root) !== 'rh.cc3798.com') throw new RuntimeException('只允许在瑞昊站点根目录运行');
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();

use app\services\store\roster\StaffRosterServices;
use think\facade\Db;

if ((string)config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('目标数据库身份不符');
}

/** 目标集合固定为当前瑞昊可见且未删除的门店；数量变化时停止，避免误扩范围。 */
function targetStores(): array
{
    $rows = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)
        ->field('id,name')->order('id asc')->select()->toArray();
    if (count($rows) !== 102) throw new RuntimeException('门店集合已变化，停止批量生成');
    return $rows;
}

/** 原模板身份与业务内容必须同时匹配，不能靠截图文字猜测后续改动。 */
function sourceShift(bool $lock = false): array
{
    $query = Db::name('store_staff_shift')->where('id', 8)->where('store_id', 133)->where('is_del', 0);
    if ($lock) $query->lock(true);
    $row = $query->find();
    if (!$row || $row['name'] !== '全天' || substr((string)$row['start_time'], 0, 5) !== '07:00'
        || substr((string)$row['end_time'], 0, 5) !== '22:00' || trim((string)$row['break_periods']) !== '') {
        throw new RuntimeException('肥西水晶城来源班次已变化');
    }
    return $row;
}

/** 同名异时长不自动覆盖，交给产品经理处理；同名同时间视为已生成。 */
function shiftState(int $storeId): string
{
    $rows = Db::name('store_staff_shift')->where('store_id', $storeId)->where('name', '全天')
        ->where('is_del', 0)->select()->toArray();
    if (count($rows) > 1) return 'conflict';
    if (!$rows) return 'missing';
    $row = $rows[0];
    return substr((string)$row['start_time'], 0, 5) === '07:00'
        && substr((string)$row['end_time'], 0, 5) === '22:00'
        && trim((string)$row['break_periods']) === '' ? 'exact' : 'conflict';
}

$stores = targetStores();
sourceShift();
if (Db::name('store_staff_shift')->where('store_id', 0)->where('name', '全天')->where('is_del', 0)->count()) {
    throw new RuntimeException('存在集团共用同名班次，停止重复生成');
}
$states = ['exact' => 0, 'missing' => 0, 'conflict' => 0];
foreach ($stores as $store) $states[shiftState((int)$store['id'])]++;
if ($states['conflict']) throw new RuntimeException('存在同名不同时间的门店班次，停止批量生成');
$scheduleBefore = Db::name('store_staff_schedule')->order('id asc')->select()->toArray();
echo json_encode(['phase' => 'preflight', 'stores' => count($stores), 'states' => $states,
    'schedule_rows' => count($scheduleBefore)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
if (getenv('RH_ROSTER_APPLY') !== '1') return;

$result = Db::transaction(static function () use ($scheduleBefore): array {
    $stores = targetStores();
    sourceShift(true);
    if (Db::name('store_staff_shift')->where('store_id', 0)->where('name', '全天')->where('is_del', 0)->count()) {
        throw new RuntimeException('运行期间出现集团共用同名班次');
    }
    $service = new StaffRosterServices();
    $created = [];
    foreach ($stores as $store) {
        $storeId = (int)$store['id'];
        // 与页面命令使用相同的门店锁，锁后重新查重，保证并发创建不会产生重复模板。
        if (!Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->lock(true)->find()) {
            throw new RuntimeException('目标门店在运行期间被删除');
        }
        $state = shiftState($storeId);
        if ($state === 'conflict') throw new RuntimeException('运行期间出现同名班次冲突');
        if ($state === 'exact') continue;
        $saved = $service->command($storeId, ['type' => 'SYSTEM', 'id' => 0], 'shift', [
            'name' => '全天', 'start_time' => '07:00', 'end_time' => '22:00',
            'request_token' => 'rh-day-shift-20261105-' . $storeId,
        ]);
        if (shiftState($storeId) !== 'exact') throw new RuntimeException('班次命令未生成目标模板');
        $created[] = ['store_id' => $storeId, 'shift_id' => (int)($saved['id'] ?? 0)];
    }
    // 仅新增模板；已有员工排班记录必须逐字段保持一致。
    $scheduleAfter = Db::name('store_staff_schedule')->order('id asc')->select()->toArray();
    if ($scheduleAfter !== $scheduleBefore) throw new RuntimeException('员工排班记录发生非预期变更');
    $totalExact = 0;
    foreach ($stores as $store) if (shiftState((int)$store['id']) === 'exact') $totalExact++;
    if ($totalExact !== count($stores)) throw new RuntimeException('仍有门店缺少目标班次');
    return ['created' => count($created), 'total_exact' => $totalExact,
        'schedule_rows' => count($scheduleAfter), 'created_rows' => $created];
});
echo json_encode(['phase' => 'applied'] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";

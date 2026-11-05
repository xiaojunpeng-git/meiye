<?php
namespace app\services\store;

use app\dao\store\StoreStaffShiftDao;
use app\services\BaseServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 门店员工班次模板
 * @mixin StoreStaffShiftDao
 */
class StoreStaffShiftServices extends BaseServices
{
    public function __construct(StoreStaffShiftDao $dao)
    {
        $this->dao = $dao;
    }

    public function getList(int $storeId): array
    {
        $list = $this->dao->getStoreShiftList($storeId);
        if (!$list) {
            return [];
        }
        $shiftIds = array_column($list, 'id');
        $usageMap = [];
        if ($shiftIds) {
            $rows = Db::name('store_staff_schedule')
                ->where('store_id', $storeId)
                ->whereIn('shift_id', $shiftIds)
                ->where('schedule_type', 1)
                ->field('shift_id, count(distinct staff_id) as cnt')
                ->group('shift_id')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $usageMap[(int)$row['shift_id']] = (int)$row['cnt'];
            }
        }
        foreach ($list as &$item) {
            $item['id'] = (int)$item['id'];
            $item['store_id'] = (int)$item['store_id'];
            $item['usage_count'] = $usageMap[(int)$item['id']] ?? 0;
            $item['break_periods'] = $this->decodeBreakPeriods($item['break_periods'] ?? '');
        }
        unset($item);
        return $list;
    }

    /** 旧入口同样冻结已有排班；不能因仍有旧调用方而破坏新Vue3排班快照。 */
    public function saveShift(int $storeId, array $data): array
    {
        return Db::transaction(fn() => $this->saveShiftInTransaction($storeId, $data));
    }

    private function saveShiftInTransaction(int $storeId, array $data): array
    {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $startTime = trim((string)($data['start_time'] ?? ''));
        $endTime = trim((string)($data['end_time'] ?? ''));
        if ($name === '') {
            throw new ValidateException('请输入班次名称');
        }
        if ($startTime === '' || $endTime === '') {
            throw new ValidateException('请选择班次时间');
        }
        $breakPeriods = $data['break_periods'] ?? [];
        if (!is_array($breakPeriods)) {
            $breakPeriods = [];
        }
        $applyAll = (int)($data['apply_all_stores'] ?? 0);
        $save = [
            'store_id' => $applyAll ? 0 : $storeId,
            'name' => $name,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'break_periods' => $breakPeriods ? json_encode($breakPeriods, JSON_UNESCAPED_UNICODE) : '',
            'sort' => (int)($data['sort'] ?? 0),
            'update_time' => time(),
        ];
        if ($id) {
            $row = Db::name('store_staff_shift')->where('id', $id)->lock(true)->find();
            if (!$row || (int)$row['is_del'] === 1) {
                throw new ValidateException('班次不存在');
            }
            if ((int)$row['store_id'] !== 0 && (int)$row['store_id'] !== $storeId) {
                throw new ValidateException('无权编辑该班次');
            }
            $this->freezeAssignments($row);
            $this->dao->update($id, $save);
            return ['id' => $id];
        }
        $save['is_del'] = 0;
        $save['add_time'] = time();
        $res = $this->dao->save($save);
        return ['id' => (int)$res->id];
    }

    /** 删除模板只停用模板，永久保留已有日期上的员工安排。 */
    public function deleteShift(int $storeId, int $id): bool
    {
        return Db::transaction(fn() => $this->deleteShiftInTransaction($storeId, $id));
    }

    private function deleteShiftInTransaction(int $storeId, int $id): bool
    {
        $row = Db::name('store_staff_shift')->where('id', $id)->lock(true)->find();
        if (!$row || (int)$row['is_del'] === 1) {
            throw new ValidateException('班次不存在');
        }
        if ((int)$row['store_id'] !== 0 && (int)$row['store_id'] !== $storeId) {
            throw new ValidateException('无权删除该班次');
        }
        $this->freezeAssignments($row);
        $this->dao->update($id, ['is_del' => 1, 'update_time' => time()]);
        return true;
    }

    /** 只补尚无快照的旧引用；新排班已有快照时绝不覆盖其已确认时间。 */
    private function freezeAssignments($row): void
    {
        Db::name('store_staff_schedule')->where('shift_id', (int)$row['id'])->where('schedule_type', 1)->where('shift_name_snapshot', '')
            ->update(['shift_name_snapshot' => $row['name'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time'], 'breaks_snapshot' => $row['break_periods']]);
    }

    public function decodeBreakPeriods($raw): array
    {
        if (!$raw) {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getShiftMap(int $storeId): array
    {
        $list = $this->dao->getStoreShiftList($storeId);
        $map = [];
        foreach ($list as $item) {
            $map[(int)$item['id']] = $item;
        }
        return $map;
    }
}

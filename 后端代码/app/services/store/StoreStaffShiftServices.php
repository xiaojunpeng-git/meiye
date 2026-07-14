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

    public function saveShift(int $storeId, array $data): array
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
            $row = $this->dao->get($id);
            if (!$row || (int)$row['is_del'] === 1) {
                throw new ValidateException('班次不存在');
            }
            if ((int)$row['store_id'] !== 0 && (int)$row['store_id'] !== $storeId) {
                throw new ValidateException('无权编辑该班次');
            }
            $this->dao->update($id, $save);
            return ['id' => $id];
        }
        $save['is_del'] = 0;
        $save['add_time'] = time();
        $res = $this->dao->save($save);
        return ['id' => (int)$res->id];
    }

    public function deleteShift(int $storeId, int $id): bool
    {
        $row = $this->dao->get($id);
        if (!$row || (int)$row['is_del'] === 1) {
            throw new ValidateException('班次不存在');
        }
        if ((int)$row['store_id'] !== 0 && (int)$row['store_id'] !== $storeId) {
            throw new ValidateException('无权删除该班次');
        }
        $this->dao->update($id, ['is_del' => 1, 'update_time' => time()]);
        Db::name('store_staff_schedule')->where('shift_id', $id)->where('store_id', $storeId)->delete();
        return true;
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

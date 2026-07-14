<?php
namespace app\dao\store;

use app\dao\BaseDao;
use app\model\store\StoreStaffSchedule;

class StoreStaffScheduleDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreStaffSchedule::class;
    }

    public function getByStoreDate(int $storeId, string $date, int $staffId = 0): array
    {
        $query = \think\facade\Db::name('store_staff_schedule')
            ->where('store_id', $storeId)
            ->where('schedule_date', $date);
        if ($staffId) {
            $query->where('staff_id', $staffId);
        }
        return $query->order('id asc')->select()->toArray();
    }

    public function getByStoreMonth(int $storeId, string $month, int $staffId = 0): array
    {
        $start = date('Y-m-01', strtotime($month));
        $end = date('Y-m-t', strtotime($month));
        $query = \think\facade\Db::name('store_staff_schedule')
            ->where('store_id', $storeId)
            ->whereBetween('schedule_date', [$start, $end]);
        if ($staffId) {
            $query->where('staff_id', $staffId);
        }
        return $query->order('schedule_date asc,id asc')->select()->toArray();
    }
}

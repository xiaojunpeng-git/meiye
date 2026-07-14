<?php
namespace app\dao\store;

use app\dao\BaseDao;
use app\model\store\StoreStaffShift;

class StoreStaffShiftDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreStaffShift::class;
    }

    public function getStoreShiftList(int $storeId): array
    {
        return \think\facade\Db::name('store_staff_shift')
            ->where('is_del', 0)
            ->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->whereOr('store_id', 0);
            })
            ->order('sort asc,id asc')
            ->select()
            ->toArray();
    }
}

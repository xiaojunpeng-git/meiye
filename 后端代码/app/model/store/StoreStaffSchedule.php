<?php
namespace app\model\store;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 门店员工排班/休息
 */
class StoreStaffSchedule extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_staff_schedule';
    /** 表内 add_time/update_time 为 int 时间戳，关闭 ORM 自动格式化避免 DateTime 报错 */
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;

    public function searchStoreIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('store_id', $value);
        }
    }

    public function searchStaffIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('staff_id', $value);
        }
    }

    public function searchScheduleDateAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('schedule_date', $value);
        }
    }

    public function searchScheduleTypeAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('schedule_type', $value);
        }
    }
}

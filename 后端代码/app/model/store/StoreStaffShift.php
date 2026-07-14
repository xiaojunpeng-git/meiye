<?php
namespace app\model\store;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 门店员工班次模板
 */
class StoreStaffShift extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_staff_shift';
    /** 表内 add_time/update_time 为 int 时间戳，关闭 ORM 自动格式化避免 DateTime 报错 */
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;

    public function searchStoreIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where(function ($q) use ($value) {
                $q->where('store_id', $value)->whereOr('store_id', 0);
            });
        }
    }

    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('is_del', $value);
        }
    }
}

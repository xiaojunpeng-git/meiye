<?php

namespace app\model\order;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class StoreDebtItem extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_debt_item';

    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'add_time';
    protected $updateTime = 'update_time';
    protected $dateFormat = false;

    public function searchDebtIdAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('debt_id', $value);
        } else {
            $query->where('debt_id', $value);
        }
    }
}

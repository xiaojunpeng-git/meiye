<?php

namespace app\model\order;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class StoreDebtRepay extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_debt_repay';

    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'add_time';
    protected $updateTime = false;
    protected $dateFormat = false;

    public function searchUidAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('uid', $value);
        } else {
            $query->where('uid', $value);
        }
    }

    public function searchDebtIdAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('debt_id', $value);
        } else {
            $query->where('debt_id', $value);
        }
    }

    public function searchPayStoreIdAttr($query, $value)
    {
        if ($value === '' || $value === null) {
            return;
        }
        if (is_array($value)) {
            $query->whereIn('pay_store_id', $value);
        } else {
            $query->where('pay_store_id', $value);
        }
    }

    public function searchAddTimeAttr($query, $value)
    {
        if (!is_array($value) || count($value) < 2) {
            return;
        }
        if ($value[0] && $value[1]) {
            $query->whereBetween('add_time', [$value[0], $value[1]]);
        }
    }
}

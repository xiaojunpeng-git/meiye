<?php

namespace app\model\order;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class StoreDebt extends BaseModel
{
    use ModelTrait;

    public const STATUS_PENDING = 0;
    public const STATUS_SETTLED = 1;
    public const STATUS_CLOSED = 2;
    public const STATUS_VOID = 3;

    protected $pk = 'id';
    protected $name = 'store_debt';

    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'add_time';
    protected $updateTime = 'update_time';
    protected $dateFormat = false;

    public function searchUidAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('uid', $value);
        } else {
            $query->where('uid', $value);
        }
    }

    public function searchStoreIdAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('store_id', $value);
        } else {
            $query->where('store_id', $value);
        }
    }

    public function searchStatusAttr($query, $value)
    {
        if ($value === '' || $value === null) {
            return;
        }
        if (is_array($value)) {
            $query->whereIn('status', $value);
        } else {
            $query->where('status', $value);
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

    public function searchKeywordAttr($query, $value)
    {
        if ($value === '' || $value === null) {
            return;
        }
        $uids = \app\model\user\User::whereLike('nickname|phone|real_name', '%' . $value . '%')->column('uid');
        if ($uids) {
            $query->whereIn('uid', $uids);
        } else {
            $query->where('uid', -1);
        }
    }
}

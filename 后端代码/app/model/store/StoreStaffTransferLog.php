<?php
namespace app\model\store;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 店员调店记录
 */
class StoreStaffTransferLog extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_staff_transfer_log';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;

    public function searchStaffIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null && (int)$value > 0) {
            $query->where('staff_id', (int)$value);
        }
    }

    public function searchFromStoreIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null && (int)$value > 0) {
            $query->where('from_store_id', (int)$value);
        }
    }

    public function searchToStoreIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null && (int)$value > 0) {
            $query->where('to_store_id', (int)$value);
        }
    }

    public function searchInvolveStoreIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null && (int)$value > 0) {
            $storeId = (int)$value;
            $query->where(function ($q) use ($storeId) {
                $q->where('from_store_id', $storeId)->whereOr('to_store_id', $storeId);
            });
        }
    }

    public function searchDataAttr($query, $value)
    {
        if ($value === '' || $value === null) {
            return;
        }
        if (is_array($value) && count($value) === 2) {
            [$start, $end] = $value;
        } else {
            $parts = explode('-', (string)$value);
            if (count($parts) < 2) {
                return;
            }
            $start = trim($parts[0]);
            $end = trim($parts[1]);
        }
        $startTime = $start ? strtotime($start) : 0;
        $endTime = $end ? strtotime($end) : 0;
        if ($endTime && date('Y-m-d', $endTime) === $end) {
            $endTime += 86399;
        }
        if ($startTime && $endTime) {
            $query->whereBetween('add_time', [$startTime, $endTime]);
        } elseif ($startTime) {
            $query->where('add_time', '>=', $startTime);
        } elseif ($endTime) {
            $query->where('add_time', '<=', $endTime);
        }
    }
}

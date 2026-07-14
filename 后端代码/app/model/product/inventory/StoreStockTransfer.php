<?php
declare(strict_types=1);

namespace app\model\product\inventory;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 库存调拨单
 */
class StoreStockTransfer extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_stock_transfer';

    public function details()
    {
        return $this->hasMany(StoreStockTransferDetail::class, 'transfer_id', 'id');
    }

    public function searchOrderSnAttr($query, $value)
    {
        if ($value === '' || $value === null) {
            return;
        }
        $value = trim((string)$value);
        if (preg_match('/^(RQ|TF|TFR)[0-9A-Za-z]+$/', $value)) {
            if (strlen($value) >= 16) {
                $query->where('order_sn', $value);
            } else {
                $query->whereLike('order_sn', $value . '%');
            }
            return;
        }
        $query->whereLike('order_sn', '%' . $value . '%');
    }

    public function searchStatusAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', (int)$value);
        }
    }

    public function searchRequestIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null && (int)$value > 0) {
            $query->where('request_id', (int)$value);
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

    public function searchKeywordAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->whereLike('order_sn|remark', '%' . $value . '%');
        }
    }

    /** 门店端：只能看自己作为调出或调入方的单 */
    public function searchStoreScopeAttr($query, $value)
    {
        $storeId = (int)$value;
        if ($storeId > 0) {
            $query->where(function ($q) use ($storeId) {
                $q->where('from_store_id', $storeId)->whereOr('to_store_id', $storeId);
            });
        }
    }
}

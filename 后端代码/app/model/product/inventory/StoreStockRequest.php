<?php
declare(strict_types=1);

namespace app\model\product\inventory;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 库存请货单
 */
class StoreStockRequest extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_stock_request';

    public function details()
    {
        return $this->hasMany(StoreStockRequestDetail::class, 'request_id', 'id');
    }

    public function searchOrderSnAttr($query, $value)
    {
        if ($value === '' || $value === null) {
            return;
        }
        $value = trim((string)$value);
        // 完整业务单号（RQ/TF 前缀）走精确或前缀索引友好查询
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

    public function searchRequestStoreIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null && (int)$value > 0) {
            $query->where('request_store_id', (int)$value);
        }
    }

    public function searchSupplyStoreIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null && (int)$value > 0) {
            $query->where('supply_store_id', (int)$value);
        }
    }

    public function searchKeywordAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->whereLike('order_sn|remark|reject_reason', '%' . $value . '%');
        }
    }

    /** 门店端：只能看自己作为请货或供货方的单 */
    public function searchStoreScopeAttr($query, $value)
    {
        $storeId = (int)$value;
        if ($storeId > 0) {
            $query->where(function ($q) use ($storeId) {
                $q->where('request_store_id', $storeId)->whereOr('supply_store_id', $storeId);
            });
        }
    }
}

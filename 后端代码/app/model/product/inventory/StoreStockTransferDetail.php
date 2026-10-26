<?php
declare(strict_types=1);

namespace app\model\product\inventory;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class StoreStockTransferDetail extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_stock_transfer_detail';

    public function searchTransferIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null && (int)$value > 0) {
            $query->where('transfer_id', (int)$value);
        }
    }
}

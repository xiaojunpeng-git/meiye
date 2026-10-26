<?php
declare(strict_types=1);

namespace app\model\product\inventory;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class StoreStockRequestDetail extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_stock_request_detail';

    public function searchRequestIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null && (int)$value > 0) {
            $query->where('request_id', (int)$value);
        }
    }
}

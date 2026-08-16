<?php

namespace app\model\report;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class CardSaleItemAllocationFact extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'cashier_v3_card_sale_item_allocation_fact';
    protected $autoWriteTimestamp = false;
}

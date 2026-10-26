<?php
declare(strict_types=1);

namespace app\model\order;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 作废后重新开单草稿（阶段 4 使用）
 */
class StoreOrderReopenDraft extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_order_reopen_draft';

    protected $autoWriteTimestamp = false;

    public const STATE_DRAFT = 0;
    public const STATE_LOADED = 1;
    public const STATE_CHECKED_OUT = 2;
    public const STATE_EXPIRED = 3;
}

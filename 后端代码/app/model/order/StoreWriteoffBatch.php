<?php

namespace app\model\order;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 批量核销业务主表
 */
class StoreWriteoffBatch extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';

    protected $name = 'store_writeoff_batch';

    /** 业务时间字段均为 int unix，关闭 ORM 自动时间戳转换 */
    protected $autoWriteTimestamp = false;
}

<?php
namespace app\model\target;

use mohe\traits\ModelTrait;
use mohe\basic\BaseModel;

/**
 * 门店目标员工分配
 * Class StoreTargetAllocate
 * @package app\model\target
 */
class StoreTargetAllocate extends BaseModel
{
    use ModelTrait;

    /** @var string */
    protected $pk = 'id';

    /** @var string */
    protected $name = 'store_target_allocate';

    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'add_time';

    protected $updateTime = 'update_time';
}

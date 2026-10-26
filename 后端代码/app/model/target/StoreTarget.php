<?php
namespace app\model\target;

use mohe\traits\ModelTrait;
use mohe\basic\BaseModel;

/**
 * 门店目标
 * Class StoreTarget
 * @package app\model\target
 */
class StoreTarget extends BaseModel
{
    use ModelTrait;

    /**
     * @var string
     */
    protected $pk = 'id';

    /**
     * @var string
     */
    protected $name = 'store_target';

    /** 时间戳字段为 int，避免 toArray 时按 datetime 字符串解析报错 */
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'add_time';

    protected $updateTime = 'update_time';
}

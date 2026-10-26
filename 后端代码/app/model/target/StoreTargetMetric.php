<?php
namespace app\model\target;

use mohe\traits\ModelTrait;
use mohe\basic\BaseModel;

/**
 * 门店目标核心指标
 * Class StoreTargetMetric
 * @package app\model\target
 */
class StoreTargetMetric extends BaseModel
{
    use ModelTrait;

    /**
     * @var string
     */
    protected $pk = 'id';

    /**
     * @var string
     */
    protected $name = 'store_target_metric';
}

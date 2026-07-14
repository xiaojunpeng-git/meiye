<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\model\order;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;


class DeliveryConfig extends BaseModel
{
    use ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'delivery_config';

    protected $updateTime = false;

    /**
     * 距离溢价设置
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function setDistancePremiumConfigAttr($value)
    {
        if ($value) {
            return is_array($value) ? json_encode($value) : $value;
        }
        return '';
    }

    /**
     * 距离溢价设置
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function getDistancePremiumConfigAttr($value)
    {
        if ($value) {
            return is_string($value) ? json_decode($value, true) : $value;
        }
        return [];
    }

    /**
     * 距离溢价设置
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function setWeightPremiumConfigAttr($value)
    {
        if ($value) {
            return is_array($value) ? json_encode($value) : $value;
        }
        return '';
    }

    /**
     * 距离溢价设置
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function getWeightPremiumConfigAttr($value)
    {
        if ($value) {
            return is_string($value) ? json_decode($value, true) : $value;
        }
        return [];
    }
}

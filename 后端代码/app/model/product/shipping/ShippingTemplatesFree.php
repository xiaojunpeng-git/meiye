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

namespace app\model\product\shipping;

use mohe\traits\ModelTrait;
use mohe\basic\BaseModel;
use think\Model;

/**
 * 包邮Model
 * Class ShippingTemplatesFree
 * @package app\model\product\shipping
 */
class ShippingTemplatesFree extends BaseModel
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
    protected $name = 'shipping_templates_free';

    /**
     * 城市ID搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchCityIdAttr($query, $value)
    {
        $query->where('city_id', $value);
    }

    /**
     * 模板id搜索
     * @param Model $query
     * @param $value
     */
    public function searchTempIdAttr($query, $value)
    {
        $query->where('temp_id', $value);
    }

    /**
     * uniqid 搜索器
     * @param Model $query
     * @param $value
     */
    public function searchUniqidAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('uniqid', $value);
        } else {
            $query->where('uniqid', $value);
        }
    }
}

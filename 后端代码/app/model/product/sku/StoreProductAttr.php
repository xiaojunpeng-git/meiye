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
namespace app\model\product\sku;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 *   商品属性Model
 * Class StoreProductAttr
 * @package app\common\model\product
 */
class StoreProductAttr extends BaseModel
{
    use ModelTrait;

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_product_attr';

    /**
     * 规格获取器
     * @param $value
     * @return false|string[]
     */
    protected function getAttrValuesAttr($value)
    {
        return explode(',', $value);
    }

    /**
     * 规格修改器
     * @param $value
     * @return string
     */
    protected function setAttrValuesAttr($value)
    {
        return is_array($value) ? implode(',', $value) : $value;
    }

    /**
     * 商品搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchProductIdAttr($query, $value)
    {
        $query->where('product_id', $value);
    }

    /**
     * 商品类型搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchTypeAttr($query, $value)
    {
        $query->where('type', $value);
    }
}

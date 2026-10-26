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

namespace app\model\product\product;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 *  商品详情Model
 * Class StoreDescription
 * @package app\model\product\product
 */
class StoreDescription extends BaseModel
{
    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_product_description';

    use ModelTrait;

    public function getDescriptionAttr($value)
    {
        return htmlspecialchars_decode($value);
    }

    /**
     * 商品ID搜索器
     * @param $query
     * @param $value
     */
    public function searchProductIdAttr($query, $value)
    {
        if ($value) $query->where('product_id', $value);
    }

    /**
     * 类型搜索器
     * @param $query
     * @param $value
     */
    public function searchTypeAttr($query, $value)
    {
        $query->where('type', $value);
    }
}

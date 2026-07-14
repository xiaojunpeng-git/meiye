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
 * Class StoreProductCoupon
 * @package app\model\product\product
 */
class StoreProductCoupon extends BaseModel
{
    use  ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_product_coupon';


    public function searchProductIdAttr($query, $value)
    {
        if(is_array($value))
            $query->whereIn('product_id',$value);
        else
            $query->where('product_id',$value);
    }

}

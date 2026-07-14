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
namespace app\model\activity\coupon;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 优惠券关联商品Model
 * Class StoreCouponProduct
 * @package app\model\activity\coupon
 */
class StoreCouponProduct extends BaseModel
{
    use ModelTrait;

    /**
     * 表名
     * @var string
     */
    protected $name = 'store_coupon_product';

}

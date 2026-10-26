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

namespace app\services\activity\coupon;


use app\dao\activity\coupon\StoreCouponUserCouponDao;
use app\services\BaseServices;

/**
 * 根据下单金额获取用户能使用的优惠卷
 * Class StoreCouponUserCouponServices
 * @package app\services\activity\coupon
 * @mixin StoreCouponUserCouponDao
 */
class StoreCouponUserCouponServices extends BaseServices
{
    /**
     * StoreCouponUserCouponServices constructor.
     * @param StoreCouponUserCouponDao $dao
     */
    public function __construct(StoreCouponUserCouponDao $dao)
    {
        $this->dao = $dao;
    }

}

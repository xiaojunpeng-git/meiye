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

namespace app\services\product\shipping;


use app\dao\product\shipping\ShippingTemplatesRegionCityDao;
use app\services\BaseServices;

/**
 * 根据地区设置邮费
 * Class ShippingTemplatesRegionCityServices
 * @package app\services\product\shipping
 * @mixin ShippingTemplatesRegionCityDao
 */
class ShippingTemplatesRegionCityServices extends BaseServices
{

    /**
     * 构造方法
     * ShippingTemplatesRegionCityServices constructor.
     * @param ShippingTemplatesRegionCityDao $dao
     */
    public function __construct(ShippingTemplatesRegionCityDao $dao)
    {
        $this->dao = $dao;
    }
}

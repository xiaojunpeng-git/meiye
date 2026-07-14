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


use app\dao\product\shipping\ShippingTemplatesFreeCityDao;
use app\services\BaseServices;

/**
 * 包邮和城市数据连表业务处理层
 * Class ShippingTemplatesFreeCityServices
 * @package app\services\product\shipping
 * @mixin ShippingTemplatesFreeCityDao
 */
class ShippingTemplatesFreeCityServices extends BaseServices
{
    /**
     * 构造方法
     * ShippingTemplatesFreeCityServices constructor.
     * @param ShippingTemplatesFreeCityDao $dao
     */
    public function __construct(ShippingTemplatesFreeCityDao $dao)
    {
        $this->dao = $dao;
    }
}

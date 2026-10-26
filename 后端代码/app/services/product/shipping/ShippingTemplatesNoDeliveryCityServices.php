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


use app\dao\product\shipping\ShippingTemplatesNoDeliveryCityDao;
use app\services\BaseServices;

/**
 * 不送达和城市数据连表业务处理层
 * Class ShippingTemplatesNoDeliveryCityServices
 * @package app\services\product\shipping
 * @mixin ShippingTemplatesNoDeliveryCityDao
 */
class ShippingTemplatesNoDeliveryCityServices extends BaseServices
{
    /**
     * 构造方法
     * ShippingTemplatesNoDeliveryCityServices constructor.
     * @param ShippingTemplatesNoDeliveryCityDao $dao
     */
    public function __construct(ShippingTemplatesNoDeliveryCityDao $dao)
    {
        $this->dao = $dao;
    }
}

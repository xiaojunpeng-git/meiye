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

namespace app\dao\order;


use app\dao\BaseDao;
use app\model\order\DeliveryConfig;

/**
 * 门店同城配送配置
 * Class DeliveryConfigDao
 * @package app\dao\order
 */
class DeliveryConfigDao extends BaseDao
{

    /**
     * @return string
     */
    protected function setModel(): string
    {
        return DeliveryConfig::class;
    }

}

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

namespace app\listener\product;


use mohe\interfaces\ListenerInterface;
use mohe\services\CacheService;

/**
 * Class ShippingUpdateSuccess
 * @package app\listener\product
 */
class ShippingUpdateSuccess implements ListenerInterface
{

    public function handle($event): void
    {
        CacheService::redisHandler('apiShipping')->clear();
    }
}

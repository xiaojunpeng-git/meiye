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

namespace app\listener\user;


use mohe\interfaces\ListenerInterface;
use mohe\services\CacheService;

/**
 * 更新收货地址事件
 * Class AddressUpdateSuccess
 * @package app\listener\user
 */
class AddressUpdateSuccess implements ListenerInterface
{

    public function handle($event): void
    {
        CacheService::redisHandler('userAdder')->clear();
    }
}

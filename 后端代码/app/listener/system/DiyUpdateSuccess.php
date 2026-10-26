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

namespace app\listener\system;


use mohe\interfaces\ListenerInterface;
use mohe\services\CacheService;

/**
 * diy更新事件
 * Class DiyUpdateSuccess
 * @package app\listener\system
 */
class DiyUpdateSuccess implements ListenerInterface
{

    public function handle($event): void
    {
        CacheService::redisHandler('diy')->clear();
    }
}

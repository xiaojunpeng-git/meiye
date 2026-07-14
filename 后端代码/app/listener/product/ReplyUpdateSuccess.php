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
 * Class ReplyUpdateSuccess
 * @package app\listener\product
 */
class ReplyUpdateSuccess implements ListenerInterface
{

    /**
     * @param $event
     */
    public function handle($event): void
    {
        [$uid] = $event;
        CacheService::redisHandler('relation_' . fmod((float)$uid, (float)10))->clear();
    }
}

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


use app\services\user\UserServices;
use mohe\interfaces\ListenerInterface;

/**
 * Class UpdateSuccess
 * @package app\listener\user
 */
class UpdateSuccess implements ListenerInterface
{

    /**
     * @param $event
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function handle($event): void
    {
		/** @var UserServices $service */
		$service = app()->make(UserServices::class);
		$service->cacheTag()->clear();
    }
}

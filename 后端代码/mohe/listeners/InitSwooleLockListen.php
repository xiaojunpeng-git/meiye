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


namespace mohe\listeners;


use app\listener\system\AutoConfig;
use mohe\interfaces\ListenerInterface;
use mohe\utils\Start;
use Swoole\Lock;
use think\facade\Event;

/**
 * swoole 初始化
 */
class InitSwooleLockListen implements ListenerInterface
{

    public function handle($event): void
    {
        $GLOBALS['_swoole_order_lock'] = [];
        $locks = array_merge(['default'], config('swoole.locks', []));
        foreach ($locks as $lock) {
            $GLOBALS['_swoole_order_lock'][$lock] = new Lock(SWOOLE_MUTEX);
        }
        Event::listen('get.config', AutoConfig::class);
        app()->make(Start::class)->show();
    }
}

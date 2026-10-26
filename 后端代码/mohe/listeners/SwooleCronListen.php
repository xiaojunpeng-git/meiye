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

use mohe\interfaces\ListenerInterface;
use think\facade\Log;

/**
 * swoole 定时任务
 */
class SwooleCronListen implements ListenerInterface
{

    public function handle($event): void
    {
        try {
            event('crontab');//app/event.php 里面配置事件
        } catch (\Throwable $e) {
            Log::error('监听定时器报错: ' . $e->getMessage());
        }

    }
}

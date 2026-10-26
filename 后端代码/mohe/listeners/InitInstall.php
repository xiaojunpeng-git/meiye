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
use Swoole\Lock;
use think\facade\Event;

/**
 * 安装检测
 */
class InitInstall implements ListenerInterface
{

    public function handle($event): void
    {
        if (!extension_loaded('swoole_loader')) {
            $swoole = '<span class="correct_span">&radic;</span> 已安装';
        } else {
            $swoole = '<a href="/install/compiler" target="_blank"><span class="correct_span error_span">&radic;</span> 点击查看帮助</a>';
        }
    }
}

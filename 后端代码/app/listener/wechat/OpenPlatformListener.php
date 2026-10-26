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

namespace app\listener\wechat;

use EasyWeChat\Kernel\Contracts\EventHandlerInterface;

/**
 * 公众平台消息
 * Class OpenPlatformListener
 * @package app\listener\wechat
 */
class OpenPlatformListener implements EventHandlerInterface
{

    public function handle($payload = null)
    {
        // TODO: Implement handle() method.
    }
}

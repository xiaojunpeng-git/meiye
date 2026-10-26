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

namespace app\listener\system\config;


use mohe\interfaces\ListenerInterface;

/**
 * 删除配置成功
 * Class DeleteSuccess
 * @package app\listener\config
 */
class DeleteSuccess implements ListenerInterface
{

    public function handle($event): void
    {
        [$id] = $event;
        \mohe\services\SystemConfigService::clear();
    }
}

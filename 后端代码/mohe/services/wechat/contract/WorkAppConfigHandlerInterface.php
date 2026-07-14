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

namespace mohe\services\wechat\contract;

/**
 * 企业微信获取应用配置
 * Interface WorkAppConfigHandlerInterface
 * @package mohe\services\wechat\contract
 */
interface WorkAppConfigHandlerInterface
{

    /**
     * 获取应用配置
     * @param string $corpId
     * @param string $type 应用标识
     * @return array
     */
    public function getAppConfig(string $corpId, string $type): array;

}

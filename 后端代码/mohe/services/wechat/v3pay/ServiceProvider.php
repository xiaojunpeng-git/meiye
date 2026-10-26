<?php
/**
 *  +----------------------------------------------------------------------
 *  | MOHE [ MOHE赋能开发者，助力企业发展 ]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
 *  +----------------------------------------------------------------------
 *  | Author: MOHE Team <admin@mohe.com>
 *  +----------------------------------------------------------------------
 */

namespace mohe\services\wechat\v3pay;


use Pimple\Container;
use Pimple\ServiceProviderInterface;

/**
 * V3支付
 * Class ServiceProvider
 * @package mohe\services\easywechat\v3pay
 */
class ServiceProvider implements ServiceProviderInterface
{

    /**
     * @param Container $pimple
     */
    public function register(Container $pimple)
    {
        $pimple['v3pay'] = function ($pimple) {
            return new PayClient($pimple, $pimple['access_token']);
        };
    }
}

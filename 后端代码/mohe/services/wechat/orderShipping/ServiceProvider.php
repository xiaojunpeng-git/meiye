<?php

/*
 * This file is part of the overtrue/wechat.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace mohe\services\wechat\orderShipping;

use Pimple\Container;
use Pimple\ServiceProviderInterface;


/**
 * 小程序订单管理
 * Class ServiceProvider
 * @package mohe\services\easywechat\orderShipping
 */
class ServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}.
     */
    public function register(Container $pimple)
    {
        $pimple['orderShipping'] = function ($pimple) {
            return new OrderClient($pimple);
        };
    }
}

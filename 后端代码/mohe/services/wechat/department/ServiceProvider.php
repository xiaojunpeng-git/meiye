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

namespace mohe\services\wechat\department;

use Pimple\Container;
use Pimple\ServiceProviderInterface;

/**
 * Class ServiceProvider
 * @author 等风来
 * @email 136327134@qq.com
 * @date 2022/10/9
 * @package mohe\services\wechat\department
 */
class ServiceProvider implements ServiceProviderInterface
{

    /**
     * @param Container $app
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2022/10/9
     */
    public function register(Container $app)
    {
        $app['department'] = function ($app) {
            return new Client($app);
        };
    }
}

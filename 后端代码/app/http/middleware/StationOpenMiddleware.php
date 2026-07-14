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

namespace app\http\middleware;


use app\Request;
use mohe\interfaces\MiddlewareInterface;

/**
 * 站点升级
 * Class StationOpenMiddleware
 * @package app\api\middleware
 */
class StationOpenMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next)
    {
        if (!sys_config('station_open', true)) {
            return app('json')->make('410010', '站点升级中，请稍候访问');
        }
        return $next($request);
    }
}

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

namespace app\http\middleware\api;


use app\Request;
use app\services\community\CommunityUserServices;
use mohe\interfaces\MiddlewareInterface;

/**
 * 社区是否开启
 * Class StationOpenMiddleware
 * @package app\api\middleware
 */
class CommunityOpenMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next)
    {
        if (!sys_config('community_status', 1)) {
            return app('json')->make('410010', '社区暂未开放');
        }

        return $next($request);
    }
}

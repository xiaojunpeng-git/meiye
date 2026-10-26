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
 * Class InstallMiddleware
 * @package app\http\middleware
 */
class InstallMiddleware implements MiddlewareInterface
{

    public function handle(Request $request, \Closure $next)
    {
        //检测是否已安装MOHE系统
        if (file_exists(root_path() . "public/install/") && !file_exists(root_path() . "public/install/install.lock")) {
            return redirect('/install/index');
        }

        return $next($request);
    }
}

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

namespace app\http\middleware\store;


use app\Request;
use app\services\store\LoginServices;
use mohe\interfaces\MiddlewareInterface;
use think\facade\Config;

/**
 * Class AuthTokenMiddleware
 * @package app\http\middleware\store
 */
class AuthTokenMiddleware implements MiddlewareInterface
{

    /**
     * @param Request $request
     * @param \Closure $next
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function handle(Request $request, \Closure $next)
    {
        $token = trim(ltrim($request->header(Config::get('cookie.token_name', 'Authori-zation')), 'Bearer'));
        /** @var LoginServices $services */
        $services = app()->make(LoginServices::class);
        $outInfo = $services->parseToken($token);

		$request->storeId = (int)$outInfo['store_id'];
		$request->storeStaffId = (int)$outInfo['id'];
		$request->storeStaffInfo = $outInfo;

        return $next($request);
    }
}

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

namespace app\http\middleware\cashier;


use app\Request;
use app\services\cashier\LoginServices;
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
		$request->cashierId = (int)$outInfo['id'];
		$request->cashierInfo = $outInfo;
		// V3 管理接口复用已验证的门店领域控制器。提供兼容身份别名，
		// 但认证仍由 cashier V3 token 与 CashierCheckRoleMiddleware 负责。
		$request->storeStaffId = (int)$outInfo['id'];
		$request->storeStaffInfo = $outInfo;

        return $next($request);
    }
}

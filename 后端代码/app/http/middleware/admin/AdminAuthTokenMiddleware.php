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

namespace app\http\middleware\admin;


use app\Request;
use app\services\system\admin\AdminAuthServices;
use mohe\interfaces\MiddlewareInterface;
use think\facade\Config;

/**
 * 后台登录验证中间件
 * Class AdminAuthTokenMiddleware
 * @package app\http\middleware\admin
 */
class AdminAuthTokenMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next)
    {
        $authInfo = null;
        $token = trim(ltrim($request->header(Config::get('cookie.token_name', 'Authori-zation')), 'Bearer'));

        /** @var AdminAuthServices $service */
        $service = app()->make(AdminAuthServices::class);
        $adminInfo = $service->parseToken($token);

		$request->isAdminLogin = !is_null($adminInfo);
		$request->adminId = (int)$adminInfo['id'];
		$request->adminInfo = $adminInfo;
		$request->adminType = $adminInfo['admin_type'] ?? 0;
		$request->agentId = $request->adminType == 3 ? ($this->adminInfo['relation_id'] ?? 0) : 0;

        return $next($request);
    }
}

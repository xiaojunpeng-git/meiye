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
use app\services\system\SystemRoleServices;
use mohe\exceptions\AuthException;
use mohe\interfaces\MiddlewareInterface;
use mohe\utils\ApiErrorCode;

/**
 * 权限规则验证
 * Class AdminCkeckRoleMiddleware
 * @package app\http\middleware
 */
class AdminCkeckRoleMiddleware implements MiddlewareInterface
{

    public function handle(Request $request, \Closure $next)
    {
        if (!$request->adminId() || !$request->adminInfo())
            throw new AuthException(ApiErrorCode::ERR_ADMINID_VOID);

        if ($request->adminInfo()['level'] || $request->adminType() == 3) {
            /** @var SystemRoleServices $systemRoleService */
            $systemRoleService = app()->make(SystemRoleServices::class);
            $systemRoleService->verifiAuth($request);
        }

        return $next($request);
    }
}

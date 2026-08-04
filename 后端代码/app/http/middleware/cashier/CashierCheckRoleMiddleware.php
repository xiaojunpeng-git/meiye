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
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use mohe\exceptions\AuthException;
use mohe\interfaces\MiddlewareInterface;
use mohe\utils\ApiErrorCode;

/**
 * 权限规则验证
 * Class AdminCkeckRoleMiddleware
 * @package app\http\middleware
 */
class CashierCheckRoleMiddleware implements MiddlewareInterface
{

    public function handle(Request $request, \Closure $next)
    {
        if (!$request->storeId() || !$request->cashierInfo())
            throw new AuthException(ApiErrorCode::ERR_ADMINID_VOID);

        // 门店端 Vue 3 uses its own position-policy channel.  Old cashier
        // roles have different menu identifiers and must not authorize V3.
        if (strpos((string)$request->pathinfo(), 'cashierapi/v3/') === 0) {
            /** @var CashierV3FeatureResolver $resolver */
            $resolver = app()->make(CashierV3FeatureResolver::class);
            if (!$resolver->resolveGrantedFeatures((array)$request->cashierInfo())) {
                throw new AuthException(ApiErrorCode::ERR_AUTH);
            }
            return $next($request);
        }

        if ($request->cashierInfo()['level']) {
            /** @var LoginServices $services */
            $services = app()->make(LoginServices::class);
            $services->verifiAuth($request);
        }

        return $next($request);
    }
}

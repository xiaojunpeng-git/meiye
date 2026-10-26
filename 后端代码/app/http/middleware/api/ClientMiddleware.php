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
use app\services\user\UserAuthServices;
use mohe\interfaces\MiddlewareInterface;

/**
 * 客户身份验证中间件
 * Class ClientMiddleware
 * @package app\http\middleware\api
 */
class ClientMiddleware implements MiddlewareInterface
{

    public function handle(Request $request, \Closure $next)
    {
        $userId = trim(ltrim($request->param('userid')));

        if (!$userId) {
            return app('json')->fail('缺少Userid');
        }

        try {
            /** @var UserAuthServices $service */
            $service = app()->make(UserAuthServices::class);
            $authInfo = $service->parseClient($userId);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }

        $request->clientInfo = function (string $key = null) use ($authInfo) {
            if ($key) {
                return $authInfo[$key] ?? null;
            } else {
                return $authInfo;
            }
        };

        $request->userid = $userId;

        return $next($request);
    }
}

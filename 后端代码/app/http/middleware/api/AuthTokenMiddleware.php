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
use app\services\user\UserAuthServices;
use mohe\exceptions\AuthException;
use mohe\interfaces\MiddlewareInterface;
use think\exception\DbException;

/**
 * Class AuthTokenMiddleware
 * @package app\api\middleware
 */
class AuthTokenMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next, bool $force = true)
    {
        $authInfo = null;
        $token = trim(ltrim($request->header('Authori-zation'), 'Bearer'));
        if (!$token) $token = trim(ltrim($request->header('Authorization'), 'Bearer'));//正式版，删除此行，某些服务器无法获取到token调整为 Authori-zation
        try {
            /** @var UserAuthServices $service */
            $service = app()->make(UserAuthServices::class);
            $authInfo = $service->parseToken($token);
        } catch (AuthException $e) {
            if ($force)
                return app('json')->make($e->getCode(), $e->getMessage());
        }

		if (!is_null($authInfo)) {
			$request->user = function (string $key = null) use (&$authInfo) {
				if ($key) {
					return $authInfo['user'][$key] ?? '';
				}
				return $authInfo['user'];
			};
			$request->tokenData = $authInfo['tokenData'];
		}
		$request->isLogin = !is_null($authInfo);
		$request->uid = is_null($authInfo) ? 0 : (int)$authInfo['user']->uid;
        //社区用户写入
        if ($request->uid) {
            /** @var CommunityUserServices $communityUserServices */
            $communityUserServices = app()->make(CommunityUserServices::class);
            $communityUserServices->hasUser($request->uid);
        }

        return $next($request);
    }
}

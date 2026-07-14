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

namespace app\http\middleware\out;


use app\Request;
use app\services\out\OutAccountServices;
use mohe\interfaces\MiddlewareInterface;
use think\facade\Config;

/**
 * Class AuthTokenMiddleware
 * @package app\http\middleware\out
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
        $authInfo = null;
        $token = trim(ltrim($request->header(Config::get('cookie.token_name', 'Authori-zation')), 'Bearer'));
        /** @var OutAccountServices $services */
        $services = app()->make(OutAccountServices::class);
        $outInfo = $services->parseToken($token);

		$request->outId = (int)$outInfo['id'];
		$request->outInfo = $outInfo;

        return $next($request);
    }
}

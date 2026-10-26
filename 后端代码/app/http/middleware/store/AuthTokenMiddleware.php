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
use app\services\cashier\LoginServices as CashierLoginServices;
use app\services\store\LoginServices;
use mohe\interfaces\MiddlewareInterface;
use think\facade\Config;
use mohe\utils\JwtAuth;

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
        // 收银 V3 的数据权限选店会话没有 system_store_staff 任职行，
        // 其 JWT 主键是 cashier_v3_store_session.id。旧门店解析器会把
        // 这个会话 ID 当成 staff.id，随后误报“登录状态有误”。先识别
        // delegated 类型，交给同一套 V3 登录服务解析；普通门店令牌仍
        // 保持原有门店解析路径。
        [, $tokenType] = app()->make(JwtAuth::class)->parseToken($token);
        if (in_array((string)$tokenType, ['cashier_v3_delegated', 'cashier_v3_organization'], true)) {
            /** @var CashierLoginServices $services */
            $services = app()->make(CashierLoginServices::class);
            $outInfo = $services->parseToken($token);
        } else {
            /** @var LoginServices $services */
            $services = app()->make(LoginServices::class);
            $outInfo = $services->parseToken($token);
        }

		$request->storeId = (int)$outInfo['store_id'];
		$request->storeStaffId = (int)$outInfo['id'];
		$request->storeStaffInfo = $outInfo;

        return $next($request);
    }
}

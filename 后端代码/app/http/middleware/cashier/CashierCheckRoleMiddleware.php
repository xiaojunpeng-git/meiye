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
            // 无 system_store_staff 的组织数据权限会话只能浏览。
            // V3 还有员工管理、房间、挂单等不经过命令网关的写入口，
            // 因此必须在路由组公共中间件再做一次服务端方法门禁；退出登录
            // 属于会话控制操作，保留允许，不能被只读模式锁住。
            $cashierInfo = (array)$request->cashierInfo();
            $method = strtoupper((string)$request->method());
            $path = (string)$request->pathinfo();
            $isLogout = substr($path, -strlen('/session/logout')) === '/session/logout';
            // workbenches/actions 同时承载只读 projection 与写 command；
            // 让统一 V3 dispatcher 按 manifest 类型判定，不能按 HTTP
            // 方法一刀切，否则只读人员连查询投影也无法加载。
            $isV3ActionGateway = substr($path, -strlen('/workbenches/actions')) === '/workbenches/actions';
            if (!empty($cashierInfo['_cashier_v3_delegated'])
                && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
                && !$isLogout
                && !$isV3ActionGateway) {
                throw new AuthException('当前为门店查看模式，不能执行新增、编辑、收银或结账操作。', 403);
            }
            /** @var CashierV3FeatureResolver $resolver */
            $resolver = app()->make(CashierV3FeatureResolver::class);
            if (!$resolver->resolveGrantedFeatures($cashierInfo)) {
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

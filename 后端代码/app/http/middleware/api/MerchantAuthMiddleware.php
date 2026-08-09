<?php

declare(strict_types=1);

namespace app\http\middleware\api;

use app\Request;
use app\services\cashier\LoginServices as CashierLoginServices;
use app\services\user\UserAuthServices;
use mohe\exceptions\AuthException;
use mohe\interfaces\MiddlewareInterface;
use think\facade\Db;

/**
 * 商家端可使用会员会话或员工内部账号签发的 merchant_legacy 会话。
 * 两种会话解析后只暴露统一 uid；员工会话不会被会员端其他路由接受。
 */
final class MerchantAuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next, bool $force = true)
    {
        $token = trim((string)$request->header('Authori-zation', ''));
        if ($token === '') {
            $token = trim((string)$request->header('Authorization', ''));
        }
        $token = trim((string)preg_replace('/^Bearer\s+/i', '', $token));

        try {
            /** @var UserAuthServices $users */
            $users = app()->make(UserAuthServices::class);
            $auth = $users->parseToken($token);
            $request->user = static function (?string $key = null) use ($auth) {
                return $key ? ($auth['user'][$key] ?? '') : $auth['user'];
            };
            $request->tokenData = $auth['tokenData'];
            $request->uid = (int)$auth['user']->uid;
            $request->isLogin = true;
            return $next($request);
        } catch (AuthException $memberError) {
            // merchant_legacy token belongs to the employee domain, not the member domain.
        }

        try {
            /** @var CashierLoginServices $cashiers */
            $cashiers = app()->make(CashierLoginServices::class);
            $staff = $cashiers->parseToken($token);
            if ((string)($staff['type'] ?? '') !== 'merchant_legacy') {
                throw new AuthException('请使用员工账号登录商家端', 410000);
            }
            $employeeId = (int)($staff['employee_id'] ?? 0);
            $uid = (int)($staff['uid'] ?? 0);
            $accountActive = $employeeId > 0 && (int)Db::name('employee_internal_account')
                ->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)->count() === 1;
            if (!$accountActive || $uid <= 0) {
                throw new AuthException('员工账号或会员映射已失效，请联系管理员处理', 410000);
            }
            $request->uid = $uid;
            $request->isLogin = true;
            return $next($request);
        } catch (AuthException $employeeError) {
            if (!$force) {
                $request->uid = 0;
                $request->isLogin = false;
                return $next($request);
            }
            return app('json')->make($employeeError->getCode() ?: 410000, $employeeError->getMessage());
        }
    }
}

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

use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\store\LoginServices;
use mohe\exceptions\AuthException;
use mohe\interfaces\MiddlewareInterface;
use mohe\utils\ApiErrorCode;

/**
 * 权限规则验证
 * Class AdminCkeckRoleMiddleware
 * @package app\http\middleware
 */
class StoreCkeckRoleMiddleware implements MiddlewareInterface
{

    public function handle(Request $request, \Closure $next)
    {
        if (!$request->storeId() || !$request->storeStaffInfo())
            throw new AuthException(ApiErrorCode::ERR_ADMINID_VOID);

        $staffInfo = (array)$request->storeStaffInfo();
        $sessionType = (string)($staffInfo['type'] ?? '');
        if (in_array($sessionType, ['cashier_v3', 'cashier_v3_delegated'], true)) {
            $requiredFeature = $this->cashierV3PresaleFeature(
                (string)$request->pathinfo(),
                strtoupper((string)$request->method())
            );
            if ($requiredFeature !== '') {
                $readOnly = $sessionType === 'cashier_v3_delegated'
                    || !empty($staffInfo['_cashier_v3_delegated']);
                if ($readOnly) {
                    if (!in_array(strtoupper((string)$request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
                        throw new AuthException('当前为门店查看模式，不能执行预售领用或作废操作。', 403);
                    }
                    return $next($request);
                }
                $features = app()->make(CashierV3FeatureResolver::class)
                    ->resolveGrantedFeatures($staffInfo);
                if (!in_array($requiredFeature, $features, true)) {
                    throw new AuthException('当前账号没有该功能的操作权限，请联系管理员。', 403);
                }
                return $next($request);
            }
        }

        if ($staffInfo['level']) {
            /** @var LoginServices $services */
            $services = app()->make(LoginServices::class);
            $services->verifiAuth($request);
        }

        return $next($request);
    }

    /**
     * 预售领用页面复用 storeapi 库存接口，但 V3 会话不能继续按旧门店角色判权。
     */
    private function cashierV3PresaleFeature(string $path, string $method): string
    {
        $path = ltrim($path, '/');
        if ($method === 'GET' && $path === 'storeapi/product/inventory/v3/presale-claims') {
            return 'cashier.v3.inventory.outbound';
        }
        if ($method === 'GET'
            && preg_match('#^storeapi/product/inventory/v3/presale-claims/[A-Za-z0-9-]+$#D', $path) === 1) {
            return 'cashier.v3.inventory.presale_claim.detail';
        }
        if ($method === 'POST' && $path === 'storeapi/product/inventory/v3/presale-claims/claim') {
            return 'cashier.v3.inventory.presale_claim.create';
        }
        if ($method === 'POST'
            && preg_match('#^storeapi/product/inventory/v3/presale-claims/[A-Za-z0-9-]+/void$#D', $path) === 1) {
            return 'cashier.v3.inventory.presale_claim.void';
        }
        return '';
    }
}

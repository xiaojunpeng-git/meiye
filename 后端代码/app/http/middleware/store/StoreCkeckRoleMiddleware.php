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
        if (in_array($sessionType, ['cashier_v3', 'cashier_v3_delegated', 'cashier_v3_organization'], true)) {
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
            // 新建调拨页始终走批量保存（也包含单张）；用当前 V3 调拨入口授权，
            // 不再回落到旧门店任务节点，源方门店与员工身份仍由领域服务复核。
            if ($this->isTransferGenerationCommand((string)$request->pathinfo(), strtoupper((string)$request->method()))) {
                $resolver = app()->make(CashierV3FeatureResolver::class);
                $features = !empty($staffInfo['_cashier_v3_delegated'])
                    ? $resolver->resolveVisibleFeatures($staffInfo)
                    : $resolver->resolveGrantedFeatures($staffInfo);
                if (!in_array('cashier.v3.inventory.transfer', $features, true)) {
                    throw new AuthException('当前账号无法进入调拨功能。', 403);
                }
                return $next($request);
            }
            // 查看模式原本只有菜单可见性；产品单独确认调拨可操作，
            // 仅对调拨写路由放行旧角色门禁，实际令牌选店和员工审计由调拨服务复核。
            if (!empty($staffInfo['_cashier_v3_delegated'])
                && $this->isDelegatedTransferCommand((string)$request->pathinfo(), strtoupper((string)$request->method()))) {
                $visible = app()->make(CashierV3FeatureResolver::class)->resolveVisibleFeatures($staffInfo);
                if (!in_array('cashier.v3.inventory.transfer', $visible, true)) {
                    throw new AuthException('当前账号无法进入调拨功能。', 403);
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

    /** 仅新单生成可复用已授权调拨入口；旧单据生命周期命令不在此放宽。 */
    private function isTransferGenerationCommand(string $path, string $method): bool
    {
        if ($method !== 'POST') return false;
        $path = ltrim($path, '/');
        return $path === 'storeapi/product/inventory/v3/cross-transfer'
            || $path === 'storeapi/product/inventory/v3/cross-transfer/batch';
    }

    /** Delegated transfer mode can act only on the selected store's transfer command routes. */
    private function isDelegatedTransferCommand(string $path, string $method): bool
    {
        if ($method !== 'POST') return false;
        $path = ltrim($path, '/');
        return $path === 'storeapi/product/inventory/v3/cross-transfer'
            || $path === 'storeapi/product/inventory/v3/cross-transfer/batch'
            || $path === 'storeapi/product/inventory/v3/cross-transfer/batch-operation'
            // 只扩展同一调拨单的撤回/记名作废命令，服务层仍复核调出方与单据状态。
            || preg_match('#^storeapi/product/inventory/v3/cross-transfer/[0-9]+/(?:update|delete-draft|dispatch|receive|withdraw|reverse)$#D', $path) === 1;
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
            && preg_match('#^storeapi/product/inventory/v3/presale-claims/[A-Za-z0-9-]+/remark$#D', $path) === 1) {
            return 'cashier.v3.inventory.presale_claim.create';
        }
        if ($method === 'POST'
            && preg_match('#^storeapi/product/inventory/v3/presale-claims/[A-Za-z0-9-]+/void$#D', $path) === 1) {
            return 'cashier.v3.inventory.presale_claim.void';
        }
        return '';
    }
}

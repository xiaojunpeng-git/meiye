<?php

namespace app\controller\admin\v1\store;

use app\controller\admin\AuthController;
use app\services\order\PlatformOrderCenterReadServices;
use app\services\organization\OrganizationScopeService;
use app\services\system\SystemRoleServices;
use mohe\exceptions\AuthException;
use mohe\utils\ApiErrorCode;
use think\facade\App;

/** 平台订单中心：仅管理员令牌 + 数据权限的只读查询入口。 */
class OrderCenter extends AuthController
{
    public function __construct(App $app)
    {
        parent::__construct($app);
    }

    public function metadata(PlatformOrderCenterReadServices $services)
    {
        $this->ensureOrderCenterPermission();
        return $this->success($services->metadata((array)$this->adminInfo));
    }

    public function records(PlatformOrderCenterReadServices $services)
    {
        $this->ensureOrderCenterPermission();
        return $this->success($services->records((array)$this->adminInfo, (array)$this->request->get()));
    }

    /** 平台订单中心的组织／门店范围，按当前订单数据权限裁剪。 */
    public function scope(PlatformOrderCenterReadServices $services, OrganizationScopeService $organizations)
    {
        $this->ensureOrderCenterPermission();
        return $this->success($services->scope((array)$this->adminInfo, $organizations));
    }

    /** POST /adminapi/store/order-center/actions: Vue3 订单中心标准只读 action envelope。 */
    public function actions(PlatformOrderCenterReadServices $services)
    {
        $this->ensureOrderCenterPermission();
        return $this->success('ok', $services->dispatchReadAction(
            (array)$this->adminInfo,
            (array)$this->request->post()
        ));
    }

    /**
     * These endpoints deliberately have no write API-menu entry.  The global
     * middleware therefore follows its legacy “unregistered routes pass”
     * compatibility branch, so the read boundary must be asserted here as
     * well.  A direct API call cannot bypass the eight visible order-center
     * capabilities.
     */
    private function ensureOrderCenterPermission(): void
    {
        $admin = (array)$this->adminInfo;
        $isRootAdmin = (int)($admin['level'] ?? 1) === 0 && (int)$this->request->adminType() !== 3;
        if ($isRootAdmin) {
            return;
        }
        /** @var SystemRoleServices $roles */
        $roles = app()->make(SystemRoleServices::class);
        $roleIds = $admin['roles'] ?? [];
        $roleIds = is_string($roleIds) ? array_filter(explode(',', $roleIds)) : (array)$roleIds;
        $granted = $roles->getRolesByAuth($roleIds, 2);
        $allowed = [
            'admin-store-order-center-sales',
            'admin-store-order-center-recharge',
            'admin-store-order-center-refund',
            'admin-store-order-center-debt',
            'admin-store-order-center-service',
            'admin-store-order-center-supplement',
            'admin-store-order-center-gift',
            'admin-store-order-center-card-operation',
        ];
        foreach ((array)$granted as $item) {
            if (in_array(trim((string)($item['unique_auth'] ?? '')), $allowed, true)) {
                return;
            }
        }
        throw new AuthException(ApiErrorCode::ERR_AUTH);
    }
}

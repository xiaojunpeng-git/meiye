<?php
namespace app\services\merchant;

use app\dao\store\SystemStoreStaffDao;
use app\services\agent\SystemRegionAgentServices;
use app\services\BaseServices;
use app\services\message\service\StoreServiceServices;
use app\services\organization\OrganizationScopeService;
use app\services\store\DeliveryServiceServices;
use app\services\store\SystemStoreStaffServices;
use app\services\system\SystemMenusServices;
use app\services\system\SystemRoleServices;

/**
 * 手机端商家身份与入口权限（统一口径）
 * permissions / stores 均按「当前身份 + 当前门店」计算，禁止多身份权限并集越权。
 */
class MerchantAccessServices extends BaseServices
{
    /**
     * 解析当前用户商家访问能力
     */
    public function resolveAccess(int $uid, array $context = []): array
    {
        $empty = [
            'can_enter_merchant' => false,
            'roles' => [],
            'active_role' => '',
            'active_store_id' => 0,
            'stores' => [],
            'resolved_store_ids' => [],
            'scope_store_ids' => [],
            'permissions' => [],
            'mall_unique_auth' => [],
            'identity' => [
                'is_staff' => false,
                'is_manager' => false,
                'is_agent' => false,
                'is_service' => false,
                'is_delivery' => false,
            ],
        ];
        if ($uid <= 0) {
            return $empty;
        }

        $roles = [];
        $identity = $empty['identity'];
        $staffByStore = []; // store_id => staff row
        $agentStoreIds = [];

        /** @var StoreServiceServices $storeService */
        $storeService = app()->make(StoreServiceServices::class);
        // 停用客服（status≠1）不得获得商家入口；customer 标志不能绕过 status
        $isService = (bool)$storeService->checkoutIsService(['uid' => $uid, 'status' => 1, 'account_status' => 1]);
        $isCustomerService = (bool)$storeService->checkoutIsService([
            'uid' => $uid,
            'status' => 1,
            'account_status' => 1,
            'customer' => 1,
        ]);
        if ($isService || $isCustomerService) {
            $identity['is_service'] = true;
            $roles[] = 'platform_service';
        }

        /** @var SystemStoreStaffDao $staffDao */
        $staffDao = app()->make(SystemStoreStaffDao::class);
        $staffRows = [];
        try {
            $staffRows = $staffDao->search([
                'uid' => $uid,
                'is_del' => 0,
                'status' => 1,
            ])->select()->toArray() ?: [];
        } catch (\Throwable $e) {
            $staffRows = [];
        }
        foreach ($staffRows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $staffByStore[$sid] = $row;
            $identity['is_staff'] = true;
            $isManager = SystemStoreStaffServices::staffIsManager($row);
            if ($isManager) {
                $identity['is_manager'] = true;
                $roles[] = 'store_manager';
            } else {
                $roles[] = 'store_staff';
            }
        }

        /** @var SystemRegionAgentServices $regionServices */
        $regionServices = app()->make(SystemRegionAgentServices::class);
        $agentId = 0;
        try {
            if ($regionServices->userIsRegionAgent($uid)) {
                $identity['is_agent'] = true;
                $roles[] = 'region_agent';
                $agent = $regionServices->getRegionAgentByUid($uid);
                $agentId = (int)($agent['id'] ?? 0);
                /** @var OrganizationScopeService $scopeService */
                $scopeService = app()->make(OrganizationScopeService::class);
                $agentStoreIds = $agentId > 0
                    ? $scopeService->getResolvedStoreIdsByLegacyAgentId($agentId)
                    : $scopeService->getResolvedStoreIdsByUid($uid);
                $agentStoreIds = array_values(array_unique(array_filter(array_map('intval', $agentStoreIds))));
            }
        } catch (\Throwable $e) {
        }

        /** @var DeliveryServiceServices $deliveryService */
        $deliveryService = app()->make(DeliveryServiceServices::class);
        try {
            $delivery = $deliveryService->getDeliveryInfoByUid($uid);
            if (!empty($delivery)) {
                $identity['is_delivery'] = true;
                $roles[] = 'delivery';
            }
        } catch (\Throwable $e) {
        }

        $roles = array_values(array_unique($roles));
        $canEnter = !empty($roles);

        // —— 当前身份 ——
        $activeRole = (string)($context['active_role'] ?? '');
        if ($activeRole === '' || !in_array($activeRole, $roles, true)) {
            $priority = ['region_agent', 'store_manager', 'store_staff', 'platform_service', 'delivery'];
            $activeRole = '';
            foreach ($priority as $role) {
                if (in_array($role, $roles, true)) {
                    $activeRole = $role;
                    break;
                }
            }
            if ($activeRole === '' && $roles) {
                $activeRole = $roles[0];
            }
        }

        // —— 当前身份下的可选门店 ——
        $roleStoreIds = [];
        if (in_array($activeRole, ['store_manager', 'store_staff'], true)) {
            foreach ($staffByStore as $sid => $row) {
                $isManager = SystemStoreStaffServices::staffIsManager($row);
                if ($activeRole === 'store_manager' && $isManager) {
                    $roleStoreIds[] = $sid;
                }
                if ($activeRole === 'store_staff' && !$isManager) {
                    $roleStoreIds[] = $sid;
                }
            }
            if ($activeRole === 'store_manager' && !$roleStoreIds) {
                foreach ($staffByStore as $sid => $row) {
                    if (SystemStoreStaffServices::staffIsManager($row)) {
                        $roleStoreIds[] = $sid;
                    }
                }
            }
            if ($activeRole === 'store_staff' && !$roleStoreIds) {
                foreach ($staffByStore as $sid => $row) {
                    $isManager = SystemStoreStaffServices::staffIsManager($row);
                    if (!$isManager) {
                        $roleStoreIds[] = $sid;
                    }
                }
                if (!$roleStoreIds && $staffByStore) {
                    $roleStoreIds = array_keys($staffByStore);
                }
            }
        } elseif ($activeRole === 'region_agent') {
            $roleStoreIds = $agentStoreIds;
        }
        // platform_service / delivery：无默认门店范围（禁止全平台聚合）

        $roleStoreIds = array_values(array_unique(array_filter(array_map('intval', $roleStoreIds))));

        $stores = [];
        if ($roleStoreIds) {
            /** @var \app\dao\store\SystemStoreDao $storeDao */
            $storeDao = app()->make(\app\dao\store\SystemStoreDao::class);
            $rows = $storeDao->getStoreList(
                ['id' => $roleStoreIds, 'is_del' => 0],
                ['id', 'name'],
                0,
                0
            ) ?: [];
            foreach ($rows as $row) {
                $stores[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'name' => (string)($row['name'] ?? ''),
                ];
            }
        }
        $allowedStoreMap = array_flip($roleStoreIds);

        $activeStoreId = (int)($context['active_store_id'] ?? 0);
        if ($activeStoreId > 0 && !isset($allowedStoreMap[$activeStoreId])) {
            $activeStoreId = 0;
        }
        if ($activeStoreId <= 0 && $stores) {
            $activeStoreId = (int)$stores[0]['id'];
        }

        // 查询范围：区域代理默认看授权全部门店；门店角色仅当前店
        $scopeStoreIds = [];
        if ($activeRole === 'region_agent') {
            $scopeStoreIds = $roleStoreIds;
        } elseif (in_array($activeRole, ['store_manager', 'store_staff'], true) && $activeStoreId > 0) {
            $scopeStoreIds = [$activeStoreId];
        }

        $activeStaff = ($activeStoreId > 0 && isset($staffByStore[$activeStoreId]))
            ? $staffByStore[$activeStoreId]
            : null;

        // 角色 ID → mall_rules → unique_auth（与 StoreStaff::info 同源）
        $mallUniqueAuth = $this->resolveMallUniqueAuth($activeStaff);

        $permissions = $this->permissionsForActiveRole($activeRole, $mallUniqueAuth, $scopeStoreIds);

        return [
            'can_enter_merchant' => $canEnter,
            'roles' => $roles,
            'active_role' => $activeRole,
            'active_store_id' => $activeStoreId,
            'stores' => $stores,
            'resolved_store_ids' => $roleStoreIds,
            'scope_store_ids' => $scopeStoreIds,
            'permissions' => $permissions,
            'mall_unique_auth' => $mallUniqueAuth,
            'identity' => $identity,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 复用门店端链路：roles(角色ID) → SystemRoleServices::getRoleIds(..., mall_rules) → getMallMenus → unique_auth
     */
    protected function resolveMallUniqueAuth(?array $staff): array
    {
        if (!$staff) {
            return [];
        }
        $rolesRaw = $staff['roles'] ?? [];
        if (is_string($rolesRaw)) {
            $rolesRaw = $rolesRaw === '' ? [] : explode(',', $rolesRaw);
        }
        if (!is_array($rolesRaw)) {
            $rolesRaw = [];
        }
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $rolesRaw))));
        if (!$roleIds) {
            return [];
        }

        $uniqueAuths = [];
        try {
            /** @var SystemRoleServices $roleServices */
            $roleServices = app()->make(SystemRoleServices::class);
            /** @var SystemMenusServices $menusServices */
            $menusServices = app()->make(SystemMenusServices::class);
            $menusIds = $roleServices->getRoleIds($roleIds, 'mall_rules');
            $menusIds = array_values(array_unique(array_filter(array_map('intval', $menusIds ?: []))));
            if ($menusIds) {
                $mallMenusAll = $menusServices->getMallMenus(1);
                foreach ($mallMenusAll as $item) {
                    if (in_array((int)($item['id'] ?? 0), $menusIds, true)) {
                        $auth = trim((string)($item['unique_auth'] ?? ''));
                        if ($auth !== '') {
                            $uniqueAuths[] = $auth;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        // 与 StoreStaff::info 一致：店长（含历史管家）默认预约权限
        $reservationAuths = [
            'mall-admin-reservation',
            'mall-admin-reservation-list',
            'mall-admin-reservation-detail',
            'mall-admin-reservation-start',
            'mall-admin-reservation-end',
        ];
        if (SystemStoreStaffServices::staffIsManager($staff)) {
            $uniqueAuths = array_merge($uniqueAuths, $reservationAuths);
        } else {
            $uniqueAuths = array_values(array_diff($uniqueAuths, $reservationAuths));
        }

        return array_values(array_unique($uniqueAuths));
    }

    /**
     * 仅按当前身份授权，不合并多角色并集
     */
    protected function permissionsForActiveRole(string $role, array $mallUniqueAuth, array $scopeStoreIds): array
    {
        $hasStoreScope = !empty($scopeStoreIds);
        $authSet = array_flip($mallUniqueAuth);
        $hasUserManage = isset($authSet['mall-admin-user'])
            || isset($authSet['mall-admin-user-list']);

        switch ($role) {
            case 'region_agent':
                if (!$hasStoreScope) {
                    return ['merchant.enter', 'merchant.profile.view'];
                }
                return [
                    'merchant.enter',
                    'merchant.home.view',
                    'merchant.profile.view',
                    'merchant.customer.view',
                    'merchant.customer.create',
                    'merchant.data.region',
                    'merchant.data.store',
                    'merchant.debt.view',
                    'merchant.target.view',
                    'merchant.target.manage',
                    'merchant.warehouse.view',
                ];
            case 'store_manager':
                if (!$hasStoreScope) {
                    return ['merchant.enter', 'merchant.profile.view'];
                }
                return [
                    'merchant.enter',
                    'merchant.home.view',
                    'merchant.profile.view',
                    'merchant.customer.view',
                    'merchant.customer.create',
                    'merchant.customer.edit',
                    'merchant.customer.trade',
                    'merchant.data.store',
                    'merchant.data.self',
                    'merchant.debt.view',
                    'merchant.target.view',
                    'merchant.target.manage',
                    'merchant.warehouse.view',
                ];
            case 'store_staff':
                if (!$hasStoreScope) {
                    return ['merchant.enter', 'merchant.profile.view'];
                }
                // 普通员工：仅本人数据入口；整店经营/整店欠款不开放（由业务层拒绝 homeStatics）
                $perms = [
                    'merchant.enter',
                    'merchant.home.view',
                    'merchant.profile.view',
                    'merchant.data.self',
                    'merchant.target.view',
                ];
                if ($hasUserManage) {
                    $perms[] = 'merchant.customer.view';
                }
                return array_values(array_unique($perms));
            case 'platform_service':
                return [
                    'merchant.enter',
                    'merchant.profile.view',
                    'merchant.customer.view',
                ];
            case 'delivery':
                return [
                    'merchant.enter',
                    'merchant.profile.view',
                ];
            default:
                return [];
        }
    }

    /**
     * 是否允许调用整店/区域经营聚合（homeStatics）
     * merchant.data.self 仅表示「本人数据」占位，不能作为整店聚合条件
     */
    public function canAggregateStoreMetrics(array $access): bool
    {
        $perms = $access['permissions'] ?? [];
        return in_array('merchant.data.store', $perms, true)
            || in_array('merchant.data.region', $perms, true);
    }

    /**
     * 接口级权限：必须具备全部 listed 权限
     */
    public function requirePermissions(array $access, array $need, string $message = '暂无操作权限'): void
    {
        $perms = $access['permissions'] ?? [];
        foreach ($need as $p) {
            if (!in_array($p, $perms, true)) {
                throw new \think\exception\ValidateException($message);
            }
        }
    }

    /**
     * 接口级权限：具备任一即可
     */
    public function requireAnyPermission(array $access, array $anyOf, string $message = '暂无操作权限'): void
    {
        $perms = $access['permissions'] ?? [];
        foreach ($anyOf as $p) {
            if (in_array($p, $perms, true)) {
                return;
            }
        }
        throw new \think\exception\ValidateException($message);
    }

    /**
     * 供业务接口使用的门店查询范围；空数组表示禁止聚合查询
     */
    public function requireScopeStoreIds(array $access): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $access['scope_store_ids'] ?? []))));
        return $ids;
    }
}

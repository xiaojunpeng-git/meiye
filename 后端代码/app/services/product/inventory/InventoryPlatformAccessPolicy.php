<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\organization\OrganizationScopeService;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use app\services\query\UnifiedQueryException;
use think\facade\Db;

/**
 * Trusted platform-inventory authority boundary.
 *
 * The browser supplies neither features nor stores.  A platform administrator
 * must hold an enabled inventory API grant, or the narrowly bridged legacy
 * inventory menu for its own read-only queries. Existing add/edit function
 * nodes authorize only their specific create commands, not warehouse management.
 * Store inventory is additionally
 * limited by the organization-admin relation; the platform default HQ
 * inventory does not require a store appointment.
 */
final class InventoryPlatformAccessPolicy
{
    public const AUTH_VIEW = 'inventory-v3-platform-batch-view';
    public const AUTH_COST = 'inventory-v3-platform-batch-cost';
    public const AUTH_EXPORT = 'inventory-v3-platform-batch-export';
    public const AUTH_QUERY_MANAGE = 'inventory-v3-platform-query-manage';
    public const AUTH_WAREHOUSE_SETTINGS = 'inventory-v3-platform-warehouse-settings';
    public const AUTH_WAREHOUSE_MANAGE = 'inventory-v3-platform-warehouse-manage';

    /** 菜单与页面对应平台路由的既有 auth；首页权限仅兼容原路由明确接受它的页面。 */
    private const LEGACY_PAGE_MENUS = [
        'overview' => ['admin-stock-manage'],
        'request' => ['admin-stock-request', 'admin-stock-manage'],
        'transfer' => ['admin-stock-transfer', 'admin-stock-manage'],
        'recipe' => ['admin-salon-recipe', 'admin-stock-manage'],
        'usage' => ['admin-salon-usage', 'admin-stock-manage'],
        'inbound' => ['admin-inbound-manage'],
        'outbound' => ['admin-outbound-manage'],
        'stock' => ['admin-inventory-details'],
        'stock-detail' => ['admin-inventory-details', 'admin-inventory-details-info'],
        'count' => ['admin-inventory-count'],
    ];

    /** 已有功能操作节点才允许新建；页面/首页节点本身绝不提升为仓库管理。 */
    private const CREATE_AUTH = [
        'inbound' => 'admin-inbound-manage-add',
        'outbound' => 'admin-outbound-manage-add',
        'count' => 'admin-inventory-count-add',
        'request' => 'admin-stock-request-add',
        'transfer' => 'admin-stock-transfer-add',
    ];

    /** 按已有功能权限计算具体新建能力；共享商品选择只能用于这些已授权表单。 */
    public function canCreate(array $access, string $page): bool
    {
        if (in_array('inventory.location.manage', (array)($access['features'] ?? []), true)) return true;
        $auth = (array)($access['unique_auth'] ?? []);
        if ($page === 'catalog') {
            foreach (array_keys(self::CREATE_AUTH) as $candidate) if ($this->canCreate($access, $candidate)) return true;
            return false;
        }
        return isset(self::CREATE_AUTH[$page]) && $this->hasLegacyPage($auth, $page)
            && in_array(self::CREATE_AUTH[$page], $auth, true);
    }

    /** 精确映射已授权功能：调拨编辑包含草稿及发收货，不放开其他模块、冲销、导入或仓库设置。 */
    public function allowsFunctionRoute(int $adminId, string $rule, string $method): bool
    {
        $rule = str_replace(['<id>', '<productid>'], [':id', ':productid'], strtolower(trim($rule)));
        if (strtolower(trim($method)) === 'post' && $rule === 'product/inventory/v3/document-remarks/:id') {
            try {
                $access = $this->resolveByAdminId($adminId);
                // Function access opens the note command; the service rechecks its exact page and warehouse scope.
                return in_array(self::AUTH_VIEW, (array)$access['unique_auth'], true)
                    || $this->hasLegacyPage((array)$access['unique_auth'], '*');
            } catch (UnifiedQueryException $exception) { return false; }
        }
        $routes = [
            'post product/inventory/v3/hq/inbound' => 'inbound',
            'post product/inventory/v3/hq/outbound' => 'outbound',
            'post product/inventory/v3/hq/count/confirm' => 'count',
            'post product/inventory/v3/hq/count/draft' => 'count',
            'get product/inventory/v3/hq/count/draft/:id' => 'count',
            'post product/inventory/v3/hq/request/apply' => 'request',
            'post product/inventory/v3/hq/request/:id/bind-supply-warehouse' => 'transfer',
            'get product/inventory/v3/hq/request/requester' => 'request',
            'get product/inventory/v3/hq/request/suppliers' => 'request',
            'post product/inventory/v3/hq/cross-transfer' => 'transfer',
            'post product/inventory/v3/hq/cross-transfer/:id/update' => 'transfer',
            'post product/inventory/v3/hq/cross-transfer/:id/delete-draft' => 'transfer',
            'post product/inventory/v3/hq/cross-transfer/:id/dispatch' => 'transfer',
            'post product/inventory/v3/hq/cross-transfer/:id/receive' => 'transfer',
            'get product/inventory/v3/hq/cross-transfer/incoming-requests' => 'transfer',
            'get product/inventory/v3/hq/cross-transfer/transfer-staffs' => 'transfer',
            'get product/inventory/v3/hq/catalog' => 'catalog',
            'get product/inventory/v3/hq/catalog/barcode' => 'catalog',
        ];
        // 主数据命令可由独立仓库设置权限授权，不传播到任何库存业务写命令。
        if (strtolower(trim($method)) === 'post' && in_array($rule, ['product/inventory/v3/locations', 'product/inventory/v3/locations/:id'], true)) {
            try { return in_array('inventory.location.settings', $this->resolveByAdminId($adminId)['features'], true); }
            catch (UnifiedQueryException $exception) { return false; }
        }
        $page = $routes[strtolower(trim($method)) . ' ' . $rule] ?? '';
        if ($page === '') return false;
        try { return $this->canCreate($this->resolveByAdminId($adminId), $page); }
        catch (UnifiedQueryException $exception) { return false; }
    }

    /**
     * 源码 GET 白名单按精确路由和业务页面衔接旧菜单，绝不补发通用查看或写能力。
     * 统一接口必须解析 pageCode，不能凭任一菜单访问所有业务单据；未知页面拒绝。
     */
    public function allowsLegacyMenuRead(int $adminId, string $rule, string $method, array $query = []): bool
    {
        if (strtolower(trim($method)) !== 'get') return false;
        // 只归一框架定义的参数占位符，不把任意实际 URL/路径前缀当授权路由。
        $rule = str_replace(['<id>', '<productid>'], [':id', ':productid'], strtolower(trim($rule)));
        $routes = [
            'locations' => '*', 'warehouse-settings' => '*', 'import' => '*', 'import/:id/detail' => '*', 'dashboard' => 'overview',
            // The service must still assert its requested page; this grant only reaches the generic read endpoint.
            'document-remarks/:id' => '*',
            'hq/inbound' => 'inbound', 'hq/inbound/:id/detail' => 'inbound', 'hq/inbound/:id/outbound-details' => 'inbound',
            'hq/outbound' => 'outbound', 'hq/outbound/:id/detail' => 'outbound',
            'hq/request' => 'request', 'hq/request/:id/detail' => 'request',
            'hq/request/parties' => 'request', 'hq/request/counterparties' => 'request', 'hq/request/acceptance-parties' => 'request',
            'hq/cross-transfer' => 'transfer', 'hq/cross-transfer/:id/detail' => 'transfer', 'hq/cross-transfer/counterparties' => 'transfer',
            'salon-usage' => 'usage', 'salon-usage/:id/detail' => 'usage',
            'hq/count/:id/detail' => 'count',
            'hq/product-summary' => 'stock', 'hq/product-summary/:productid/detail' => 'stock-detail',
            'batch-stock' => 'stock', 'unified-query/batch-stock' => 'stock', 'unified-query/capabilities' => 'stock',
        ];
        $prefix = 'product/inventory/v3/';
        $page = strpos($rule, $prefix) === 0 ? ($routes[substr($rule, strlen($prefix))] ?? '') : '';
        if ($rule === $prefix . 'unified-query/operational') {
            $page = $this->queryPage(is_string($query['pageCode'] ?? null) ? $query['pageCode'] : '');
        }
        if (in_array($rule, ['product/inventory/recipe/list', 'product/inventory/recipe/info/:id'], true)) $page = 'recipe';
        if ($page === '') return false;
        $admin = Db::name('system_admin')->where('id', $adminId)->where('is_del', 0)->find();
        if (!$admin || (int)$admin['status'] !== 1 || (int)$admin['admin_type'] !== 0) return false;
        return $this->hasLegacyPage($this->roleUniqueAuth($admin), $page);
    }

    /** 统一查询在领域上下文再次校验页面，保护直接调用与后续新增控制器路径。 */
    public function assertQueryPage(array $access, string $pageCode): void
    {
        if (in_array(self::AUTH_VIEW, (array)$access['unique_auth'], true)) return;
        $page = $this->queryPage($pageCode);
        if ($page === '' || !$this->hasLegacyPage((array)$access['unique_auth'], $page)) {
            throw $this->denied('当前岗位无权查看该库存页面。');
        }
    }

    /** 只映射已有统一查询页面；金额、统计口径仍由原 provider 负责。 */
    private function queryPage(string $pageCode): string
    {
        return [
            'inventory_batch_stock' => 'stock', 'inventory_inbound' => 'inbound',
            'inventory_outbound' => 'outbound', 'inventory_count' => 'count',
            'inventory_request' => 'request', 'inventory_transfer' => 'transfer',
            'inventory_salon_usage' => 'usage',
        ][$pageCode] ?? '';
    }

    private function hasLegacyPage(array $auth, string $page): bool
    {
        $menus = $page === '*' ? array_merge(...array_values(self::LEGACY_PAGE_MENUS)) : (self::LEGACY_PAGE_MENUS[$page] ?? []);
        return (bool)array_intersect($auth, $menus);
    }

    /**
     * @return array{admin_id:int,is_super_admin:bool,headquarters_only:bool,store_ids:array,unique_auth:array,features:array,permission_version:string}
     */
    public function resolve(array $adminInfo): array
    {
        $adminId = (int)($adminInfo['id'] ?? 0);
        if ($adminId <= 0) {
            throw $this->denied('无法确认平台库存登录身份。');
        }
        return $this->resolveByAdminId($adminId);
    }

    /**
     * Used by the export worker, which reconstructs authority from the saved
     * account identity rather than trusting a serialized browser profile.
     *
     * @return array{admin_id:int,is_super_admin:bool,headquarters_only:bool,store_ids:array,unique_auth:array,features:array,permission_version:string}
     */
    public function resolveByAdminId(int $adminId): array
    {
        if ($adminId <= 0) {
            throw $this->denied('无法确认平台库存登录身份。');
        }
        $admin = Db::name('system_admin')->where('id', $adminId)->where('is_del', 0)->find();
        if (!$admin || (int)($admin['status'] ?? 0) !== 1 || (int)($admin['admin_type'] ?? 0) === 3) {
            throw $this->denied('当前平台账号不可访问库存。');
        }

        $isSuperAdmin = (int)($admin['level'] ?? 1) === 0;
        $uniqueAuth = $isSuperAdmin ? [
            self::AUTH_VIEW,
            self::AUTH_COST,
            self::AUTH_EXPORT,
            self::AUTH_QUERY_MANAGE,
            self::AUTH_WAREHOUSE_MANAGE,
            self::AUTH_WAREHOUSE_SETTINGS,
        ] : $this->roleUniqueAuth($admin);
        $hasNativeView = in_array(self::AUTH_VIEW, $uniqueAuth, true);
        // 有效旧库存菜单可建立只读数据范围，但不补发 AUTH_VIEW/仓库写权限；
        // HTTP 边界仍逐路由检查，其他库存接口不会因这条兼容路径被放开。
        if (!$hasNativeView
            && !$this->hasLegacyPage($uniqueAuth, '*')) {
            throw $this->denied('当前岗位未配置“平台库存查看”权限。');
        }

        $storeIds = $isSuperAdmin ? $this->allActiveStoreIds() : $this->organizationStoreIds($adminId);
        // HQ is the platform inventory default.  A platform operator may be
        // authorized for HQ inventory without holding any store appointment;
        // store scope is checked later when the STORE subject is requested.
        $headquartersOnly = !$isSuperAdmin && !$storeIds;

        // 平台库存查看即包含当前已授权库存范围内的单价和金额；
        // 旧独立成本菜单仍保留作历史权限记录，但不再是查看金额的前提。
        $features = [InventoryBatchStockQueryContract::PERMISSION_VIEW, InventoryBatchStockQueryContract::PERMISSION_COST];
        if ($hasNativeView && in_array(self::AUTH_EXPORT, $uniqueAuth, true)) {
            $features[] = InventoryBatchStockQueryContract::PERMISSION_EXPORT;
        }
        // UQ preference/custom-field commands are deliberately separate from
        // data viewing.  They still inherit the same trusted DataScope.
        if ($hasNativeView && in_array(self::AUTH_QUERY_MANAGE, $uniqueAuth, true)) {
            $features[] = 'inventory.batch.query.manage';
        }
        // 兼容只读菜单不能替代写路径原有的“库存查看 + 仓库管理”双重前提。
        if ($hasNativeView && in_array(self::AUTH_WAREHOUSE_MANAGE, $uniqueAuth, true)) {
            $features[] = 'inventory.location.manage';
        }

        // 仓库设置只维护主数据，不提升入库、出库、盘点或调拨的写能力。
        if ($hasNativeView && (in_array(self::AUTH_WAREHOUSE_SETTINGS, $uniqueAuth, true) || in_array(self::AUTH_WAREHOUSE_MANAGE, $uniqueAuth, true))) $features[] = 'inventory.location.settings';
        sort($storeIds, SORT_NUMERIC);
        sort($uniqueAuth, SORT_STRING);
        sort($features, SORT_STRING);
        return [
            'admin_id' => $adminId,
            'is_super_admin' => $isSuperAdmin,
            'headquarters_only' => $headquartersOnly,
            'store_ids' => $storeIds,
            'unique_auth' => $uniqueAuth,
            'features' => $features,
            'permission_version' => hash('sha256', implode('|', [
                (string)$adminId,
                (string)(int)($admin['level'] ?? 1),
                implode(',', $uniqueAuth),
                implode(',', $storeIds),
                $headquartersOnly ? 'hq-only' : 'hq-and-store',
            ])),
        ];
    }

    /** @param string[] $features */
    public function assertFeature(array $access, string $feature, string $message): void
    {
        if (!in_array($feature, (array)($access['features'] ?? []), true)) {
            throw $this->denied($message);
        }
    }

    /** @return string[] */
    private function roleUniqueAuth(array $admin): array
    {
        $roleIds = [];
        foreach (explode(',', (string)($admin['roles'] ?? '')) as $value) {
            $id = (int)$value;
            if ($id > 0) $roleIds[$id] = $id;
        }
        if (!$roleIds) return [];
        $rules = Db::name('system_role')->whereIn('id', array_values($roleIds))->where('status', 1)->column('rules');
        $menuIds = [];
        foreach ((array)$rules as $ruleList) {
            foreach (explode(',', (string)$ruleList) as $value) {
                $id = (int)$value;
                if ($id > 0) $menuIds[$id] = $id;
            }
        }
        if (!$menuIds) return [];
        $rows = Db::name('system_menus')->whereIn('id', array_values($menuIds))
            ->where('type', 1)->where('is_del', 0)->field('unique_auth')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $auth = trim((string)($row['unique_auth'] ?? ''));
            if ($auth !== '') $result[$auth] = $auth;
        }
        return array_values($result);
    }

    /** @return int[] */
    private function organizationStoreIds(int $adminId): array
    {
        $orgAdminIds = Db::name('organization_admin')->where('admin_id', $adminId)
            ->where('is_del', 0)->column('id');
        if (!$orgAdminIds) return [];
        /** @var OrganizationScopeService $scope */
        $scope = app()->make(OrganizationScopeService::class);
        $storeIds = [];
        foreach ($orgAdminIds as $orgAdminId) {
            foreach ($scope->getAdminResolvedStoreIds((int)$orgAdminId) as $storeId) {
                $storeId = (int)$storeId;
                if ($storeId > 0) $storeIds[$storeId] = $storeId;
            }
        }
        return array_values($storeIds);
    }

    /** @return int[] */
    private function allActiveStoreIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', Db::name('system_store')
            ->where('is_del', 0)->where('is_show', 1)->column('id')))));
    }

    private function denied(string $message): UnifiedQueryException
    {
        return new UnifiedQueryException('INVENTORY_PLATFORM_ACCESS_DENIED', $message, []);
    }
}

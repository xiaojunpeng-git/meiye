<?php
namespace app\services\cashier\v3\permission;

use app\services\organization\JobPositionPolicyServices;
use app\services\mobile\merchant\MobileMerchantCapabilityCatalog;
use think\facade\Db;

/**
 * 从真实收银会话解析功能入口权限码。
 *
 * 权威链路（普通账号）：
 * AuthTokenMiddleware → 已绑定门店的员工任职 → 员工渠道覆盖（如有）
 * → 岗位 store_v3 规则 → cashier.v3.* feature codes。
 *
 * 平台超级管理员也必须拥有当前门店的有效任职和 store_v3 授权；
 * 平台级别不能绕过门店会话边界。
 *
 * 菜单服务不可用时零权限，不得凭普通 profile.unique_auth 放行。
 */
class CashierV3FeatureResolver
{
    public const FEATURE_CODES = [
        'cashier.v3.ai',
        'cashier.v3.cashier',
        'cashier.v3.cashier.recharge',
        'cashier.v3.cashier.gift',
        'cashier.v3.cashier.checkout',
        'cashier.v3.cashier.card.upgrade',
        'cashier.v3.cashier.card.extend',
        'cashier.v3.cashier.card.transfer',
        'cashier.v3.cashier.card.disable',
        'cashier.v3.cashier.card.enable',
        'cashier.v3.cashier.card.project_replace',
        'cashier.v3.cashier.card.project_upgrade',
        'cashier.v3.writeoff',
        'cashier.v3.room',
        'cashier.v3.reservation',
        'cashier.v3.member',
        'cashier.v3.hang',
        'cashier.v3.order_center',
        'cashier.v3.management_center',
        'cashier.v3.member.create',
        'cashier.v3.member.edit',
        'cashier.v3.member.batch',
        'cashier.v3.inventory.overview',
        'cashier.v3.inventory.inbound',
        'cashier.v3.inventory.outbound',
        'cashier.v3.inventory.stock',
        'cashier.v3.inventory.count',
        'cashier.v3.inventory.movement',
        'cashier.v3.inventory.statistics',
        'cashier.v3.inventory.request',
        'cashier.v3.inventory.transfer',
        'cashier.v3.inventory.usage',
        'cashier.v3.inventory.import',
        'cashier.v3.order.staff_adjust',
        'cashier.v3.order.refund',
        'cashier.v3.order.void',
        'cashier.v3.order.reopen',
        'cashier.v3.order.receipt_print',
        'cashier.v3.order.debt_view',
        'cashier.v3.order.service_detail',
        'cashier.v3.order.service_void',
        'cashier.v3.staff.create',
        'cashier.v3.staff.edit',
        'cashier.v3.staff.permission_edit',
        'cashier.v3.staff.export',
        'cashier.v3.inventory.presale_claim.create',
        'cashier.v3.inventory.presale_claim.detail',
        'cashier.v3.inventory.presale_claim.void',
    ];

    public const UNIQUE_AUTH_TO_FEATURE = [
        'cashier-cashier-index' => 'cashier.v3.cashier',
        'cashier-order-index' => 'cashier.v3.order_center',
        'cashier-hang-index' => 'cashier.v3.hang',
        'cashier-table-index' => 'cashier.v3.room',
        'cashier-verify-index' => 'cashier.v3.writeoff',
        'cashier-reservation-list' => 'cashier.v3.reservation',
        'cashier-recharge-index' => 'cashier.v3.member',
        'cashier-inventory-overview' => 'cashier.v3.inventory.overview',
        'cashier-inventory-inbound' => 'cashier.v3.inventory.inbound',
        'cashier-inventory-outbound' => 'cashier.v3.inventory.outbound',
        'cashier-inventory-stock' => 'cashier.v3.inventory.stock',
        'cashier-inventory-count' => 'cashier.v3.inventory.count',
        'cashier-inventory-movement' => 'cashier.v3.inventory.movement',
        'cashier-inventory-statistics' => 'cashier.v3.inventory.statistics',
        'cashier-inventory-request' => 'cashier.v3.inventory.request',
        'cashier-inventory-transfer' => 'cashier.v3.inventory.transfer',
        'cashier-inventory-usage' => 'cashier.v3.inventory.usage',
        'cashier-inventory-import' => 'cashier.v3.inventory.import',
    ];

    public const SUPER_ADMIN_LEVEL_RULE = 'level_0_super_admin_all_features';

    /**
     * 手机商家端能力与收银 V3 复用能力的唯一服务端桥接。
     *
     * 手机端岗位规则不是 system_menus id，不能再把 401xxx 直接交给
     * getMenusList / system_menus 解析。仅已由 mobile merchant session
     * 解析并标记为可信的服务端操作上下文会走到此映射。
     */
    private const MOBILE_MERCHANT_RULE_TO_FEATURES = [
        MobileMerchantCapabilityCatalog::RULE_RESERVATIONS => ['cashier.v3.reservation'],
    ];

    /** @var callable|null function(array $profile): string[] 仅测试可替换；生产 freeze 后不可换 */
    protected $menuResolver;

    /** @var bool */
    protected $frozen = false;

    public function setMenuResolver(callable $resolver): void
    {
        if ($this->frozen) {
            throw new \LogicException('CashierV3FeatureResolver 已 freeze');
        }
        $this->menuResolver = $resolver;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isSuperAdminLevelRule(array $operatorProfile): bool
    {
        return array_key_exists('level', $operatorProfile) && (int)$operatorProfile['level'] === 0;
    }

    /**
     * @return string[]
     */
    public function resolveGrantedFeatures(array $operatorProfile): array
    {
        // V3 never consumes legacy cashier/store_backend menu rules. The
        // current active staff assignment and V3 position rule must exist.
        // An employee-level entry row is an explicit override: absence inherits
        // the position entry, while an explicit disabled row denies access.
        $granted = $this->storeV3GrantedFeatures($operatorProfile);
        // This marker is produced only by the mobile merchant-session adapter.
        // It re-reads the active mobile capability grants server-side; client
        // input can neither set it nor widen the returned feature set.
        if (!empty($operatorProfile['_trusted_mobile_merchant_session'])) {
            $granted = array_merge($granted, $this->mobileMerchantFeatures((int)($operatorProfile['employee_id'] ?? 0)));
        }
        return array_values(array_unique($granted));
    }

    /**
     * 返回可进入页面的菜单权限。组织人员的门店会话是只读浏览，
     * 页面入口全部可见，但不会获得任何可操作的细粒度权限。
     * @return string[]
     */
    public function resolveVisibleFeatures(array $operatorProfile): array
    {
        if (!empty($operatorProfile['_cashier_v3_delegated'])) {
            $visible = [];
            $walk = static function (array $nodes) use (&$walk, &$visible): void {
                foreach ($nodes as $node) {
                    $code = trim((string)($node['feature_code'] ?? ''));
                    if ($code !== '') $visible[$code] = true;
                    $walk((array)($node['children'] ?? []));
                }
            };
            $walk(JobPositionPolicyServices::STORE_V3_MENU_TREE);
            return array_keys($visible);
        }
        return $this->resolveGrantedFeatures($operatorProfile);
    }

    /** @return string[] */
    private function storeV3GrantedFeatures(array $operatorProfile): array
    {
        $staffId = (int)($operatorProfile['id'] ?? $operatorProfile['staff_id'] ?? 0);
        $employeeId = (int)($operatorProfile['employee_id'] ?? 0);
        $storeId = (int)($operatorProfile['store_id'] ?? 0);
        if (!empty($operatorProfile['_cashier_v3_delegated'])) {
            // delegated 会话只读：页面由 resolveVisibleFeatures 放行，
            // 这里不返回任何可操作权限，避免只读身份被误当作员工授权。
            return [];
        }
        if ($staffId <= 0 || $employeeId <= 0 || $storeId <= 0) {
            return [];
        }
        try {
            $staff = Db::name('system_store_staff')
                ->where('id', $staffId)->where('employee_id', $employeeId)->where('store_id', $storeId)
                ->where('status', 1)->where('is_del', 0)->find();
            if (!$staff) {
                return [];
            }
            $entry = Db::name('staff_channel_entry')
                ->where('staff_id', $staffId)->where('employee_id', $employeeId)->where('store_id', $storeId)
                ->where('channel', JobPositionPolicyServices::CHANNEL_STORE_V3)
                ->where('is_del', 0)->find();
            if ($entry && (int)($entry['status'] ?? 0) !== 1) {
                return [];
            }
            $ruleRows = Db::name('staff_job_position')->alias('job')
                ->join('position position', 'position.id = job.position_id')
                ->join('job_position_channel_rule rule', 'rule.position_id = job.position_id')
                ->where('job.staff_id', $staffId)->where('job.employee_id', $employeeId)
                ->where('job.status', 1)->where('job.is_del', 0)->where('job.end_time', 0)
                ->where('position.status', 1)->where('position.use_store', 1)
                ->where('rule.channel', JobPositionPolicyServices::CHANNEL_STORE_V3)
                ->where('rule.status', 1)
                ->column('rule.rules');
            $ruleIds = [];
            foreach ($ruleRows as $rules) {
                foreach (explode(',', (string)$rules) as $ruleId) {
                    $ruleId = (int)$ruleId;
                    if ($ruleId > 0) $ruleIds[$ruleId] = $ruleId;
                }
            }
            $base = JobPositionPolicyServices::storeV3FeaturesFromRuleIds(array_values($ruleIds));
            return $this->mergeEmployeeOverrides($staffId, $employeeId, $storeId, $base);
        } catch (\Throwable $exception) {
            return [];
        }
    }

    /** @return string[] */
    private function mergeEmployeeOverrides(int $staffId, int $employeeId, int $storeId, array $base): array
    {
        if ($staffId <= 0 || $employeeId <= 0 || $storeId <= 0) return array_values(array_unique($base));
        try {
            $rows = Db::name('staff_store_v3_feature_override')
                ->where('staff_id', $staffId)->where('employee_id', $employeeId)->where('store_id', $storeId)
                ->where('status', 1)->where('is_del', 0)->select()->toArray();
        } catch (\Throwable $e) {
            // Upgrade 尚未执行时保持岗位权限，避免登录整体不可用；写接口仍会提示升级未完成。
            return array_values(array_unique($base));
        }
        $allowed = array_fill_keys(array_values(array_intersect($base, self::FEATURE_CODES)), true);
        foreach ($rows as $row) {
            $code = trim((string)($row['feature_code'] ?? ''));
            if (!in_array($code, self::FEATURE_CODES, true)) continue;
            $effect = (string)($row['effect'] ?? 'inherit');
            if ($effect === 'deny') unset($allowed[$code]);
            if ($effect === 'allow') $allowed[$code] = true;
        }
        return array_keys($allowed);
    }

    /**
     * 数据权限选店会话使用组织直属岗位（staff_id=0）的收银 V3 规则。
     * 规则仍由服务端岗位事实计算，客户端不能注入 feature 列表。
     *
     * @return string[]
     */
    public function employeeStoreV3GrantedFeatures(int $employeeId): array
    {
        if ($employeeId <= 0) {
            return [];
        }
        try {
            $positionIds = Db::name('staff_job_position')
                ->where('employee_id', $employeeId)
                ->where('staff_id', 0)
                ->where('status', 1)->where('is_del', 0)->where('end_time', 0)
                ->column('position_id');
            $positionIds = array_values(array_unique(array_filter(array_map('intval', $positionIds ?: []))));
            if (!$positionIds) {
                return [];
            }
            $ruleRows = Db::name('job_position_channel_rule')
                ->whereIn('position_id', $positionIds)
                ->where('channel', JobPositionPolicyServices::CHANNEL_STORE_V3)
                ->where('status', 1)->column('rules');
            $ruleIds = [];
            foreach ($ruleRows as $rules) {
                foreach (explode(',', (string)$rules) as $ruleId) {
                    $ruleId = (int)$ruleId;
                    if ($ruleId > 0) $ruleIds[$ruleId] = $ruleId;
                }
            }
            return JobPositionPolicyServices::storeV3FeaturesFromRuleIds(array_values($ruleIds));
        } catch (\Throwable $exception) {
            return [];
        }
    }

    /** @return string[] */
    private function featuresFromUniqueAuths(array $uniqueAuths): array
    {
        $granted = [];
        foreach ($uniqueAuths as $auth) {
            $auth = trim((string)$auth);
            if ($auth !== '' && isset(self::UNIQUE_AUTH_TO_FEATURE[$auth])) {
                $granted[] = self::UNIQUE_AUTH_TO_FEATURE[$auth];
            }
        }
        return array_values(array_unique($granted));
    }

    /** @return string[] */
    private function mobileMerchantFeatures(int $employeeId): array
    {
        if ($employeeId <= 0) return [];
        try {
            $rules = (string)\think\facade\Db::name('employee_mobile_auth')
                ->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)->value('rules');
            $ids = [];
            foreach (explode(',', $rules) as $rule) {
                $id = (int)$rule;
                if ($id > 0) $ids[$id] = $id;
            }
            if (!$ids) return [];
            $features = [];
            foreach ($ids as $id) {
                foreach (self::MOBILE_MERCHANT_RULE_TO_FEATURES[$id] ?? [] as $feature) {
                    $features[$feature] = $feature;
                }
            }
            return array_values($features);
        } catch (\Throwable $exception) {
            return [];
        }
    }

    /**
     * 普通账号：必须由菜单服务（或测试注入的同形状 resolver）得到 unique_auth。
     * 菜单服务异常 → 零权限；禁止回退 profile.unique_auth。
     *
     * @return string[]|null null=无法解析 → 零权限
     */
    protected function resolveUniqueAuths(array $operatorProfile)
    {
        if ($this->menuResolver !== null) {
            try {
                return (array)call_user_func($this->menuResolver, $operatorProfile);
            } catch (\Throwable $e) {
                // 菜单服务不可用：零权限（不得凭普通 profile.unique_auth 放行）
                return [];
            }
        }

        if (!isset($operatorProfile['roles']) || !is_array($operatorProfile['roles'])) {
            return null;
        }
        $roles = $operatorProfile['roles'];
        if (!$roles) {
            return [];
        }
        $level = (int)($operatorProfile['level'] ?? 1);
        try {
            /** @var \app\services\system\SystemMenusServices $menus */
            $menus = app()->make(\app\services\system\SystemMenusServices::class);
            [, $uniqueAuth] = $menus->getMenusList($roles, $level, 3);
            return is_array($uniqueAuth) ? array_values($uniqueAuth) : [];
        } catch (\Throwable $e) {
            // 菜单服务不可用：零权限（不得凭普通 profile.unique_auth 放行）
            return [];
        }
    }
}

<?php
namespace app\services\cashier\v3\permission;

/**
 * 从真实收银会话解析功能入口权限码。
 *
 * 权威链路（普通账号）：
 * AuthTokenMiddleware → LoginServices::parseToken() → cashierInfo(roles/level)
 * → SystemMenusServices::getMenusList(roles, level, type=3) → unique_auth
 * → UNIQUE_AUTH_TO_FEATURE → cashier.v3.* feature codes
 *
 * 独立超级管理员规则（level=0）：
 * 与 getMenusList 一致不过滤 rules，功能入口全开。
 *
 * 菜单服务不可用时零权限，不得凭普通 profile.unique_auth 放行。
 */
class CashierV3FeatureResolver
{
    public const FEATURE_CODES = [
        'cashier.v3.cashier',
        'cashier.v3.writeoff',
        'cashier.v3.room',
        'cashier.v3.reservation',
        'cashier.v3.member',
        'cashier.v3.hang',
        'cashier.v3.order_center',
        'cashier.v3.management_center',
        'cashier.v3.member.create',
        'cashier.v3.member.batch',
    ];

    public const UNIQUE_AUTH_TO_FEATURE = [
        'cashier-cashier-index' => 'cashier.v3.cashier',
        'cashier-order-index' => 'cashier.v3.order_center',
        'cashier-hang-index' => 'cashier.v3.hang',
        'cashier-verify-index' => 'cashier.v3.writeoff',
        'cashier-reservation-list' => 'cashier.v3.reservation',
        'cashier-recharge-index' => 'cashier.v3.member',
    ];

    public const SUPER_ADMIN_LEVEL_RULE = 'level_0_super_admin_all_features';

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
        if ($this->isSuperAdminLevelRule($operatorProfile)) {
            return self::FEATURE_CODES;
        }

        $uniqueAuths = $this->resolveUniqueAuths($operatorProfile);
        if ($uniqueAuths === null) {
            return [];
        }

        $granted = [];
        foreach ($uniqueAuths as $auth) {
            $auth = trim((string)$auth);
            if ($auth === '') {
                continue;
            }
            if (isset(self::UNIQUE_AUTH_TO_FEATURE[$auth])) {
                $granted[] = self::UNIQUE_AUTH_TO_FEATURE[$auth];
            }
        }
        return array_values(array_unique($granted));
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

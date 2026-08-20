<?php
namespace app\services\cashier\v3;

/**
 * 可参与 command.contexts 与事务内加锁的资源类型总表。
 *
 * 每个 kind 声明：
 * 1. scope：权威作用域类型
 * 2. lock：固定加锁全序
 * 3. ownership：c1_builtin（C1 底座可默认推导）或 domain（必须注册业务 provider）
 */
class CashierV3ResourceKindCatalog
{
    public const OWNERSHIP_C1 = 'c1_builtin';
    public const OWNERSHIP_DOMAIN = 'domain';

    private const KINDS = [
        'member' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 10, 'ownership' => self::OWNERSHIP_DOMAIN],
        'member_balance' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 20, 'ownership' => self::OWNERSHIP_DOMAIN],
        'member_benefit_pool' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 30, 'ownership' => self::OWNERSHIP_DOMAIN],
        // 权益明细的集合串行点；C3 服务占用与兼容期旧写路径必须共锁。
        'entitlement_occupation_guard' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 31, 'ownership' => self::OWNERSHIP_DOMAIN],
        'member_gift' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 35, 'ownership' => self::OWNERSHIP_DOMAIN],
        'card_holder' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 40, 'ownership' => self::OWNERSHIP_DOMAIN],
        // 销售目录按“卡项组成集合 -> 商品 -> SKU”锁定，禁止按卡内项目顺序取锁。
        'catalog_card_definition' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 41, 'ownership' => self::OWNERSHIP_DOMAIN],
        'catalog_product' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 42, 'ownership' => self::OWNERSHIP_DOMAIN],
        'catalog_sku' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 43, 'ownership' => self::OWNERSHIP_DOMAIN],
        // 定制卡配置在创建后成为结账来源；配置、商品和 SKU 固定按此顺序锁定。
        'custom_card_configuration' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 44, 'ownership' => self::OWNERSHIP_DOMAIN],
        'performance_rule' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 45, 'ownership' => self::OWNERSHIP_DOMAIN],
        'inventory_policy' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 46, 'ownership' => self::OWNERSHIP_DOMAIN],
        'inventory_recipe' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 47, 'ownership' => self::OWNERSHIP_DOMAIN],

        'inventory_stock' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 50, 'ownership' => self::OWNERSHIP_DOMAIN],
        'inventory_batch' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 55, 'ownership' => self::OWNERSHIP_DOMAIN],
        'inventory_shortage_cursor' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 56, 'ownership' => self::OWNERSHIP_DOMAIN],
        'checkout_debt_policy' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 57, 'ownership' => self::OWNERSHIP_DOMAIN],
        'member_debt_guard' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 58, 'ownership' => self::OWNERSHIP_DOMAIN],
        'recharge_order' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 59, 'ownership' => self::OWNERSHIP_DOMAIN],
        'sales_order' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 60, 'ownership' => self::OWNERSHIP_DOMAIN],
        // 服务记录的历史人员调整独立于收银工作台。其版本由成功的调整操作数派生，
        // 防止两个门店人员基于同一条服务记录同时覆盖手艺人分配。
        'service_record' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 61, 'ownership' => self::OWNERSHIP_DOMAIN],
        // 按原始权益订单稳定存在的欠款并发门闩；建欠与还款路径必须共锁。
        'entitlement_debt_guard' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 64, 'ownership' => self::OWNERSHIP_DOMAIN],
        'debt_record' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 65, 'ownership' => self::OWNERSHIP_DOMAIN],
        'service_order' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 70, 'ownership' => self::OWNERSHIP_DOMAIN],
        'writeoff_draft' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 80, 'ownership' => self::OWNERSHIP_DOMAIN],
        'hang_order' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 90, 'ownership' => self::OWNERSHIP_DOMAIN],
        'reservation' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 100, 'ownership' => self::OWNERSHIP_DOMAIN],
        'room' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 110, 'ownership' => self::OWNERSHIP_DOMAIN],
        'room_time_slot' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 120, 'ownership' => self::OWNERSHIP_DOMAIN],
        // 员工档案／门店任职资源；不得用 staff_time_slot 代替员工身份。
        'staff_profile' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 125, 'ownership' => self::OWNERSHIP_DOMAIN],
        'staff_time_slot' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 130, 'ownership' => self::OWNERSHIP_DOMAIN],
        'checkout_request' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 140, 'ownership' => self::OWNERSHIP_DOMAIN],
        // 充值结账有自己的草稿和金额语义，不能复用销售 checkout_request。
        'recharge_checkout_request' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 141, 'ownership' => self::OWNERSHIP_DOMAIN],
        // C1 底座：工作台与账号级查询方案可由 C1 内置 provider 负责
        'cashier_workspace' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 150, 'ownership' => self::OWNERSHIP_C1],
        'query_preference' => ['scope' => CashierV3ResourceScope::TYPE_ACCOUNT, 'lock' => 160, 'ownership' => self::OWNERSHIP_C1],
    ];

    public static function isKnown(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }

    public static function assertKnown(string $kind): void
    {
        if (!isset(self::KINDS[$kind])) {
            throw CashierV3CommandException::invalidContext(
                '本次操作携带了未登记的对象类型，请刷新当前工作台后重试。',
                ['kind' => $kind, 'reason' => 'kind_unknown']
            );
        }
    }

    public static function scopeTypeOf(string $kind): string
    {
        self::assertKnown($kind);
        return self::KINDS[$kind]['scope'];
    }

    public static function lockOrderOf(string $kind): int
    {
        self::assertKnown($kind);
        return self::KINDS[$kind]['lock'];
    }

    /**
     * Stable same-kind resource ordering shared by Gateway and domain planners.
     * Canonical positive decimal IDs follow numeric order without integer casts;
     * opaque IDs and decimal strings with leading zeroes follow byte order.
     */
    public static function compareResourceIds(string $left, string $right): int
    {
        $leftDecimal = preg_match('/^[1-9][0-9]*$/D', $left) === 1;
        $rightDecimal = preg_match('/^[1-9][0-9]*$/D', $right) === 1;
        if ($leftDecimal && $rightDecimal) {
            $lengthOrder = strlen($left) <=> strlen($right);
            if ($lengthOrder !== 0) {
                return $lengthOrder;
            }
        }
        return strcmp($left, $right);
    }

    public static function compareResources(
        string $leftKind,
        string $leftId,
        string $rightKind,
        string $rightId
    ): int {
        $order = self::lockOrderOf($leftKind) <=> self::lockOrderOf($rightKind);
        if ($order !== 0) {
            return $order;
        }
        $kindOrder = strcmp($leftKind, $rightKind);
        return $kindOrder !== 0 ? $kindOrder : self::compareResourceIds($leftId, $rightId);
    }

    public static function ownershipOf(string $kind): string
    {
        self::assertKnown($kind);
        return self::KINDS[$kind]['ownership'];
    }

    public static function isDomainOwned(string $kind): bool
    {
        return self::ownershipOf($kind) === self::OWNERSHIP_DOMAIN;
    }

    /** @return string[] */
    public static function allKinds(): array
    {
        return array_keys(self::KINDS);
    }

    /** @return array<string,string> */
    public static function scopeMatrix(): array
    {
        $matrix = [];
        foreach (self::KINDS as $kind => $definition) {
            $matrix[$kind] = $definition['scope'];
        }
        return $matrix;
    }

    /** @return string[] */
    public static function selfCheck(): array
    {
        $problems = [];
        $seenLockOrders = [];
        foreach (self::KINDS as $kind => $definition) {
            $lock = $definition['lock'] ?? 0;
            $scope = $definition['scope'] ?? '';
            $ownership = $definition['ownership'] ?? '';
            if (!is_int($lock) || $lock <= 0) {
                $problems[] = sprintf('kind %s 的加锁序号无效', $kind);
            }
            if (isset($seenLockOrders[$lock])) {
                $problems[] = sprintf('kind %s 与 %s 的加锁序号重复（%d）', $kind, $seenLockOrders[$lock], $lock);
            }
            $seenLockOrders[$lock] = $kind;
            if (!in_array($scope, CashierV3ResourceScope::allTypes(), true)) {
                $problems[] = sprintf('kind %s 的 scope_type 非法：%s', $kind, (string)$scope);
            }
            if (!in_array($ownership, [self::OWNERSHIP_C1, self::OWNERSHIP_DOMAIN], true)) {
                $problems[] = sprintf('kind %s 的 ownership 非法：%s', $kind, (string)$ownership);
            }
        }
        return $problems;
    }
}

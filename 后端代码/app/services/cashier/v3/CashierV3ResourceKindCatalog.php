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
        'card_holder' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 40, 'ownership' => self::OWNERSHIP_DOMAIN],

        'inventory_stock' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 50, 'ownership' => self::OWNERSHIP_DOMAIN],
        'sales_order' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 60, 'ownership' => self::OWNERSHIP_DOMAIN],
        'debt_record' => ['scope' => CashierV3ResourceScope::TYPE_TENANT, 'lock' => 65, 'ownership' => self::OWNERSHIP_DOMAIN],
        'service_order' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 70, 'ownership' => self::OWNERSHIP_DOMAIN],
        'writeoff_draft' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 80, 'ownership' => self::OWNERSHIP_DOMAIN],
        'hang_order' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 90, 'ownership' => self::OWNERSHIP_DOMAIN],
        'reservation' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 100, 'ownership' => self::OWNERSHIP_DOMAIN],
        'room' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 110, 'ownership' => self::OWNERSHIP_DOMAIN],
        'room_time_slot' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 120, 'ownership' => self::OWNERSHIP_DOMAIN],
        'staff_time_slot' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 130, 'ownership' => self::OWNERSHIP_DOMAIN],
        'checkout_request' => ['scope' => CashierV3ResourceScope::TYPE_STORE, 'lock' => 140, 'ownership' => self::OWNERSHIP_DOMAIN],
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

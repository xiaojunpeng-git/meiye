<?php
namespace app\services\cashier\v3\permission;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3ResultCode;

/**
 * 选择器授权：按业务来源固定的 canonical selector entry，不使用进程内 token。
 *
 * 客户端必须提交 selectorEntry（或 selector_entry），取值仅允许本类登记的短名；
 * 服务端映射到 feature 并校验 DataScope，禁止用 selectorContext 字符串单独判权。
 * 多 worker／跨请求天然一致，无常驻 token 泄漏。
 */
class CashierV3SelectorGrantServices
{
    /** entry 短名 => feature code */
    public const ENTRY_FEATURE_MAP = [
        'cashier' => 'cashier.v3.cashier',
        'writeoff' => 'cashier.v3.writeoff',
        'reservation' => 'cashier.v3.reservation',
        'member' => 'cashier.v3.member',
        'hang' => 'cashier.v3.hang',
        'order_center' => 'cashier.v3.order_center',
        'management_center' => 'cashier.v3.management_center',
        'room' => 'cashier.v3.room',
    ];

    /** 实体类型允许的 entry 集合 */
    public const ENTITY_ALLOWED_ENTRIES = [
        'member' => ['cashier', 'writeoff', 'reservation', 'member', 'hang', 'order_center'],
        'query_entity' => ['cashier', 'reservation', 'management_center', 'order_center', 'member'],
    ];

    /** @var bool */
    protected $frozen = false;

    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * 校验 canonical selector entry 并返回 claims（无 token、可跨 worker）。
     *
     * @return array{entry:string,feature:string,entity_type:string,operator_id:int,store_id:int}
     */
    public function assertCanonicalEntry(
        array $payload,
        CashierV3DataScopeContext $dataScope,
        string $expectedEntityType
    ): array {
        $entry = trim((string)($payload['selectorEntry']
            ?? $payload['selector_entry']
            ?? ''));
        // 兼容旧字段名但不得当作自由文本权限：只接受已登记 entry
        if ($entry === '') {
            $legacy = trim((string)($payload['selectorContext']
                ?? $payload['selectionScope']
                ?? $payload['selector_context']
                ?? ''));
            if ($legacy !== '' && isset(self::ENTRY_FEATURE_MAP[$legacy])) {
                $entry = $legacy;
            }
        }
        if ($entry === '' || !isset(self::ENTRY_FEATURE_MAP[$entry])) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '选择来源无效，请从正确的业务入口重新打开。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'selector_entry_invalid', 'entry' => $entry]
            );
        }
        $allowed = self::ENTITY_ALLOWED_ENTRIES[$expectedEntityType] ?? [];
        if (!in_array($entry, $allowed, true)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '选择来源无效，请从正确的业务入口重新打开。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'selector_entry_entity_mismatch', 'entry' => $entry, 'entity' => $expectedEntityType]
            );
        }
        $feature = self::ENTRY_FEATURE_MAP[$entry];
        if (!$dataScope->hasFeature($feature)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号没有该功能的操作权限，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['feature' => $feature, 'entry' => $entry]
            );
        }
        return [
            'entry' => $entry,
            'feature' => $feature,
            'entity_type' => $expectedEntityType,
            'operator_id' => $dataScope->operatorId(),
            'store_id' => $dataScope->forcedStoreId(),
        ];
    }

    /**
     * @deprecated 已改为 canonical entry；保留签名供旧测试发现迁移失败
     */
    public function issue(
        CashierV3DataScopeContext $dataScope,
        string $entry,
        string $entityType,
        int $ttlSeconds = 300
    ): array {
        throw new \LogicException(
            'CashierV3SelectorGrantServices::issue 已废弃：请使用 canonical selectorEntry，禁止进程内 token'
        );
    }

    /**
     * @deprecated
     */
    public function consume(array $payload, CashierV3DataScopeContext $dataScope, string $expectedEntityType): array
    {
        return $this->assertCanonicalEntry($payload, $dataScope, $expectedEntityType);
    }
}

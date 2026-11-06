<?php
declare(strict_types=1);

namespace app\services\product\inventory;

/** 以认证后的录入身份收窄库存单据，仓库、组织与状态权限仍由各列表先行确定。 */
final class InventoryDocumentCreatorScope
{
    public const FIELD = 'recorded_by_me';
    public const PAGES = ['inventory_inbound', 'inventory_outbound', 'inventory_transfer', 'inventory_salon_usage'];

    public static function platform(int $adminId): array
    {
        return ['id' => $adminId, 'type' => 'PLATFORM_ADMIN'];
    }

    /** 组织选店会话的稳定录入身份是 employee_id，传统门店登录使用任职 ID。 */
    public static function store(int $operatorId, array $profile): array
    {
        if (!empty($profile['_cashier_v3_organization']) || !empty($profile['_cashier_v3_delegated'])) {
            return ['id' => (int)($profile['employee_id'] ?? 0), 'type' => 'EMPLOYEE'];
        }
        return ['id' => $operatorId, 'type' => 'STORE_STAFF'];
    }

    /** 原始流水或领用单保存的身份是录入依据，冲销人及姓名不参与判断。 */
    public static function applyFacts($query, array $actor, string $alias = 'f'): void
    {
        $query->where($alias . '.operator_id', $actor['id'])->where($alias . '.operator_type', $actor['type'])
            ->where($alias . '.operator_id', '>', 0);
    }

    /** 院装单的原领退明细已链接原始流水，由此区分同编号的不同账号。 */
    public static function applyUsage($query, array $actor): void
    {
        $query->where('d.operator_id', $actor['id'])->where('d.operator_id', '>', 0)
            ->whereExists(static function ($match) use ($actor): void {
                $match->name('inventory_salon_usage_line')->alias('original_line')
                    ->join('inventory_batch_movement_fact f', 'f.id=original_line.movement_fact_id')
                    ->whereRaw('original_line.document_id=d.id AND f.tenant_id=d.tenant_id AND f.location_id=d.location_id');
                self::applyFacts($match, $actor);
            });
    }

    /** 调拨按草稿原创建人查询。能证明来自平台总部的早期单据仍可命中。 */
    public static function applyTransfer($query, array $actor): void
    {
        $query->where('d.created_by_operator_id', $actor['id'])->where('d.created_by_operator_id', '>', 0)
            ->where(static function ($typed) use ($actor): void {
                $typed->where('d.created_by_operator_type', $actor['type']);
                if ($actor['type'] === 'PLATFORM_ADMIN') {
                    $typed->whereOr(static function ($legacy): void {
                        $legacy->where('d.created_by_operator_type', '')->where('d.initiator_store_id', 0)
                            ->where('d.from_party_type', 'HQ')->where('d.created_by_operator_name_snapshot', '<>', '');
                    });
                }
            });
    }
}

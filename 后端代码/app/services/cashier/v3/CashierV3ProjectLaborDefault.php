<?php

namespace app\services\cashier\v3;

/** 购物车与人员候选共用的手工费默认值读模型；不改写配置或历史分配事实。 */
final class CashierV3ProjectLaborDefault
{
    /**
     * 已售卡后续核销的手工费按当前卡内项目设置读取。
     * holder/detail/project 三个身份必须相互吻合；找不到仍有效的卡配置时退回项目通用值，
     * 已完成服务的手工费事实不在此处回算。
     */
    public static function entitlementCents(int $projectId, int $holderId, int $detailId, string $tenantId): int
    {
        $projectDefault = self::cents($projectId, $tenantId);
        if ($projectId <= 0 || $holderId <= 0 || $detailId <= 0) {
            return $projectDefault;
        }
        $relation = CashierV3ProjectPersonnelDefaults::currentCardRelation($projectId, $holderId, $detailId);
        return $relation === null ? $projectDefault : max(0, (int)$relation['labor_fee_cents']);
    }

    /** 返回当前租户平台主项目配置的金额（分）；未配置为 0，不改变人员资格或权限。 */
    public static function cents(int $projectId, string $tenantId): int
    {
        if ($projectId <= 0 || $tenantId === '') {
            return 0;
        }
        // 门店复制项目的规则属于 pid 指向的平台主项目；0 仍是明确配置。
        $rule = CashierV3ProjectPersonnelDefaults::projectRule($projectId, $tenantId);
        return is_array($rule)
            ? max(0, (int)($rule['labor_configured_unit_amount_cents'] ?? 0))
            : 0;
    }
}

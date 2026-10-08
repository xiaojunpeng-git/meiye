<?php

namespace app\services\cashier\v3;

use think\facade\Db;

/** 购物车与人员候选共用的手工费默认值读模型；不改写配置或历史分配事实。 */
final class CashierV3ProjectLaborDefault
{
    /** 返回当前租户平台主项目配置的金额（分）；未配置为 0，不改变人员资格或权限。 */
    public static function cents(int $projectId, string $tenantId): int
    {
        if ($projectId <= 0 || $tenantId === '') {
            return 0;
        }
        // 门店复制项目的规则属于 pid 指向的平台主项目。必须先解析身份，
        // 不能让门店旧占位规则（包括非零值）遮住平台配置；平台配置为 0 同样有效。
        $masterId = (int)(Db::name('store_product')
            ->where('id', $projectId)
            ->where('type', 1)
            ->where('product_type', 6)
            ->value('pid') ?: 0);
        $rule = Db::name('cashier_v3_project_performance_rule')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $masterId > 0 ? $masterId : $projectId)
            ->order('id', 'desc')
            ->find();
        return is_array($rule)
            ? max(0, (int)($rule['labor_configured_unit_amount_cents'] ?? 0))
            : 0;
    }
}

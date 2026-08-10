<?php

$root = dirname(__DIR__, 3);
$module = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-08-10-收银V3预约已购项目来源/02-正式升级.sql');

function reservationEntitlementProjectOk(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

reservationEntitlementProjectOk('已购项目按会员、有效卡、有效订单和剩余次数投影',
    strpos($module, "Db::name('user_card_holder')") !== false
    && strpos($module, "->where('uid', \$memberId)") !== false
    && strpos($module, "Db::name('store_order')") !== false
    && strpos($module, "Db::name('store_order_cart_info')") !== false
    && strpos($module, "->where('write_surplus_times', '>', 0)") !== false);
reservationEntitlementProjectOk('同项目的每个权益明细作为独立 card option 返回',
    strpos($module, "'source' => 'card'") !== false
    && strpos($module, "'entitlementSourceDetailId' => \$detailId") !== false
    && strpos($module, "'skuId' => 0") !== false);
reservationEntitlementProjectOk('选择会员后可通过独立目录投影刷新其已购项目',
    strpos($module, "hasProjection('query-reservation-project-catalog')") !== false
    && strpos($module, "registerProjection('query-reservation-project-catalog'") !== false
    && strpos($module, "'reservationProjectCatalog'") !== false);
reservationEntitlementProjectOk('可售项目保留 unpaid 选项，已购项目保存权益明细标识',
    strpos($module, "'source' => 'unpaid'") !== false
    && strpos($module, "\$source === 'card'") !== false
    && strpos($module, '已购项目缺少权益明细') !== false);
reservationEntitlementProjectOk('预约行、编辑器和事件快照保留权益来源明细',
    strpos($module, "'project_source' => \$isEntitlement ? 'ENTITLEMENT' : 'UNPAID'") !== false
    && strpos($module, "'entitlement_source_detail_id' => \$isEntitlement") !== false
    && strpos($module, "'entitlementSourceDetailId' => \$source === 'card'") !== false
    && strpos($module, "'entitlementSourceDetailId' => (int)(\$line['entitlement_source_detail_id'] ?? 0)") !== false);
reservationEntitlementProjectOk('来源明细迁移是独立、幂等且保留历史行的',
    strpos($migration, '20260810-004-cashier-v3-reservation-entitlement-source-v1') !== false
    && strpos($migration, 'information_schema.COLUMNS') !== false
    && strpos($migration, 'ADD COLUMN `entitlement_source_detail_id`') !== false
    && strpos($migration, "NOT NULL DEFAULT ''0''") !== false
    && strpos($migration, 'idx_entitlement_source_detail') !== false);

echo "RESERVATION_ENTITLEMENT_PROJECT_CONTRACT=PASS\n";

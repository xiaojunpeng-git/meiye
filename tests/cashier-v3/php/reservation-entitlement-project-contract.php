<?php

$root = dirname(__DIR__, 3);
$module = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php');
$projection = file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php');
$editor = file_get_contents($root . '/前端代码/cashier-v3/src/components/reservation/ReservationEditorOverlay.vue');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-08-10-收银V3预约已购项目来源/02-正式升级.sql');

function reservationEntitlementProjectOk(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

reservationEntitlementProjectOk('预约已购项目复用使用权益的跨店与欠款权威投影',
    strpos($module, 'CashierV3EntitlementProjectionServices') !== false
    && strpos($module, '->reservationSources($memberId, $operator, $dataScope)') !== false
    && strpos($projection, 'CashierV3CrossStoreEntitlementPolicy') !== false
    && strpos($projection, "'欠款限制后暂无可用次数'") !== false);
reservationEntitlementProjectOk('有物理余次的每个权益明细都返回且保留禁用原因',
    strpos($module, "'source' => 'card'") !== false
    && strpos($module, "'entitlementSourceDetailId' => \$detailId") !== false
    && strpos($module, "'remainingTimes' => \$remainingTimes") !== false
    && strpos($module, "'availableTimes' => max(0, (int)(\$project['availableTimes'] ?? 0))") !== false
    && strpos($module, "'disabledReason' => \$projectSelectable ? ''") !== false);
reservationEntitlementProjectOk('已升级原卡遗留余次不会形成孤立预约权益或触发空订单欠款计算',
    strpos($projection, "\$order = \$orders[(int)\$holder['oid']] ?? [];") !== false
    && strpos($projection, "if (\$order === [])") !== false
    && strpos($projection, '孤立 holder 当作可预约权益') !== false);
reservationEntitlementProjectOk('预约项目弹窗展示卡名余次与不可用原因',
    strpos($editor, 'entitlementOptionText(option)') !== false
    && strpos($editor, '剩余 ${remainingTimes} 次') !== false
    && strpos($editor, "option.disabledReason || '当前不可选择'") !== false);
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

<?php

$root = dirname(__DIR__, 3);
$module = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php');
$lifecycle = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationLifecycleServices.php');
$staffAvailability = file_get_contents($root . '/后端代码/app/services/store/StoreReservationStaffServices.php');
$partition = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationPartitionProvider.php');
$detail = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationDetailQueryServices.php');
$completionFacts = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationCompletionFactServices.php');
$serviceRecordQuery = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-09-15-预约时段与服务前置/02-正式升级.sql');
$serviceSnapshotMigration = file_get_contents($root . '/后端代码/database/upgrades/2026-09-16-预约点客服务单快照/02-正式升级.sql');

function reservationTimeWindowOk(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

reservationTimeWindowOk('预约允许无项目并保留一小时默认时段',
    strpos($module, 'private static function projectInput') !== false
    && strpos($module, 'return array_values($projects);') !== false
    && strpos($module, '$projectDurationMinutes > 0 ? $projectDurationMinutes : 60') !== false);
reservationTimeWindowOk('起止时间由请求显式保存且服务端拒绝倒置或跨日区间',
    strpos($module, "appointmentEndAt'] ?? \$reservation['appointmentEndTime'") !== false
    && strpos($module, '结束时间必须晚于开始时间。') !== false
    && strpos($module, '结束时间必须在预约当日。') !== false
    && substr_count($module, 'self::schedule($reservation, $duration)') >= 4
    && strpos($module, "'appointment_end_at' => \$endAt") !== false);
reservationTimeWindowOk('预约项目时长由后端目录权威计算',
    strpos($module, 'self::projectDuration($projectId, $storeId)') !== false
    && strpos($module, 'Client-provided duration') !== false);
reservationTimeWindowOk('预约级手艺人安排是无项目预约的权威安排',
    strpos($module, "Db::name('cashier_v3_reservation_staff_schedule')") !== false
    && strpos($module, 'replaceScheduledStaff') !== false
    && strpos($staffAvailability, "Db::name('cashier_v3_reservation_staff_schedule')") !== false
    && strpos($migration, 'CREATE TABLE IF NOT EXISTS `eb_cashier_v3_reservation_staff_schedule`') !== false
    && strpos($migration, 'uk_tenant_reservation_staff') !== false);
reservationTimeWindowOk('列表、详情和日历优先读取预约级安排并兼容旧项目行',
    strpos($partition, "Db::name('cashier_v3_reservation_staff_schedule')") !== false
    && strpos($partition, 'artisanStaffByReservation') !== false
    && strpos($detail, "Db::name('cashier_v3_reservation_staff_schedule')") !== false
    && strpos($detail, 'if (!$plannedStaffIds)') !== false
    && strpos($detail, 'if (!$lines) return null;') === false);
reservationTimeWindowOk('开始服务在生成服务单前原子校验项目和手艺人',
    strpos($module, 'assertServiceStartPrerequisitesInTx') !== false
    && strpos($lifecycle, '开始服务前请至少选择一个项目。') !== false
    && strpos($lifecycle, '开始服务前请至少选择一名手艺人。') !== false
    && strpos($lifecycle, 'before any service-order row is inserted') !== false);
reservationTimeWindowOk('点客随预约人员安排、服务单快照和服务事实一并保存',
    strpos($module, 'isPointCustomer') !== false
    && strpos($module, "'is_point_customer' => isset(\$pointCustomerStaffIds") !== false
    && strpos($migration, '`is_point_customer`') !== false
    && strpos($lifecycle, 'cashier_v3_service_order_staff_assignment') !== false
    && strpos($serviceSnapshotMigration, '`eb_cashier_v3_service_order_staff_assignment`') !== false
    && strpos($lifecycle, 'point_customer_staff_ids') !== false
    && strpos($completionFacts, "'isPointCustomer' => isset(\$pointCustomerStaffIds") !== false);
reservationTimeWindowOk('服务记录列表沿用完成时的点客快照',
    strpos($completionFacts, "'craftsman:point'") !== false
    && strpos($completionFacts, "'craftsman:round'") !== false
    && strpos($serviceRecordQuery, 'pointCustomerByLineAndEmployeeId') !== false
    && strpos($serviceRecordQuery, 'pointCustomerFromRoleSnapshot') !== false
    && strpos($serviceRecordQuery, "'isPointCustomer' => \$pointCustomer") !== false
    && strpos($serviceRecordQuery, "'（点）'") !== false);

echo "RESERVATION_TIME_WINDOW_CONTRACT=PASS\n";

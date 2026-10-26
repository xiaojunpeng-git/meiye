<?php

$root = dirname(__DIR__, 3);
$module = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php');
$query = file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationDetailQueryServices.php');

function reservationDetailOk(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

reservationDetailOk('预约详情 action 已注册正式 projection handler',
    strpos($module, "registerProjection('open-reservation-detail'") !== false
    && strpos($module, "new CashierV3ReservationDetailQueryServices()") !== false);
reservationDetailOk('详情按租户、当前操作门店和预约主键读取权威预约头',
    strpos($query, "Db::name('cashier_v3_reservation')") !== false
    && strpos($query, "requiresStoreSetGate()") !== false
    && strpos($query, "allowsStore(\$storeId)") !== false
    && strpos($query, "->where('tenant_id', \$tenantId)") !== false
    && strpos($query, "->where('store_id', \$storeId)") !== false
    && strpos($query, "->where('id', \$reservationId)") !== false);
reservationDetailOk('项目、服务单和操作时间线读取各自权威子表',
    strpos($query, "Db::name('cashier_v3_reservation_line')") !== false
    && strpos($query, "Db::name('cashier_v3_service_order')") !== false
    && strpos($query, "Db::name('cashier_v3_reservation_operation')") !== false);
reservationDetailOk('详情返回完整只读契约、预约业务版本与生命周期动作',
    strpos($query, "'detailReady' => true") !== false
    && strpos($query, "'relatedRecords' => [") !== false
    && strpos($query, "'actions' => self::actions(\$status)") !== false
    && strpos($query, "'edit-reservation'") !== false
    && strpos($query, "'cancel-reservation'") !== false
    && strpos($query, "'start-reservation-service'") !== false
    && strpos($query, "'end-reservation-service'") !== false
    && strpos($module, "'kind' => 'reservation'") !== false);

echo "RESERVATION_DETAIL_PROJECTION_CONTRACT=PASS\n";

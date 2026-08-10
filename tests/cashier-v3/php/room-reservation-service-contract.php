<?php
/**
 * Read-model contract for showing in-service V3 reservations on the room page.
 * This is intentionally source-level: the projection must remain independent
 * from orders, room guards, checkout and all room-occupancy writes.
 */

$root = dirname(__DIR__, 3);
$reader = file_get_contents($root . '/后端代码/app/services/cashier/v3/room/CashierV3RoomReservationReadServices.php');
$partition = file_get_contents($root . '/后端代码/app/services/cashier/v3/room/CashierV3RoomPartitionProvider.php');
$hangModule = file_get_contents($root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangModule.php');

function roomReservationOk(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

roomReservationOk('room projection reads only the new reservation source',
    strpos($reader, "Db::name('cashier_v3_reservation')") !== false
    && strpos($reader, "->where('status', 'IN_SERVICE')") !== false
    && strpos($reader, "->where('room_id', '>', 0)") !== false
    && strpos($reader, "->where('tenant_id', \$dataScope->tenantId())") !== false
    && strpos($reader, "->where('store_id', \$operator->storeId())") !== false
);
roomReservationOk('room projection retains reservation identity, project summary and actual start',
    strpos($reader, "'reservationId' => \$id") !== false
    && strpos($reader, "'reservationNo' =>") !== false
    && strpos($reader, "'projectSummary' => \$projects[\$id]") !== false
    && strpos($reader, "'serviceStartedAt' => self::formatTime") !== false
    && strpos($reader, "'operation_type', 'START_SERVICE'") !== false
);
roomReservationOk('room projection is read-only and never writes orders or room guards',
    strpos($reader, '->insert(') === false
    && strpos($reader, '->update(') === false
    && strpos($reader, '->delete(') === false
    && strpos($reader, 'cashier_v3_service_order') === false
    && strpos($reader, 'RoomOpenServiceGuard') === false
    && strpos($reader, 'checkout') === false
);
roomReservationOk('partition keeps every active reservation for a shared room',
    strpos($partition, 'activeByRoom($operatorScope, $dataScope)') !== false
    && strpos($partition, "'activeReservationServices' =>") === false
    && strpos($partition, "\$room['activeReservationServices'] = \$reservationServices") !== false
    && strpos($partition, "\$room['activeServiceCount'] = count(\$room['activeServiceSources'])") !== false
    && strpos($partition, "\$room['status'] = '服务中'") !== false
);
roomReservationOk('room detail stays a reservation projection without a service order',
    strpos($partition, "\$detail['serviceOrder'] = null") !== false
    && strpos($partition, "\$detail['actions'] = []") !== false
);
roomReservationOk('room refresh returns a rebuilt root state for the current room projection',
    preg_match("/'refresh-room-status'[\\s\\S]{0,1800}'return_root_state'\\s*=>\\s*true/", $hangModule) === 1
);

echo "ROOM_RESERVATION_SERVICE_CONTRACT=PASS\n";

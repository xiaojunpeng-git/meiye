<?php
/** Runs after the shared disposable historical fixture bootstrap, inside one
 * rollback: ordinary main + imported add-on must query and commit through the
 * same authority. No customer database or persistent test data is permitted. */
Db::startTrans();
try {
    $ordinary = Db::name('store_order')->where('id', 5338)->find();
    unset($ordinary['id']);
    $ordinary['order_id'] = 'LOCAL-OWNED-' . bin2hex(random_bytes(5));
    $ordinary['unique'] = bin2hex(random_bytes(16));
    $ordinary['is_system_del'] = 0;
    $ordinary['store_id'] = 11;
    $ordinary['verify_code'] = bin2hex(random_bytes(5));
    $ordinaryId = (int)Db::name('store_order')->insertGetId($ordinary);
    $base = Db::name('store_order_cart_info')->where('id', 13967)->find();
    $make = static function (array $changes) use ($base): int {
        $r = array_merge($base, ['write_surplus_times' => 2, 'write_start' => 0, 'write_end' => 0], $changes);
        unset($r['id']); $r['unique'] = bin2hex(random_bytes(16));
        $id = (int)Db::name('store_order_cart_info')->insertGetId($r);
        cloneReservationRuleComponent($id, $r);
        return $id;
    };
    $main = $make(['oid' => $ordinaryId]);
    $imported = $make(['write_surplus_times' => 1]);
    $expired = $make(['write_end' => time() - 60]);
    $depleted = $make(['write_surplus_times' => 0]);
    $ids = static function (array $groups): array {
        $ids = []; foreach ($groups['list'] as $group) foreach ($group['cart_info'] as $item) $ids[] = (int)$item['id'];
        return $ids;
    };
    $deny = static function (callable $fn, string $reason): void {
        try { $fn(); } catch (ValidateException $expected) { return; }
        throw new RuntimeException('Missing rejection: ' . $reason);
    };
    $options = $ids($service->ownedAddonOptions(421, $ordinaryId, $main, 11));
    if (!in_array($imported, $options, true) || in_array($main, $options, true)
        || in_array($expired, $options, true) || in_array($depleted, $options, true)) {
        throw new RuntimeException('Unified picker did not preserve main/expiry/quota boundaries');
    }
    $deny(static function () use ($service, $ordinaryId, $main): void {
        $service->ownedAddonOptions(420, $ordinaryId, $main, 11);
    }, 'foreign member picker');
    $deny(static function () use ($service, $main): void {
        $service->ownedAddonOptions(421, 5338, $main, 11);
    }, 'main/order mismatch');
    $input = ['cart_num' => 1, 'cart_info_id' => $main, 'store_id' => 11,
        'reservation_time' => date('Y-m-d', time() + 3 * 86400), 'reservation_start' => '10:00',
        'reservation_end' => '10:01', 'service_duration_minutes' => 1,
        'addon_items' => [['cart_info_id' => $imported, 'addon_service_duration' => 1]]];
    foreach ([$expired, $depleted, $main] as $bad) {
        $deny(static function () use ($booking, $ordinaryId, $input, $bad): void {
            $booking->create(421, $ordinaryId, array_merge($input, ['addon_items' => [['cart_info_id' => $bad]]]));
        }, 'invalid mixed add-on');
    }
    $count = (int)Db::name('cashier_v3_reservation')->count();
    $created = $booking->create(421, $ordinaryId, $input);
    $id = (int)$created['reservationId'];
    $lines = Db::name('cashier_v3_reservation_line')->where('reservation_id', $id)->order('id')->select()->toArray();
    $header = Db::name('cashier_v3_reservation')->where('id', $id)->find();
    $minutes = $service->reservationDetail(421, $main, 11)['_project_duration'] + $service->reservationDetail(421, $imported, 11)['_duration'];
    if (count($lines) !== 2 || (int)$lines[1]['entitlement_source_detail_id'] !== $imported
        || (int)$header['appointment_end_at'] - (int)$header['appointment_start_at'] !== $minutes * 60) {
        throw new RuntimeException('Ordinary + imported booking lines/duration differ');
    }
    if ($booking->create(421, $ordinaryId, $input)['reservationId'] !== $created['reservationId']) {
        throw new RuntimeException('Mixed booking replay was not idempotent');
    }
    if (in_array($imported, $ids($service->ownedAddonOptions(421, $ordinaryId, $main, 11)), true)) {
        throw new RuntimeException('Occupied imported visit remained selectable');
    }
    $deny(static function () use ($booking, $ordinaryId, $input): void {
        $booking->create(421, $ordinaryId, array_merge($input, ['reservation_start' => '14:00']));
    }, 'mixed add-on occupied');
    if ((int)Db::name('cashier_v3_reservation')->count() !== $count + 1
        || (int)Db::name('store_order_cart_info')->where('id', $imported)->value('write_surplus_times') !== 1) {
        throw new RuntimeException('Failed mixed booking partially committed or consumed rights');
    }
    Db::name('cashier_v3_reservation')->where('id', $id)->update(['status' => 'CANCELLED']);
    if (!in_array($imported, $ids($service->ownedAddonOptions(421, $ordinaryId, $main, 11)), true)) {
        throw new RuntimeException('Cancelled booking did not release availability');
    }
    // A valid ordinary main never grants another organization's imported right.
    Db::name('migration_entitlement_scope')->where('target_uid', 421)->update(['owner_org_id' => 2]);
    if (in_array($imported, $ids($service->ownedAddonOptions(421, $ordinaryId, $main, 11)), true)) {
        throw new RuntimeException('Imported scope bypassed by ordinary main');
    }
    $deny(static function () use ($booking, $ordinaryId, $input): void {
        $booking->create(421, $ordinaryId, array_merge($input, ['reservation_start' => '16:00']));
    }, 'mixed foreign organization submit');
} finally { Db::rollback(); }
echo "MIXED_OWNED_PICKER_AND_BOOKING_ROLLBACK_PASS\n";

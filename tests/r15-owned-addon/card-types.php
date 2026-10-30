<?php
/** Real issued-rule snapshots, holders and reservation writes in disposable
 * MySQL. Each scenario rolls back; shared counters and kinds are not summed. */
Db::startTrans();
try {
    $fixtureCards = [];
    foreach (['normal', 'choice_kind', 'choice_count', 'time', 'combination'] as $i => $type) {
        $o = Db::name('store_order')->where('id', 5338)->find(); unset($o['id']);
        $o['order_id'] = 'LOCAL-TYPE-' . bin2hex(random_bytes(5)); $o['unique'] = md5($o['order_id']);
        $o['is_system_del'] = 0; $o['store_id'] = 11;
        $oid = (int)Db::name('store_order')->insertGetId($o);
        $holder = Db::name('user_card_holder')->where('oid', 5338)->find(); unset($holder['id']);
        $holder['oid'] = $oid; $holder['card_name'] = '类型测试-' . $type; $holder['card_no'] = (string)(8100000 + $i);
        $hid = (int)Db::name('user_card_holder')->insertGetId($holder);
        $components = []; $ids = [];
        for ($j = 0; $j < ($type === 'combination' ? 4 : 2); $j++) {
            $c = Db::name('store_order_cart_info')->where('id', 13967)->find(); unset($c['id']);
            $c['oid'] = $oid; $c['unique'] = bin2hex(random_bytes(16)); $c['write_start'] = 0; $c['write_end'] = 0;
            $c['write_surplus_times'] = $type === 'time' ? 1000000 : 2; $c['write_times'] = $c['write_surplus_times'];
            $id = (int)Db::name('store_order_cart_info')->insertGetId($c); $ids[] = $id;
            $components[] = ['detailId' => $id, 'projectId' => (int)$c['product_id'], 'configuredPriceCents' => 100,
                'totalTimes' => $c['write_times'], 'remainingTimes' => $c['write_surplus_times'],
                'writeoffAmountCents' => 100, 'selectionStatus' => in_array($type, ['choice_kind', 'combination'], true) ? 'candidate' : 'selected'];
        }
        $f = insertRuleFixture('picker-' . bin2hex(random_bytes(5)), $type, $hid, 421, $type === 'choice_kind' ? 1 : 0,
            $type === 'choice_count' ? 1 : 0, $components, time() - 3600, time() + 30 * 86400);
        if ($type === 'combination') {
            $groups = [];
            foreach (['choice_count', 'choice_kind'] as $n => $rule) {
                $no = $n + 1;
                $def = ['groupNo' => $no, 'ruleType' => $rule, 'choiceLimit' => $rule === 'choice_kind' ? 1 : 0, 'sharedTimes' => $rule === 'choice_count' ? 1 : 0];
                $groups[] = $def;
                Db::name('cashier_v3_card_rule_group_state')->insert([
                    'tenant_id' => '0', 'rule_state_id' => $f['stateId'], 'card_holder_id' => $hid,
                    'group_no' => $no, 'group_name_snapshot' => '测试组' . $no, 'group_rule_type' => $rule,
                    'choice_limit' => $def['choiceLimit'], 'selected_kind_count' => 0,
                    'shared_total_times' => $def['sharedTimes'], 'shared_remaining_times' => $def['sharedTimes'],
                    'definition_snapshot_json' => json_encode($def), 'immutable_fingerprint' => cardRuleFingerprint($def),
                    'state_version' => 1, 'status' => 'active', 'occurred_at' => time(), 'recorded_at' => time(), 'add_time' => time(), 'update_time' => time()]);
            }
            $state = Db::name('cashier_v3_card_rule_state')->where('id', $f['stateId'])->find();
            $def = json_decode($state['definition_snapshot_json'], true); $def['combinationGroups'] = $groups;
            Db::name('cashier_v3_card_rule_state')->where('id', $f['stateId'])->update(['definition_snapshot_json' => json_encode($def), 'immutable_fingerprint' => cardRuleFingerprint($def)]);
            foreach ($ids as $j => $id) {
                $row = Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $id)->find();
                $snap = json_decode($row['component_snapshot_json'], true); $snap['combinationGroupNo'] = $j < 2 ? 1 : 2;
                Db::name('cashier_v3_card_rule_component')->where('id', $row['id'])->update(['combination_group_no' => $snap['combinationGroupNo'], 'component_snapshot_json' => json_encode($snap), 'immutable_fingerprint' => cardRuleFingerprint($snap)]);
            }
        }
        $fixtureCards[$type] = ['oid' => $oid, 'ids' => $ids];
    }
    $options = $service->ownedAddonOptions(421, 5338, 13967, 11);
    foreach ($fixtureCards as $type => $f) {
        $group = null; foreach ($options['list'] as $g) if ((int)$g['order']['id'] === $f['oid']) $group = $g;
        if (!$group || $group['order']['card']['rule_type'] !== $type || count($group['cart_info']) !== count($f['ids'])) throw new RuntimeException('Card type missing: ' . $type);
        $detailUsage = $service->withCardBenefitsReservationUsage(421,
            Db::name('store_order_cart_info')->whereIn('id', $f['ids'])->select()->toArray());
        foreach ($detailUsage as $usageRow) {
            if (!$usageRow['reservation_usage']['known'] || $usageRow['reservation_usage']['available_quantity'] <= 0) throw new RuntimeException('Fresh typed detail unavailable: ' . $type);
            if ($type === 'time' && $usageRow['reservation_usage']['available_text'] !== '不限次') throw new RuntimeException('Virtual time-card count exposed');
        }
        $text = json_encode($group, JSON_UNESCAPED_UNICODE);
        if ($type === 'choice_count' && $group['order']['card']['remaining_text'] !== '共享可用1次') throw new RuntimeException('Shared header summed children');
        if ($type === 'choice_kind' && $group['cart_info'][0]['card_presentation']['remaining_text'] !== '待选 · —') throw new RuntimeException('Unselected kinds shown as independently owned');
        if ($type === 'time' && strpos($group['cart_info'][0]['card_presentation']['remaining_text'], '不限次') === false) throw new RuntimeException('Time virtual count leaked to label');
        if ($type === 'combination' && array_unique(array_column(array_column($group['cart_info'], 'card_presentation'), 'group_no')) !== [1, 3 => 2]) {
            $nos = array_values(array_unique(array_column(array_column($group['cart_info'], 'card_presentation'), 'group_no'))); sort($nos);
            if ($nos !== [1, 2]) throw new RuntimeException('Combination groups not preserved');
        }
    }
    foreach (['choice_count', 'choice_kind', 'combination'] as $i => $type) {
        $ids = $fixtureCards[$type]['ids'];
        $input = ['cart_num' => 1, 'cart_info_id' => 13967, 'store_id' => 11,
            'reservation_time' => date('Y-m-d', time() + (6 + $i) * 86400), 'reservation_start' => '10:00',
            'service_duration_minutes' => 1, 'addon_items' => [['cart_info_id' => $ids[0]], ['cart_info_id' => $ids[1]]]];
        $before = (int)Db::name('cashier_v3_reservation')->count(); $blocked = false;
        try { $booking->create(421, 5338, $input); } catch (ValidateException $expected) { $blocked = true; }
        if (!$blocked || (int)Db::name('cashier_v3_reservation')->count() !== $before) throw new RuntimeException('Shared/kind combined overbooking accepted: ' . $type);
        $input['addon_items'] = [['cart_info_id' => $ids[0]]];
        $created = $booking->create(421, 5338, $input);
        if ((int)$created['reservationId'] <= 0) throw new RuntimeException('Valid typed booking failed');
        // The sibling competes for the same pool/kind slot, despite its own raw count.
        $right = $service->reservationDetail(421, $ids[1], 11);
        // Card-detail projection must agree with the picker for shared pools
        // and kind limits, without counting a sibling as a second direct visit.
        $usageRows = $service->withCardBenefitsReservationUsage(421,
            Db::name('store_order_cart_info')->whereIn('id', $ids)->select()->toArray());
        $usageById = array_column($usageRows, null, 'id');
        if ($usageById[$ids[1]]['reservation_usage']['available_quantity'] !== 0
            || $usageById[$ids[1]]['waiting_service'] !== 0) throw new RuntimeException('Card detail sibling quota/count differs');
        if ($type === 'choice_count' && $usageById[$ids[1]]['reservation_usage']['shared_occupied_quantity'] !== 1) throw new RuntimeException('Shared occupancy missing');
        if ($service->availableQuantity(421, $right) !== 0) throw new RuntimeException('Sibling ignored pending pool/kind occupancy');
        Db::name('cashier_v3_reservation')->where('id', $created['reservationId'])->update(['status' => 'CANCELLED']);
        if ($service->availableQuantity(421, $right) <= 0) throw new RuntimeException('Cancel failed to release shared capacity');
    }
} finally { Db::rollback(); }
echo "FIVE_CARD_TYPES_PRESENTATION_AND_POOL_ROLLBACK_PASS\n";

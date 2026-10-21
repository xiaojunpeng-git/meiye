<?php
declare(strict_types=1);

/** Local MySQL regression: synthetic cards/facts are always rolled back, never run against a remote DB. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
(new think\App($backend . '/'))->initialize();

use think\facade\Db;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
use app\services\cashier\v3\reservation\CashierV3ReservationModule;
use app\services\cashier\v3\reservation\CashierV3ReservationSharedPoolRepairServices;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3ServiceRecordVoidServices;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3ScopeResolver;

if (!in_array(config('database.connections.mysql.hostname'), ['mysql', '127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Local database required');
}
function r24Check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS ', $label, "\n";
}
function r24Clone(string $table, array $row, array $changes): array {
    unset($row['id']);
    $row = array_replace($row, $changes);
    $row['id'] = (int)Db::name($table)->insertGetId($row);
    return $row;
}
function r24Fingerprint(array $value): string {
    $canonical = static function ($value) use (&$canonical) {
        if (!is_array($value)) return $value;
        if (array_keys($value) !== ($value ? range(0, count($value) - 1) : [])) ksort($value);
        return array_map($canonical, $value);
    };
    $value = $canonical($value);
    return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// Reuse schema-complete templates, but create fresh identities and freeze all business inputs.
$baseState = Db::name('cashier_v3_card_rule_state')->where('rule_type', 'choice_count')->find();
if (!$baseState) throw new RuntimeException('An issued shared card template is required');
$baseHolder = Db::name('user_card_holder')->where('id', $baseState['card_holder_id'])->find();
$baseOrder = Db::name('store_order')->where('id', $baseHolder['oid'])->find();
$baseComponent = Db::name('cashier_v3_card_rule_component')->where('rule_state_id', $baseState['id'])->find();
$baseDetail = Db::name('store_order_cart_info')->where('id', $baseComponent['legacy_detail_id'])->find();
$baseLine = Db::name('cashier_v3_reservation_line')->where('project_source', 'ENTITLEMENT')->find();
$baseHeader = Db::name('cashier_v3_reservation')->where('id', $baseLine['reservation_id'])->find();
$now = time();
$tag = 'r24-' . bin2hex(random_bytes(5));
$createdHolders = [];

$fixture = static function (int $total, string $price, array $quantities, string $kind = 'choice_count') use (
    $baseState, $baseHolder, $baseOrder, $baseComponent, $baseDetail, $baseLine, $baseHeader, $tag, $now, &$createdHolders
): array {
    $seq = count($createdHolders);
    $key = $tag . '-' . $seq;
    $order = r24Clone('store_order', $baseOrder, [
        'order_id' => $key, 'unique' => md5($key), 'pay_price' => $price, 'cash_pay_price' => $price,
        'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00',
        'refund_status' => 0, 'terminal_action' => 0, 'paid' => 1, 'is_del' => 0,
    ]);
    $holder = r24Clone('user_card_holder', $baseHolder, [
        'oid' => $order['id'], 'card_name' => 'R24 shared fixture', 'card_no' => (string)random_int(8000000, 9999999),
        'write_times' => $total, 'write_surplus_times' => $total,
        'is_del' => 0, 'write_start' => $now - 100, 'write_end' => 0,
    ]);
    $createdHolders[] = $holder['id'];
    $definition = ['fixture' => $key];
    if ($kind === 'combination') {
        $definition['combinationGroups'] = [];
        foreach ($quantities as $i => $_quantity) $definition['combinationGroups'][] = [
            'groupNo' => $i + 1, 'ruleType' => 'choice_count', 'choiceLimit' => 0, 'sharedTimes' => 1,
        ];
    }
    $state = r24Clone('cashier_v3_card_rule_state', $baseState, [
        'state_id' => 'CRS-' . $key, 'receipt_id' => 'CPR-' . $key,
        'sales_order_id' => 'SO-' . $key, 'sales_order_line_id' => 'SOL-' . $key,
        'card_holder_id' => $holder['id'], 'member_id' => $holder['uid'],
        'rule_type' => $kind, 'shared_total_times' => $total, 'shared_remaining_times' => $total,
        'choice_limit' => 0, 'selected_kind_count' => 0,
        'valid_from' => $now - 100, 'valid_through' => 0, 'status' => 'active', 'state_version' => 1,
        'definition_snapshot_json' => json_encode($definition), 'immutable_fingerprint' => r24Fingerprint($definition),
    ]);
    $header = $baseHeader;
    $header['id'] = random_int(1500000000, 1900000000);
    $header['tenant_id'] = '0';
    $header['member_id'] = $holder['uid'];
    $header['store_id'] = max(1, (int)$holder['store_id']);
    $header['service_order_id'] = 0;
    $header['service_order_no_snapshot'] = 'FW-' . $key;
    $details = [];
    foreach ($quantities as $i => $quantity) {
        $cartSnapshot = ['sourceType' => 'cashier_v3_card_purchase', 'card_rule_type' => $kind,
            'productInfo' => ['store_name' => 'R24 project ' . $i], 'block_writeoff_on_debt' => 1];
        $detail = r24Clone('store_order_cart_info', $baseDetail, [
            'oid' => $order['id'], 'cart_id' => $key . '-' . $i,
            'write_times' => $total, 'write_surplus_times' => $total, 'is_writeoff' => 0,
            'pay_price' => '23.00', 'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00',
            'cart_info' => json_encode($cartSnapshot), 'write_start' => $now - 100, 'write_end' => 0,
        ]);
        $componentSnapshot = ['configuredPriceCents' => 2300, 'fixture' => $key . '-' . $i];
        if ($kind === 'combination') {
            $componentSnapshot['combinationGroupNo'] = $i + 1;
            $group = $definition['combinationGroups'][$i];
            r24Clone('cashier_v3_card_rule_group_state', [], [
                'rule_state_id' => $state['id'],
                'tenant_id' => '0', 'card_holder_id' => $holder['id'], 'group_no' => $i + 1,
                'group_name_snapshot' => 'R24 group ' . $i, 'group_rule_type' => 'choice_count',
                'shared_total_times' => 1, 'shared_remaining_times' => 1,
                'choice_limit' => 0, 'selected_kind_count' => 0, 'state_version' => 1, 'status' => 'active',
                'definition_snapshot_json' => json_encode($group), 'immutable_fingerprint' => r24Fingerprint($group),
                'occurred_at' => $now, 'recorded_at' => $now, 'add_time' => $now, 'update_time' => $now,
            ]);
            $cartSnapshot['combination_group_no'] = $i + 1;
            Db::name('store_order_cart_info')->where('id', $detail['id'])->update(['cart_info' => json_encode($cartSnapshot)]);
        }
        r24Clone('cashier_v3_card_rule_component', $baseComponent, [
            'component_state_id' => 'CRC-' . $key . '-' . $i, 'rule_state_id' => $state['id'],
            'card_holder_id' => $holder['id'], 'legacy_detail_id' => $detail['id'],
            'relation_id' => $detail['id'],
            'combination_group_no' => $kind === 'combination' ? $i + 1 : 0,
            'total_times' => $kind === 'normal' ? $total : 0,
            'remaining_times' => $kind === 'normal' ? $total : 0,
            'state_version' => 1, 'status' => 'active', 'selection_status' => 'not_applicable',
            'component_snapshot_json' => json_encode($componentSnapshot),
            'immutable_fingerprint' => r24Fingerprint($componentSnapshot),
        ]);
        r24Clone('cashier_v3_reservation_line', $baseLine, [
            'reservation_id' => $header['id'], 'tenant_id' => '0', 'line_key' => $key . '-' . $i,
            'entitlement_source_detail_id' => $detail['id'], 'project_id' => $detail['product_id'],
            'project_source' => 'ENTITLEMENT', 'quantity' => $quantity,
            'project_name_snapshot' => 'R24 project ' . $i, 'artisan_staff_ids_json' => '[]',
        ]);
        $details[] = $detail;
    }
    if ($kind === 'combination') {
        r24Clone('cashier_v3_card_purchase_receipt', [], [
            'receipt_id' => 'CPR-' . $key, 'legacy_order_id' => $order['id'], 'card_holder_id' => $holder['id'],
            'sales_order_line_id' => 'SOL-' . $key, 'status' => 'completed',
            'benefit_detail_ids_json' => json_encode(array_column($details, 'id')),
            'tenant_id' => '0', 'checkout_request_id' => $key, 'sales_order_id' => 'SO-' . $key,
            'issue_no' => 1, 'command_idempotency_key' => $key, 'immutable_fingerprint' => hash('sha256', $key),
            'contract_version' => 'local-fixture', 'store_id' => $header['store_id'], 'member_id' => $holder['uid'],
            'catalog_product_id' => $holder['product_id'], 'catalog_sku_id' => 0, 'base_cart_id' => 0,
            'result_snapshot_json' => '{}', 'occurred_at' => $now, 'settled_at' => $now,
            'recorded_at' => $now, 'add_time' => $now, 'update_time' => $now,
        ]);
        r24Clone('cashier_v3_card_sale_item_allocation_fact', [], [
            'allocation_fact_id' => 'CAF-' . $key, 'natural_key' => $key,
            'card_receipt_id' => 'CPR-' . $key, 'component_product_id' => $details[0]['product_id'],
            'sale_amount_cents' => (int)bcmul($price, '100', 0), 'status' => 'effective',
            'contract_version' => 'local-fixture', 'fact_version' => 1, 'reversal_of' => '', 'tenant_id' => '0',
            'organization_id' => $header['organization_id'], 'store_id' => $header['store_id'], 'member_id' => $holder['uid'],
            'order_id' => 'SO-' . $key, 'sale_fact_id' => $key, 'source_line_id' => 'SOL-' . $key,
            'card_issue_no' => 1, 'item_name_snapshot' => 'Local fixture', 'component_count' => count($details),
            'category_id_snapshot' => 0, 'category_name_snapshot' => '', 'category_path_snapshot' => '',
            'configured_amount_cents' => 10000, 'cash_performance_amount_cents' => 10000,
            'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'business_event_no' => $key, 'command_idempotency_key' => $key, 'immutable_fingerprint' => hash('sha256', $key),
            'add_time' => $now, 'update_time' => $now,
        ]);
    }
    return compact('state', 'holder', 'header', 'details', 'order');
};
$consume = static function (array $f, string $suffix = '') use ($now, $tag): array {
    return (new CashierV3ReservationLifecycleServices())->consumeInTx($f['header'], $now, [
        'eventNo' => $tag . ':' . $f['header']['id'] . $suffix,
        'commandIdempotencyKey' => $tag . ':' . $f['header']['id'] . $suffix,
        'operatorId' => 1, 'operatorName' => 'Local regression',
    ]);
};
$facts = static function (array $f): array {
    return Db::name('cashier_v3_entitlement_writeoff_fact')->where('holder_id', $f['holder']['id'])
        ->order('id asc')->select()->toArray();
};
$readiness = new CashierV3CashierReadinessGuard();
$projection = new CashierV3EntitlementProjectionServices($readiness, new CashierV3CashierWorkspaceServices($readiness),
    new CashierV3EntitlementResourceVersionProvider($readiness), new CashierV3ResourceVersionServices(new CashierV3ScopeResolver()));
$project = Closure::bind(function (array $f): array {
    $snapshot = [
        'orders' => [$f['order']],
        'holders' => [Db::name('user_card_holder')->where('id', $f['holder']['id'])->find()],
        'carts' => Db::name('store_order_cart_info')->where('oid', $f['order']['id'])->select()->toArray(),
        'debts' => [], 'actualSaleAmounts' => [],
    ];
    return $this->buildSources($snapshot, [], [], '0', false, true);
}, $projection, CashierV3EntitlementProjectionServices::class);

Db::startTrans();
try {
    $f = $fixture(1, '99.00', [1, 1]);
    $result = $consume($f);
    $rows = $facts($f);
    r24Check(count($rows) === 1 && $result['insufficientEntitlementCount'] === 1, 'two projects cannot consume the same single shared use');
    r24Check((int)$rows[0]['actual_entitlement_amount_cents'] === 9900
        && (int)$rows[0]['purchase_amount_cents_snapshot'] === 9900, '99 yuan shared card writes 99 yuan, never project allocation 23');
    $state = Db::name('cashier_v3_card_rule_state')->where('id', $f['state']['id'])->find();
    r24Check((int)$state['shared_remaining_times'] === 0 && $state['status'] === 'exhausted'
        && (int)Db::name('user_card_holder')->where('id', $f['holder']['id'])->value('write_surplus_times') === 0,
        'issued rule and legacy holder exhaust atomically');
    $sources = $project($f);
    r24Check(count($sources) === 1 && !$sources[0]['selectable']
        && $sources[0]['projects'][0]['disabledReason'] === '共享次数已用完'
        && $sources[0]['projects'][0]['debtRestrictionLabel'] === '',
        'exhausted selector uses shared-pool reason without false debt');

    // Recreate the reported historical drift without changing any immutable fact.
    Db::name('cashier_v3_card_rule_state')->where('id', $state['id'])->update(['shared_remaining_times' => 1, 'status' => 'active']);
    $sources = $project($f);
    r24Check(!$sources[0]['selectable'] && !array_filter($sources[0]['projects'], static function (array $p): bool { return $p['selectable']; })
        && $sources[0]['projects'][1]['disabledReason'] === '权益次数记录不一致，请联系管理员核对'
        && $sources[0]['projects'][1]['debtRestrictionLabel'] === '',
        'legacy holder exhausted but rule pool stale blocks all alternative projects');
    Db::name('cashier_v3_card_rule_state')->where('id', $state['id'])->update(['shared_remaining_times' => 0, 'status' => 'exhausted']);
    // A separate reservation observes the exhausted card under the same authority checks.
    $other = $f;
    $other['header']['id']++;
    $line = Db::name('cashier_v3_reservation_line')->where('reservation_id', $f['header']['id'])->find();
    r24Clone('cashier_v3_reservation_line', $line, ['reservation_id' => $other['header']['id'], 'line_key' => $tag . '-other']);
    $again = $consume($other);
    r24Check($again['writeoff'] === 0 && count($facts($f)) === 1, 'another reservation cannot spend the exhausted pool');

    $rules = new CashierV3CardRuleEntitlementAuthorityServices();
    $org = (string)Db::name('organization_store')->where('store_id', $f['header']['store_id'])->value('org_id');
    $operator = new CashierV3OperatorScope((int)$f['header']['store_id'], 1, $org, '0');
    $dataScope = new CashierV3DataScopeContext(1, 1, $operator->storeId(), '0', $operator->organizationId(),
        null, CashierV3DataScopeContext::MODE_ALL, [], true, 'local_test', '1', ['cashier.v3.cashier'], ['staff_name' => 'Local test']);
    $recorder = new CashierV3BusinessEventRecorder();
    $service = Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id', 'reservation:' . $f['header']['id'])->order('id asc')->find();
    $voidScope = ['operator_scope' => $operator, 'data_scope' => $dataScope, 'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution('void-service-record', $tag . '-void', $operator, $dataScope, 'local-test'),
        'event_contract' => CashierV3ActionManifest::eventContractFor('void-service-record'),
        'idempotency_key' => $tag . '-void', 'payload' => ['serviceFactId' => (string)$service['id'], 'reason' => 'Local regression']];
    $voider = new CashierV3ServiceRecordVoidServices();
    $void = $voider->executeInTx('void-service-record', $voidScope);
    $voidReplay = $voider->executeInTx('void-service-record', $voidScope);
    r24Check((int)$void['restoredQuantity'] === 1 && !empty($voidReplay['replayed'])
        && (int)Db::name('user_card_holder')->where('id', $f['holder']['id'])->value('write_surplus_times') === 1,
        'service void restores shared and legacy pools exactly once on replay');
    r24Check((int)Db::name('cashier_v3_card_rule_state')->where('id', $state['id'])->value('shared_remaining_times') === 1,
        'shared restoration stays within original total');
    try {
        $rules->restoreServiceTimesInTx('0', $f['holder']['id'], $f['details'][0]['id'], 1, $now);
        throw new RuntimeException('duplicate restoration accepted');
    } catch (\app\services\cashier\v3\CashierV3CommandException $e) {
        r24Check(($e->getDetail()['reason'] ?? '') === 'card_rule_restore_overflow', 'duplicate rule restoration rejects overflow');
    }

    $tail = $fixture(3, '100.00', [1, 2]);
    $consume($tail);
    $rows = $facts($tail);
    r24Check(count($rows) === 2 && (int)$rows[0]['actual_entitlement_amount_cents'] === 3300
        && (int)$rows[1]['actual_entitlement_amount_cents'] === 6700
        && (int)$rows[1]['consumed_times_before_snapshot'] === 1,
        'multi-project completion preserves shared whole-yuan sequence and last-use remainder');

    $bad = $fixture(1, '99.50', [1]);
    Db::execute('SAVEPOINT r24_invalid_amount');
    try {
        $consume($bad);
        throw new RuntimeException('fractional shared amount accepted');
    } catch (LogicException $e) {
        if ($e->getMessage() !== 'reservation_shared_purchase_invalid') throw $e;
        Db::execute('ROLLBACK TO SAVEPOINT r24_invalid_amount');
    }
    r24Check((int)Db::name('cashier_v3_card_rule_state')->where('id', $bad['state']['id'])->value('shared_remaining_times') === 1
        && count($facts($bad)) === 0, 'invalid amount rolls back rule deduction and facts');

    $normal = $fixture(3, '99.00', [1], 'normal');
    $consume($normal);
    r24Check((int)Db::name('cashier_v3_card_rule_component')->where('rule_state_id', $normal['state']['id'])
        ->value('remaining_times') === 2, 'normal issued card also updates its rule component');

    $stale = $fixture(3, '99.00', [1]);
    $consume($stale);
    try {
        $rules->applyDeductionsInTx([[
            'holder_id' => $stale['holder']['id'], 'source_detail_id' => $stale['details'][0]['id'],
            'project_id' => $stale['details'][0]['product_id'], 'deduct_physical_times' => 1,
            'expected_physical_remaining_times' => 3,
        ]], ['tenant_id' => '0', 'store_id' => $stale['header']['store_id'],
            'member_id' => $stale['holder']['uid'], 'occurred_at' => $now]);
        throw new RuntimeException('stale shared snapshot accepted');
    } catch (\app\services\cashier\v3\CashierV3CommandException $e) {
        r24Check(($e->getDetail()['reason'] ?? '') === 'card_rule_deduction_version_conflict'
            && (int)Db::name('cashier_v3_card_rule_state')->where('id', $stale['state']['id'])->value('shared_remaining_times') === 2,
            'stale concurrent deduction cannot spend a changed pool');
    }

    $combination = $fixture(2, '100.00', [1, 1], 'combination');
    $consume($combination);
    $rows = $facts($combination);
    r24Check(count($rows) === 2 && (int)$rows[0]['actual_entitlement_amount_cents'] === 5000
        && (int)$rows[1]['actual_entitlement_amount_cents'] === 5000
        && (int)Db::name('cashier_v3_card_rule_group_state')->where('rule_state_id', $combination['state']['id'])->sum('shared_remaining_times') === 0,
        'combination groups deduct independently and preserve their own purchase amounts');

    // Exercise the actual end-service handler, including its locked header state gate.
    $ended = $fixture(3, '99.00', [1]);
    $persistedHeader = r24Clone('cashier_v3_reservation', $ended['header'], [
        'reservation_id' => $tag . '-end', 'reservation_no' => $tag . '-end',
        'status' => 'IN_SERVICE', 'version' => 2, 'lifecycle_generation' => CashierV3ReservationLifecycleServices::GENERATION,
    ]);
    Db::name('cashier_v3_reservation_line')->where('reservation_id', $ended['header']['id'])->update(['reservation_id' => $persistedHeader['id']]);
    $ended['header'] = $persistedHeader;
    $endScope = $voidScope;
    $endScope['action'] = 'end-reservation-service';
    $endScope['idempotency_key'] = $tag . '-end';
    $endScope['payload'] = ['reservationId' => $persistedHeader['id']];
    $endScope['event_execution'] = $recorder->newExecution('end-reservation-service', $tag . '-end', $operator, $dataScope, 'local-test');
    $endScope['event_contract'] = CashierV3ActionManifest::eventContractFor('end-reservation-service');
    $endHandler = Closure::bind(static function (array $scope): array { return self::end($scope); }, null, CashierV3ReservationModule::class);
    $endHandler($endScope);
    try {
        $endHandler($endScope);
        throw new RuntimeException('completed reservation handler accepted twice');
    } catch (\app\services\cashier\v3\CashierV3CommandException $e) {
        r24Check($e->getResultCode() === \app\services\cashier\v3\CashierV3ResultCode::INVALID_COMMAND_CONTEXT
            && count($facts($ended)) === 1
            && (int)Db::name('cashier_v3_card_rule_state')->where('id', $ended['state']['id'])->value('shared_remaining_times') === 2,
            'repeated end-service invocation cannot create a second deduction or writeoff');
    }

    // Emulate only the historical defect on synthetic rows, then exercise the audited correction.
    $historical = $fixture(1, '99.00', [1]);
    $consume($historical);
    $oldWriteoff = $facts($historical)[0];
    Db::name('cashier_v3_entitlement_writeoff_fact')->where('id', $oldWriteoff['id'])
        ->update(['actual_entitlement_amount_cents' => 2300, 'purchase_amount_cents_snapshot' => 2300]);
    Db::name('cashier_v3_performance_fact')->where('checkout_request_id', $oldWriteoff['checkout_request_id'])
        ->update(['amount_cents' => 2300, 'allocation_base_amount_cents' => 2300]);
    Db::name('cashier_v3_card_rule_state')->where('id', $historical['state']['id'])
        ->update(['shared_remaining_times' => 1, 'status' => 'active', 'state_version' => 1]);
    $originalWriteoff = $facts($historical)[0];
    $repair = new CashierV3ReservationSharedPoolRepairServices();
    $plan = $repair->planInTx($historical['holder']['id']);
    try {
        $repair->applyInTx($historical['holder']['id'], str_repeat('0', 64), $tag . '-repair', $now);
        throw new RuntimeException('stale repair fingerprint accepted');
    } catch (LogicException $e) {
        r24Check($e->getMessage() === 'repair_plan_changed', 'historical repair rejects a stale fingerprint before writes');
    }
    $corrected = $repair->applyInTx($historical['holder']['id'], $plan['fingerprint'], $tag . '-repair', $now);
    r24Check($corrected['deltaCents'] === 7600 && $corrected['consumptionNetCents'] === 9900
        && $facts($historical)[0] === $originalWriteoff,
        'historical repair appends a 76-yuan net correction and keeps original writeoff unchanged');
    $historyService = Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id', $oldWriteoff['checkout_request_id'])->find();
    $historyVoid = $voidScope;
    $historyVoid['payload']['serviceFactId'] = (string)$historyService['id'];
    $historyVoid['idempotency_key'] = $tag . '-corrected-void';
    $historyVoid['event_execution'] = $recorder->newExecution('void-service-record', $historyVoid['idempotency_key'], $operator, $dataScope, 'local-test');
    $voider->executeInTx('void-service-record', $historyVoid);
    r24Check((int)Db::name('cashier_v3_performance_fact')->where('checkout_request_id', $oldWriteoff['checkout_request_id'])
        ->where('performance_type', 'consumption_performance_recorded')->sum('amount_cents') === 0
        && (int)Db::name('cashier_v3_card_rule_state')->where('id', $historical['state']['id'])->value('shared_remaining_times') === 1,
        'future service void reverses corrected 99-yuan facts and restores exactly one use');

    // Two independent InnoDB transactions must serialize on the same committed holder.
    Db::name('user_card_holder')->where('id', $baseHolder['id'])->lock(true)->find();
    $c = config('database.connections.mysql');
    $contender = new PDO('mysql:host=' . $c['hostname'] . ';port=' . $c['hostport'] . ';dbname=' . $c['database'],
        $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $contender->exec('SET SESSION innodb_lock_wait_timeout=1');
    $contender->beginTransaction();
    try {
        $q = $contender->prepare('SELECT id FROM eb_user_card_holder WHERE id=? FOR UPDATE');
        $q->execute([$baseHolder['id']]);
        throw new RuntimeException('parallel holder lock was not serialized');
    } catch (PDOException $e) {
        r24Check((int)($e->errorInfo[1] ?? 0) === 1205, 'independent transactions serialize on the common holder lock');
    } finally { $contender->rollBack(); }
} finally {
    Db::rollback();
}
r24Check(Db::name('cashier_v3_entitlement_writeoff_fact')->whereIn('holder_id', $createdHolders)->count() === 0,
    'all synthetic cards and facts rolled back');

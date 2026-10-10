<?php
declare(strict_types=1);

/**
 * R8: exercise the real reversal service against the recovered local seven-operation
 * fixture, never a production connection. Every mutation is inside a rollback-only
 * transaction; this test does not grant gateway permissions or change money facts.
 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\order\CashierV3OrderCenterVoidServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if (getenv('R8_SERIAL_VOID_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only explicitly enabled local Docker rollback tests are permitted');
}

function serialAssert(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS ', $label, "\n";
}

$operator = new CashierV3OperatorScope(39, 976, '165', '0');
$scope = new CashierV3DataScopeContext(976, 976, 39, '0', '165', null,
    CashierV3DataScopeContext::MODE_ALL, [], true, 'integration', '976', [], ['id' => 976, 'staff_name' => 'local rollback']);
$service = new CashierV3OrderCenterVoidServices();
$recorder = new CashierV3BusinessEventRecorder();
$contract = [
    'required_event_types' => ['card.operation.voided'], 'allowed_event_types' => ['card.operation.voided'],
    'event_rules' => ['card.operation.voided' => ['min_count' => 1, 'max_count' => 1,
        'aggregate_type' => 'card_operation', 'source_type' => 'void-order-center-project-replacement']],
    'eventless_reason' => '', 'activation_blocked_until_event_contract' => false,
    'consumers' => ['card.operation.voided' => []],
];
$call = static function (int $id, string $key = '', ?CashierV3OperatorScope $op = null) use ($service, $operator, $scope, $recorder, $contract): array {
    $op = $op ?: $operator;
    $key = $key ?: 'R8-LOCAL-' . bin2hex(random_bytes(16));
    return $service->executeInTx('void-order-center-project-replacement', [
        'operator_scope' => $op, 'data_scope' => $scope, 'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution('void-order-center-project-replacement', $key, $op, $scope, 'r8-local'),
        'event_contract' => $contract, 'idempotency_key' => $key,
        'payload' => ['recordId' => 'card-operation:' . $id, 'reason' => 'R8 rollback test'],
    ]);
};
$hash = static function (): string {
    $data = [];
    foreach (['cashier_v3_order_center_void_operation', 'cashier_v3_business_event', 'cashier_v3_outbox',
        'cashier_v3_card_state', 'cashier_v3_entitlement_resource_version', 'cashier_v3_card_rule_component',
        'cashier_v3_payment_fact', 'cashier_v3_balance_fact', 'cashier_v3_performance_fact'] as $table) {
        // Count/max-id catches test writes; the relevant entitlement rows are hashed in full below.
        $data[$table] = Db::name($table)->field('COUNT(*) n, MAX(id) last_id')->find();
    }
    $data['details'] = Db::name('store_order_cart_info')->where('oid', 2115379)->order('id')->select()->toArray();
    $data['state'] = Db::name('cashier_v3_card_state')->where('card_holder_id', 1884488)->find();
    $data['forward'] = Db::name('cashier_v3_card_operation')->where('source_card_holder_id', 1884488)->order('id')->select()->toArray();
    return hash('sha256', json_encode($data));
};
$before = $hash();
Db::startTrans();
try {
    serialAssert((int)Db::name('store_order_cart_info')->where('id', 4274105)->value('write_surplus_times') === 6
        && (int)Db::name('cashier_v3_card_state')->where('card_holder_id', 1884488)->value('current_version') === 10,
        'recovered fixture starts with 6 remaining, version 10 and two existing voids');
    $immutable = Db::name('cashier_v3_card_operation')->where('source_card_holder_id', 1884488)->order('id')->select()->toArray();
    $version = 10; $remaining = 6;
    foreach ([29, 28, 27, 26, 24] as $id) {
        $key = 'R8-LOCAL-' . bin2hex(random_bytes(16));
        $result = $call($id, $key);
        $remaining += 2; $version++;
        serialAssert(empty($result['data']['void']['replayed'])
            && (int)Db::name('store_order_cart_info')->where('id', 4274105)->value('write_surplus_times') === $remaining
            && (int)Db::name('cashier_v3_card_state')->where('card_holder_id', 1884488)->value('current_version') === $version,
            "reverse {$id}: remainder {$remaining}, monotonically increasing version {$version}");
        $count = Db::name('cashier_v3_business_event')->count();
        serialAssert(!empty($call($id, $key)['data']['void']['replayed'])
            && !empty($call($id)['data']['void']['replayed'])
            && Db::name('cashier_v3_business_event')->count() === $count,
            "same/new-key replay {$id} cannot restore twice or append an event");
    }
    serialAssert(!empty($call(25)['data']['void']['replayed']) && !empty($call(36)['data']['void']['replayed'])
        && (int)Db::name('store_order_cart_info')->where('id', 4274105)->value('write_surplus_times') === 16,
        'previously voided records stay idempotent; final count is original 16, not purchased 28');
    serialAssert($immutable === Db::name('cashier_v3_card_operation')->where('source_card_holder_id', 1884488)->order('id')->select()->toArray(),
        'all original card-operation snapshots remain immutable');
    $ops = array_column($immutable, 'operation_id');
    $voids = Db::name('cashier_v3_order_center_void_operation')->whereIn('source_id', $ops)->where('status', 'succeeded')->select()->toArray();
    serialAssert(count($voids) === 7, 'exactly seven successful reversal audits, including the two existing ones');
    foreach ($voids as $void) {
        serialAssert(Db::name('cashier_v3_business_event')->where('event_no', $void['business_event_no'])
            ->where('source_id', $void['operation_id'])->where('event_type', 'card.operation.voided')->where('reversal_of', '<>', '')->count() === 1,
            'each reversal has an immutable linked event');
    }
    $targets = Db::name('store_order_cart_info')->where('oid', 2115379)->where('cart_id', 'like', 'cop%')->select()->toArray();
    serialAssert(count($targets) === 7 && array_sum(array_column($targets, 'write_surplus_times')) === 0
        && array_sum(array_column($targets, 'refund_num')) === 7, 'seven replacement targets revoked, not deleted');
} finally { Db::rollback(); }
serialAssert($hash() === $before, 'complete serial reversal test rolled back every entitlement, audit, event and version write');

/** Mutations model conflicts only inside a rollback transaction; rejects must leave no additional writes. */
$reject = static function (string $name, callable $mutate, int $id, string $reason, ?CashierV3OperatorScope $op = null) use ($call, $hash): void {
    Db::startTrans();
    try {
        $mutate(); $before = $hash(); $rejected = false;
        try { $call($id, '', $op); }
        catch (CashierV3CommandException $e) { $rejected = ($e->getDetail()['reason'] ?? '') === $reason; }
        serialAssert($rejected && $before === $hash(), $name . ' rejected without partial writes');
    } finally { Db::rollback(); }
};
$noop = static function (): void {};
$reject('out-of-order earlier operation', $noop, 28, 'replacement_void_card_changed');
$reject('different handling store', $noop, 29, 'replacement_void_original_invalid', new CashierV3OperatorScope(1, 976, '165', '0'));
$reject('unknown state marker', static function (): void {
    Db::name('cashier_v3_card_state')->where('card_holder_id', 1884488)->update(['last_operation_id' => 'COP-UNKNOWN']);
}, 29, 'replacement_void_card_changed');
$reject('unexplained version gap', static function (): void {
    Db::name('cashier_v3_card_state')->where('card_holder_id', 1884488)->update(['current_version' => 11]);
}, 29, 'replacement_void_card_changed');
$reject('missing reversal event', static function (): void {
    Db::name('cashier_v3_order_center_void_operation')->where('id', 51)->update(['business_event_no' => 'EV-' . str_repeat('0', 32)]);
}, 29, 'replacement_void_card_changed');
$reject('wrong reversal origin', static function (): void {
    $event = Db::name('cashier_v3_order_center_void_operation')->where('id', 51)->value('business_event_no');
    Db::name('cashier_v3_business_event')->where('event_no', $event)->update(['reversal_of' => 'EV-' . str_repeat('0', 32)]);
}, 29, 'replacement_void_card_changed');
$reject('pending later operation', static function (): void {
    Db::name('cashier_v3_card_operation')->where('id', 36)->update(['operation_status' => 'pending']);
}, 29, 'replacement_void_card_changed');
$reject('target already consumed', static function (): void {
    Db::name('store_order_cart_info')->where('id', 4477113)->update(['write_surplus_times' => 0]);
}, 29, 'replacement_void_target_changed');
$reject('source count drift', static function (): void {
    Db::name('store_order_cart_info')->where('id', 4274105)->update(['write_surplus_times' => 5]);
}, 29, 'replacement_void_source_changed');
$reject('source card transferred', static function (): void {
    Db::name('cashier_v3_card_state')->where('card_holder_id', 1884488)->update(['current_member_id' => 1]);
}, 29, 'replacement_void_card_changed');
$reject('later extension cannot masquerade as reversible replacement', static function (): void {
    Db::name('cashier_v3_card_operation')->where('id', 36)->update(['operation_type' => 'card_extension']);
}, 29, 'replacement_void_card_changed');
$reject('active service blocks reversal', static function (): void {
    $row = Db::name('cashier_v3_service_order_line')->where('status', 'ACTIVE')->find();
    if (!$row) throw new RuntimeException('active service fixture missing');
    Db::name('cashier_v3_service_order_line')->where('id', $row['id'])->update(['entitlement_source_detail_id' => '4274105', 'tenant_id' => '0']);
}, 29, 'replacement_void_downstream_use');

// Late failure is raised after entitlement/card writes. The caller transaction must roll everything back.
Db::startTrans();
try {
    Db::name('cashier_v3_entitlement_resource_version')->where('resource_kind', 'member_benefit_pool')
        ->where('resource_id', '4477113')->update(['current_version' => 0]);
    $rejected = false;
    try { $call(29); }
    catch (CashierV3CommandException $e) { $rejected = ($e->getDetail()['reason'] ?? '') === 'replacement_void_target_version_missing'; }
    serialAssert($rejected, 'late target version failure reaches transactional rollback boundary');
} finally { Db::rollback(); }
serialAssert($hash() === $before, 'late failure restores counts, version, audits and events after rollback');

/** Same historical fixture plus transaction-only issued-rule projections; no historical migration. */
foreach (['normal', 'choice_kind', 'choice_count', 'time'] as $ruleType) {
    Db::startTrans();
    try {
        $definition = ['ruleType' => $ruleType, 'test' => 'R8 rollback'];
        $json = json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stateId = (int)Db::name('cashier_v3_card_rule_state')->insertGetId([
            'state_id' => 'CRS-R8-' . bin2hex(random_bytes(16)), 'tenant_id' => '0',
            'receipt_id' => 'R8-ROLLBACK', 'sales_order_id' => 'R8-ROLLBACK', 'sales_order_line_id' => 'R8-ROLLBACK',
            'card_holder_id' => 1884488, 'member_id' => 989348, 'issue_store_id' => 39,
            'rule_type' => $ruleType, 'rule_version' => 1, 'definition_version' => 1,
            'choice_limit' => $ruleType === 'choice_kind' ? 1 : 0,
            'shared_total_times' => $ruleType === 'choice_count' ? 28 : 0,
            'shared_remaining_times' => $ruleType === 'choice_count' ? 16 : 0,
            'immutable_fingerprint' => hash('sha256', $json), 'definition_snapshot_json' => $json,
            'state_version' => 1, 'status' => 'active',
        ]);
        $sourceComponentId = 0;
        foreach (Db::name('store_order_cart_info')->where('oid', 2115379)->order('id')->select()->toArray() as $detail) {
            $snapshot = json_decode((string)$detail['cart_info'], true) ?: [];
            if ((int)$detail['id'] !== 4274105 && empty($snapshot['cardOperationId'])) continue;
            $originalSource = (int)$detail['id'] === 4274105;
            $componentSnapshot = $originalSource ? ['configuredPriceCents' => 7700] : [
                'replacementOperationId' => $snapshot['cardOperationId'], 'replacementSourceDetails' => [4274105 => 2],
            ];
            if (!$originalSource && $snapshot['cardOperationId'] === 'COP-0660536AE63C5CF95978CFEA0EEDDB92C37C3B01') {
                $componentSnapshot['replacementSourceDetails'][4274105] = 1;
            }
            $componentJson = json_encode($componentSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $componentId = (int)Db::name('cashier_v3_card_rule_component')->insertGetId([
                'component_state_id' => 'CRC-R8-' . bin2hex(random_bytes(16)), 'tenant_id' => '0',
                'rule_state_id' => $stateId, 'card_holder_id' => 1884488, 'legacy_detail_id' => (int)$detail['id'],
                'relation_id' => (int)$detail['id'], 'project_product_id' => (int)$detail['product_id'],
                'project_sku_unique' => 'R8', 'project_type' => 6,
                'total_times' => $originalSource ? 28 : 1, 'remaining_times' => (int)$detail['write_surplus_times'],
                'selection_status' => $ruleType === 'choice_kind' ? 'selected' : 'not_applicable',
                'immutable_fingerprint' => hash('sha256', $componentJson), 'component_snapshot_json' => $componentJson,
                'state_version' => $originalSource ? 7 : ((int)$detail['refund_num'] > 0 ? 2 : 1),
                'status' => (int)$detail['refund_num'] > 0 ? 'revoked' : 'active',
            ]);
            if ($originalSource) $sourceComponentId = $componentId;
        }
        foreach ([29, 28, 27, 26, 24] as $id) $call($id);
        $component = Db::name('cashier_v3_card_rule_component')->where('id', $sourceComponentId)->find();
        $state = Db::name('cashier_v3_card_rule_state')->where('id', $stateId)->find();
        serialAssert((int)$component['remaining_times'] === (in_array($ruleType, ['normal', 'choice_kind'], true) ? 16 : 6)
            && (int)$state['shared_remaining_times'] === ($ruleType === 'choice_count' ? 16 : 0)
            && (string)$state['definition_snapshot_json'] === $json
            && Db::name('cashier_v3_card_rule_component')->where('rule_state_id', $stateId)->where('status', 'revoked')->count() === 7,
            $ruleType . ': five serial voids restore only independent counters and revoke seven targets; shared/time rule unchanged');
        serialAssert((new CashierV3CardRuleEntitlementAuthorityServices())->snapshotForHolder('0', 1884488, true) !== null,
            $ruleType . ': immutable rule fingerprints still valid');
    } finally { Db::rollback(); }
}
serialAssert($hash() === $before, 'all four issued-rule fixtures and serial reversals rolled back');

// Two real connections: the common holder lock excludes concurrent card mutation.
// This verifies DB mutual exclusion, not a committed multi-process gateway stress test.
$config = config('database.connections.mysql');
$pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'],
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
Db::startTrans();
try {
    Db::name('user_card_holder')->where('id', 1884488)->lock(true)->find();
    $pdo->beginTransaction(); $blocked = false;
    try { $pdo->query('SELECT id FROM eb_user_card_holder WHERE id=1884488 FOR UPDATE'); }
    catch (PDOException $e) { $blocked = (int)($e->errorInfo[1] ?? 0) === 1205; }
    serialAssert($blocked, 'second MySQL connection cannot acquire holder while reversal owns it');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    Db::rollback();
}
serialAssert($hash() === $before, 'two-connection conflict left no business writes');
echo "R8_PROJECT_REPLACEMENT_SERIAL_VOID_LOCAL=PASS\n";

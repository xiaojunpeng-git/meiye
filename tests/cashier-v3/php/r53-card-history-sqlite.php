<?php
declare(strict_types=1);

/** R53: execute the real history SQL against an isolated in-memory authority dataset. */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
if (!class_exists(\Swoole\Table::class)) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}
$backend = dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use think\facade\Db;

$app = new \think\App($backend . '/');
$db = new \think\DbManager();
$db->setConfig(['default' => 'r53', 'connections' => ['r53' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'eb_',
    'fields_strict' => true, 'fields_cache' => false,
]]]);
\think\Container::getInstance()->instance(\think\DbManager::class, $db);
if (Db::connect()->getConfig('database') !== ':memory:') throw new RuntimeException('isolation_required');

// Use the actual migration column names, so a query of an invented amount,
// target-holder or tenant column fails instead of being accepted by a mock.
$schemas = [];
foreach (glob($backend . '/database/upgrades/*/*.sql') as $path) {
    $sql = file_get_contents($path);
    preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? `(eb_[^`]+)` \((.*?)\) ENGINE=/s', $sql, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        preg_match_all('/^\s*`([^`]+)`\s+/m', $match[2], $columns);
        $schemas[$match[1]] = $columns[1];
    }
}
$tables = ['cashier_v3_card_state', 'cashier_v3_card_purchase_receipt', 'cashier_v3_sales_order_line', 'cashier_v3_sales_order',
    'cashier_v3_card_operation', 'cashier_v3_card_operation_settlement', 'cashier_v3_multi_card_upgrade',
    'cashier_v3_multi_card_upgrade_source', 'cashier_v3_refund_adjustment', 'cashier_v3_entitlement_service_fact',
    'cashier_v3_entitlement_writeoff_fact', 'cashier_v3_service_record_void_operation',
    'cashier_v3_debt_repayment', 'cashier_v3_debt_repayment_collection', 'cashier_v3_debt_cancellation', 'cashier_v3_debt_restoration',
    'cashier_v3_order_center_void_operation', 'cashier_v3_order_lifecycle_operation',
    'cashier_v3_order_lifecycle_benefit_reversal', 'cashier_v3_balance_fact'];
foreach ($tables as $table) {
    $columns = $schemas['eb_' . $table] ?? null;
    if (!$columns) throw new RuntimeException('migration_missing:' . $table);
    // Remarks were added after the base service-fact migration.
    if (in_array($table, ['cashier_v3_entitlement_service_fact', 'cashier_v3_sales_order_line'], true)) {
        $columns[] = 'detail_remark_snapshot';
    }
    $definitions = array_map(static fn(string $c): string => $c === 'id'
        ? '`id` INTEGER PRIMARY KEY AUTOINCREMENT' : '`' . $c . '` TEXT DEFAULT \'\'', array_unique($columns));
    Db::execute('CREATE TABLE eb_' . $table . ' (' . implode(',', $definitions) . ')');
}
Db::execute('CREATE TABLE eb_user_card_holder (id INTEGER PRIMARY KEY,uid INTEGER,oid INTEGER,card_name TEXT,card_no TEXT,add_time INTEGER,store_id INTEGER,is_del INTEGER DEFAULT 0,write_start INTEGER DEFAULT 0,write_end INTEGER DEFAULT 0,write_surplus_times INTEGER DEFAULT 9)');
Db::execute('CREATE TABLE eb_store_order (id INTEGER PRIMARY KEY,uid INTEGER,pay_price TEXT,card_upgrade_use_oid INTEGER DEFAULT 0,paid INTEGER DEFAULT 1,is_del INTEGER DEFAULT 0,is_user_del INTEGER DEFAULT 0,is_system_del INTEGER DEFAULT 0,mark TEXT DEFAULT \'\',refund_status INTEGER DEFAULT 0,terminal_action INTEGER DEFAULT 0,store_id INTEGER DEFAULT 8)');
Db::execute('CREATE TABLE eb_system_store (id INTEGER PRIMARY KEY,name TEXT)');
Db::execute('CREATE TABLE eb_system_store_staff (id INTEGER PRIMARY KEY,staff_name TEXT)');
Db::execute('CREATE TABLE eb_store_debt (id INTEGER PRIMARY KEY,order_id INTEGER)');
// Legacy column names are verified against the existing writers and archived
// schema. Their identities are separate from the V3 authority identifiers.
Db::execute('CREATE TABLE eb_store_order_status (id INTEGER PRIMARY KEY,oid INTEGER,change_type TEXT,change_message TEXT,change_time INTEGER,change_manager TEXT)');
Db::execute('CREATE TABLE eb_card_project_replacement (id INTEGER PRIMARY KEY,holder_id INTEGER,oid INTEGER,target_amount TEXT,business_time INTEGER,operate_time INTEGER,store_id INTEGER,staff_id INTEGER,status INTEGER,snapshot_json TEXT)');
Db::execute('CREATE TABLE eb_store_order_terminal_operation (id INTEGER PRIMARY KEY,store_order_id INTEGER,state INTEGER,business_type INTEGER,action_type INTEGER,refund_amount TEXT,refund_business_date TEXT,operated_at INTEGER,store_id INTEGER,reason TEXT)');
Db::execute('CREATE TABLE eb_store_order_writeoff (id INTEGER PRIMARY KEY,oid INTEGER,order_cart_id INTEGER,status INTEGER,writeoff_num INTEGER,add_time INTEGER,type INTEGER,relation_id INTEGER,service_type INTEGER,staff_id INTEGER)');
Db::execute('CREATE TABLE eb_store_order_cart_info (id INTEGER PRIMARY KEY,oid INTEGER,cart_info TEXT)');
// The migration-scope optional-table probe is supported without loading any
// environment file or connecting to a local/remote business database.
Db::execute("ATTACH DATABASE ':memory:' AS information_schema");
Db::execute('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT,TABLE_NAME TEXT)');
Db::connect()->getPdo()->sqliteCreateFunction('DATABASE', static fn(): string => 'r53');

$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException($name);
    $checks++;
};
$insert = static function (string $table, array $row): void { Db::name($table)->insert($row); };
$base = ['tenant_id' => 'T', 'business_date' => '2026-10-24', 'occurred_at' => 100,
    'store_id' => 8, 'operator_id' => 1];
$operator = new CashierV3OperatorScope(8, 1, 'ORG', 'T');
$scope = new CashierV3DataScopeContext(1, 1, 8, 'T', 'ORG', [8], CashierV3DataScopeContext::MODE_STORES,
    [], false, '', 'r53', [], ['id' => 1]);
$service = new CashierV3MemberDetailQueryServices();
$history = static function (int $holder, int $page = 1) use ($service, $operator, $scope): array {
    return $service->cardHistory(2, $holder, $page, $operator, $scope);
};
$byKey = static fn(array $result): array => array_column($result['records'], null, 'key');
$insert('system_store', ['id' => 8, 'name' => '历史门店']);
$insert('system_store_staff', ['id' => 1, 'staff_name' => '办理人']);
foreach ([10, 20, 30, 40, 50] as $id) {
    $insert('user_card_holder', ['id' => $id, 'uid' => $id === 40 ? 3 : 2, 'oid' => $id,
        'card_name' => '卡' . $id, 'card_no' => '重复卡号', 'store_id' => 8, 'add_time' => 1]);
    $insert('store_order', ['id' => $id, 'uid' => 1, 'pay_price' => '100.00']);
}
$operation = static function (string $id, string $type, array $extra = []) use ($insert, $base): void {
    $insert('cashier_v3_card_operation', array_replace($base, [
        'operation_id' => $id, 'operation_type' => $type, 'operation_status' => 'succeeded',
        'source_card_holder_id' => 10, 'card_name_snapshot' => '原卡', 'card_no_snapshot' => '原卡号',
        'settlement_delta_cents' => 0, 'source_remaining_value_cents' => 12300, 'reason_snapshot' => '办理原因',
        'member_name_after_snapshot' => '受让会员', 'write_end_after' => 1792857600, 'card_status_after' => 'disabled',
    ], $extra));
};
foreach (['card_upgrade', 'card_extension', 'card_transfer', 'card_disable', 'card_enable', 'project_replacement', 'project_upgrade', 'card_to_balance'] as $type) {
    $operation($type, $type);
}
$replacementId = Db::name('cashier_v3_card_operation')->where('operation_id', 'project_replacement')->value('id');
$operation('pending', 'card_upgrade', ['operation_status' => 'awaiting_checkout']);
$operation('failed', 'project_upgrade', ['operation_status' => 'failed']);
$operation('cancelled', 'card_upgrade', ['operation_status' => 'cancelled']);
$operation('foreign-tenant', 'card_to_balance', ['tenant_id' => 'OTHER']);
$operation('foreign-holder', 'card_transfer', ['source_card_holder_id' => 40]);
$rows = $byKey($history(10));
$check(count($rows) === 12, 'all_operations_and_purchase_without_foreign_rows');
$check($rows['operation:card_to_balance']['amount'] === '123.00', 'conversion_uses_full_source_value_not_zero_delta');
$check($rows['operation:card_to_balance']['type'] === '卡转余额', 'conversion_type_label');
$check($rows['operation:card_to_balance']['result'] === '已完成；全额转入赠金；整卡已作废', 'conversion_terminal_result');
$check($rows['operation:card_to_balance']['detailRemark'] === '办理原因', 'reason_is_separate');
Db::name('cashier_v3_card_operation')->where('operation_id', 'card_to_balance')->update(['operation_status' => 'completed']);
$completed = $byKey($history(10))['operation:card_to_balance'];
$check($completed['result'] === '已完成；全额转入赠金；整卡已作废' && $completed['amount'] === '123.00', 'core_completed_conversion_status_and_amount');
$check($rows['operation:pending']['result'] === '待结账' && $rows['operation:failed']['result'] === '失败'
    && $rows['operation:cancelled']['result'] === '已取消', 'statuses_not_reason');
$check($rows['operation:card_transfer']['content'] === '受让会员'
    && $rows['operation:card_disable']['content'] === '停用'
    && $rows['operation:card_extension']['content'] !== '', 'operation_specific_content');

// Single-card target has no source operation of its own. The real settlement
// link must recover it while ignoring a different tenant's matching target.
$insert('cashier_v3_card_operation_settlement', ['tenant_id' => 'T', 'operation_id' => 'card_upgrade',
    'target_card_holder_id' => 30, 'source_card_holder_id' => 10, 'settlement_status' => 'settled', 'sales_order_id' => 'SALE-SINGLE']);
$rows = $byKey($history(30));
$check(isset($rows['operation:card_upgrade']) && str_contains($rows['operation:card_upgrade']['content'], '原卡号'), 'new_card_recovers_single_upgrade_source');

foreach ([['S1', 1, 'LINE', 'REQ', 1], ['S2', 2, 'LINE', 'OTHER-REQ', 2]] as [$id, $pk, $line, $request, $member]) {
    $insert('cashier_v3_entitlement_service_fact', array_replace($base, ['id' => $pk, 'service_fact_id' => $id,
        'source_line_id' => $line, 'checkout_request_id' => $request, 'member_id' => $member,
        'service_status' => 'completed', 'quantity' => 1, 'project_name_snapshot' => '服务' . $id, 'detail_remark_snapshot' => '核销备注']));
    $insert('cashier_v3_entitlement_writeoff_fact', array_replace($base, ['source_line_id' => $line,
        'checkout_request_id' => $request, 'holder_id' => $pk === 1 ? 10 : 40, 'status' => 'completed']));
}
$insert('cashier_v3_service_record_void_operation', array_replace($base, ['operation_id' => 'SV', 'service_fact_id' => 1,
    'status' => 'succeeded', 'reason_snapshot' => '退回权益']));
$insert('cashier_v3_refund_adjustment', array_replace($base, ['adjustment_id' => 'RA', 'operation_id' => 'RF',
    'card_holder_id' => 10, 'status' => 'voided', 'refund_amount_cents' => 1200, 'void_target' => 1, 'target_name_snapshot' => '原卡', 'reason_snapshot' => '退卡']));
foreach ([['VV', 'refund:RF'], ['PV', 'card-operation:' . $replacementId]] as [$id, $record]) {
    $insert('cashier_v3_order_center_void_operation', ['tenant_id' => 'T', 'operation_id' => $id,
        'record_id' => $record, 'status' => 'succeeded', 'store_id' => 8, 'operator_id' => 1,
        'created_at' => 120, 'reason_snapshot' => '作废原因']);
}
$rows = $byKey($history(10));
$check(isset($rows['writeoff:S1']) && !isset($rows['writeoff:S2']), 'pre_transfer_writeoff_exact_request_holder');
$check($rows['writeoff:S1']['result'] === '已作废' && isset($rows['service-void:SV']), 'writeoff_void_both_facts');
$check($rows['refund:RA']['result'] === '已作废' && isset($rows['void:VV']), 'voided_refund_not_lost');
$check($rows['operation:project_replacement']['result'] === '已作废' && isset($rows['void:PV']), 'replacement_void_uses_numeric_record_identity');

$insert('cashier_v3_multi_card_upgrade', ['tenant_id' => 'T', 'upgrade_id' => 'MULTI', 'upgrade_status' => 'settled',
    'target_card_holder_id' => 50, 'sales_order_id' => 'SALE-MULTI', 'sales_order_line_id' => 'ML', 'settled_at' => 110]);
foreach ([10, 20] as $line => $source) $insert('cashier_v3_multi_card_upgrade_source', ['tenant_id' => 'T',
    'upgrade_id' => 'MULTI', 'source_card_holder_id' => $source, 'line_no' => $line + 1, 'credit_cents' => 5000,
    'source_card_name_snapshot' => '卡' . $source, 'source_card_no_snapshot' => 'NO' . $source]);
$insert('cashier_v3_sales_order', array_replace($base, ['order_id' => 'SALE-MULTI']));
$insert('cashier_v3_sales_order_line', ['tenant_id' => 'T', 'order_line_id' => 'ML', 'item_name_snapshot' => '新卡']);
$rows = $byKey($history(50));
$check(str_contains($rows['multi-upgrade:MULTI']['content'], 'NO10')
    && str_contains($rows['multi-upgrade:MULTI']['content'], 'NO20') && $rows['multi-upgrade:MULTI']['amount'] === '100.00', 'multi_target_all_sources_once');
$check($byKey($history(20))['multi-upgrade:MULTI']['amount'] === '50.00', 'each_source_only_its_credit');
$insert('cashier_v3_order_lifecycle_operation', array_replace($base, ['operation_id' => 'LIFE', 'source_type' => 'sales',
    'source_order_id' => 'SALE-MULTI', 'operation_type' => 'void', 'status' => 'succeeded', 'cash_refund_cents' => 0, 'reason_snapshot' => '销售作废']));
$insert('cashier_v3_order_lifecycle_benefit_reversal', ['tenant_id' => 'T', 'card_holder_id' => 50, 'operation_id' => 'LIFE', 'status' => 'revoked']);
foreach ([10, 20, 50] as $id) {
    $rows = $byKey($history($id));
    $check(isset($rows['lifecycle:LIFE']) && str_contains($rows['multi-upgrade:MULTI']['result'], '已冲销'), 'multi_reversal_visible:' . $id);
    $check(count(array_filter($rows, static fn(array $r): bool => $r['key'] === 'lifecycle:LIFE')) === 1, 'one_reversal_per_identity:' . $id);
}

// A single upgrade reversal must remain visible on both sides and preserve
// unrelated failed void attempts as attempts, never terminal business facts.
Db::name('cashier_v3_card_operation_settlement')->where('operation_id', 'card_upgrade')
    ->update(['settlement_status' => 'reversed']);
$insert('cashier_v3_order_lifecycle_operation', array_replace($base, ['operation_id' => 'SINGLE-VOID', 'source_type' => 'sales',
    'source_order_id' => 'SALE-SINGLE', 'operation_type' => 'void', 'status' => 'succeeded', 'cash_refund_cents' => 0]));
foreach ([10, 30] as $id) {
    $rows = $byKey($history($id));
    $check(isset($rows['lifecycle:SINGLE-VOID']) && $rows['operation:card_upgrade']['result'] === '已冲销；原权益已恢复', 'single_upgrade_reversal:' . $id);
}
$insert('cashier_v3_order_center_void_operation', ['tenant_id' => 'OTHER', 'operation_id' => 'FOREIGN-VOID',
    'record_id' => 'card-operation:' . $replacementId, 'status' => 'succeeded']);
$insert('cashier_v3_order_center_void_operation', ['tenant_id' => 'T', 'operation_id' => 'FAILED-VOID',
    'record_id' => 'card-operation:' . $replacementId, 'status' => 'failed']);
$rows = $byKey($history(10));
$check(!isset($rows['void:FOREIGN-VOID']) && !isset($rows['void:FAILED-VOID']), 'void_attempt_scope_and_status');

$insert('store_debt', ['id' => 1, 'order_id' => 10]);
$insert('cashier_v3_debt_repayment', array_replace($base, ['repayment_id' => 'RP', 'repayment_no' => '补交单',
    'debt_id' => 1, 'repayment_amount_cents' => 2000, 'status' => 'voided']));
$insert('cashier_v3_debt_repayment_collection', ['repayment_id' => 'RP', 'remark_snapshot' => '收款备注', 'payment_line_no' => 1]);
$insert('cashier_v3_order_center_void_operation', ['tenant_id' => 'T', 'operation_id' => 'RPV',
    'record_id' => 'v3-sales-supplement:RP', 'status' => 'succeeded', 'store_id' => 8, 'operator_id' => 1,
    'created_at' => 120, 'reason_snapshot' => '补交作废']);
$insert('cashier_v3_debt_cancellation', array_replace($base, ['cancellation_id' => 'DC', 'debt_id' => 1,
    'debt_no_snapshot' => '欠款单', 'cancelled_debt_cents' => 1000, 'reason_snapshot' => '免除原因']));
$insert('cashier_v3_debt_restoration', array_replace($base, ['restoration_id' => 'DR', 'cancellation_id' => 'DC',
    'debt_id' => 1, 'restored_debt_cents' => 1000]));
$rows = $byKey($history(10));
$check(isset($rows['repayment:RP'], $rows['void:RPV']) && $rows['repayment:RP']['result'] === '已作废'
    && $rows['repayment:RP']['detailRemark'] === '收款备注', 'repayment_void_and_collection_remarks');
$check(isset($rows['debt-cancel:DC'], $rows['debt-restore:DR']) && str_contains($rows['debt-cancel:DC']['result'], '已冲销'), 'debt_restoration_preserves_original');

// Purchase line remarks are retained alongside operation-specific notes.
$insert('cashier_v3_card_purchase_receipt', ['tenant_id' => 'T', 'card_holder_id' => 10, 'sales_order_line_id' => 'PURCHASE']);
$insert('cashier_v3_sales_order_line', ['tenant_id' => 'T', 'order_line_id' => 'PURCHASE', 'detail_remark_snapshot' => '购卡备注']);
$check($byKey($history(10))['card:10']['detailRemark'] === '购卡备注', 'purchase_remark_preserved');

foreach ([['card_transfer', 1], ['card_extend', 2], ['card_upgrade_use', 3], ['refund_price', 4]] as [$type, $id]) {
    $insert('store_order_status', ['id' => $id, 'oid' => 10, 'change_type' => $type,
        'change_message' => '历史记录' . $id, 'change_time' => 20, 'change_manager' => '历史操作人']);
}
$insert('store_order_status', ['id' => 5, 'oid' => 40, 'change_type' => 'card_transfer', 'change_time' => 20]);
$insert('card_project_replacement', ['id' => 1, 'holder_id' => 10, 'oid' => 10, 'target_amount' => '20',
    'business_time' => 21, 'operate_time' => 22, 'store_id' => 8, 'staff_id' => 1, 'status' => 0,
    'snapshot_json' => json_encode(['target' => ['name' => '历史替换项目'], 'remark' => '历史替换备注'])]);
$insert('store_order_cart_info', ['id' => 1, 'oid' => 10,
    'cart_info' => json_encode(['productInfo' => ['store_name' => '历史项目']])]);
$insert('store_order_cart_info', ['id' => 2, 'oid' => 40, 'cart_info' => '{}']);
foreach ([[1, 1, 1], [2, 2, 0]] as [$id, $detail, $status]) $insert('store_order_writeoff', [
    'id' => $id, 'oid' => 10, 'order_cart_id' => $detail, 'status' => $status, 'writeoff_num' => 2,
    'add_time' => 30, 'type' => 1, 'relation_id' => 8, 'service_type' => 0, 'staff_id' => 1]);
$rows = $byKey($history(10));
$check(isset($rows['legacy-status:1'], $rows['legacy-status:2'], $rows['legacy-status:3'], $rows['legacy-status:4'])
    && !isset($rows['legacy-status:5']), 'legacy_audits_exact_origin_order');
$check($rows['legacy-replacement:1']['content'] === '历史替换项目' && $rows['legacy-replacement:1']['detailRemark'] === '历史替换备注', 'legacy_replacement_physical_holder');
$check($rows['legacy-writeoff:1']['result'] === '已撤销' && !isset($rows['legacy-writeoff:2']), 'legacy_revoked_writeoff_validates_detail_order');
$insert('store_order_terminal_operation', ['id' => 1, 'store_order_id' => 10, 'state' => 4, 'business_type' => 1,
    'action_type' => 2, 'refund_amount' => '100.00', 'refund_business_date' => '', 'operated_at' => 40,
    'store_id' => 8, 'reason' => '历史作废原因']);
$insert('store_order_terminal_operation', ['id' => 2, 'store_order_id' => 10, 'state' => 5, 'business_type' => 1]);
$rows = $byKey($history(10));
$check(isset($rows['legacy-terminal:1']) && !isset($rows['legacy-terminal:2']) && !isset($rows['legacy-status:4']), 'legacy_terminal_only_success_no_notification_duplicate');
Db::name('store_order')->where('id', 10)->update(['card_upgrade_use_oid' => 50]);
$check(isset($byKey($history(50))['legacy-status:3']), 'legacy_new_card_upgrade_source_via_exact_order_link');
$insert('cashier_v3_order_lifecycle_operation', array_replace($base, ['operation_id' => 'IMPORTED', 'source_type' => 'imported_card',
    'source_order_id' => '10', 'operation_type' => 'refund', 'status' => 'succeeded', 'cash_refund_cents' => 2500]));
$check($byKey($history(10))['lifecycle:IMPORTED']['amount'] === '25.00', 'imported_card_refund_without_unified_adjustment');

$insert('cashier_v3_balance_fact', array_replace($base, ['fact_id' => 'BF', 'member_id' => 2, 'status' => 'effective',
    'balance_change_type' => 'card_to_balance', 'source_document_type' => 'card_operation', 'fact_direction' => 'forward',
    'principal_delta_cents' => 0, 'bonus_delta_cents' => 12300, 'principal_after_cents' => 0, 'bonus_after_cents' => 12300,
    'settled_at' => 100, 'recorded_at' => 100]));
$balance = new ReflectionMethod($service, 'balanceChanges');
$balance->setAccessible(true);
$balanceRows = $balance->invoke($service, 2, 'T');
$check($balanceRows[0]['typeLabel'] === '卡转余额' && $balanceRows[0]['sourceLabel'] === '卡操作'
    && $balanceRows[0]['bonusDelta'] === '123.00' && $balanceRows[0]['principalDelta'] === '0.00', 'balance_fact_labels_and_gift_amount');

// A transferred card is visible only under its current owner. Session tenant,
// forced store and self-only restrictions remain enforced by the production guard.
foreach ([static fn() => $service->cardHistory(1, 10, 1, $operator, $scope),
    static fn() => $history(40),
    static fn() => $service->cardHistory(2, 10, 1, new CashierV3OperatorScope(8, 1, 'ORG', 'OTHER'), $scope),
    static fn() => $service->cardHistory(2, 10, 1, new CashierV3OperatorScope(9, 1, 'ORG', 'T'), $scope)] as $action) {
    try { $action(); throw new RuntimeException('unauthorized_history_accepted'); }
    catch (CashierV3CommandException $expected) { $checks++; }
}
foreach ([CashierV3DataScopeContext::MODE_NONE, CashierV3DataScopeContext::MODE_SELF_PARTICIPANT] as $mode) {
    $restricted = new CashierV3DataScopeContext(1, 1, 8, 'T', 'ORG', [8], $mode, [], false, '', 'r53', [], ['id' => 1]);
    try { $service->cardHistory(2, 10, 1, $operator, $restricted); throw new RuntimeException('restricted_scope_accepted'); }
    catch (CashierV3CommandException $expected) { $checks++; }
}
// More than one page must preserve all authority identities and stable ordering.
for ($i = 0; $i < 60; $i++) $operation('page-' . $i, 'card_extension', ['occurred_at' => 200]);
$changesBefore = (int)Db::query('SELECT total_changes() AS n')[0]['n'];
$first = $history(10); $second = $history(10, 2);
$check(count($first['records']) === 50 && $first['total'] > 50, 'full_total_first_page');
$keys = array_merge(array_column($first['records'], 'key'), array_column($second['records'], 'key'));
$check(count($keys) === count(array_unique($keys)) && count($keys) === $first['total'], 'pagination_no_duplicates_or_gaps');
$check($history(10)['records'] === $first['records'] && $history(10, 999)['records'] === [], 'stable_refresh_empty_page');
$check((int)Db::query('SELECT total_changes() AS n')[0]['n'] === $changesBefore, 'history_does_not_write_any_authority');

// The endpoint is useful only if the existing all-card screen can still
// return the holder ID. Stale quantities/prices must never become active rights.
foreach ([70, 80] as $id) {
    $insert('user_card_holder', ['id' => $id, 'uid' => 777, 'oid' => $id,
        'card_name' => '关闭卡' . $id, 'card_no' => 'CLOSED-' . $id, 'store_id' => 8, 'add_time' => 1,
        'is_del' => $id === 70 ? 1 : 0, 'write_surplus_times' => 99]);
    $insert('store_order', ['id' => $id, 'uid' => 777, 'pay_price' => '999.00',
        'card_upgrade_use_oid' => $id === 80 ? 50 : 0, 'terminal_action' => 0]);
    $insert('cashier_v3_card_state', ['tenant_id' => 'T', 'card_holder_id' => $id, 'current_member_id' => 777,
        'card_status' => $id === 70 ? 'disabled' : 'upgraded']);
}
$operation('closed-conversion', 'card_to_balance', ['source_card_holder_id' => 70, 'operation_status' => 'completed']);
$cardReader = new ReflectionMethod($service, 'cards');
$cardReader->setAccessible(true);
$allCards = $cardReader->invoke($service, 777, 'T', '', 'all', '', '', 8);
$check(count($allCards) === 2, 'all_retains_converted_and_upgraded_holders');
$closedById = array_column($allCards, null, 'id');
$check($closedById['70']['statusLabel'] === '已作废' && $closedById['70']['statusCode'] === 'voided', 'converted_card_not_merely_disabled');
$check($closedById['80']['statusLabel'] === '已升级', 'upgraded_source_visible_in_all');
foreach ($allCards as $card) {
    $check($card['remainingTimes'] === 0 && $card['debtLimitedRemainingTimes'] === 0
        && $card['remainingAmount'] === '0.00' && $card['projects'] === [], 'closed_card_zero_effective_rights:' . $card['id']);
    $timeline = $service->cardHistory(777, (int)$card['id'], 1, $operator, $scope);
    $check($timeline['holderId'] === (int)$card['id'], 'all_card_id_opens_existing_history_action:' . $card['id']);
}
$check($cardReader->invoke($service, 777, 'T', '', 'active', '', '', 8) === [], 'active_excludes_closed_cards_despite_stale_quantities');
$check($cardReader->invoke($service, 888, 'T', '', 'all', '', '', 8) === [], 'all_does_not_expand_member_scope');
$insert('user_card_holder', ['id' => 90, 'uid' => 999, 'oid' => 10, 'card_name' => '另一持卡实例', 'card_no' => '重复卡号', 'store_id' => 8]);
$ambiguous = $byKey($history(10)) + $byKey($history(10, 2));
$check(!isset($ambiguous['legacy-status:1']) && !isset($ambiguous['legacy-terminal:1'])
    && isset($ambiguous['legacy-replacement:1']), 'ambiguous_order_audit_omitted_exact_holder_replacement_kept');
echo 'R53_CARD_HISTORY_SQLITE=PASS checks=' . $checks . ' rows=' . $first['total'] . PHP_EOL;

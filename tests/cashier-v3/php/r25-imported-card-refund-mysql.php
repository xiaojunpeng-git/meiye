<?php
declare(strict_types=1);

/**
 * R25 本地事务回滚验收：跨店旧卡退款、欠款剩余部分、已核销历史与统一业绩对账。
 * 强制本地 mysql 主机；仅事务内使用既有迁入卡，结束时回滚全部业务与快照写入。
 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3ImportedCardRefundServices;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('R25 tests require the local Docker database');
}
function check25(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $message);
    echo 'PASS ' . $message . PHP_EOL;
}
function deny25(callable $action, string $message): void {
    $denied = false;
    try { $action(); } catch (CashierV3CommandException $exception) { $denied = true; }
    check25($denied, $message);
}

$fixture = (array)Db::name('store_debt')->alias('d')
    ->join('store_order o', 'o.id=d.order_id')->join('user_card_holder h', 'h.oid=o.id AND h.uid=o.uid')
    ->where('d.status', 0)->where('h.is_del', 0)->where('o.refund_status', 0)->where('o.terminal_action', 0)
    ->where('o.pay_type', 'migration')->where('o.channel_type', 'migration')->where('o.product_type', 5)
    ->whereRaw('h.write_times>h.write_surplus_times')
    ->whereNotExists(function ($sub): void {
        $sub->name('cashier_v3_card_state')->alias('cs')->whereRaw('cs.card_holder_id=h.id');
    })->whereNotExists(function ($sub): void {
        $sub->name('cashier_v3_debt_cancellation')->alias('dc')->whereRaw('dc.debt_id=d.id');
    })->field('h.id holder_id,h.uid member_id,h.oid order_id,o.store_id origin_store_id,d.id debt_id')->find();
if (!$fixture) throw new RuntimeException('No local partially used migration card with debt');
$staff = (array)Db::name('system_store_staff')->alias('s')->join('organization_store os', 'os.store_id=s.store_id')
    ->where('s.store_id', '<>', (int)$fixture['origin_store_id'])->where('s.is_del', 0)
    ->where('s.level', 0)->where('s.status', 1)
    ->field('s.*,os.org_id')->find();
if (!$staff) throw new RuntimeException('No other local processing store');
$storeId = (int)$staff['store_id'];
$operatorId = (int)$staff['id'];
$organizationId = (string)$staff['org_id'];
$operator = new CashierV3OperatorScope($storeId, $operatorId, $organizationId, '0');
$features = ['cashier.v3.order_center', 'cashier.v3.order.refund'];
$makeScope = static function (array $grants) use ($storeId, $operatorId, $organizationId, $staff): CashierV3DataScopeContext {
    return new CashierV3DataScopeContext($operatorId, $operatorId, $storeId, '0', $organizationId,
        [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r25-local', $grants, $staff);
};
$scope = $makeScope($features);
$service = new CashierV3ImportedCardRefundServices();
$recorder = new CashierV3BusinessEventRecorder();
$memberId = (int)$fixture['member_id'];
$holderId = (int)$fixture['holder_id'];
$debtId = (int)$fixture['debt_id'];
$orderId = (int)$fixture['order_id'];
$beforeHolder = (array)Db::name('user_card_holder')->where('id', $holderId)->find();
$beforeDebt = (array)Db::name('store_debt')->where('id', $debtId)->find();
$beforeOrder = (array)Db::name('store_order')->where('id', $orderId)->find();
$beforeDetails = Db::name('store_order_cart_info')->where('oid', $orderId)->order('id asc')->select()->toArray();
$key = 'CMD-' . sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)),
    bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1),
    substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
$date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$range = ['start' => $date, 'end' => $date];
$metrics = new RegisteredMetricReadServices();

Db::startTrans();
try {
    // A paid portion remains paid; the refund cancels only the 700 still outstanding.
    Db::name('store_debt')->where('id', $debtId)->update(['total_debt' => '1000.00', 'repaid_debt' => '300.00']);
    $candidates = $service->candidates(['memberId' => $memberId, 'pageSize' => 50], $operator, $scope);
    $card = array_values(array_filter($candidates['cards'], static function (array $card) use ($holderId): bool {
        return (int)$card['holderId'] === $holderId;
    }))[0] ?? null;
    if (!$card) throw new RuntimeException('Fixture missing from refund candidates');
    check25($card['remainingDebtCents'] === 70000 && $card['canRefund'], 'cross-store candidate reads unpaid 700');
    $payload = ['memberId' => $memberId, 'holderId' => $holderId,
        'snapshotFingerprint' => $card['snapshotFingerprint'], 'refundAmount' => '123', 'reason' => 'R25事务回滚退款自测'];
    $command = ['operator_scope' => $operator, 'data_scope' => $scope,
        'event_recorder' => $recorder, 'event_execution' => $recorder->newExecution('refund-imported-card', $key, $operator, $scope, 'r25-local'),
        'event_contract' => CashierV3ActionManifest::eventContractFor('refund-imported-card'),
        'idempotency_key' => $key, 'payload' => $payload];
    $denied = $command; $denied['data_scope'] = $makeScope(['cashier.v3.order_center']);
    deny25(static function () use ($service, $denied): void { $service->refundInTx($denied); }, 'refund permission enforced by backend');
    $invalid = $command; $invalid['payload']['refundAmount'] = '0';
    deny25(static function () use ($service, $invalid): void { $service->refundInTx($invalid); }, 'zero refund amount rejected');
    $invalid['payload']['refundAmount'] = '123.45';
    deny25(static function () use ($service, $invalid): void { $service->refundInTx($invalid); }, 'fractional yuan refund rejected');
    Db::name('store_debt')->where('id', $debtId)->update(['repaid_debt' => '301.00']);
    deny25(static function () use ($service, $command): void { $service->refundInTx($command); }, 'repayment after selection invalidates confirmation');
    Db::name('store_debt')->where('id', $debtId)->update(['repaid_debt' => '300.00']);
    Db::name('store_order')->where('id', $orderId)->update(['uid' => $memberId + 9999999]);
    deny25(static function () use ($service, $command): void { $service->refundInTx($command); }, 'transferred card cannot be refunded');
    Db::name('store_order')->where('id', $orderId)->update(['uid' => $memberId]);
    // 有 V3 卡状态的旧卡也必须终止，不能仅隐藏旧 holder 而遗留可核销状态。
    $stateId = Db::name('cashier_v3_card_state')->insertGetId([
        'tenant_id' => '0', 'card_holder_id' => $holderId, 'origin_order_id' => $orderId,
        'origin_member_id' => $memberId, 'current_member_id' => $memberId,
        'card_status' => 'enabled', 'current_version' => 1, 'created_at' => time(), 'updated_at' => time(),
    ]);
    $ruleId = Db::name('cashier_v3_card_rule_state')->insertGetId([
        'state_id' => $key, 'tenant_id' => '0', 'receipt_id' => $key,
        'sales_order_id' => (string)$orderId, 'sales_order_line_id' => $key,
        'card_holder_id' => $holderId, 'member_id' => $memberId, 'issue_store_id' => (int)$fixture['origin_store_id'],
        'catalog_product_id' => 0, 'catalog_sku_id' => 0, 'rule_type' => 'fixed_count',
        'rule_version' => 1, 'definition_version' => 1, 'validity_mode' => 0,
        'shared_total_times' => 10, 'shared_remaining_times' => 5,
        'immutable_fingerprint' => hash('sha256', $key), 'definition_snapshot_json' => '{}',
        'status' => 'active', 'occurred_at' => time(), 'recorded_at' => time(), 'add_time' => time(), 'update_time' => time(),
    ]);
    $componentId = Db::name('cashier_v3_card_rule_component')->insertGetId([
        'component_state_id' => $key, 'tenant_id' => '0', 'rule_state_id' => $ruleId,
        'card_holder_id' => $holderId, 'legacy_detail_id' => (int)$beforeDetails[0]['id'], 'relation_id' => 1,
        'project_product_id' => 0, 'project_sku_id' => 0, 'project_sku_unique' => '',
        'project_type' => 1, 'project_name_snapshot' => 'R25本地回滚项目', 'total_times' => 10, 'remaining_times' => 5,
        'selection_status' => 'selected', 'immutable_fingerprint' => hash('sha256', $key), 'component_snapshot_json' => '{}',
        'status' => 'active', 'occurred_at' => time(), 'recorded_at' => time(), 'add_time' => time(), 'update_time' => time(),
    ]);
    $fresh = $service->candidates(['memberId' => $memberId, 'pageSize' => 50], $operator, $scope);
    $card = array_values(array_filter($fresh['cards'], static function (array $row) use ($holderId): bool {
        return (int)$row['holderId'] === $holderId;
    }))[0];
    $payload['snapshotFingerprint'] = $card['snapshotFingerprint'];
    $command['payload'] = $payload;
    $beforeMetrics = [];
    foreach (['cash_performance', 'refund_performance', 'actual_performance'] as $code) {
        $beforeMetrics[$code] = $metrics->summary($code, '0', [$storeId], $range);
    }
    // 真正通过命令网关的权限重建、空上下文契约、事件约束与回执链路。
    // 使用现有本地门店管理员，不修改岗位或授予新权限；外层事务统一回滚。
    $dispatcher = app\services\cashier\v3\bootstrap\CashierV3Bootstrap::dispatcher();
    $result = $dispatcher->gateway()->execute('refund-imported-card',
        ['idempotencyKey' => $key, 'contexts' => []], $payload, $operator,
        ['client_session_id' => 'SESSION-' . substr($key, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-imported-card'), $scope,
        CashierV3ActionManifest::requireAction('refund-imported-card'));
    check25($result['status'] === 'success', 'real command gateway completes legacy refund');
    $operationId = $result['data']['importedCardRefund']['operationId'];
    $fact = (array)Db::name('cashier_v3_payment_fact')->where('order_id', $operationId)->find();
    check25((int)$fact['amount_cents'] === -12300 && (int)$fact['store_id'] === $storeId
        && $fact['source_document_type'] === 'imported_card_refund', 'refund cash fact uses exact amount and processing store');
    check25((int)Db::name('user_card_holder')->where('id', $holderId)->value('is_del') === 1
        && (int)Db::name('store_order')->where('id', $orderId)->value('refund_status') === 2,
        'refund disables remaining card rights');
    check25(Db::name('cashier_v3_card_state')->where('id', $stateId)->value('card_status') === 'disabled',
        'V3 card authority also disables remaining rights');
    check25(Db::name('cashier_v3_card_rule_state')->where('id', $ruleId)->value('status') === 'revoked'
        && Db::name('cashier_v3_card_rule_component')->where('id', $componentId)->value('status') === 'revoked'
        && (int)Db::name('cashier_v3_card_rule_component')->where('id', $componentId)->value('remaining_times') === 5,
        'rule rights revoked without rewriting remaining or used history');
    $debt = (array)Db::name('store_debt')->where('id', $debtId)->find();
    check25((int)$debt['status'] === 3 && $debt['repaid_debt'] === '300.00'
        && $debt['total_debt'] === '1000.00', 'remaining debt cancelled while paid 300 preserved');
    check25(Db::name('store_order_cart_info')->where('oid', $orderId)->order('id asc')->select()->toArray() === $beforeDetails,
        'original project entitlements and used history retained');
    $afterCandidates = $service->candidates(['memberId' => $memberId, 'pageSize' => 50], $operator, $scope);
    check25(!in_array($holderId, array_column($afterCandidates['cards'], 'holderId'), true), 'refunded card no longer offered');
    check25($metrics->summary('cash_performance', '0', [$storeId], $range) === $beforeMetrics['cash_performance']
        && $metrics->summary('refund_performance', '0', [$storeId], $range) === $beforeMetrics['refund_performance'] + 12300
        && $metrics->summary('actual_performance', '0', [$storeId], $range) === $beforeMetrics['actual_performance'] - 12300,
        'cash unchanged, refund increases 123 yuan, actual decreases 123 yuan');
    $detail = $metrics->detailPage('refund_performance', '0', [$storeId], $range, 1, 100);
    $refundRows = array_values(array_filter($detail['rows'], static function (array $row) use ($operationId): bool {
        return (string)$row['order_id'] === $operationId;
    }));
    $grouped = $metrics->groupedStoreTotals('refund_performance', '0', [$storeId], $range, [$storeId => 'processing']);
    check25(count($refundRows) === 1 && (int)$refundRows[0]['metric_value'] === 12300
        && (int)$grouped[0]['metric_value'] === $beforeMetrics['refund_performance'] + 12300,
        'refund metric detail and grouped totals reconcile with summary');
    $page = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'refund', 'keyword' => $result['business_no'], 'dateFrom' => $date, 'dateTo' => $date,
    ], $operator, $scope);
    check25(count($page['records']) === 1 && $page['records'][0]['refundAmount'] === '123.00'
        && $page['records'][0]['refundSourceType'] === 'imported_card', 'refund records show the completed old-card refund');
    $debtPage = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'debt', 'dataScope' => 'all', 'keyword' => (string)$beforeDebt['debt_no'],
        'dateFrom' => '2000-01-01', 'dateTo' => '2099-12-31',
    ], new CashierV3OperatorScope((int)$fixture['origin_store_id'], $operatorId, $organizationId, '0'),
        new CashierV3DataScopeContext($operatorId, $operatorId, (int)$fixture['origin_store_id'], '0', $organizationId,
            [(int)$fixture['origin_store_id']], CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r25-debt',
            ['cashier.v3.order_center', 'cashier.v3.order.view_debt'], $staff));
    check25(count($debtPage['records']) === 1 && !$debtPage['records'][0]['canRestore'],
        'refunded old-card debt is not offered for restoration');
    $replay = $dispatcher->gateway()->execute('refund-imported-card',
        ['idempotencyKey' => $key, 'contexts' => []], $payload, $operator,
        ['client_session_id' => 'SESSION-' . substr($key, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-imported-card'), $scope,
        CashierV3ActionManifest::requireAction('refund-imported-card'));
    check25($replay['replay']
        && Db::name('cashier_v3_payment_fact')->where('order_id', $operationId)->count() === 1,
        'same refund request produces exactly one cash fact');
    $policy = app\services\cashier\v3\bootstrap\CashierV3Bootstrap::dispatcher()->policies()
        ->requirePolicy('refund-imported-card')->resolve($payload, []);
    check25($policy['allows_empty_contexts'] && !$policy['identities'], 'refund depends on locked confirmation snapshot');
} finally {
    Db::rollback();
}
check25((array)Db::name('user_card_holder')->where('id', $holderId)->find() === $beforeHolder
    && (array)Db::name('store_order')->where('id', $orderId)->find() === $beforeOrder
    && (array)Db::name('store_debt')->where('id', $debtId)->find() === $beforeDebt
    && !Db::name('cashier_v3_card_state')->where('id', $stateId)->count()
    && !Db::name('cashier_v3_card_rule_state')->where('id', $ruleId)->count()
    && !Db::name('cashier_v3_command_receipt')->where('idempotency_key', $key)->count()
    && !Db::name('cashier_v3_order_lifecycle_operation')->where('command_idempotency_key', $key)->count(),
    'all test business changes rolled back');
echo "R25_IMPORTED_CARD_REFUND=PASS\n";

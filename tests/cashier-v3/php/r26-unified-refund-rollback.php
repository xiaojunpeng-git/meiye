<?php
declare(strict_types=1);

/** R26 local integration probe. Existing customer facts are never persisted by this test. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use app\services\cashier\v3\order\CashierV3RefundSalespersonServices;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('The R26 probe is local-only.');
}
$staff = (array)Db::name('system_store_staff')->alias('s')
    ->join('organization_store os', 'os.store_id=s.store_id')
    ->where('s.status', 1)->where('s.is_del', 0)->where('s.level', 0)
    ->field('s.*,os.org_id')->find();
$storeId = (int)$staff['store_id'];
$operator = new CashierV3OperatorScope($storeId, (int)$staff['id'], (string)$staff['org_id'], '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], $storeId, '0',
    (string)$staff['org_id'], [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r26-rollback', ['cashier.v3.order_center', 'cashier.v3.order.refund'], $staff);
$service = new CashierV3UnifiedRefundServices();
// 新退款在独立负向事实中记录本门店销售人；回滚后原销售事实保持不变。
$personnelEntry = (new CashierV3RefundSalespersonServices())->selection([
    'mode' => 'card', 'memberId' => 978855, 'holderId' => 2074451,
], $operator, $scope);
$refundSalesperson = $personnelEntry['salespeople'][0] ?? null;
if (!$refundSalesperson) throw new RuntimeException('Local refund salesperson probe fixture unavailable.');
$singleSalesperson = [['staffId' => $refundSalesperson['staffId'], 'allocationWeight' => 100]];
$cards = $service->candidates(['memberId' => 978855, 'mode' => 'card'], $operator, $scope)['cards'];
$card = array_values(array_filter($cards, static fn(array $item): bool => (int)$item['holderId'] === 2074451))[0] ?? null;
if (!$card || !$card['canRefund']) throw new RuntimeException('Local card probe fixture unavailable.');
$hex = bin2hex(random_bytes(16));
$key = 'CMD-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4'
    . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
$payload = ['mode' => 'card', 'memberId' => 978855, 'holderId' => 2074451,
    'componentId' => (int)$card['components'][0]['id'], 'lineId' => '',
    'refundAmount' => '450', 'balanceAmount' => '700', 'balanceKind' => 'bonus',
    'voidTarget' => false, 'validThrough' => 0, 'reason' => 'R26本地事务回滚验证',
    'salespeople' => $singleSalesperson];
$dispatcher = CashierV3Bootstrap::dispatcher();
Db::startTrans();
try {
    // An old client must not turn the now fixed bonus-credit field into principal.
    try {
        $service->executeInTx([
            'operator_scope' => $operator, 'data_scope' => $scope,
            'payload' => ['mode' => 'document', 'memberId' => 1136826,
                'voidTarget' => false, 'refundAmount' => '1',
                'balanceAmount' => '1', 'balanceKind' => 'principal'],
        ]);
        throw new RuntimeException('Principal credit was accepted for document refund.');
    } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
        if (!str_contains($error->getMessage(), '只能记入赠金')) throw $error;
    }
    // The UI is not an authority for whole-yuan validation; reject both decimal fields in the command.
    foreach (['refundAmount', 'balanceAmount'] as $field) {
        try {
            $service->executeInTx([
                'operator_scope' => $operator, 'data_scope' => $scope,
                'payload' => ['mode' => 'card', 'memberId' => 978855, 'voidTarget' => false,
                    'refundAmount' => $field === 'refundAmount' ? '1.50' : '1',
                    'balanceAmount' => $field === 'balanceAmount' ? '1.50' : '1'],
            ]);
            throw new RuntimeException($field . ' accepted a decimal amount.');
        } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
            if (!str_contains($error->getMessage(), '整数元')) throw $error;
        }
    }
    $response = $dispatcher->gateway()->execute('refund-card-or-line',
        ['idempotencyKey' => $key, 'contexts' => []], $payload, $operator,
        ['client_session_id' => 'SESSION-' . substr($key, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
        CashierV3ActionManifest::requireAction('refund-card-or-line'));
    if (($response['status'] ?? '') !== 'success') throw new RuntimeException(json_encode($response, JSON_UNESCAPED_UNICODE));
    $cardNo = (string)($response['data']['unifiedRefund']['operationNo'] ?? '');
    if (!preg_match('/^TK[0-9]{6}[0-9]{4}$/D', $cardNo)) throw new RuntimeException('Card refund number is not TK + date + 4 digits.');
    $refundId = (string)($response['data']['unifiedRefund']['operationId'] ?? '');
    if ((int)Db::name('cashier_v3_performance_fact')->where('order_id', $refundId)
        ->where('performance_type', 'refund_sales_deduction')->sum('amount_cents') !== -45000) {
        throw new RuntimeException('Refund salesperson negative performance fact missing.');
    }
    $replayed = $dispatcher->gateway()->execute('refund-card-or-line',
        ['idempotencyKey' => $key, 'contexts' => []], $payload, $operator,
        ['client_session_id' => 'SESSION-' . substr($key, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
        CashierV3ActionManifest::requireAction('refund-card-or-line'));
    if ((string)($replayed['data']['unifiedRefund']['operationNo'] ?? '') !== $cardNo) {
        throw new RuntimeException('Idempotent retry changed the refund number: ' . json_encode($replayed, JSON_UNESCAPED_UNICODE));
    }
    echo json_encode($response['data']['unifiedRefund'] ?? $response, JSON_UNESCAPED_UNICODE), PHP_EOL;
    $history = (new CashierV3MemberDetailQueryServices())->cardHistory(978855, 2074451, 1, $operator, $scope);
    if (!in_array('退款', array_column($history['records'], 'type'), true)) {
        throw new RuntimeException('Card history does not show the refund.');
    }
    $docCandidates = $service->candidates(['memberId' => 1136826, 'mode' => 'document'], $operator, $scope);
    $line = $docCandidates['lines'][0] ?? null;
    if (!$line || $line['remainingAmountCents'] < 1200) throw new RuntimeException('Local document probe fixture unavailable.');
    $docHex = bin2hex(random_bytes(16));
    $docKey = 'CMD-' . substr($docHex, 0, 8) . '-' . substr($docHex, 8, 4) . '-4'
        . substr($docHex, 13, 3) . '-8' . substr($docHex, 17, 3) . '-' . substr($docHex, 20, 12);
    $docPayload = ['mode' => 'document', 'memberId' => 1136826, 'holderId' => 0,
        'componentId' => 0, 'lineId' => $line['lineId'], 'refundAmount' => '10',
        'balanceAmount' => '12', 'balanceKind' => 'bonus', 'voidTarget' => true,
        'validThrough' => 0, 'reason' => 'R26本地单据事务回滚验证', 'salespeople' => $singleSalesperson];
    $doc = $dispatcher->gateway()->execute('refund-card-or-line',
        ['idempotencyKey' => $docKey, 'contexts' => []], $docPayload, $operator,
        ['client_session_id' => 'SESSION-' . substr($docKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
        CashierV3ActionManifest::requireAction('refund-card-or-line'));
    if (($doc['status'] ?? '') !== 'success' || ($doc['data']['unifiedRefund']['voidTarget'] ?? false) !== true) {
        throw new RuntimeException('Document void command failed: ' . json_encode($doc, JSON_UNESCAPED_UNICODE));
    }
    $documentNo = (string)($doc['data']['unifiedRefund']['operationNo'] ?? '');
    if (substr($documentNo, 0, 8) !== substr($cardNo, 0, 8)
        || (int)substr($documentNo, -4) !== (int)substr($cardNo, -4) + 1) {
        throw new RuntimeException('Card and document refunds did not share the daily TK sequence.');
    }
    $page = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'refund', 'keyword' => $doc['business_no'],
        'dateFrom' => date('Y-m-d'), 'dateTo' => date('Y-m-d'),
    ], $operator, $scope);
    if (count($page['records']) !== 1 || !str_contains($page['records'][0]['refundSummary'], '作废明细')) {
        throw new RuntimeException('Document refund record does not describe the void.');
    }
    echo json_encode($doc['data']['unifiedRefund'], JSON_UNESCAPED_UNICODE), PHP_EOL;

    // A shared-count card has one whole-card value/pool; project carts must not receive separate refunds.
    $sharedMemberId = 1130058;
    $sharedHolderId = 2074459;
    $sharedCards = $service->candidates(['memberId' => $sharedMemberId, 'mode' => 'card'], $operator, $scope)['cards'];
    $shared = array_values(array_filter($sharedCards,
        static fn(array $item): bool => (int)$item['holderId'] === $sharedHolderId))[0] ?? null;
    if (!$shared || !$shared['canRefund'] || $shared['ruleType'] !== 'choice_count'
        || count($shared['components']) !== 1 || (int)$shared['components'][0]['id'] !== 0) {
        throw new RuntimeException('Local shared-count card probe fixture unavailable.');
    }
    $sharedRuleBefore = (array)Db::name('cashier_v3_card_rule_state')->where('card_holder_id', $sharedHolderId)->find();
    $legacyBefore = Db::name('store_order_cart_info')->where('oid', (int)$shared['sourceOrderId'])
        ->where('cart_type', 2)->where('product_type', 6)->column('write_surplus_times', 'id');
    $sharedHex = bin2hex(random_bytes(16));
    $sharedKey = 'CMD-' . substr($sharedHex, 0, 8) . '-' . substr($sharedHex, 8, 4) . '-4'
        . substr($sharedHex, 13, 3) . '-8' . substr($sharedHex, 17, 3) . '-' . substr($sharedHex, 20, 12);
    $sharedPayload = ['mode' => 'card', 'memberId' => $sharedMemberId, 'holderId' => $sharedHolderId,
        'componentId' => 0, 'lineId' => '', 'refundAmount' => '100', 'balanceAmount' => '0',
        'balanceKind' => 'bonus', 'voidTarget' => false, 'validThrough' => 0,
        'reason' => 'R26本地共享次数事务回滚验证', 'salespeople' => $singleSalesperson];
    $sharedResponse = $dispatcher->gateway()->execute('refund-card-or-line',
        ['idempotencyKey' => $sharedKey, 'contexts' => []], $sharedPayload, $operator,
        ['client_session_id' => 'SESSION-' . substr($sharedKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
        CashierV3ActionManifest::requireAction('refund-card-or-line'));
    $sharedResult = (array)($sharedResponse['data']['unifiedRefund'] ?? []);
    $expectedTimes = (int)$sharedRuleBefore['shared_remaining_times'] - 1;
    if (($sharedResponse['status'] ?? '') !== 'success' || (int)($sharedResult['remainingTimes'] ?? -1) !== $expectedTimes
        || (int)($sharedResult['remainingAmountCents'] ?? -1) !== (int)$shared['remainingAmountCents'] - 10000
        || (int)($sharedResult['balanceCreditCents'] ?? -1) !== 0) {
        throw new RuntimeException('Shared-count refund result mismatch: ' . json_encode($sharedResponse, JSON_UNESCAPED_UNICODE));
    }
    $sharedRuleAfter = (array)Db::name('cashier_v3_card_rule_state')->where('card_holder_id', $sharedHolderId)->find();
    $sharedHolderAfter = (array)Db::name('user_card_holder')->where('id', $sharedHolderId)->find();
    $legacyAfter = Db::name('store_order_cart_info')->where('oid', (int)$shared['sourceOrderId'])
        ->where('cart_type', 2)->where('product_type', 6)->column('write_surplus_times', 'id');
    if ((int)$sharedRuleAfter['shared_remaining_times'] !== $expectedTimes
        || (int)$sharedHolderAfter['write_surplus_times'] !== $expectedTimes || $legacyAfter !== $legacyBefore) {
        throw new RuntimeException('Shared pool, holder and service-cart quantities diverged.');
    }
    $refreshedCards = $service->candidates(['memberId' => $sharedMemberId, 'mode' => 'card'], $operator, $scope)['cards'];
    $refreshed = array_values(array_filter($refreshedCards,
        static fn(array $item): bool => (int)$item['holderId'] === $sharedHolderId))[0] ?? null;
    if (!$refreshed || !$refreshed['canRefund'] || (int)$refreshed['remainingTimes'] !== $expectedTimes
        || (int)$refreshed['remainingAmountCents'] !== (int)$sharedResult['remainingAmountCents']) {
        throw new RuntimeException('Shared-count refund cannot be read consistently after the write.');
    }
    $memberDetail = (new CashierV3MemberDetailQueryServices())->read(
        $sharedMemberId, $operator, $scope, ['tab' => 'assets']);
    $memberCard = array_values(array_filter($memberDetail['cards'],
        static fn(array $item): bool => (int)$item['id'] === $sharedHolderId))[0] ?? null;
    if (!$memberCard || (int)$memberCard['remainingTimes'] !== $expectedTimes
        || (int)bcmul((string)$memberCard['remainingAmount'], '100', 0) !== (int)$sharedResult['remainingAmountCents']) {
        throw new RuntimeException('Member detail still displays stale shared-card totals.');
    }
    $entitlementSources = app()->make(CashierV3EntitlementProjectionServices::class)
        ->reservationSources($sharedMemberId, $operator, $scope);
    $sharedSource = array_values(array_filter($entitlementSources,
        static fn(array $item): bool => (int)($item['id'] ?? 0) === $sharedHolderId))[0] ?? null;
    if (!$sharedSource || (int)$sharedSource['remainingTimes'] !== $expectedTimes
        || (int)bcmul((string)$sharedSource['remainingAmount'], '100', 0) !== (int)$sharedResult['remainingAmountCents']) {
        throw new RuntimeException('Cashier entitlement source still displays stale shared-card totals.');
    }
    echo json_encode($sharedResult, JSON_UNESCAPED_UNICODE), PHP_EOL;
    $nextHex = bin2hex(random_bytes(16));
    $nextKey = 'CMD-' . substr($nextHex, 0, 8) . '-' . substr($nextHex, 8, 4) . '-4'
        . substr($nextHex, 13, 3) . '-8' . substr($nextHex, 17, 3) . '-' . substr($nextHex, 20, 12);
    $nextPayload = $sharedPayload;
    $nextPayload['refundAmount'] = '50';
    $next = $dispatcher->gateway()->execute('refund-card-or-line',
        ['idempotencyKey' => $nextKey, 'contexts' => []], $nextPayload, $operator,
        ['client_session_id' => 'SESSION-' . substr($nextKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
        CashierV3ActionManifest::requireAction('refund-card-or-line'));
    $nextResult = (array)($next['data']['unifiedRefund'] ?? []);
    if (($next['status'] ?? '') !== 'success' || (int)($nextResult['remainingTimes'] ?? -1) !== $expectedTimes - 1
        || (int)($nextResult['remainingAmountCents'] ?? -1) !== (int)$sharedResult['remainingAmountCents'] - 5000) {
        throw new RuntimeException('Second shared-count refund double-counted usage or value.');
    }
    $voidHex = bin2hex(random_bytes(16));
    $voidKey = 'CMD-' . substr($voidHex, 0, 8) . '-' . substr($voidHex, 8, 4) . '-4'
        . substr($voidHex, 13, 3) . '-8' . substr($voidHex, 17, 3) . '-' . substr($voidHex, 20, 12);
    $voidPayload = $sharedPayload;
    $voidPayload['refundAmount'] = '10';
    $voidPayload['voidTarget'] = true;
    $closed = $dispatcher->gateway()->execute('refund-card-or-line',
        ['idempotencyKey' => $voidKey, 'contexts' => []], $voidPayload, $operator,
        ['client_session_id' => 'SESSION-' . substr($voidKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
        CashierV3ActionManifest::requireAction('refund-card-or-line'));
    $closedResult = (array)($closed['data']['unifiedRefund'] ?? []);
    $closedRule = (array)Db::name('cashier_v3_card_rule_state')->where('card_holder_id', $sharedHolderId)->find();
    if (($closed['status'] ?? '') !== 'success' || ($closedResult['voidTarget'] ?? false) !== true
        || (int)($closedResult['remainingTimes'] ?? -1) !== 0
        || (int)$closedRule['shared_remaining_times'] !== 0 || (string)$closedRule['status'] !== 'revoked') {
        throw new RuntimeException('Voided shared-count card retained usable rights.');
    }
} catch (\Throwable $error) {
    $failure = $error;
} finally {
    Db::rollback();
}
if (isset($failure)) {
    // 缺少本地固定样本也必须返回失败码，不能把中途回滚误报成完整通过。
    file_put_contents('php://stderr', $failure->getMessage() . PHP_EOL);
    exit(1);
}
if (Db::name(CashierV3UnifiedRefundServices::TABLE)->where('command_idempotency_key', $key)->count()) {
    throw new RuntimeException('Probe transaction did not roll back.');
}
if (Db::name('cashier_v3_business_document_no')->where('source_id', (string)$response['data']['unifiedRefund']['operationId'])->count()) {
    throw new RuntimeException('Rolled-back refund kept its visible number mapping.');
}
if (Db::name(CashierV3UnifiedRefundServices::TABLE)->where('command_idempotency_key', $sharedKey)->count()) {
    throw new RuntimeException('Shared-count probe transaction did not roll back.');
}
echo "R26_ROLLBACK=PASS\n";

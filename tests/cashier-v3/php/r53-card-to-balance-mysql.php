<?php
declare(strict_types=1);

/** R53 uses only local Docker MySQL; every business fixture and mutation rolls back. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3StateContextServices;
use app\services\cashier\v3\CashierV3IdempotencyKeyServices;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\card\CashierV3CardToBalanceServices;
use app\services\cashier\v3\card\CashierV3CardConversionGuard;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\card\CashierV3IssuedCardRuleStateServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
(new think\App('/var/www/html/'))->initialize();
// Think's CLI renderer can exit zero after an uncaught exception. CI must see
// a real failure code, as well as the terminal success marker on a passing run.
set_exception_handler(static function (\Throwable $error): void {
    fwrite(fopen('php://stderr', 'wb'), 'R53_CARD_TO_BALANCE_MYSQL_FAILED ' . get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});
if (config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('local MySQL only');
$checks = 0;
function check53(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $checks++; echo 'PASS ' . $label . PHP_EOL;
}
function denied53(callable $fn, string $label): void {
    try { $fn(); } catch (CashierV3CommandException $e) { check53(true, $label); return; }
    throw new RuntimeException('FAIL accepted ' . $label);
}
function clone53(string $table, string $idField, array $changes): int {
    $row = (array)Db::name($table)->where($idField, '>', 0)->find();
    if (!$row) throw new RuntimeException('missing base ' . $table);
    unset($row[$idField]);
    return (int)Db::name($table)->insertGetId(array_replace($row, $changes));
}
$staff = (array)Db::name('system_store_staff')->alias('s')->join('organization_store os', 'os.store_id=s.store_id')
    ->where('s.status', 1)->where('s.is_del', 0)->field('s.*,os.org_id')->find();
$storeId = (int)$staff['store_id']; $operatorId = (int)$staff['id'];
$operator = new CashierV3OperatorScope($storeId, $operatorId, (string)$staff['org_id'], '0');
$access = new CashierV3DataScopeContext($operatorId, $operatorId, $storeId, '0', (string)$staff['org_id'],
    [$storeId], 'stores', [], false, '', 'r53-local', [CashierV3CardToBalanceServices::FEATURE], $staff);
$recorder = new CashierV3BusinessEventRecorder();
$service = new CashierV3CardToBalanceServices(); $reader = new CashierV3UnifiedRefundServices();
$prefix = 'R53-' . bin2hex(random_bytes(8));
$command = static function (array $payload, string $suffix) use ($recorder, $operator, $access, $prefix): array {
    $key = $prefix . '-' . $suffix;
    return ['operator_scope' => $operator, 'data_scope' => $access, 'payload' => $payload, 'contexts' => [],
        'idempotency_key' => $key, 'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution('submit-card-operation', $key, $operator, $access, 'r53-local'),
        'event_contract' => CashierV3ActionManifest::eventContractFor('submit-card-operation')];
};
denied53(static function () use ($service) { $service->submitInTx([]); }, 'transaction is mandatory');
Db::startTrans();
try {
    $memberId = clone53('user', 'uid', ['account' => $prefix, 'phone' => '', 'status' => 1,
        'is_del' => 0, 'delete_time' => null, 'now_money' => '500.00', 'ben_money' => '400.00',
        'give_money' => '100.00', 'balance_version' => 1]);
    $orderId = clone53('store_order', 'id', ['order_id' => $prefix, 'unique' => md5($prefix), 'uid' => $memberId, 'store_id' => $storeId,
        'paid' => 1, 'is_del' => 0, 'is_system_del' => 0, 'is_user_del' => 0, 'refund_status' => 0,
        'terminal_action' => 0, 'card_upgrade_use_oid' => 0, 'pay_price' => '1000.00',
        'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00']);
    $holderId = clone53('user_card_holder', 'id', ['uid' => $memberId, 'oid' => $orderId,
        'store_id' => $storeId, 'card_no' => $prefix, 'card_name' => 'R53测试卡', 'is_del' => 0,
        'write_times' => 10, 'write_surplus_times' => 7, 'write_start' => 0, 'write_end' => time() - 86400]);
    $detailId = clone53('store_order_cart_info', 'id', ['oid' => $orderId, 'uid' => $memberId, 'cart_id' => $prefix,
        'cart_type' => 2, 'product_type' => 6, 'source_type' => 'migration', 'is_writeoff' => 0, 'is_gift' => 0,
        'write_times' => 10, 'write_surplus_times' => 7, 'write_end' => time() - 86400,
        'pay_price' => '1000.00', 'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00',
        'cart_info' => json_encode(['productInfo' => ['store_name' => 'R53项目'],
            'rh_source' => ['source_line_paid_amount' => '1000.00']])]);
    $payload = ['operationType' => 'card_to_balance', 'memberId' => $memberId,
        'sourceCardHolderId' => $holderId, 'confirmedAmountCents' => 70000, 'reason' => 'R53事务回滚验证'];
    if (getenv('R53_GATEWAY_TEST') === '1') {
        // Opt-in integration uses real non-admin staff, employee appointments and
        // job grants. No permission/configuration rows are fabricated or changed.
        Db::execute('SAVEPOINT r53_real_gateway');
        $gatewayWalletBefore = Db::name('user')->where('uid', $memberId)->find();
        $gatewayHolderBefore = Db::name('user_card_holder')->where('id', $holderId)->find();
        $gatewayOrderBefore = Db::name('store_order')->where('id', $orderId)->find();
        $gatewayKey = '';
        try {
            $dispatcher = CashierV3Bootstrap::dispatcher();
            $gatewayStaff = []; $gatewayScope = null; $staffGateFailures = [];
            $candidates = Db::name('system_store_staff')->alias('s')
                ->join('employee e', 'e.id=s.employee_id')->join('organization_store os', 'os.store_id=s.store_id')
                ->where('s.status', 1)->where('s.is_del', 0)->where('s.level', '<>', 0)
                ->where('e.status', 1)->where('e.is_del', 0)->field('s.*,os.org_id')->order('s.id asc')->select()->toArray();
            foreach ($candidates as $candidate) {
                $resolvedOperator = $dispatcher->scopeResolver()->operatorScope((int)$candidate['store_id'], (int)$candidate['id']);
                $resolvedScope = $dispatcher->dataScopeFactory()->build((int)$candidate['store_id'], (int)$candidate['id'],
                    $candidate, $resolvedOperator->tenantId(), $resolvedOperator->organizationId());
                if (!$resolvedScope->hasFeature(CashierV3CardToBalanceServices::FEATURE)
                    || !$resolvedScope->hasFeature('cashier.v3.cashier.card.disable')
                    || !$resolvedScope->allowsStore((int)$candidate['store_id']) || $resolvedScope->isReadOnlySession()) {
                    $staffGateFailures[] = ['staffId' => (int)$candidate['id'], 'employeeId' => (int)$candidate['employee_id'],
                        'storeId' => (int)$candidate['store_id'], 'mode' => $resolvedScope->authorizationMode(),
                        'hasFeature' => $resolvedScope->hasFeature(CashierV3CardToBalanceServices::FEATURE)];
                    continue;
                }
                $gatewayStaff = $candidate; $gatewayScope = $resolvedScope; break;
            }
            if (!$gatewayStaff) throw new RuntimeException('R53_GATEWAY_GATE no actual authorized non-level0 staff: '
                . json_encode($staffGateFailures, JSON_UNESCAPED_UNICODE));
            check53($gatewayScope->employeeId() === (int)$gatewayStaff['employee_id'], 'Gateway fixture resolves real staff employee and store grants');
            echo 'R53_GATEWAY_ACTOR ' . json_encode(['staffId' => (int)$gatewayStaff['id'],
                'employeeId' => (int)$gatewayStaff['employee_id'], 'storeId' => (int)$gatewayStaff['store_id'],
                'level' => (int)$gatewayStaff['level'], 'authorizationMode' => $gatewayScope->authorizationMode()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            $uuid = static function (): string {
                $hex = bin2hex(random_bytes(16));
                return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
                    . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
            };
            $clientSessionId = 'SESSION-' . $uuid(); $gatewayKey = 'CMD-' . $uuid();
            $stateContexts = new CashierV3StateContextServices(new CashierV3IdempotencyKeyServices());
            $context = $stateContexts->resolve((int)$gatewayStaff['store_id'], (int)$gatewayStaff['id'], $clientSessionId);
            $session = ['store_id' => (int)$gatewayStaff['store_id'], 'operator_id' => (int)$gatewayStaff['id'],
                'operator_profile' => $gatewayStaff, 'client_session_id' => $clientSessionId,
                'state_context_id' => $context['state_context_id'], 'operator_ip' => '127.0.0.1'];
            $body = ['action' => 'submit-card-operation', 'clientSessionId' => $clientSessionId,
                'stateContextId' => $context['state_context_id'], 'correlationId' => 'CORR-R53-' . $uuid(),
                'command' => ['action' => 'submit-card-operation', 'idempotencyKey' => $gatewayKey, 'contexts' => []],
                'operationType' => 'card_to_balance', 'memberId' => (string)$memberId,
                'sourceCardHolderId' => (string)$holderId, 'confirmedAmountCents' => '70000', 'reason' => 'R53真实Gateway回滚联调'];
            Db::execute('SAVEPOINT r53_legacy_card_gateway');
            try {
                // Exercise the existing versioned Gateway path as well: the
                // resource provider locks origin before holder, then the real
                // card authority performs the existing disable mutation.
                $provider = new CashierV3EntitlementResourceVersionProvider(new CashierV3CashierReadinessGuard());
                $realOperator = $dispatcher->scopeResolver()->operatorScope((int)$gatewayStaff['store_id'], (int)$gatewayStaff['id']);
                $holderVersion = $provider->synchronizeProjectionVersion('card_holder', (string)$holderId, $realOperator, $gatewayScope);
                $disableBody = ['action' => 'submit-card-operation', 'clientSessionId' => $clientSessionId,
                    'stateContextId' => $context['state_context_id'], 'correlationId' => 'CORR-R53-' . $uuid(),
                    'command' => ['action' => 'submit-card-operation', 'idempotencyKey' => 'CMD-' . $uuid(),
                        'contexts' => [['kind' => 'card_holder', 'id' => (string)$holderId, 'expectedVersion' => $holderVersion]]],
                    'operationType' => 'card_disable', 'sourceCardHolderId' => (string)$holderId, 'reason' => 'R53旧卡操作锁序回归'];
                $disable = $dispatcher->dispatch($disableBody, $session);
                check53(($disable['result']['status'] ?? '') === 'success'
                    && Db::name('cashier_v3_card_state')->where('card_holder_id', $holderId)->value('card_status') === 'disabled',
                    'existing versioned card-disable traverses real Gateway provider and authority successfully: ' . json_encode($disable['result'] ?? []));
                check53(Db::name('user')->where('uid', $memberId)->find() === $gatewayWalletBefore
                    && Db::name('store_order')->where('id', $orderId)->find() === $gatewayOrderBefore,
                    'existing versioned Gateway card operation keeps wallet and original order unchanged');
            } finally { Db::execute('ROLLBACK TO SAVEPOINT r53_legacy_card_gateway'); }
            $success = $dispatcher->dispatch($body, $session);
            check53(($success['result']['status'] ?? '') === 'success' && empty($success['replay']),
                'real Gateway dispatch accepts empty contexts and numeric-string business identifiers: ' . json_encode($success['result'] ?? []));
            $gatewayWalletAfter = Db::name('user')->where('uid', $memberId)->find();
            check53($gatewayWalletAfter['ben_money'] === '400.00' && $gatewayWalletAfter['give_money'] === '800.00'
                && $gatewayWalletAfter['now_money'] === '1200.00', 'Gateway adds exactly700 gift with principal unchanged');
            check53(Db::name('store_order')->where('id', $orderId)->find() === $gatewayOrderBefore, 'Gateway preserves entire original order row');
            $replay = $dispatcher->dispatch($body, $session);
            check53(Db::name('user')->where('uid', $memberId)->find() === $gatewayWalletAfter,
                'Gateway replay does not add gift or wallet version twice');
            check53(Db::name('cashier_v3_business_event')->where('command_idempotency_key', $gatewayKey)->count() === 1
                && Db::name('cashier_v3_balance_fact')->where('command_idempotency_key', $gatewayKey)->count() === 1,
                'Gateway success plus replay persist exactly one event and balance fact');
            check53(($replay['result']['status'] ?? '') === 'success' && !empty($replay['replay'])
                && ($replay['data']['cardOperation'] ?? null) === ($success['data']['cardOperation'] ?? null),
                'real Gateway same-key replay returns original card operation: ' . json_encode([
                    'result' => $replay['result'] ?? [], 'replay' => $replay['replay'] ?? null,
                    'originalOperation' => $success['data']['cardOperation'] ?? null,
                    'replayedOperation' => $replay['data']['cardOperation'] ?? null], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $error) {
            echo 'R53_GATEWAY_GATE ' . json_encode(['class' => get_class($error), 'message' => $error->getMessage(),
                'code' => $error instanceof CashierV3CommandException ? $error->getResultCode() : '',
                'detail' => $error instanceof CashierV3CommandException ? $error->getDetail() : []], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            throw $error;
        } finally {
            Db::execute('ROLLBACK TO SAVEPOINT r53_real_gateway');
        }
        check53(Db::name('user')->where('uid', $memberId)->find() === $gatewayWalletBefore
            && Db::name('user_card_holder')->where('id', $holderId)->find() === $gatewayHolderBefore
            && !Db::name('cashier_v3_business_event')->where('command_idempotency_key', $gatewayKey)->count()
            && !Db::name('cashier_v3_balance_fact')->where('command_idempotency_key', $gatewayKey)->count(),
            'Gateway savepoint restores fixture rights wallet and event/fact before original service tests');
    }
    $snapshot = $reader->transferSnapshot($holderId, $memberId, '0', true);
    check53($snapshot['eligible'] && $snapshot['amountCents'] === 70000, 'expired card retains actual unconsumed 700');
    Db::execute('SAVEPOINT r53_zero_carrier');
    Db::name('store_order')->where('id', $orderId)->update(['pay_price' => '0.00']);
    $zeroCarrier = $reader->transferSnapshot($holderId, $memberId, '0', true);
    check53($zeroCarrier['eligible'] && $zeroCarrier['amountCents'] === 70000,
        'zero-price legacy carrier does not cap real source-line remaining value');
    Db::execute('ROLLBACK TO SAVEPOINT r53_zero_carrier');
    $originalOrder = Db::name('store_order')->where('id', $orderId)->find();
    Db::execute('SAVEPOINT r53_storeless_scope');
    Db::name('store_order')->where('id', $orderId)->update(['store_id' => 0, 'mark' => 'migration_entitlement', 'is_system_del' => 1]);
    Db::name('user_card_holder')->where('id', $holderId)->update(['store_id' => 0]);
    denied53(static function () use ($service, $command, $payload) { $service->submitInTx($command($payload, 'storeless-unregistered')); },
        'storeless historical holder without migration scope cannot bypass organization grant');
    Db::name('store_order')->where('id', $orderId)->update(['mark' => 'not_a_migration']);
    denied53(static function () use ($service, $command, $payload) { $service->submitInTx($command($payload, 'storeless-forged-mark')); },
        'storeless holder with wrong marker cannot use ordinary cross-store allowance');
    Db::execute('ROLLBACK TO SAVEPOINT r53_storeless_scope');
    $counts = [];
    foreach (['cashier_v3_sale_fact', 'cashier_v3_payment_fact', 'cashier_v3_refund_adjustment'] as $table) {
        $counts[$table] = Db::name($table)->count();
    }
    $bad = $payload; $bad['confirmedAmountCents'] = 69900;
    denied53(static function () use ($service, $command, $bad) { $service->submitInTx($command($bad, 'amount')); }, 'amount cannot be reduced or edited');
    $bad['confirmedAmountCents'] = 70001;
    denied53(static function () use ($service, $command, $bad) { $service->submitInTx($command($bad, 'fraction')); }, 'fractional yuan cannot be saved');
    $bad = $payload; $bad['memberId']++;
    denied53(static function () use ($service, $command, $bad) { $service->submitInTx($command($bad, 'owner')); }, 'wrong current member is rejected');
    $noGrant = $command($payload, 'grant');
    $noGrant['data_scope'] = new CashierV3DataScopeContext($operatorId, $operatorId, $storeId, '0', (string)$staff['org_id'],
        [$storeId], 'stores', [], false, '', 'r53', [], $staff);
    denied53(static function () use ($service, $noGrant) { $service->submitInTx($noGrant); }, 'dedicated permission is mandatory');
    // A pending debt blocks conversion; an audited cancellation reduces value rather than becoming money.
    $debtId = clone53('store_debt', 'id', ['order_id' => $orderId, 'uid' => $memberId, 'store_id' => $storeId,
        'debt_no' => $prefix, 'order_sn' => $prefix, 'total_debt' => '200.00', 'repaid_debt' => '0.00', 'status' => 0]);
    denied53(static function () use ($service, $command, $payload) { $service->submitInTx($command($payload, 'debt')); }, 'pending debt blocks conversion');
    Db::name('store_debt')->where('id', $debtId)->update(['status' => 3]);
    Db::name('cashier_v3_debt_cancellation')->insert(['cancellation_id' => $prefix, 'debt_id' => $debtId,
        'tenant_id' => '0', 'store_id' => $storeId, 'member_id' => $memberId, 'debt_no_snapshot' => $prefix,
        'source_order_no_snapshot' => $prefix, 'original_debt_cents' => 20000, 'repaid_debt_cents' => 0,
        'cancelled_debt_cents' => 20000, 'reason_snapshot' => 'R53测试', 'operator_id' => $operatorId,
        'operator_name_snapshot' => '测试', 'store_name_snapshot' => '测试', 'command_idempotency_key' => $prefix,
        'business_event_no' => $prefix, 'business_date' => date('Y-m-d'), 'occurred_at' => time(), 'settled_at' => time(), 'recorded_at' => time()]);
    $snapshot = $reader->transferSnapshot($holderId, $memberId, '0', true);
    check53($snapshot['eligible'] && $snapshot['pendingDebtCents'] === 0 && $snapshot['amountCents'] === 50000,
        'cancelled200 is excluded from remaining700');
    $payload['confirmedAmountCents'] = 50000;
    // A transferred card is valued against the immutable origin debt, but the
    // entire credit belongs to its current holder, including a disabled card.
    $originMemberId = $memberId;
    $memberId = clone53('user', 'uid', ['account' => $prefix . '-receiver', 'phone' => '', 'status' => 1,
        'is_del' => 0, 'delete_time' => null, 'now_money' => '500.00', 'ben_money' => '400.00',
        'give_money' => '100.00', 'balance_version' => 1]);
    Db::name('user_card_holder')->where('id', $holderId)->update(['uid' => $memberId]);
    Db::name('cashier_v3_card_state')->insert(['tenant_id' => '0', 'card_holder_id' => $holderId,
        'origin_order_id' => $orderId, 'origin_member_id' => $originMemberId, 'current_member_id' => $memberId,
        'card_status' => 'disabled', 'effective_write_start' => 0, 'effective_write_end' => time() - 86400,
        'current_version' => 3, 'created_at' => time(), 'updated_at' => time()]);
    $payload['memberId'] = $memberId;
    $snapshot = $reader->transferSnapshot($holderId, $memberId, '0', true);
    check53($snapshot['eligible'] && $snapshot['amountCents'] === 50000, 'transferred disabled current holder retains value after origin cancellation');
    $projectionReflection = new ReflectionClass(CashierV3EntitlementProjectionServices::class);
    $projection = $projectionReflection->newInstanceWithoutConstructor();
    $rulesProperty = $projectionReflection->getProperty('cardRules'); $rulesProperty->setAccessible(true);
    $rulesProperty->setValue($projection, new CashierV3CardRuleEntitlementAuthorityServices());
    $loadRows = $projectionReflection->getMethod('loadRows'); $loadRows->setAccessible(true);
    $build = $projectionReflection->getMethod('buildSources'); $build->setAccessible(true);
    $projectedRows = $loadRows->invoke($projection, $memberId, $operator, '0', false, [$holderId], null, true, true, true, true, true);
    $sources = $build->invoke($projection, $projectedRows, [], [], '0', false, true);
    check53(count($sources) === 1 && $sources[0]['balanceTransfer']['eligible']
        && $sources[0]['balanceTransfer']['amountCents'] === $snapshot['amountCents'], 'list and submit monetary readers agree for transferred disabled card');
    Db::execute('SAVEPOINT r53_legacy_terminal');
    $terminalId = (int)Db::name('store_order_terminal_operation')->insertGetId(['store_order_id' => $orderId,
        'operation_no' => $prefix . '-terminal', 'request_token' => $prefix . '-terminal',
        'state' => 2, 'action_type' => 1]);
    denied53(static function () use ($service, $command, $payload) { $service->submitInTx($command($payload, 'terminal-pending')); },
        'claimed legacy channel-pending refund blocks conversion while original terminal_action remains zero');
    $terminalRows = $loadRows->invoke($projection, $memberId, $operator, '0', false, [$holderId], null, true, true, true, true, true);
    $terminalSources = $build->invoke($projection, $terminalRows, [], [], '0', false, true);
    check53(!$terminalSources[0]['balanceTransfer']['eligible']
        && strpos($terminalSources[0]['balanceTransfer']['unavailableReason'], '原流程') !== false,
        'batched projection marks legacy terminal-workflow card unavailable');
    Db::name('store_order_terminal_operation')->where('id', $terminalId)->update(['state' => 5]);
    denied53(static function () use ($service, $command, $payload) { $service->submitInTx($command($payload, 'terminal-resumable')); },
        'retryable failed legacy terminal workflow still blocks conversion');
    check53(Db::name('store_order')->where('id', $orderId)->find() === $originalOrder,
        'pending legacy terminal checks never mutate the original sale or invoke its refund workflow');
    Db::execute('ROLLBACK TO SAVEPOINT r53_legacy_terminal');
    Db::execute('SAVEPOINT r53_related_terminal');
    $repayOrderId = clone53('store_order', 'id', ['order_id' => $prefix . '-repay', 'unique' => md5($prefix . '-repay'),
        'uid' => $originMemberId, 'store_id' => $storeId, 'paid' => 1, 'is_del' => 0, 'terminal_action' => 0,
        'is_debt_repay' => 1, 'debt_repay_origin_order_id' => $orderId]);
    $repayTerminalId = (int)Db::name('store_order_terminal_operation')->insertGetId(['store_order_id' => $repayOrderId,
        'operation_no' => $prefix . '-repay-terminal', 'request_token' => $prefix . '-repay-terminal', 'state' => 2, 'action_type' => 1]);
    check53(!CashierV3CardConversionGuard::legacyTerminalOrderIds([$orderId], true)
        && CashierV3CardToBalanceServices::legacyRelatedTerminalOrderIds([$orderId], true) === [$orderId],
        'repayment terminal blocks origin even when the origin itself has no terminal operation');
    denied53(static function () use ($service, $command, $payload) { $service->submitInTx($command($payload, 'repay-terminal')); },
        'actual conversion rejects in-flight terminal on explicitly linked legacy repayment');
    $repayRows = $loadRows->invoke($projection, $memberId, $operator, '0', false, [$holderId], null, true, true, true, true, true);
    $repaySources = $build->invoke($projection, $repayRows, [], [], '0', false, true);
    check53(!$repaySources[0]['balanceTransfer']['eligible'], 'batched projection also rejects repayment terminal');
    Db::name('store_order')->where('id', $repayOrderId)->update(['is_debt_repay' => 0, 'debt_repay_origin_order_id' => 0]);
    Db::name('store_debt_repay')->insert(['repay_no' => $prefix . '-bridge', 'debt_id' => $debtId,
        'repay_order_id' => $repayOrderId, 'order_id' => $orderId, 'uid' => $originMemberId,
        'repay_amount' => '200.00', 'pay_store_id' => $storeId, 'debt_store_id' => $storeId]);
    Db::name('store_order_terminal_operation')->where('id', $repayTerminalId)->update(['state' => 5]);
    denied53(static function () use ($service, $command, $payload) { $service->submitInTx($command($payload, 'repay-bridge-terminal')); },
        'ledger-only repayment bridge with retryable failed terminal also blocks conversion');
    check53(Db::name('store_order')->where('id', $orderId)->find() === $originalOrder,
        'related repayment terminal checks preserve complete original order');
    Db::execute('ROLLBACK TO SAVEPOINT r53_related_terminal');
    foreach (['normal', 'choice_count', 'time'] as $ruleType) {
        $realHolder = (array)Db::name('cashier_v3_card_rule_state')->alias('r')->join('user_card_holder h', 'h.id=r.card_holder_id')
            ->join('store_order o', 'o.id=h.oid')->where('r.tenant_id', '0')->where('r.rule_type', $ruleType)
            ->where('r.status', 'active')->where('h.is_del', 0)->where('h.write_surplus_times', '>', 0)
            ->where('o.paid', 1)->where('o.is_del', 0)->where('o.is_system_del', 0)->where('o.refund_status', 0)
            ->where('o.terminal_action', 0)->where('o.card_upgrade_use_oid', 0)->field('h.id,h.uid')->find();
        if (!$realHolder) throw new RuntimeException('missing real ' . $ruleType . ' card');
        $realValue = $reader->transferSnapshot((int)$realHolder['id'], (int)$realHolder['uid'], '0', true);
        $realRows = $loadRows->invoke($projection, (int)$realHolder['uid'], $operator, '0', false,
            [(int)$realHolder['id']], null, true, true, true, true, true);
        $realSources = $build->invoke($projection, $realRows, [], [], '0', false, true);
        check53(count($realSources) === 1 && $realSources[0]['balanceTransfer']['amountCents'] === $realValue['amountCents'],
            'real ' . $ruleType . ' pool agrees with entitlement source amount');
        if ($ruleType === 'choice_count') {
            Db::execute('SAVEPOINT r53_independent');
            $sharedHolder = Db::name('user_card_holder')->where('id', (int)$realHolder['id'])->find();
            clone53('store_order_cart_info', 'id', ['oid' => (int)$sharedHolder['oid'], 'uid' => (int)$realHolder['uid'],
                'cart_id' => $prefix . '-independent', 'cart_type' => 2, 'product_type' => 6, 'product_id' => 990001,
                'is_writeoff' => 0, 'is_gift' => 0, 'write_times' => 3, 'write_surplus_times' => 2,
                'write_start' => 0, 'write_end' => 0, 'pay_price' => '120.00', 'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00',
                'cart_info' => json_encode(['productInfo' => ['store_name' => '独立替换项目'],
                    'sourceType' => 'cashier_v3_project_replacement',
                    'amountCalculationVersion' => 'whole-yuan-floor-final-remainder-v1'])]);
            $independentValue = $reader->transferSnapshot((int)$realHolder['id'], (int)$realHolder['uid'], '0', true);
            $independentRows = $loadRows->invoke($projection, (int)$realHolder['uid'], $operator, '0', false,
                [(int)$realHolder['id']], null, true, true, true, true, true);
            $independentSources = $build->invoke($projection, $independentRows, [], [], '0', false, true);
            check53($independentValue['amountCents'] === $realValue['amountCents'] + 8000
                && $independentSources[0]['balanceTransfer']['amountCents'] === $independentValue['amountCents'],
                'shared card adds independent replacement remaining80 exactly once');
            Db::execute('ROLLBACK TO SAVEPOINT r53_independent');
        }
    }
    // Build a genuine issued combination state with two selectable services in
    // one shared pool plus a separate choice-kind group. All fixtures roll back.
    Db::execute('SAVEPOINT r53_combination');
    Db::name('store_debt')->where('id', $debtId)->update(['status' => 1]);
    Db::name('user_card_holder')->where('id', $holderId)->update(['write_times' => 5, 'write_surplus_times' => 3]);
    $comboReceipt = $prefix . '-combo'; $comboDetails = []; $issuedComponents = [];
    foreach ([101 => [1, 'choice_count', 30000, 3, 2], 102 => [1, 'choice_count', 30000, 3, 2],
        103 => [2, 'choice_kind', 40000, 2, 1]] as $productId => [$groupNo, $groupRule, $paid, $total, $left]) {
        $info = ['productInfo' => ['store_name' => 'R53组合项目'], 'combination_group_no' => $groupNo];
        $cartChanges = ['oid' => $orderId, 'uid' => $originMemberId, 'product_id' => $productId,
            'cart_id' => $comboReceipt . '-' . $productId, 'cart_type' => 2, 'product_type' => 6,
            'source_type' => 'cashier_v3', 'is_writeoff' => 0, 'is_gift' => 0, 'write_times' => $total,
            'write_surplus_times' => $left, 'write_start' => 0, 'write_end' => time() + 86400,
            'pay_price' => bcdiv((string)$paid, '100', 2), 'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00',
            'cart_info' => json_encode($info)];
        if ($productId === 101) {
            Db::name('store_order_cart_info')->where('id', $detailId)->update($cartChanges); $comboDetail = $detailId;
        } else $comboDetail = clone53('store_order_cart_info', 'id', $cartChanges);
        $comboDetails[] = $comboDetail;
        $issuedComponents[] = ['detailId' => $comboDetail, 'snapshot' => ['relationId' => $productId,
            'productId' => $productId, 'skuUnique' => 'r53-' . $productId, 'combinationGroupNo' => $groupNo,
            'combinationGroupRule' => $groupRule, 'writeTimes' => $total, 'configuredAmountCents' => $paid,
            'nameSnapshot' => 'R53组合项目']];
        clone53('cashier_v3_card_sale_item_allocation_fact', 'id', ['allocation_fact_id' => $comboReceipt . '-' . $productId,
            'natural_key' => $comboReceipt . '-' . $productId, 'card_receipt_id' => $comboReceipt,
            'component_product_id' => $productId, 'sale_amount_cents' => $paid, 'status' => 'effective']);
    }
    clone53('cashier_v3_card_purchase_receipt', 'id', ['receipt_id' => $comboReceipt, 'tenant_id' => '0',
        'checkout_request_id' => $comboReceipt, 'sales_order_id' => $comboReceipt, 'sales_order_line_id' => $comboReceipt,
        'issue_no' => 1, 'command_idempotency_key' => $comboReceipt, 'legacy_order_id' => $orderId,
        'card_holder_id' => $holderId, 'benefit_detail_ids_json' => json_encode($comboDetails), 'status' => 'completed']);
    clone53('cashier_v3_sales_order_line', 'id', ['order_id' => $comboReceipt, 'order_line_id' => $comboReceipt,
        'checkout_request_id' => $comboReceipt, 'checkout_line_id' => $comboReceipt,
        'natural_key' => $comboReceipt, 'tenant_id' => '0', 'member_id' => $originMemberId,
        'store_id' => $storeId, 'sale_amount_cents' => 100000]);
    (new CashierV3IssuedCardRuleStateServices())->issueInTx($comboReceipt,
        ['tenant_id' => '0', 'order_id' => $comboReceipt, 'member_id' => $memberId, 'store_id' => $storeId],
        ['order_line_id' => $comboReceipt], [], ['ruleType' => 'combination', 'ruleVersion' => 1,
            'definitionVersion' => 1, 'combinationGroups' => [
                ['groupNo' => 1, 'nameSnapshot' => '共享组', 'ruleType' => 'choice_count', 'choiceLimit' => 0, 'sharedTimes' => 3],
                ['groupNo' => 2, 'nameSnapshot' => '独立组', 'ruleType' => 'choice_kind', 'choiceLimit' => 1, 'sharedTimes' => 0]]],
        [], ['issuedComponents' => $issuedComponents], ['holderId' => $holderId], time());
    Db::name('cashier_v3_card_rule_group_state')->where('card_holder_id', $holderId)->where('group_no', 1)
        ->update(['shared_remaining_times' => 2]);
    Db::name('cashier_v3_card_rule_component')->where('card_holder_id', $holderId)->where('combination_group_no', 2)
        ->update(['remaining_times' => 1]);
    $comboValue = $reader->transferSnapshot($holderId, $memberId, '0', true);
    $comboRows = $loadRows->invoke($projection, $memberId, $operator, '0', false, [$holderId], null, true, true, true, true, true);
    $comboSources = $build->invoke($projection, $comboRows, [], [], '0', false, true);
    check53($comboValue['amountCents'] === 60000 && $comboSources[0]['balanceTransfer']['amountCents'] === 60000,
        'combination shared600 remaining400 counted once plus independent400 remaining200');
    Db::execute('SAVEPOINT r53_closed_detail');
    Db::name('store_order_cart_info')->where('id', $comboDetails[2])->update(['is_writeoff' => 1]);
    $closedValue = $reader->transferSnapshot($holderId, $memberId, '0', true);
    $closedRows = $loadRows->invoke($projection, $memberId, $operator, '0', false, [$holderId], null, true, true, true, true, true);
    $closedSources = $build->invoke($projection, $closedRows, [], [], '0', false, true);
    check53($closedValue['amountCents'] === 40000 && $closedSources[0]['balanceTransfer']['amountCents'] === 40000,
        'closed detail with physical surplus contributes zero without reallocating its original price');
    Db::execute('SAVEPOINT r53_closed_cancelled');
    Db::name('store_debt')->where('id', $debtId)->update(['status' => 3, 'total_debt' => '100.00', 'repaid_debt' => '0.00']);
    Db::name('cashier_v3_debt_cancellation')->where('debt_id', $debtId)
        ->update(['original_debt_cents' => 10000, 'cancelled_debt_cents' => 10000]);
    $stackedValue = $reader->transferSnapshot($holderId, $memberId, '0', true);
    $stackedRows = $loadRows->invoke($projection, $memberId, $operator, '0', false, [$holderId], null, true, true, true, true, true);
    $stackedSources = $build->invoke($projection, $stackedRows, [], [], '0', false, true);
    check53($stackedValue['amountCents'] === 30000 && $stackedSources[0]['balanceTransfer']['amountCents'] === 30000,
        'closed combination detail plus cancelled100 leaves surviving400 pool transferable300 in reader and projection');
    $stackedPayload = $payload; $stackedPayload['confirmedAmountCents'] = 30000;
    $stackedResult = $service->submitInTx($command($stackedPayload, 'stacked-success'));
    check53($stackedResult['operation']['amountCents'] === 30000
        && (int)Db::name('cashier_v3_balance_fact')->where('command_idempotency_key', $prefix . '-stacked-success')->value('bonus_delta_cents') === 30000,
        'closed plus cancelled combination actually converts exactly300 and no cancelled portion');
    Db::execute('ROLLBACK TO SAVEPOINT r53_closed_cancelled');
    Db::execute('ROLLBACK TO SAVEPOINT r53_closed_detail');
    Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $comboDetails[2])->update(['status' => 'revoked']);
    $revokedValue = $reader->transferSnapshot($holderId, $memberId, '0', true);
    $revokedRows = $loadRows->invoke($projection, $memberId, $operator, '0', false, [$holderId], null, true, true, true, true, true);
    $revokedSources = $build->invoke($projection, $revokedRows, [], [], '0', false, true);
    check53($revokedValue['amountCents'] === 40000 && $revokedSources[0]['balanceTransfer']['amountCents'] === 40000,
        'revoked rule component with physical surplus contributes zero in reader and projection');
    Db::execute('ROLLBACK TO SAVEPOINT r53_closed_detail');
    Db::name('store_order_cart_info')->where('id', $comboDetails[0])->update(['is_writeoff' => 1]);
    $oneOption = $reader->transferSnapshot($holderId, $memberId, '0', true);
    check53($oneOption['amountCents'] === 60000, 'closing one shared option does not duplicate or erase the remaining shared pool');
    Db::execute('ROLLBACK TO SAVEPOINT r53_closed_detail');
    $comboPayload = $payload; $comboPayload['confirmedAmountCents'] = 60000;
    $comboResult = $service->submitInTx($command($comboPayload, 'combo-success'));
    check53($comboResult['operation']['amountCents'] === 60000
        && !Db::name('cashier_v3_card_rule_group_state')->where('card_holder_id', $holderId)->where('status', '<>', 'revoked')->count(),
        'combination conversion atomically revokes all group pools');
    Db::execute('ROLLBACK TO SAVEPOINT r53_combination');
    // Legacy terminal states remain disallowed even if physical rights survive.
    Db::execute('SAVEPOINT r53_terminal');
    Db::name('store_order')->where('id', $orderId)->update(['terminal_action' => 2]);
    check53(!$reader->transferSnapshot($holderId, $memberId, '0', true)['eligible'], 'voided source cannot convert');
    Db::execute('ROLLBACK TO SAVEPOINT r53_terminal');
    Db::execute('SAVEPOINT r53_occupation');
    Db::name('cashier_v3_reservation_entitlement_occupation')->insert(['tenant_id' => '0', 'card_holder_id' => $holderId,
        'entitlement_source_detail_id' => $detailId, 'reservation_id' => 900000000 + $holderId,
        'reservation_line_id' => 900000000 + $holderId, 'status' => 'ACTIVE', 'occupied_times' => 1,
        'version' => 1, 'released_at' => 0, 'consumed_at' => 0, 'created_at' => time(), 'updated_at' => time()]);
    denied53(static function () use ($service, $command, $payload) { $service->submitInTx($command($payload, 'occupied')); }, 'active reservation occupation rejects conversion');
    Db::execute('ROLLBACK TO SAVEPOINT r53_occupation');
    // A real event-contract failure after operation insertion is rolled back at the caller transaction boundary.
    Db::execute('SAVEPOINT r53_failure');
    $bad = $command($payload, 'event-failure'); $bad['event_contract']['allowed_event_types'] = ['invalid.event'];
    try { $service->submitInTx($bad); throw new RuntimeException('event failure accepted'); }
    catch (CashierV3CommandException $e) { Db::execute('ROLLBACK TO SAVEPOINT r53_failure'); }
    check53((int)Db::name('user_card_holder')->where('id', $holderId)->value('is_del') === 0
        && !Db::name('cashier_v3_card_operation')->where('command_idempotency_key', $bad['idempotency_key'])->count(),
        'failed event rolls back inserted operation and leaves rights intact');
    Db::execute('SAVEPOINT r53_fact_failure');
    $failedFactCommand = $command($payload, 'fact-failure');
    $collisionFactId = 'CTB-' . substr(hash('sha256', '0|' . $failedFactCommand['idempotency_key']), 0, 40);
    clone53('cashier_v3_balance_fact', 'id', ['fact_id' => $collisionFactId, 'natural_key' => $prefix . '-collision',
        'command_idempotency_key' => $prefix . '-collision']);
    $beforeFailureWallet = Db::name('user')->where('uid', $memberId)->find();
    $failed = false;
    try { $service->submitInTx($failedFactCommand); }
    catch (\Throwable $e) { $failed = strpos($e->getMessage(), 'Duplicate') !== false; }
    Db::execute('ROLLBACK TO SAVEPOINT r53_fact_failure');
    check53($failed && Db::name('user')->where('uid', $memberId)->find() === $beforeFailureWallet
        && (int)Db::name('user_card_holder')->where('id', $holderId)->value('is_del') === 0,
        'failure after wallet credit and rights close rolls back both plus event/audit');
    $cmd = $command($payload, 'success');
    $result = $service->submitInTx($cmd);
    $recorder->assertRequiredPersistedInTx($cmd['event_execution'], $cmd['event_contract']);
    check53($result['operation']['amountCents'] === 50000 && $result['operation']['settlementDeltaCents'] === 0,
        'whole remaining value is credited without settlement');
    $wallet = Db::name('user')->where('uid', $memberId)->find();
    check53($wallet['ben_money'] === '400.00' && $wallet['give_money'] === '600.00' && $wallet['now_money'] === '1000.00',
        'principal unchanged, whole500 credited as gift');
    check53((int)Db::name('user_card_holder')->where('id', $holderId)->value('is_del') === 1
        && (int)Db::name('store_order_cart_info')->where('id', $detailId)->value('is_writeoff') === 1,
        'all card rights are closed');
    check53(Db::name('store_order')->where('id', $orderId)->find() === $originalOrder, 'original legacy sale row entirely unchanged');
    denied53(static function () use ($holderId) { CashierV3CardConversionGuard::assertHolderNotConverted($holderId, '0'); },
        'old writeoff reversal cannot restore converted rights');
    $convertedDebt = (array)Db::name('store_debt')->where('id', $debtId)->find();
    denied53(static function () use ($convertedDebt) { CashierV3CardConversionGuard::assertDebtNotConverted($convertedDebt, '0'); },
        'old debt restoration cannot reintroduce converted card debt');
    $fact = Db::name('cashier_v3_balance_fact')->where('command_idempotency_key', $cmd['idempotency_key'])->find();
    check53($fact['balance_change_type'] === 'card_to_balance' && $fact['source_document_type'] === 'card_operation'
        && (int)$fact['bonus_delta_cents'] === 50000 && (int)$fact['principal_delta_cents'] === 0, 'independent auditable balance fact');
    $retry = $command($payload, 'success');
    $replay = $service->submitInTx($retry);
    $recorder->assertRequiredPersistedInTx($retry['event_execution'], $retry['event_contract']);
    check53($replay['replayed'] && $replay['operation'] === $result['operation'], 'same-key replay precedes closed-card guard and retains one event');
    check53(Db::name('user')->where('uid', $memberId)->find() === $wallet, 'replay never credits twice');
    denied53(static function () use ($service, $command, $payload) { $service->submitInTx($command($payload, 'second')); }, 'new-key retry cannot reuse closed rights');
    $bad = $payload; $bad['reason'] = 'changed';
    denied53(static function () use ($service, $command, $bad) { $service->submitInTx($command($bad, 'success')); }, 'same-key different intent conflicts');
    foreach ($counts as $table => $count) check53(Db::name($table)->count() === $count, $table . ' untouched');
    // A second physical connection must block on the same real holder row. It cannot
    // see an uncommitted close, pass availability, or independently credit that card.
    $config = config('database.connections.mysql');
    $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . ($config['hostport'] ?: 3306)
        . ';dbname=' . $config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=1'); $pdo->beginTransaction();
    $blocked = false;
    try { $pdo->query('SELECT id FROM eb_user_card_holder WHERE id=' . $holderId . ' FOR UPDATE'); }
    catch (PDOException $e) { $blocked = (int)($e->errorInfo[1] ?? 0) === 1205; }
    finally { $pdo->rollBack(); }
    check53($blocked, 'concurrent physical connection cannot pass the held card lock');
} finally { Db::rollback(); }
check53(!Db::name('cashier_v3_card_operation')->whereLike('command_idempotency_key', $prefix . '%')->count()
    && !Db::name('user')->where('account', $prefix)->count(), 'all local financial and fixture mutations rolled back');
echo "R53_CARD_TO_BALANCE_MYSQL_OK checks=$checks\n";

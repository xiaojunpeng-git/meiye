<?php
declare(strict_types=1);

/**
 * D5: only entitlement business dates move; appointments, actual times and balances do not.
 * Local Docker/MySQL only. All synthetic rows and command writes are always rolled back.
 * Run: docker exec -i -e D5_LOCAL_TEST=1 -w /var/www/html mohe-cashier-app php < this-file
 */
if (getenv('D5_LOCAL_TEST') !== '1' || !is_file('/.dockerenv')) {
    throw new RuntimeException('Requires explicit local Docker test authorization');
}
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3OrderAttributionCorrectionServices;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use app\services\report\GroupManagementDashboardDailyAggregateServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Refusing non-local ruihao test database');
}
$checks = [];
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks[] = $label;
};
$service = new CashierV3OrderAttributionCorrectionServices();
$tables = ['cashier_v3_entitlement_service_fact', 'cashier_v3_entitlement_writeoff_fact',
    'cashier_v3_performance_fact', 'cashier_v3_customer_lifecycle_fact'];
$rows = static function (string $table, string $group): array {
    $q = Db::name($table)->where('tenant_id', '0');
    $table === 'cashier_v3_customer_lifecycle_fact'
        ? $q->where('natural_key', 'service-visit:' . $group) : $q->where('checkout_request_id', $group);
    return $q->order('id', 'asc')->select()->toArray();
};
$snapshot = static function (string $group) use ($tables, $rows): array {
    $result = [];
    foreach ($tables as $table) $result[$table] = $rows($table, $group);
    return $result;
};
$context = static function (int $storeId, bool $readOnly = false, array $features = ['cashier.v3.order_center'], string $tenant = '0'): array {
    $profile = (array)Db::name('system_store_staff')->where('store_id', $storeId)->order('id')->find();
    if (!$profile) throw new RuntimeException('Local staff fixture missing');
    $operatorId = (int)$profile['id'];
    $org = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
    return [new CashierV3OperatorScope($storeId, $operatorId, $org, $tenant),
        new CashierV3DataScopeContext($operatorId, $operatorId, $storeId, $tenant, $org, [$storeId],
            CashierV3DataScopeContext::MODE_STORES, [], false, '', 'D5-local', $features, $profile, $readOnly)];
};
$command = static function (string $group, string $old, string $new, string $key, array $ctx, array $override = [], ?array $contract = null) use ($service): array {
    [$operator, $scope] = $ctx;
    $action = 'correct-service-order-date';
    $recorder = new CashierV3BusinessEventRecorder();
    return $service->executeInTx(['operator_scope' => $operator, 'data_scope' => $scope,
        'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution($action, $key, $operator, $scope, 'D5-local-test'),
        'event_contract' => $contract ?? CashierV3ActionManifest::eventContractFor($action),
        'action' => $action, 'idempotency_key' => $key,
        'payload' => array_merge(['recordType' => 'service', 'recordId' => $group,
            'field' => 'business_date', 'expectedValue' => $old, 'newValue' => $new], $override)]);
};
$deny = static function (callable $run, string $message, string $label) use ($assert): void {
    try { $run(); } catch (CashierV3CommandException $e) {
        $assert(str_contains($e->getMessage(), $message), $label);
        return;
    }
    throw new RuntimeException('Expected denial: ' . $label);
};
$token = bin2hex(random_bytes(8));
$group = 'reservation:' . random_int(1500000000, 1900000000);
$normalGroup = 'CKR-' . sha1('D5-normal-' . $token);
$oldDate = '2026-10-04';
$newDate = '2026-10-01';
$ctx = $context(128);
$originalGroup = 'reservation:2148';
$original = $snapshot($originalGroup);
$assert(count($original[$tables[0]]) === 1 && $original[$tables[0]][0]['service_record_no'] === 'FW100401232', 'identified reported FW record');

// Protected source tables are read only in this test, never required by the correction command.
$protected = static function () use ($snapshot, $originalGroup): array {
    $result = ['original' => $snapshot($originalGroup)];
    foreach (['cashier_v3_reservation' => 'id', 'cashier_v3_reservation_line' => 'reservation_id',
        'cashier_v3_reservation_entitlement_occupation' => 'reservation_id',
        'cashier_v3_reservation_staff_schedule' => 'reservation_id'] as $table => $column) {
        $result[$table] = Db::name($table)->where($column, 2148)->order('id')->select()->toArray();
    }
    $result['holder'] = Db::name('user_card_holder')->where('id', 1898297)->find();
    $result['cart'] = Db::name('store_order_cart_info')->where('id', 4288599)->find();
    return $result;
};
$protectedBefore = $protected();
$aggregateBefore = Db::name(GroupManagementDashboardDailyAggregateServices::TABLE)->where('tenant_id', '0')
    ->where('store_id', 128)->whereIn('business_date', [$oldDate, $newDate])->order('id')->select()->toArray();

Db::startTrans();
try {
    // Clone completed facts under a group that deliberately has no appointment, request or receipt.
    // Synthetic linkage stays inside this transaction and cannot consume real rights again.
    $clone = static function (string $table, array $source, string $target, string $event, int $index) use ($token): array {
        unset($source['id']);
        foreach (['service_fact_id', 'writeoff_id', 'fact_id', 'receipt_id'] as $idColumn) {
            if (isset($source[$idColumn])) $source[$idColumn] = 'D5-' . sha1($token . $table . $target . $index);
        }
        if (isset($source['natural_key'])) $source['natural_key'] = $table === 'cashier_v3_customer_lifecycle_fact'
            ? 'service-visit:' . $target : 'D5:' . $token . ':' . sha1($table . $target . $index);
        if (isset($source['checkout_request_id'])) $source['checkout_request_id'] = $target;
        if (isset($source['business_event_no'])) $source['business_event_no'] = $event;
        if (isset($source['command_idempotency_key'])) $source['command_idempotency_key'] = 'D5-seed-' . $token;
        if (isset($source['source_line_id'])) $source['source_line_id'] = $target . ':' . $index;
        if (isset($source['service_record_no'])) $source['service_record_no'] = 'D5FW' . substr(sha1($token . $target), 0, 16) . $index;
        if (isset($source['business_date'])) $source['business_date'] = '2026-10-04';
        $source['id'] = Db::name($table)->insertGetId($source);
        return $source;
    };
    $event = 'EV-' . sha1($token);
    foreach ($tables as $table) {
        foreach ($original[$table] as $index => $row) $clone($table, $row, $group, $event, $index);
    }
    $second = $clone($tables[0], $original[$tables[0]][0], $group, $event, 100);
    // A later independent performance event must keep its own date, even in the same group.
    $later = $clone($tables[2], $original[$tables[2]][0], $group, 'EV-' . sha1($token . '-later'), 101);
    $assert(!Db::name('cashier_v3_reservation')->where('id', (int)substr($group, 12))->find()
        && !Db::name('cashier_v3_checkout_request')->where('request_id', $group)->find()
        && !Db::name('cashier_v3_entitlement_completion_receipt')->where('checkout_request_id', $group)->find(),
        'correction fixture has no appointment or checkout credentials');
    $before = $snapshot($group);
    $key = 'D5-date-' . $token;
    $result = $command($group, $oldDate, $newDate, $key, $ctx);
    $after = $snapshot($group);
    foreach ($before as $table => $facts) {
        foreach ($facts as $index => $fact) {
            if ($table !== $tables[2] || $fact['business_event_no'] === $event) $fact['business_date'] = $newDate;
            $assert($fact === $after[$table][$index], $table . ' row ' . $index . ' changed business_date only');
        }
    }
    foreach ([$oldDate, $newDate] as $date) {
        $amount = (int)Db::name('cashier_v3_performance_fact')->where('tenant_id', '0')->where('store_id', 128)
            ->where('business_date', $date)->where('status', 'effective')
            ->where('fact_type', 'consumption_performance_recorded')->sum('amount_cents');
        $aggregate = (int)Db::name(GroupManagementDashboardDailyAggregateServices::TABLE)->where('tenant_id', '0')
            ->where('store_id', 128)->where('business_date', $date)->value('consumption_performance_cents');
        $assert($amount === $aggregate, 'daily aggregate agrees with facts for ' . $date);
    }
    $history = $service->history(['recordType' => 'service', 'recordId' => $group], $ctx[0], $ctx[1])['changes'];
    $assert(count($history) === 1 && $history[0]['oldValue'] === $oldDate && $history[0]['newValue'] === $newDate,
        'audit contains old and new business dates');
    $businessEvent = Db::name('cashier_v3_business_event')->where('aggregate_id', $result['operationId'])->find();
    // This synchronous audit event intentionally has no async consumers in the action manifest.
    $assert((bool)$businessEvent && Db::name('cashier_v3_outbox')->where('event_no', $businessEvent['event_no'])->count() === 0,
        'correction event persisted with the declared zero async routes');
    $replay = $command($group, $oldDate, $newDate, $key, $ctx);
    $assert($replay['replayed'] === true && $replay['operationId'] === $result['operationId']
        && Db::name('cashier_v3_order_attribution_change')->where('record_id', $group)->count() === 1
        && Db::name('cashier_v3_business_event')->where('aggregate_id', $result['operationId'])->count() === 1
        && $snapshot($group) === $after, 'replay does not duplicate facts, audit or event');
    $deny(static function () use ($command, $group, $oldDate, $key, $ctx): void {
        $command($group, $oldDate, '2026-10-02', $key, $ctx);
    }, '内容已变化', 'idempotency key cannot change payload');
    $deny(static function () use ($command, $group, $oldDate, $ctx, $token): void {
        $command($group, $oldDate, '2026-10-02', 'D5-stale-' . $token, $ctx);
    }, '已被其他操作更改', 'stale date refuses overwrite');
    foreach ([[$context(140), '找不到', 'cross-store'], [$context(128, false, ['cashier.v3.order_center'], 'D5OTHER'), '找不到', 'cross-tenant'],
        [$context(128, true), '无权', 'read-only'], [$context(128, false, []), '无权', 'missing-feature']] as [$deniedCtx, $message, $label]) {
        $deny(static function () use ($command, $group, $newDate, $deniedCtx, $token, $label): void {
            $command($group, $newDate, '2026-10-02', 'D5-' . $label . $token, $deniedCtx);
        }, $message, $label . ' access denied');
    }
    foreach ([['recordId' => 'reservation:0'], ['recordId' => 'FW100401232'], ['field' => 'source'],
        ['newValue' => '2999-10-01'], ['newValue' => '2026-02-30']] as $index => $override) {
        $deny(static function () use ($command, $group, $newDate, $override, $token, $ctx, $index): void {
            $command($group, $newDate, '2026-10-02', 'D5-invalid-' . $token . $index, $ctx, $override);
        }, $index === 0 ? '记录标识无效' : ($index === 1 ? '找不到' : ($index === 2 ? '不支持' : '有效业务日期')),
            'invalid input ' . $index . ' denied');
    }
    // Divergent member/event/date and writeoff dates cannot be silently merged into this service.
    foreach ([['member_id', 1], ['business_event_no', 'EV-D5-other'], ['business_date', $oldDate], ['service_status', 'voided']] as [$column, $value]) {
        Db::startTrans();
        try {
            Db::name($tables[0])->where('id', $second['id'])->update([$column => $value]);
            $deny(static function () use ($command, $group, $newDate, $ctx, $token, $column): void {
                $command($group, $newDate, '2026-10-02', 'D5-divergent-' . $token . $column, $ctx);
            }, '不一致', 'inconsistent service ' . $column . ' denied');
        } finally { Db::rollback(); }
    }
    Db::startTrans();
    try {
        Db::name($tables[1])->where('checkout_request_id', $group)->update(['business_date' => $oldDate]);
        $deny(static function () use ($command, $group, $newDate, $ctx, $token): void {
            $command($group, $newDate, '2026-10-02', 'D5-writeoff-conflict-' . $token, $ctx);
        }, '日期不一致', 'inconsistent writeoff date denied');
    } finally { Db::rollback(); }

    // Simulate failure after date writes: gateway transaction rollback must undo facts and audit.
    Db::startTrans();
    $failed = false;
    try { $command($group, $newDate, '2026-10-02', 'D5-failure-' . $token, $ctx, [], []); }
    catch (CashierV3CommandException $e) {
        $failed = true;
        $assert($snapshot($group) !== $after
            && Db::name('cashier_v3_order_attribution_change')->where('record_id', $group)->count() === 2,
            'injected event-contract failure occurred after date and audit writes');
    }
    finally { Db::rollback(); }
    $assert($failed && $snapshot($group) === $after
        && Db::name('cashier_v3_order_attribution_change')->where('record_id', $group)->count() === 1,
        'post-write event failure rolls back facts and audit');

    $page = (new CashierV3SalesOrderQueryServices())->querySalesOrders([
        'recordType' => 'sales', 'dateFrom' => $newDate, 'dateTo' => $newDate,
        'keyword' => 'D5FW' . substr(sha1($token . $group), 0, 16), 'pageSize' => 20], $ctx[0], $ctx[1]);
    $listed = array_values(array_filter((array)$page['records'], static function (array $row) use ($group): bool {
        return ($row['checkoutRequestId'] ?? '') === $group;
    }));
    $assert(count($listed) === 1 && $listed[0]['businessDate'] === $newDate
        && in_array('correct-service-order-date', $listed[0]['availableActions'], true), 'order center reads corrected entitlement group');

    // Ordinary CKR checkout retains its request/receipt date chain without appointments.
    $normalOriginal = 'CKR-fc8ed3ee0ae98d0891edea1785d82d9e793b7902';
    foreach ($tables as $table) {
        foreach ($rows($table, $normalOriginal) as $index => $row) $clone($table, $row, $normalGroup, 'EV-' . sha1($normalGroup), $index);
    }
    $request = (array)Db::name('cashier_v3_checkout_request')->where('request_id', $normalOriginal)->find();
    unset($request['id']);
    $request['request_id'] = $normalGroup;
    $request['business_date'] = $oldDate;
    $request['creation_idempotency_key'] = $request['last_idempotency_key'] = 'D5-normal-' . $token;
    Db::name('cashier_v3_checkout_request')->insert($request);
    $receipt = (array)Db::name('cashier_v3_entitlement_completion_receipt')->where('checkout_request_id', $normalOriginal)->find();
    $clone('cashier_v3_entitlement_completion_receipt', $receipt, $normalGroup, 'EV-' . sha1($normalGroup), 0);
    $normalBefore = $snapshot($normalGroup);
    $command($normalGroup, $oldDate, $newDate, 'D5-normal-date-' . $token, $context(140));
    $normalAfter = $snapshot($normalGroup);
    foreach ($normalBefore as $table => $facts) {
        foreach ($facts as $index => $fact) {
            $fact['business_date'] = $newDate;
            $assert($fact === $normalAfter[$table][$index], 'normal checkout ' . $table . ' row ' . $index . ' business_date only');
        }
    }
    $assert(Db::name('cashier_v3_checkout_request')->where('request_id', $normalGroup)->value('business_date') === $newDate
        && Db::name('cashier_v3_entitlement_completion_receipt')->where('checkout_request_id', $normalGroup)->value('business_date') === $newDate,
        'normal checkout request and receipt still move together');
    $assert($protected() === $protectedBefore, 'original FW record, appointments, occupations, staff schedule and rights balances unchanged');
    $service->history(['recordType' => 'service', 'recordId' => $originalGroup], $ctx[0], $ctx[1]);
    $assert(true, 'reported real FW identity is now accepted without writing its date');
} finally { Db::rollback(); }

$assert($snapshot($group) === array_fill_keys($tables, []) && $snapshot($normalGroup) === array_fill_keys($tables, []),
    'all synthetic facts rolled back');
$assert(!Db::name('cashier_v3_checkout_request')->where('request_id', $normalGroup)->find()
    && !Db::name('cashier_v3_order_attribution_change')->whereIn('record_id', [$group, $normalGroup])->find(),
    'synthetic request and correction logs rolled back');
$assert($protected() === $protectedBefore, 'protected source data identical after rollback');
$assert(Db::name(GroupManagementDashboardDailyAggregateServices::TABLE)->where('tenant_id', '0')->where('store_id', 128)
    ->whereIn('business_date', [$oldDate, $newDate])->order('id')->select()->toArray() === $aggregateBefore,
    'daily aggregate restored after rollback');
echo json_encode(['status' => 'PASS', 'mode' => 'local-transaction-rollback', 'checkCount' => count($checks), 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

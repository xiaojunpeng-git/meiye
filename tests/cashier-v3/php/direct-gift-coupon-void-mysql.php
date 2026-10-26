<?php
declare(strict_types=1);

/**
 * Local MySQL integration: execute the real gift-void service and coupon settlement.
 * All fixture edits, voids, events and quota changes are rolled back, including on failure.
 * Never run against a remote DB; the selected original gift must remain unchanged.
 */
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3OrderCenterVoidServices;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\cashier\v3\settlement\CashierV3CheckoutCouponSettlementServices;
use think\facade\Db;

chdir('/var/www/html');
require 'vendor/autoload.php';
$app = new think\App();
$app->initialize();
$config = Db::connect()->getConfig();
if (!in_array((string)$config['hostname'], ['mysql', '127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('LOCAL_DB_REQUIRED');
}
$giftNo = getenv('DIRECT_GIFT_TEST_NO') ?: 'ZS26100900003';
$authority = (array)Db::name('cashier_v3_direct_gift_authority')->where('gift_no', $giftNo)->find();
$item = (array)Db::name('cashier_v3_direct_gift_item')->where('gift_id', $authority['gift_id'] ?? '')
    ->where('gift_kind', 'coupon')->where('status', 'issued')->find();
$fact = (array)Db::name('cashier_v3_gift_fact')->where('source_type', 'direct_gift')
    ->where('source_detail_id', $item['item_id'] ?? '')->where('status', 'effective')->find();
$ids = json_decode((string)($item['coupon_user_ids_json'] ?? ''), true);
if (!$authority || !$item || !$fact || !is_array($ids) || count($ids) !== 1) {
    throw new RuntimeException('ONE_UNUSED_DIRECT_COUPON_FIXTURE_REQUIRED');
}
$couponId = (int)$ids[0];
$issueId = (int)$item['coupon_issue_id'];
$originalCoupon = (array)Db::name('store_coupon_user')->where('id', $couponId)->find();
$originalIssue = (array)Db::name('store_coupon_issue')->where('id', $issueId)->find();
$storeId = (int)$authority['store_id'];
$memberId = (int)$authority['member_id'];
$tenantId = (string)$authority['tenant_id'];
$orgId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
$recordId = 'v3-direct-gift:' . $fact['gift_fact_id'];
$operator = new CashierV3OperatorScope($storeId, 1, $orgId, $tenantId);
$dataScope = new CashierV3DataScopeContext(1, 1, $storeId, $tenantId, $orgId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'direct-coupon-void-test', ['cashier.v3.order_center'],
    ['id' => 1, 'staff_name' => 'local rollback test']);
$service = new CashierV3OrderCenterVoidServices();
$recorder = new CashierV3BusinessEventRecorder();
$action = 'void-order-center-gift';
$contract = CashierV3ActionManifest::eventContractFor($action);
$passed = 0;
$check = static function (string $name, bool $condition) use (&$passed): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $name);
    $passed++;
    echo 'PASS ' . $name . PHP_EOL;
};
$invoke = static function (string $key, array $overrides = []) use ($service, $recorder, $action, $contract, $recordId, $operator, $dataScope): array {
    $scope = array_merge([
        'operator_scope' => $operator, 'data_scope' => $dataScope, 'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution($action, $key, $operator, $dataScope, 'direct-coupon-void-test'),
        'event_contract' => $contract, 'idempotency_key' => $key,
        'payload' => ['recordId' => $recordId, 'reason' => 'local rollback test'],
    ], $overrides);
    $result = $service->executeInTx($action, $scope);
    if (!$result['data']['void']['replayed']) {
        $recorder->assertRequiredPersistedInTx($scope['event_execution'], $scope['event_contract']);
    }
    return $result;
};
$expectReason = static function (callable $call, string $reason) use ($check): void {
    try { $call(); } catch (CashierV3CommandException $error) {
        $actual = (string)($error->getDetail()['reason'] ?? '');
        $check('reject ' . $reason . ' (actual ' . $actual . ')', $actual === $reason);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $reason);
};
$scenario = static function (callable $run): void {
    Db::startTrans();
    try { $run(); } finally { Db::rollback(); }
};
// Isolate coupon settlement from sales creation: a server-only frozen plan fixture,
// not a real sale/payment. This exercises the production coupon consumer itself.
$consume = static function (int $id) use ($memberId, $dataScope): array {
    $coupon = (array)Db::name('store_coupon_user')->where('id', $id)->find();
    $planClass = new ReflectionClass(CashierV3SalesOrderPlanV1::class);
    $plan = $planClass->newInstanceWithoutConstructor();
    foreach (['header' => ['order_id' => 'SALE-COUPON-ROLLBACK-TEST', 'member_id' => $memberId],
        'lines' => [['coupon_user_id' => $id, 'coupon_discount_cents' => 1,
            'coupon_name_snapshot' => $coupon['coupon_title'], 'original_amount_cents' => 100000000,
            'discount_amount_cents' => 1, 'order_line_id' => 'LINE-COUPON-ROLLBACK-TEST']]] as $name => $value) {
        $property = $planClass->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($plan, $value);
    }
    return (new CashierV3CheckoutCouponSettlementServices())->consumeInTx($plan,
        ['orderId' => 'SALE-COUPON-ROLLBACK-TEST'], $dataScope, time());
};
$counts = static function (): array {
    $counts = [];
    foreach (['cashier_v3_order_center_void_operation', 'cashier_v3_gift_fact', 'cashier_v3_business_event',
        'cashier_v3_outbox', 'store_coupon_issue_user', 'cashier_v3_payment_fact', 'cashier_v3_performance_fact'] as $table) {
        $counts[$table] = (int)Db::name($table)->count();
    }
    return $counts;
};
$baselineCounts = $counts();

$scenario(static function () use ($invoke, $check, $couponId, $issueId, $item, $authority, $fact, $counts, $baselineCounts, $expectReason, $consume, $operator, $dataScope, $giftNo): void {
    Db::name('store_coupon_issue')->where('id', $issueId)->update(['total_count' => 10, 'remain_count' => 8]);
    $untouched = (array)Db::name('store_coupon_user')->where('id', $couponId)->find();
    unset($untouched['id']);
    $otherId = (int)Db::name('store_coupon_user')->insertGetId($untouched);
    $result = $invoke('direct-coupon-void-finite-test');
    $check('unused coupon revoked', (int)Db::name('store_coupon_user')->where('id', $couponId)->value('status') === 2
        && (int)Db::name('store_coupon_user')->where('id', $couponId)->value('is_fail') === 1);
    $check('same member/template other coupon untouched', (int)Db::name('store_coupon_user')->where('id', $otherId)->value('status') === 0);
    $check('finite quota restored once', (int)Db::name('store_coupon_issue')->where('id', $issueId)->value('remain_count') === 9);
    $check('gift item and authority voided', Db::name('cashier_v3_direct_gift_item')->where('id', $item['id'])->value('status') === 'voided'
        && Db::name('cashier_v3_direct_gift_authority')->where('id', $authority['id'])->value('status') === 'voided');
    $reader = new CashierV3OrderCenterRecordQueryServices();
    $normal = $reader->queryRecords(['recordType' => 'gift', 'keyword' => $giftNo], $operator, $dataScope);
    $all = $reader->queryRecords(['recordType' => 'gift', 'keyword' => $giftNo, 'dataScope' => 'all'], $operator, $dataScope);
    $check('normal list hides void; all list retains one voided record', count($normal['records']) === 0
        && count($all['records']) === 1 && $all['records'][0]['giftStatus'] === '已作废');
    $check('original gift fact immutable', (array)Db::name('cashier_v3_gift_fact')->where('id', $fact['id'])->find() === $fact);
    $reverse = (array)Db::name('cashier_v3_gift_fact')->where('reversal_of', $fact['gift_fact_id'])->find();
    $check('reversal has current command/operator/date', ($reverse['command_idempotency_key'] ?? '') === 'direct-coupon-void-finite-test'
        && (int)$reverse['operator_id'] === 1 && $reverse['business_date'] === date('Y-m-d'));
    $event = (array)Db::name('cashier_v3_business_event')->where('event_no', $reverse['business_event_no'])->find();
    $payload = json_decode((string)$event['payload'], true);
    $check('audit links exact revoked coupon and quota', ($payload['couponUserIds'] ?? []) === [$couponId]
        && (int)($payload['restoredCouponInventoryCount'] ?? -1) === 1 && $event['reversal_of'] === $fact['business_event_no']);
    $afterFirst = $counts();
    $same = $invoke('direct-coupon-void-finite-test');
    $different = $invoke('direct-coupon-void-second-key-test');
    $check('same and different keys replay without new writes', $same['data']['void']['replayed'] && $different['data']['void']['replayed']
        && $result['business_no'] === $different['business_no'] && $counts() === $afterFirst
        && (int)Db::name('store_coupon_issue')->where('id', $issueId)->value('remain_count') === 9);
    $check('issuance history retained; no financial facts added', $afterFirst['store_coupon_issue_user'] === $baselineCounts['store_coupon_issue_user']
        && $afterFirst['cashier_v3_payment_fact'] === $baselineCounts['cashier_v3_payment_fact']
        && $afterFirst['cashier_v3_performance_fact'] === $baselineCounts['cashier_v3_performance_fact']);
    $expectReason(static function () use ($consume, $couponId): void { $consume($couponId); }, 'checkout_coupon_frozen_authority_changed');
});

$scenario(static function () use ($invoke, $check, $issueId): void {
    Db::name('store_coupon_issue')->where('id', $issueId)->update(['total_count' => 0, 'remain_count' => 0, 'status' => 0]);
    $invoke('direct-coupon-void-unlimited-test');
    $check('unlimited/downlisted template void does not inflate quota', (int)Db::name('store_coupon_issue')->where('id', $issueId)->value('remain_count') === 0);
});

$scenario(static function () use ($invoke, $check, $item, $fact, $couponId, $issueId): void {
    $extra = (array)Db::name('store_coupon_user')->where('id', $couponId)->find();
    unset($extra['id']);
    $extraId = (int)Db::name('store_coupon_user')->insertGetId($extra);
    Db::name('cashier_v3_direct_gift_item')->where('id', $item['id'])->update(['quantity' => 2, 'coupon_user_ids_json' => json_encode([$extraId, $couponId])]);
    Db::name('cashier_v3_gift_fact')->where('id', $fact['id'])->update(['quantity' => 2]);
    Db::name('store_coupon_issue')->where('id', $issueId)->update(['total_count' => 10, 'remain_count' => 8]);
    $invoke('direct-coupon-void-batch-test');
    $check('batch revokes all exact coupons and restores exact quantity', (int)Db::name('store_coupon_user')->whereIn('id', [$couponId, $extraId])->where('status', 2)->count() === 2
        && (int)Db::name('store_coupon_issue')->where('id', $issueId)->value('remain_count') === 10);
});

$rejectScenario = static function (string $name, callable $mutate, string $reason) use ($scenario, $invoke, $expectReason, $check, $counts, $item, $couponId, $issueId): void {
    $scenario(static function () use ($name, $mutate, $reason, $invoke, $expectReason, $check, $counts, $item, $couponId, $issueId): void {
        $mutate();
        $before = $counts();
        $couponBefore = (array)Db::name('store_coupon_user')->where('id', $couponId)->find();
        $quotaBefore = Db::name('store_coupon_issue')->where('id', $issueId)->value('remain_count');
        $expectReason(static function () use ($invoke, $name): void { $invoke('direct-coupon-reject-' . $name); }, $reason);
        $check($name . ' no partial revocation', $counts() === $before
            && (array)Db::name('store_coupon_user')->where('id', $couponId)->find() === $couponBefore
            && Db::name('store_coupon_issue')->where('id', $issueId)->value('remain_count') === $quotaBefore
            && Db::name('cashier_v3_direct_gift_item')->where('id', $item['id'])->value('status') === 'issued');
    });
};
$rejectScenario('used', static function () use ($consume, $couponId): void {
    Db::name('store_coupon_user')->where('id', $couponId)->update(['start_time' => time() - 60, 'end_time' => time() + 86400]);
    $consume($couponId);
}, 'gift_coupon_already_changed');
foreach (['use_time' => time(), 'oid' => 123, 'uid' => $memberId + 1, 'cid' => $issueId + 1,
    'type' => 'get', 'is_fail' => 1, 'status' => 2] as $field => $value) {
    $rejectScenario($field, static function () use ($couponId, $field, $value): void {
        Db::name('store_coupon_user')->where('id', $couponId)->update([$field => $value]);
    }, 'gift_coupon_already_changed');
}
$rejectScenario('missing-coupon', static function () use ($couponId): void {
    Db::name('store_coupon_user')->where('id', $couponId)->delete();
}, 'gift_coupon_user_missing');
$rejectScenario('quota-overflow', static function () use ($issueId): void {
    Db::name('store_coupon_issue')->where('id', $issueId)->update(['total_count' => 10, 'remain_count' => 10]);
}, 'gift_coupon_inventory_invalid');
$rejectScenario('duplicate-id', static function () use ($item, $fact, $couponId): void {
    Db::name('cashier_v3_direct_gift_item')->where('id', $item['id'])->update(['quantity' => 2, 'coupon_user_ids_json' => json_encode([$couponId, $couponId])]);
    Db::name('cashier_v3_gift_fact')->where('id', $fact['id'])->update(['quantity' => 2]);
}, 'gift_coupon_identity_duplicate');
$rejectScenario('invalid-json', static function () use ($item): void {
    Db::name('cashier_v3_direct_gift_item')->where('id', $item['id'])->update(['coupon_user_ids_json' => '{}']);
}, 'gift_coupon_projection_invalid');
$rejectScenario('batch-partly-used', static function () use ($item, $fact, $couponId): void {
    $extra = (array)Db::name('store_coupon_user')->where('id', $couponId)->find();
    unset($extra['id']);
    $extra['status'] = 1;
    $extra['use_time'] = time();
    $extraId = (int)Db::name('store_coupon_user')->insertGetId($extra);
    Db::name('cashier_v3_direct_gift_item')->where('id', $item['id'])->update(['quantity' => 2, 'coupon_user_ids_json' => json_encode([$couponId, $extraId])]);
    Db::name('cashier_v3_gift_fact')->where('id', $fact['id'])->update(['quantity' => 2]);
}, 'gift_coupon_already_changed');

// An item-level void cannot close still-issued sibling gifts or leak a replay
// across stores/tenants, even when the caller knows the original command key.
$scenario(static function () use ($invoke, $authority, $item, $check): void {
    $sibling = $item;
    unset($sibling['id']);
    $sibling['item_id'] .= '-SIBLING';
    $sibling['item_no'] = 2;
    $sibling['gift_kind'] = 'product';
    $sibling['coupon_user_ids_json'] = '[]';
    $siblingId = (int)Db::name('cashier_v3_direct_gift_item')->insertGetId($sibling);
    $invoke('direct-coupon-void-sibling-test');
    $check('sibling item and authority stay issued', Db::name('cashier_v3_direct_gift_item')->where('id', $siblingId)->value('status') === 'issued'
        && Db::name('cashier_v3_direct_gift_authority')->where('id', $authority['id'])->value('status') === 'issued');
});
$scenario(static function () use ($invoke, $expectReason, $storeId, $orgId, $tenantId): void {
    $wrongStore = new CashierV3OperatorScope($storeId + 1, 1, $orgId, $tenantId);
    $otherTenant = new CashierV3DataScopeContext(1, 1, $storeId, 'test-other-tenant', $orgId, [$storeId],
        CashierV3DataScopeContext::MODE_STORES, [], false, '', 'direct-coupon-scope-test', [], []);
    $expectReason(static function () use ($invoke, $wrongStore): void {
        $invoke('direct-coupon-wrong-store-test', ['operator_scope' => $wrongStore]);
    }, 'gift_authority_changed');
    $expectReason(static function () use ($invoke, $otherTenant): void {
        $invoke('direct-coupon-wrong-tenant-test', ['data_scope' => $otherTenant]);
    }, 'gift_not_active');
    $invoke('direct-coupon-scope-success-test');
    $expectReason(static function () use ($invoke, $wrongStore): void {
        $invoke('direct-coupon-scope-success-test', ['operator_scope' => $wrongStore]);
    }, 'order_center_void_replay_mismatch');
    $expectReason(static function () use ($invoke, $wrongStore): void {
        $invoke('direct-coupon-other-key-wrong-store', ['operator_scope' => $wrongStore]);
    }, 'order_center_void_kind_mismatch');
});

// Independent DB connection proves the actual row lock excludes concurrent
// redemption/transfer. A one-second lock timeout prevents any worker writes.
$scenario(static function () use ($invoke, $config, $couponId, $check): void {
    $invoke('direct-coupon-void-lock-test');
    $dsn = 'mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'];
    $worker = new PDO($dsn, (string)$config['username'], (string)$config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $worker->exec('SET SESSION innodb_lock_wait_timeout=1');
    $worker->beginTransaction();
    $blocked = false;
    try {
        $statement = $worker->prepare('UPDATE eb_store_coupon_user SET status=1,use_time=? WHERE id=? AND status=0 AND is_fail=0 AND use_time=0');
        $statement->execute([time(), $couponId]);
    } catch (PDOException $error) {
        $blocked = (int)($error->errorInfo[1] ?? 0) === 1205;
    } finally { $worker->rollBack(); }
    $check('concurrent redemption blocked by void transaction row lock', $blocked);
});

$scenario(static function () use ($invoke, $expectReason, $fact, $check, $couponId): void {
    // Force an event-persistence failure after coupon/quota updates; the command
    // owner must roll back the entire transaction, not preserve a partial void.
    Db::name('cashier_v3_gift_fact')->where('id', $fact['id'])->update(['business_event_no' => 'invalid-original-event']);
    $expectReason(static function () use ($invoke): void { $invoke('direct-coupon-event-failure-test'); }, 'reversal_identity_invalid');
    $check('late event failure really occurs after coupon update, before outer rollback',
        (int)Db::name('store_coupon_user')->where('id', $couponId)->value('status') === 2);
});
$check('all writes rolled back and original acceptance sample preserved',
    (array)Db::name('store_coupon_user')->where('id', $couponId)->find() === $originalCoupon
    && (array)Db::name('store_coupon_issue')->where('id', $issueId)->find() === $originalIssue
    && (array)Db::name('cashier_v3_direct_gift_authority')->where('id', $authority['id'])->find() === $authority
    && (array)Db::name('cashier_v3_direct_gift_item')->where('id', $item['id'])->find() === $item
    && (array)Db::name('cashier_v3_gift_fact')->where('id', $fact['id'])->find() === $fact
    && $counts() === $baselineCounts);
echo 'DIRECT_GIFT_COUPON_VOID_MYSQL passed=' . $passed . ' failed=0' . PHP_EOL;

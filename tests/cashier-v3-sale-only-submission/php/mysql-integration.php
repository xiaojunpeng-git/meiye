<?php
declare(strict_types=1);

require '/tests/cashier-v3/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require '/tests/cashier-v3/lib/_lib.php';
require '/tests/cashier-v3/lib/TestGraphFactory.php';
require '/tests/cashier-v3/lib/MemberIntegrationFixture.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutResultReadRepository;
use C1A\CashierV3\Test\MemberIntegrationFixture;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');
MemberIntegrationFixture::bindTestAdapters();
MemberIntegrationFixture::ensureLegacySchema();

$passed = 0;
$failed = 0;

function saleOnlyOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
}

function saleOnlyUuidKey(string $prefix): string
{
    return $prefix . '-' . MemberIntegrationFixture::uuid();
}

function saleOnlyCommandBody(
    array $session,
    string $action,
    array $payload,
    array $contexts,
    string $idempotencyKey
): array {
    return array_merge($payload, [
        'action' => $action,
        'clientSessionId' => $session['client_session_id'],
        'stateContextId' => $session['state_context_id'],
        'correlationId' => saleOnlyUuidKey('CORR'),
        'command' => [
            'action' => $action,
            'idempotencyKey' => $idempotencyKey,
            'contexts' => array_values($contexts),
        ],
    ]);
}

function saleOnlyPublicContexts(string $workspaceId, string $requestId = ''): array
{
    $workspaceVersion = (int)Db::name('cashier_v3_resource_version')
        ->where('resource_kind', 'cashier_workspace')
        ->where('resource_id', $workspaceId)
        ->value('current_version');
    if ($workspaceVersion <= 0) {
        throw new RuntimeException('SALE_ONLY_WORKSPACE_VERSION_MISSING');
    }
    $contexts = [[
        'kind' => 'cashier_workspace',
        'id' => $workspaceId,
        'expectedVersion' => $workspaceVersion,
    ]];
    if ($requestId !== '') {
        $requestVersion = (int)Db::name('cashier_v3_checkout_request')
            ->where('request_id', $requestId)
            ->value('request_version');
        if ($requestVersion <= 0) {
            throw new RuntimeException('SALE_ONLY_REQUEST_VERSION_MISSING');
        }
        $contexts[] = [
            'kind' => 'checkout_request',
            'id' => $requestId,
            'expectedVersion' => $requestVersion,
        ];
    }
    return $contexts;
}

function saleOnlyCheckoutProjection($dispatcher, array $session, string $workspaceId): array
{
    $service = new CashierV3CheckoutProjectionServices(
        null,
        new ThinkPhpCashierV3CheckoutResultReadRepository()
    );
    $projection = $service->readCurrent(
        $workspaceId,
        (string)$session['state_context_id'],
        MemberIntegrationFixture::operatorScope(1),
        MemberIntegrationFixture::dataScope($dispatcher, 1)
    );
    if (!is_array($projection)) {
        throw new RuntimeException('SALE_ONLY_CHECKOUT_PROJECTION_MISSING');
    }
    return $projection;
}

function saleOnlyCommonPayload(array $checkout): array
{
    return [
        'checkoutRequestId' => (string)$checkout['checkoutRequestId'],
        'checkoutRequestVersion' => (int)$checkout['checkoutRequestVersion'],
        'preparationRequestId' => (string)$checkout['preparationRequestId'],
        'preparationToken' => (string)$checkout['preparationToken'],
    ];
}

function saleOnlyResetFixture(): void
{
    MemberIntegrationFixture::resetAndSeed();
    foreach ([
        'cashier_v3_checkout_resource_plan_row',
        'cashier_v3_checkout_resource_plan',
        'cashier_v3_checkout_source_reference',
        'cashier_v3_checkout_payment_draft',
        'cashier_v3_checkout_line_draft',
        'cashier_v3_checkout_request',
        'cashier_v3_sales_order_line',
        'cashier_v3_sales_order',
        'cashier_v3_payment_collection',
        'cashier_v3_payment_collection_batch',
        'cashier_v3_sale_fact',
        'cashier_v3_payment_fact',
        'cashier_v3_balance_fact',
        'cashier_v3_performance_fact',
        'cashier_v3_outbox_attempt',
        'cashier_v3_outbox',
        'cashier_v3_consumer_once',
        'cashier_v3_business_event',
        'cashier_v3_command_receipt',
        'cashier_v3_resource_version',
        'cashier_v3_state_context',
        'cashier_v3_workspace_line',
        'cashier_v3_workspace_draft',
        'cashier_v3_entitlement_resource_version',
    ] as $table) {
        Db::execute('DELETE FROM `eb_' . $table . '`');
    }
    Db::execute('DELETE FROM `eb_store_card_related`');
    Db::execute('DELETE FROM `eb_store_product_attr_value`');
    Db::execute('DELETE FROM `eb_store_product`');
    Db::execute('DELETE FROM `eb_store_product_category`');

    Db::name('store_product_category')->insert([
        'id' => 51,
        'cate_name' => '日常商品',
        'type' => 1,
        'relation_id' => MemberIntegrationFixture::STORE_ID,
        'is_show' => 1,
    ]);
    Db::name('store_product')->insert([
        'id' => 501,
        'pid' => 0,
        'type' => 1,
        'relation_id' => MemberIntegrationFixture::STORE_ID,
        'product_type' => 0,
        'store_name' => '测试普通产品',
        'cate_id' => '51',
        'keyword' => '',
        'unit_name' => '件',
        'sort' => 1,
        'is_show' => 1,
        'is_del' => 0,
        'is_verify' => 1,
        'is_inventory' => 0,
        'allow_negative_stock' => 1,
        'card_num' => 0,
        'card_num_type' => 0,
    ]);
    Db::name('store_product_attr_value')->insert([
        'id' => 1501,
        'product_id' => 501,
        'product_type' => 0,
        'unique' => 'SKU-1501',
        'suk' => '默认',
        'price' => '100.00',
        'ot_price' => '100.00',
        'stock' => '100.0000',
        'code' => 'PRODUCT-501',
        'bar_code' => '',
        'is_show' => 1,
        'type' => 0,
        'write_times' => 0,
        'write_valid' => 1,
        'write_days' => 0,
        'write_start' => 0,
        'write_end' => 0,
    ]);
}

function saleOnlyPrepareScenario($dispatcher): array
{
    saleOnlyResetFixture();
    $chooseRequest = MemberIntegrationFixture::commandRequest(
        $dispatcher,
        'choose-catalog-item',
        ['itemId' => 1501],
        1,
        saleOnlyUuidKey('CMD')
    );
    $session = $chooseRequest['session'];
    $workspaceId = $chooseRequest['workspace_id'];
    $chosen = $dispatcher->dispatch($chooseRequest['body'], $session);
    if (($chosen['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('SALE_ONLY_CHOOSE_FAILED');
    }

    $initialPrepareKey = saleOnlyUuidKey('CHECKOUT_PREPARE');
    $prepared = $dispatcher->dispatch(saleOnlyCommandBody(
        $session,
        'prepare-checkout',
        ['preparationRequestId' => $initialPrepareKey],
        saleOnlyPublicContexts($workspaceId),
        $initialPrepareKey
    ), $session);
    if (($prepared['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('SALE_ONLY_PREPARE_FAILED');
    }

    $checkout = saleOnlyCheckoutProjection($dispatcher, $session, $workspaceId);
    if ((string)$checkout['preparationRequestId'] !== $initialPrepareKey) {
        throw new RuntimeException('SALE_ONLY_PREPARATION_ID_DRIFT');
    }
    $paymentPayload = saleOnlyCommonPayload($checkout);
    $paymentPayload['paymentMethodId'] = 'wechat';
    $payment = $dispatcher->dispatch(saleOnlyCommandBody(
        $session,
        'add-payment-method',
        $paymentPayload,
        saleOnlyPublicContexts($workspaceId, (string)$checkout['checkoutRequestId']),
        saleOnlyUuidKey('CMD')
    ), $session);
    if (($payment['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('SALE_ONLY_PAYMENT_DRAFT_FAILED');
    }

    $checkout = saleOnlyCheckoutProjection($dispatcher, $session, $workspaceId);
    $submitKey = saleOnlyUuidKey('CHECKOUT');
    $submitPrepareKey = preg_replace('/^CHECKOUT-/', 'CHECKOUT_PREPARE-', $submitKey);
    $submissionPreparation = $dispatcher->dispatch(saleOnlyCommandBody(
        $session,
        'prepare-checkout-submission',
        saleOnlyCommonPayload($checkout),
        saleOnlyPublicContexts($workspaceId, (string)$checkout['checkoutRequestId']),
        (string)$submitPrepareKey
    ), $session);
    if (($submissionPreparation['result']['status'] ?? '') !== CashierV3ResultCode::STATUS_SUCCESS) {
        throw new RuntimeException('SALE_ONLY_SUBMISSION_PREPARATION_FAILED');
    }

    $checkout = saleOnlyCheckoutProjection($dispatcher, $session, $workspaceId);
    $requestId = (string)$checkout['checkoutRequestId'];
    $requestVersion = (int)$checkout['checkoutRequestVersion'];
    if ((string)$checkout['requestStatus'] !== 'ready_for_submit') {
        throw new RuntimeException('SALE_ONLY_REQUEST_NOT_READY');
    }
    $finalBody = saleOnlyCommandBody(
        $session,
        'submit-checkout',
        saleOnlyCommonPayload($checkout),
        saleOnlyPublicContexts($workspaceId, $requestId),
        $submitKey
    );
    return [
        'session' => $session,
        'workspaceId' => $workspaceId,
        'requestId' => $requestId,
        'requestVersion' => $requestVersion,
        'submitKey' => $submitKey,
        'finalBody' => $finalBody,
    ];
}

function saleOnlyCounts(): array
{
    $out = [];
    foreach ([
        'cashier_v3_sales_order',
        'cashier_v3_sales_order_line',
        'cashier_v3_payment_collection_batch',
        'cashier_v3_payment_collection',
        'cashier_v3_business_event',
        'cashier_v3_outbox',
        'cashier_v3_sale_fact',
        'cashier_v3_payment_fact',
        'cashier_v3_balance_fact',
        'cashier_v3_performance_fact',
    ] as $table) {
        $out[$table] = (int)Db::name($table)->count();
    }
    return $out;
}

function saleOnlyFailure(callable $operation): array
{
    try {
        $operation();
    } catch (CashierV3CommandException $exception) {
        return [
            'class' => get_class($exception),
            'code' => $exception->getResultCode(),
            'reason' => (string)($exception->getDetail()['reason'] ?? ''),
            'message' => $exception->getMessage(),
        ];
    } catch (Throwable $throwable) {
        return [
            'class' => get_class($throwable),
            'code' => '',
            'reason' => '',
            'message' => $throwable->getMessage(),
        ];
    }
    return [];
}

try {
    $dispatcher = MemberIntegrationFixture::dispatcher();

    $success = saleOnlyPrepareScenario($dispatcher);
    $submitted = $dispatcher->dispatch($success['finalBody'], $success['session']);
    $submission = (array)($submitted['data']['checkoutSubmission'] ?? []);
    saleOnlyOk('SO-SUB-MYSQL-01 real dispatcher and Gateway complete one sale-only checkout',
        ($submitted['result']['status'] ?? '') === CashierV3ResultCode::STATUS_SUCCESS
        && empty($submitted['replay'])
        && ($submission['requestStatus'] ?? '') === 'succeeded');

    $requestId = $success['requestId'];
    $requestVersion = $success['requestVersion'];
    $submitKey = $success['submitKey'];
    $order = (array)Db::name('cashier_v3_sales_order')
        ->where('checkout_request_id', $requestId)->find();
    $orderLine = (array)Db::name('cashier_v3_sales_order_line')
        ->where('order_id', (string)($order['order_id'] ?? ''))->find();
    saleOnlyOk('SO-SUB-MYSQL-02 settled order and line freeze the exact sale authority',
        (int)Db::name('cashier_v3_sales_order')->count() === 1
        && (int)Db::name('cashier_v3_sales_order_line')->count() === 1
        && ($order['composition'] ?? '') === 'sale_only'
        && ($order['order_status'] ?? '') === 'settled'
        && (int)($order['order_version'] ?? 0) === 1
        && ($order['order_direction'] ?? '') === 'forward'
        && (int)($order['checkout_request_version'] ?? 0) === $requestVersion
        && (int)($order['line_count'] ?? 0) === 1
        && (int)($order['total_quantity'] ?? 0) === 1
        && (int)($order['original_amount_cents'] ?? 0) === 10000
        && (int)($order['discount_amount_cents'] ?? -1) === 0
        && (int)($order['sale_amount_cents'] ?? 0) === 10000
        && ($order['command_idempotency_key'] ?? '') === $submitKey
        && ($orderLine['item_type'] ?? '') === 'product'
        && ($orderLine['line_status'] ?? '') === 'settled'
        && (int)($orderLine['line_version'] ?? 0) === 1
        && ($orderLine['line_direction'] ?? '') === 'forward'
        && (int)($orderLine['quantity'] ?? 0) === 1
        && (int)($orderLine['sale_amount_cents'] ?? 0) === 10000);

    $batch = (array)Db::name('cashier_v3_payment_collection_batch')
        ->where('checkout_request_id', $requestId)->find();
    $collection = (array)Db::name('cashier_v3_payment_collection')
        ->where('checkout_request_id', $requestId)->find();
    saleOnlyOk('SO-SUB-MYSQL-03 used bookkeeping method produces one settled wechat collection',
        (int)Db::name('cashier_v3_payment_collection_batch')->count() === 1
        && (int)Db::name('cashier_v3_payment_collection')->count() === 1
        && ($batch['batch_status'] ?? '') === 'settled'
        && (int)($batch['batch_version'] ?? 0) === 1
        && ($batch['batch_direction'] ?? '') === 'forward'
        && (int)($batch['checkout_request_version'] ?? 0) === $requestVersion
        && (int)($batch['collection_count'] ?? 0) === 1
        && (int)($batch['collected_amount_cents'] ?? 0) === 10000
        && (int)($batch['cash_performance_amount_cents'] ?? 0) === 10000
        && (int)($batch['receivable_amount_cents'] ?? 0) === 10000
        && ($collection['payment_method'] ?? '') === 'wechat'
        && ($collection['collection_status'] ?? '') === 'settled'
        && (int)($collection['collection_version'] ?? 0) === 1
        && ($collection['collection_direction'] ?? '') === 'forward'
        && (int)($collection['amount_cents'] ?? 0) === 10000
        && (int)($collection['cash_performance_amount_cents'] ?? 0) === 10000,
        json_encode(['batch' => $batch, 'collection' => $collection], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $event = (array)Db::name('cashier_v3_business_event')
        ->where('source_type', 'submit-checkout')
        ->where('source_id', $requestId)
        ->find();
    saleOnlyOk('SO-SUB-MYSQL-04 one checkout.completed event is committed without Outbox rows',
        (int)Db::name('cashier_v3_business_event')->count() === 1
        && ($event['event_type'] ?? '') === 'checkout.completed'
        && (int)($event['event_version'] ?? 0) === 1
        && ($event['aggregate_type'] ?? '') === 'sales_order'
        && ($event['aggregate_id'] ?? '') === ($order['order_id'] ?? '')
        && (int)($event['aggregate_version'] ?? 0) === 1
        && ($event['source_type'] ?? '') === 'submit-checkout'
        && ($event['source_id'] ?? '') === $requestId
        && ($event['command_idempotency_key'] ?? '') === $submitKey
        && (int)Db::name('cashier_v3_outbox')->count() === 0,
        json_encode(['event' => $event, 'outboxCount' => (int)Db::name('cashier_v3_outbox')->count()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $saleFact = (array)Db::name('cashier_v3_sale_fact')
        ->where('checkout_request_id', $requestId)->find();
    $paymentFact = (array)Db::name('cashier_v3_payment_fact')
        ->where('checkout_request_id', $requestId)->find();
    $performanceFact = (array)Db::name('cashier_v3_performance_fact')
        ->where('checkout_request_id', $requestId)->find();
    saleOnlyOk('SO-SUB-MYSQL-05 sale payment and actual-performance facts share one authority',
        (int)Db::name('cashier_v3_sale_fact')->count() === 1
        && ($saleFact['fact_type'] ?? '') === 'sale_completed'
        && ($saleFact['fact_direction'] ?? '') === 'forward'
        && ($saleFact['status'] ?? '') === 'effective'
        && ($saleFact['source_type'] ?? '') === 'product'
        && (int)($saleFact['quantity'] ?? 0) === 1
        && (int)($saleFact['original_amount_cents'] ?? 0) === 10000
        && (int)($saleFact['discount_amount_cents'] ?? -1) === 0
        && (int)($saleFact['sale_amount_cents'] ?? 0) === 10000
        && (int)Db::name('cashier_v3_payment_fact')->count() === 1
        && ($paymentFact['fact_type'] ?? '') === 'payment_collected'
        && ($paymentFact['fact_direction'] ?? '') === 'forward'
        && ($paymentFact['status'] ?? '') === 'effective'
        && ($paymentFact['payment_method'] ?? '') === 'wechat'
        && (int)($paymentFact['amount_cents'] ?? 0) === 10000
        && (int)Db::name('cashier_v3_balance_fact')->count() === 0
        && (int)Db::name('cashier_v3_performance_fact')->count() === 1
        && ($performanceFact['fact_type'] ?? '') === 'actual_performance_recorded'
        && ($performanceFact['fact_direction'] ?? '') === 'forward'
        && ($performanceFact['status'] ?? '') === 'effective'
        && ($performanceFact['performance_type'] ?? '') === 'actual_performance_recorded'
        && (int)($performanceFact['employee_id'] ?? -1) === 0
        && (int)($performanceFact['allocation_base_amount_cents'] ?? 0) === 10000
        && (int)($performanceFact['amount_cents'] ?? 0) === 10000,
        json_encode([
            'saleFact' => $saleFact,
            'paymentFact' => $paymentFact,
            'performanceFact' => $performanceFact,
            'counts' => saleOnlyCounts(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $request = (array)Db::name('cashier_v3_checkout_request')
        ->where('request_id', $requestId)->find();
    $draft = (array)Db::name('cashier_v3_workspace_draft')
        ->where('workspace_id', $success['workspaceId'])->find();
    $lineDraft = (array)Db::name('cashier_v3_checkout_line_draft')
        ->where('request_id', $requestId)->find();
    $paymentDraft = (array)Db::name('cashier_v3_checkout_payment_draft')
        ->where('request_id', $requestId)->find();
    $resourcePlan = (array)Db::name('cashier_v3_checkout_resource_plan')
        ->where('request_id', $requestId)->find();
    $receipt = (array)Db::name('cashier_v3_command_receipt')
        ->where('idempotency_key', $submitKey)->find();
    saleOnlyOk('SO-SUB-MYSQL-06 request drafts plan and cart reach one committed terminal state',
        ($request['request_status'] ?? '') === 'succeeded'
        && (int)($request['request_version'] ?? 0) === $requestVersion + 1
        && ($request['last_idempotency_key'] ?? '') === $submitKey
        && ($request['last_operation'] ?? '') === 'submit'
        && ($lineDraft['draft_status'] ?? '') === 'committed'
        && (int)($lineDraft['draft_version'] ?? 0) === $requestVersion
        && ($paymentDraft['draft_status'] ?? '') === 'committed'
        && (int)($paymentDraft['draft_version'] ?? 0) === $requestVersion
        && ($paymentDraft['payment_method'] ?? '') === 'wechat'
        && ($resourcePlan['plan_status'] ?? '') === 'consumed'
        && (int)($resourcePlan['bound_request_version'] ?? 0) === $requestVersion
        && (int)Db::name('cashier_v3_workspace_line')
            ->where('workspace_id', $success['workspaceId'])->count() === 0
        && (int)($draft['member_id'] ?? -1) === 0
        && ($draft['customer_mode'] ?? '') === 'guest'
        && ($draft['draft_status'] ?? '') === 'editing'
        && ($receipt['action'] ?? '') === 'submit-checkout'
        && (int)($receipt['status'] ?? 0) === 1
        && ($receipt['business_no'] ?? '') === ($order['order_no'] ?? ''));

    $queryBody = [
        'action' => 'query-checkout-result',
        'originalIdempotencyKey' => $submitKey,
        'clientSessionId' => $success['session']['client_session_id'],
        'stateContextId' => $success['session']['state_context_id'],
        'correlationId' => saleOnlyUuidKey('CORR'),
    ];
    $queried = $dispatcher->dispatch($queryBody, $success['session']);
    $queryResult = (array)($queried['data']['checkoutResult'] ?? []);
    $queryIsPureResult = !isset($queried['state'])
        && !isset($queried['stateRevision'])
        && !isset($queried['versions']);
    $rootCheckout = saleOnlyCheckoutProjection(
        $dispatcher,
        $success['session'],
        $success['workspaceId']
    );
    saleOnlyOk('SO-SUB-MYSQL-07 pure result query and separately rebuilt root project the same succeeded authority',
        ($queryResult['status'] ?? '') === CashierV3ResultCode::STATUS_SUCCESS
        && ($queryResult['phase'] ?? '') === 'succeeded'
        && ($queryResult['checkoutRequest']['requestId'] ?? '') === $requestId
        && ($queryResult['salesOrder']['orderId'] ?? '') === ($order['order_id'] ?? '')
        && $queryIsPureResult
        && !empty($queried['requiresRefresh'])
        && ($rootCheckout['status'] ?? '') === 'succeeded'
        && ($rootCheckout['checkoutRequestId'] ?? '') === $requestId
        && ($rootCheckout['salesOrderId'] ?? '') === ($order['order_id'] ?? '')
        && ($rootCheckout['resumeOnLoad'] ?? true) === false
        && ($rootCheckout['canRetry'] ?? true) === false
        && ($rootCheckout['commandContexts'] ?? null) === [],
        json_encode([
            'queryResult' => $queryResult,
            'queryHasRootFields' => !$queryIsPureResult,
            'rootCheckout' => $rootCheckout,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $beforeReplay = [
        'counts' => saleOnlyCounts(),
        'requestVersion' => (int)$request['request_version'],
        'planStatus' => (string)Db::name('cashier_v3_checkout_resource_plan')->value('plan_status'),
        'workspaceVersion' => (int)Db::name('cashier_v3_resource_version')
            ->where('resource_kind', 'cashier_workspace')
            ->where('resource_id', $success['workspaceId'])->value('current_version'),
        'receiptFinishTime' => (int)($receipt['finish_time'] ?? 0),
    ];
    $replayed = $dispatcher->dispatch($success['finalBody'], $success['session']);
    $afterReplayRequestVersion = (int)Db::name('cashier_v3_checkout_request')
        ->where('request_id', $requestId)->value('request_version');
    saleOnlyOk('SO-SUB-MYSQL-08 exact same final body replays without additional business writes',
        !empty($replayed['replay'])
        && $beforeReplay['counts'] === saleOnlyCounts()
        && $beforeReplay['requestVersion'] === $afterReplayRequestVersion
        && $beforeReplay['planStatus'] === (string)Db::name('cashier_v3_checkout_resource_plan')->value('plan_status')
        && $beforeReplay['workspaceVersion'] === (int)Db::name('cashier_v3_resource_version')
            ->where('resource_kind', 'cashier_workspace')
            ->where('resource_id', $success['workspaceId'])->value('current_version')
        && $beforeReplay['receiptFinishTime'] === (int)Db::name('cashier_v3_command_receipt')
            ->where('idempotency_key', $submitKey)->value('finish_time'));

    $rollback = saleOnlyPrepareScenario($dispatcher);
    $rollbackRequestBefore = (array)Db::name('cashier_v3_checkout_request')
        ->where('request_id', $rollback['requestId'])->find();
    $rollbackCountsBefore = saleOnlyCounts();
    $rollbackWorkspaceVersion = (int)Db::name('cashier_v3_resource_version')
        ->where('resource_kind', 'cashier_workspace')
        ->where('resource_id', $rollback['workspaceId'])->value('current_version');
    Db::execute("CREATE TRIGGER `trg_sale_only_late_failure` BEFORE DELETE ON `eb_cashier_v3_workspace_line` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SALE_ONLY_LATE_ROLLBACK'");
    try {
        $lateFailure = saleOnlyFailure(static function () use ($dispatcher, $rollback): void {
            $dispatcher->dispatch($rollback['finalBody'], $rollback['session']);
        });
    } finally {
        Db::execute('DROP TRIGGER IF EXISTS `trg_sale_only_late_failure`');
    }
    $rollbackRequestAfter = (array)Db::name('cashier_v3_checkout_request')
        ->where('request_id', $rollback['requestId'])->find();
    saleOnlyOk('SO-SUB-MYSQL-09 late cart-cleanup failure rolls every attempted final write back',
        $lateFailure !== []
        && strpos((string)($lateFailure['message'] ?? ''), 'SALE_ONLY_LATE_ROLLBACK') !== false
        && $rollbackCountsBefore === saleOnlyCounts()
        && ($rollbackRequestAfter['request_status'] ?? '') === 'ready_for_submit'
        && (int)($rollbackRequestAfter['request_version'] ?? 0) === $rollback['requestVersion']
        && ($rollbackRequestAfter['last_operation'] ?? '') === ($rollbackRequestBefore['last_operation'] ?? '')
        && ($rollbackRequestAfter['last_idempotency_key'] ?? '')
            === ($rollbackRequestBefore['last_idempotency_key'] ?? '')
        && (int)Db::name('cashier_v3_checkout_line_draft')
            ->where('request_id', $rollback['requestId'])
            ->where('draft_version', $rollback['requestVersion'])
            ->where('draft_status', 'draft')->count() === 1
        && (int)Db::name('cashier_v3_checkout_payment_draft')
            ->where('request_id', $rollback['requestId'])
            ->where('draft_version', $rollback['requestVersion'])
            ->where('draft_status', 'draft')->count() === 1
        && (int)Db::name('cashier_v3_checkout_resource_plan')
            ->where('request_id', $rollback['requestId'])
            ->where('bound_request_version', $rollback['requestVersion'])
            ->where('plan_status', 'active')->count() === 1
        && (int)Db::name('cashier_v3_workspace_line')
            ->where('workspace_id', $rollback['workspaceId'])->count() === 1
        && (int)Db::name('cashier_v3_command_receipt')
            ->where('idempotency_key', $rollback['submitKey'])->count() === 0
        && $rollbackWorkspaceVersion === (int)Db::name('cashier_v3_resource_version')
            ->where('resource_kind', 'cashier_workspace')
            ->where('resource_id', $rollback['workspaceId'])->value('current_version'),
        json_encode($lateFailure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
} catch (Throwable $throwable) {
    $unexpectedDetail = $throwable instanceof CashierV3CommandException
        ? ' code=' . $throwable->getResultCode()
            . ' detail=' . json_encode($throwable->getDetail(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '';
    saleOnlyOk(
        'SO-SUB-MYSQL-UNEXPECTED focused integration completed without an unexpected throwable',
        false,
        get_class($throwable) . ': ' . $throwable->getMessage() . $unexpectedDetail
    );
}

echo 'SALE_ONLY_SUBMISSION_MYSQL passed=' . $passed . ' failed=' . $failed . "\n";
exit($failed === 0 ? 0 : 1);

<?php

require '/workspace/tests/cashier-v3/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require '/workspace/tests/cashier-v3/lib/_lib.php';

$backendRoot = getenv('ECP_BACKEND_ROOT') ?: '/workspace/后端代码';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3TransactionGuard.php';
require_once $backendRoot . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionContractException.php';
require_once $backendRoot . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionKernel.php';
require_once $backendRoot . '/app/services/cashier/v3/checkout/persistence/CashierV3EntitlementCompletionPersistenceException.php';
require_once $backendRoot . '/app/services/cashier/v3/checkout/persistence/CashierV3EntitlementCompletionPlanV1.php';
require_once $backendRoot . '/app/services/cashier/v3/checkout/persistence/CashierV3EntitlementCompletionOccupationWriter.php';
require_once $backendRoot . '/app/services/cashier/v3/checkout/persistence/ThinkPhpCashierV3EntitlementCompletionWriter.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionPersistenceException;
use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionPlanV1;
use app\services\cashier\v3\checkout\persistence\ThinkPhpCashierV3EntitlementCompletionWriter;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');

function ecpMysqlStaffSnapshots(): array
{
    return [[
        'staffId' => 11, 'employeeId' => 1011, 'staffName' => '手艺人甲',
        'staffVersion' => 111, 'storeId' => 7,
        'employeeTypeCodeSnapshot' => 'internal', 'employeeTypeAuthorityVersion' => 21,
    ], [
        'staffId' => 12, 'employeeId' => 1012, 'staffName' => '外包手艺人乙',
        'staffVersion' => 112, 'storeId' => 7,
        'employeeTypeCodeSnapshot' => 'outsourced', 'employeeTypeAuthorityVersion' => 22,
    ]];
}

function ecpMysqlAllocation(int $staffId, int $sequence, int $amount, int $weight): array
{
    return [
        'staffId' => $staffId,
        'isPrimary' => $sequence === 1,
        'sequence' => $sequence,
        'amountCents' => $amount,
        'staffVersion' => 100 + $staffId,
        'staffName' => $staffId === 11 ? '手艺人甲' : '外包手艺人乙',
        'storeId' => 7,
        'laborWeight' => $weight,
    ];
}

function ecpMysqlLine(
    string $lineId,
    int $holderId,
    int $orderId,
    int $detailId,
    int $projectId,
    int $quantity,
    int $actual,
    int $labor,
    string $serviceObject,
    bool $experience,
    array $allocations
): array {
    return [
        'lineId' => $lineId,
        'sortNo' => substr($lineId, -1) === '1' ? 10 : 20,
        'source' => [
            'entitlementInstanceType' => 'card_holder',
            'entitlementInstanceId' => $holderId,
            'sourceKind' => 'count_card',
            'isGift' => false,
            'giftSourceType' => 'none',
            'giftId' => 0,
            'giftVersion' => 0,
            'holderId' => $holderId,
            'originOrderId' => $orderId,
            'sourceNameSnapshot' => '护理次卡-' . $holderId,
            'sourceCodeSnapshot' => 'CARD-' . $holderId,
            'sourceDetailId' => $detailId,
            'projectId' => $projectId,
            'projectNameSnapshot' => '深层护理-' . $projectId,
            'projectCategoryIdSnapshot' => 51,
            'projectCategoryNameSnapshot' => '面部护理',
            'sourceVersion' => 81,
            'detailVersion' => 131,
            'purchaseAmountCents' => 10000,
            'totalPurchaseTimes' => 5,
            'consumedTimesAtLock' => 0,
            'amountCalculationVersion' => 'cumulative-half-up-cent-v2',
        ],
        'quantity' => $quantity,
        'actualEntitlementAmountCents' => $actual,
        'performanceRuleSnapshot' => [
            'ruleVersion' => 301,
            'consumptionMode' => 'actual_entitlement_amount',
            'consumptionConfiguredUnitAmountCents' => 0,
            'laborMode' => 'project_configured_amount',
            'laborConfiguredUnitAmountCents' => 1000,
        ],
        'consumptionPerformance' => [
            'mode' => 'actual_entitlement_amount', 'amountCents' => $actual,
        ],
        'laborPerformance' => [
            'mode' => 'project_configured_amount',
            'amountCents' => $labor,
            'allocations' => $allocations,
        ],
        'serviceSnapshot' => [
            'serviceObject' => $serviceObject,
            'isExperience' => $experience,
            'craftsmen' => $allocations,
            'primaryCraftsmanId' => $allocations[0]['staffId'],
            'businessDate' => '2026-07-29',
            'businessTimezone' => 'Asia/Shanghai',
            'occurredAt' => 1785283200,
            'settledAt' => 1785283260,
            'recordedAt' => 1785283261,
            'sourceType' => 'direct',
            'serviceOrderId' => 0,
            'reservationId' => 0,
            'occupationContributors' => [],
        ],
    ];
}

function ecpMysqlPlan(
    string $requestId,
    string $idempotencyKey,
    int $holderId,
    int $orderId,
    int $detailId,
    int $projectId,
    bool $secondExperience = true
): CashierV3EntitlementCompletionPlanV1 {
    $lineOne = ecpMysqlLine(
        $requestId . '-line-1', $holderId, $orderId, $detailId, $projectId,
        1, 3333, 1000, 'self', false,
        [ecpMysqlAllocation(11, 1, 334, 1), ecpMysqlAllocation(12, 2, 666, 2)]
    );
    $lineTwo = ecpMysqlLine(
        $requestId . '-line-2', $holderId, $orderId, $detailId, $projectId,
        2, 6667, 2000, 'friend', $secondExperience,
        [ecpMysqlAllocation(12, 1, 2000, 2)]
    );
    $kernel = [
        'contractVersion' => CashierV3EntitlementCompletionKernel::CONTRACT_VERSION,
        'action' => CashierV3EntitlementCompletionKernel::ACTION,
        'composition' => CashierV3EntitlementCompletionKernel::COMPOSITION_ENTITLEMENT_ONLY,
        'persistenceStatus' => 'not_persisted',
        'requiresGatewayTransaction' => true,
        'workspaceId' => 'workspace-' . $requestId,
        'stateContextId' => 'state-' . $requestId,
        'memberId' => 1001,
        'storeId' => 7,
        'operatorId' => 21,
        'businessDate' => '2026-07-29',
        'businessTimezone' => 'Asia/Shanghai',
        'occurredAt' => 1785283200,
        'settledAt' => 1785283260,
        'recordedAt' => 1785283261,
        'dimensionSnapshot' => [
            'tenantId' => 'tenant-1', 'organizationId' => 3,
            'organizationName' => '华东区域', 'organizationPath' => '/1/3/',
            'storeId' => 7, 'storeName' => '旗舰店', 'memberId' => 1001,
            'memberName' => '会员甲', 'operatorId' => 21, 'operatorName' => '收银员甲',
        ],
        'source' => ['type' => 'direct', 'serviceOrderId' => 0, 'reservationId' => 0],
        'entitlementDeductions' => [[
            'sourceKey' => 'card_holder:' . $holderId . ':' . $detailId,
            'entitlementInstanceType' => 'card_holder',
            'entitlementInstanceId' => $holderId,
            'sourceKind' => 'count_card',
            'isGift' => false,
            'giftSourceType' => 'none',
            'giftId' => 0,
            'giftVersion' => 0,
            'holderId' => $holderId,
            'originOrderId' => $orderId,
            'sourceDetailId' => $detailId,
            'projectId' => $projectId,
            'sourceVersion' => 81,
            'detailVersion' => 131,
            'expectedPhysicalRemainingTimes' => 5,
            'deductPhysicalTimes' => 3,
            'convertCurrentSourceOccupiedTimes' => 0,
            'lineIds' => [$requestId . '-line-1', $requestId . '-line-2'],
        ]],
        'linePlans' => [$lineOne, $lineTwo],
        'totals' => [
            'lineCount' => 2, 'serviceQuantity' => 3,
            'actualEntitlementAmountCents' => 10000,
            'consumptionPerformanceCents' => 10000,
            'laborPerformanceCents' => 3000,
        ],
    ];
    $receiptId = CashierV3EntitlementCompletionPlanV1::receiptId(
        'tenant-1', $requestId, $idempotencyKey
    );
    return CashierV3EntitlementCompletionPlanV1::fromKernelPlan($kernel, [
        'contractVersion' => CashierV3EntitlementCompletionPlanV1::CONTRACT_VERSION,
        'checkoutRequestId' => $requestId,
        'commandIdempotencyKey' => $idempotencyKey,
        'businessEventNo' => 'EVT-' . $requestId,
        'documentId' => $receiptId,
        'documentNo' => $receiptId,
        'tenantNameSnapshot' => '测试商户',
        'staffSnapshots' => ecpMysqlStaffSnapshots(),
    ]);
}

function ecpMysqlInsertAuthority(
    ThinkPhpCashierV3EntitlementCompletionWriter $writer,
    int $holderId,
    int $orderId,
    int $detailId,
    int $projectId
): void {
    Db::name('store_order')->insert([
        'id' => $orderId, 'uid' => 1001, 'store_id' => 7, 'paid' => 1,
        'is_del' => 0, 'is_system_del' => 0, 'is_user_del' => 0,
        'refund_status' => 0, 'terminal_action' => 0, 'card_upgrade_use_oid' => 0,
        'order_id' => 'LEGACY-' . $orderId, 'mark' => '测试订单',
        'pay_price' => '100.00', 'cash_pay_price' => '100.00',
        'yue_pay_price' => '0.00', 'debt_amount' => '0.00',
        'repaid_debt_amount' => '0.00',
    ]);
    Db::name('user_card_holder')->insert([
        'id' => $holderId, 'uid' => 1001, 'oid' => $orderId,
        'card_name' => '护理次卡', 'card_no' => 'CARD-' . $holderId,
        'store_id' => 7, 'product_type' => 5, 'write_times' => 5,
        'write_surplus_times' => 5, 'write_start' => 0, 'write_end' => 0, 'is_del' => 0,
    ]);
    Db::name('store_order_cart_info')->insert([
        'id' => $detailId, 'oid' => $orderId, 'cart_id' => 'cart-' . $detailId,
        'product_id' => $projectId, 'cart_type' => 2, 'product_type' => 6,
        'cart_info' => '{}', 'write_times' => 5, 'write_surplus_times' => 5,
        'is_writeoff' => 0, 'write_start' => 0, 'write_end' => 0,
        'pay_price' => '100.00', 'debt_amount' => '0.00',
        'repaid_debt_amount' => '0.00', 'is_gift' => 0,
    ]);

    $holder = Db::name('user_card_holder')->where('id', $holderId)->find();
    $cart = Db::name('store_order_cart_info')->where('id', $detailId)->find();
    $order = Db::name('store_order')->where('id', $orderId)->find();
    $holderFingerprint = new ReflectionMethod($writer, 'holderFingerprint');
    $holderFingerprint->setAccessible(true);
    $detailFingerprint = new ReflectionMethod($writer, 'detailFingerprint');
    $detailFingerprint->setAccessible(true);
    Db::name('cashier_v3_entitlement_resource_version')->insertAll([[
        'resource_kind' => 'card_holder', 'resource_id' => (string)$holderId,
        'member_id' => 1001,
        'source_fingerprint' => $holderFingerprint->invoke($writer, $holder, $order, '0'),
        'current_version' => 81, 'last_action' => 'test_fixture',
        'add_time' => 1785283100, 'update_time' => 1785283100,
    ], [
        'resource_kind' => 'member_benefit_pool', 'resource_id' => (string)$detailId,
        'member_id' => 1001,
        'source_fingerprint' => $detailFingerprint->invoke($writer, $cart, $order, $holder, 1001, '0'),
        'current_version' => 131, 'last_action' => 'test_fixture',
        'add_time' => 1785283100, 'update_time' => 1785283100,
    ]]);
}

$writer = new ThinkPhpCashierV3EntitlementCompletionWriter();
ecpMysqlInsertAuthority($writer, 2001, 5001, 3001, 4001);
ecpMysqlInsertAuthority($writer, 2002, 5002, 3002, 4002);
$plan = ecpMysqlPlan(
    'checkout-request-001', 'idem-entitlement-completion-001', 2001, 5001, 3001, 4001
);

$outsideRejected = false;
try {
    $writer->persistInTx($plan);
} catch (CashierV3CommandException $exception) {
    $outsideRejected = $exception->getResultCode() === CashierV3ResultCode::COMMAND_TRANSACTION_REQUIRED;
}
ok('writer rejects transactionless execution', $outsideRejected, '', 'ECP-MYSQL-01');

Db::startTrans();
try {
    $result = $writer->persistInTx($plan);
    Db::commit();
} catch (Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
ok('success result freezes receipt and actual totals',
    preg_match('/^ECR-[0-9a-f]{40}$/D', $result['receiptId']) === 1
        && $result['replayed'] === false
        && $result['totals']['serviceQuantity'] === 3
        && $result['inserted'] === ['writeoff' => 2, 'service' => 2, 'performance' => 5],
    json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'ECP-MYSQL-02');
ok('same-source quantity 1 plus 2 deducts exactly 3 once',
    (int)Db::name('user_card_holder')->where('id', 2001)->value('write_surplus_times') === 2
        && (int)Db::name('store_order_cart_info')->where('id', 3001)->value('write_surplus_times') === 2,
    '', 'ECP-MYSQL-03');
ok('receipt writeoff service and performance facts commit together',
    (int)Db::name('cashier_v3_entitlement_completion_receipt')->where('checkout_request_id', 'checkout-request-001')->where('status', 'completed')->count() === 1
        && (int)Db::name('cashier_v3_entitlement_writeoff_fact')->where('checkout_request_id', 'checkout-request-001')->sum('quantity') === 3
        && (int)Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id', 'checkout-request-001')->sum('quantity') === 3
        && (int)Db::name('cashier_v3_performance_fact')->where('checkout_request_id', 'checkout-request-001')->count() === 5,
    '', 'ECP-MYSQL-04');
ok('source and detail versions advance with post-write fingerprints',
    (int)Db::name('cashier_v3_entitlement_resource_version')->where('resource_kind', 'card_holder')->where('resource_id', '2001')->value('current_version') === 82
        && (int)Db::name('cashier_v3_entitlement_resource_version')->where('resource_kind', 'member_benefit_pool')->where('resource_id', '3001')->value('current_version') === 132,
    '', 'ECP-MYSQL-05');

Db::startTrans();
try {
    $replay = $writer->persistInTx($plan);
    Db::commit();
} catch (Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
ok('same idempotency key and same plan replays without duplicate effects',
    $replay['replayed'] === true
        && $replay['receiptId'] === $result['receiptId']
        && $replay['inserted'] === ['writeoff' => 0, 'service' => 0, 'performance' => 0]
        && (int)Db::name('user_card_holder')->where('id', 2001)->value('write_surplus_times') === 2
        && (int)Db::name('cashier_v3_entitlement_writeoff_fact')->where('checkout_request_id', 'checkout-request-001')->count() === 2,
    '', 'ECP-MYSQL-06');

$conflictPlan = ecpMysqlPlan(
    'checkout-request-001', 'idem-entitlement-completion-001', 2001, 5001, 3001, 4001, false
);
$conflictReason = '';
Db::startTrans();
try {
    $writer->persistInTx($conflictPlan);
    Db::commit();
} catch (CashierV3EntitlementCompletionPersistenceException $exception) {
    $conflictReason = $exception->reason();
    Db::rollback();
} catch (Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
ok('same idempotency identity with different payload conflicts',
    $conflictReason === 'completion_idempotency_payload_conflict',
    $conflictReason, 'ECP-MYSQL-07');

$rollbackPlan = ecpMysqlPlan(
    'checkout-request-rollback', 'idem-entitlement-completion-rollback', 2002, 5002, 3002, 4002
);
$rollbackInjected = false;
Db::startTrans();
try {
    $writer->persistInTx($rollbackPlan);
    throw new RuntimeException('injected_downstream_failure');
} catch (RuntimeException $exception) {
    $rollbackInjected = $exception->getMessage() === 'injected_downstream_failure';
    Db::rollback();
}
ok('downstream failure is owned by the outer transaction', $rollbackInjected, '', 'ECP-MYSQL-08');
ok('outer rollback restores rights receipt and every fact table',
    (int)Db::name('user_card_holder')->where('id', 2002)->value('write_surplus_times') === 5
        && (int)Db::name('store_order_cart_info')->where('id', 3002)->value('write_surplus_times') === 5
        && (int)Db::name('cashier_v3_entitlement_completion_receipt')->where('checkout_request_id', 'checkout-request-rollback')->count() === 0
        && (int)Db::name('cashier_v3_entitlement_writeoff_fact')->where('checkout_request_id', 'checkout-request-rollback')->count() === 0
        && (int)Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id', 'checkout-request-rollback')->count() === 0
        && (int)Db::name('cashier_v3_performance_fact')->where('checkout_request_id', 'checkout-request-rollback')->count() === 0,
    '', 'ECP-MYSQL-09');

finish('C2_ENTITLEMENT_COMPLETION_PERSISTENCE_MYSQL56');

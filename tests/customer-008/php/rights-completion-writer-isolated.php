<?php
declare(strict_types=1);

/**
 * Run the real V3 completion writer against one imported source entitlement.
 * The entire checkout write, including holder/cart/rule and service facts, is
 * inspected before rollback; the disposable fixture retains no usage.
 */
$backend = getenv('M008_RIGHTS_TEST_BACKEND') ?: '/var/www/html';
if ((getenv('M008_RIGHTS_TEST_HOST') ?: '') !== 'm008-rights-isolate') {
    throw new RuntimeException('Disposable host guard');
}
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'database.hostname' => 'm008-rights-isolate', 'DATABASE_HOSTNAME' => 'm008-rights-isolate',
    'database.hostport' => '3306', 'DATABASE_HOSTPORT' => '3306',
    'database.database' => 'mohe_008', 'DATABASE_DATABASE' => 'mohe_008',
    'database.username' => 'root', 'DATABASE_USERNAME' => 'root',
    'database.password' => 'rights-test-local', 'DATABASE_PASSWORD' => 'rights-test-local',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'rights_writer_isolated_skip_dotenv_reload');
$app->initialize();

use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionPlanV1;
use app\services\cashier\v3\checkout\persistence\ThinkPhpCashierV3EntitlementCompletionWriter;
use think\facade\Db;

$sourceDetailId = (int)(getenv('M008_RIGHTS_TEST_SOURCE_DETAIL') ?: 265);
if (!in_array($sourceDetailId, [265, 9905], true)) {
    throw new RuntimeException('Reviewed source detail fixture required');
}
$source = Db::name('migration_entitlement_scope')->alias('s')
    ->join('user_card_holder h', 'h.id=s.card_holder_id')
    ->join('store_order_cart_info c', 'c.oid=h.oid AND c.cart_type=2')
    ->where('s.source_detail_id', $sourceDetailId)
    ->field('h.id holder_id,h.uid,h.oid,h.card_name,h.card_no,h.write_times,h.write_surplus_times,c.id detail_id,c.product_id,c.cart_info')
    ->find();
if (!$source || (int)$source['uid'] !== 421 || (int)$source['write_surplus_times'] <= 0) {
    throw new RuntimeException('Imported normal-card source fixture missing');
}
$initialRemaining = (int)$source['write_surplus_times'];
$totalTimes = (int)$source['write_times'];
$choice = $sourceDetailId === 9905;
$cartSnapshot = json_decode((string)$source['cart_info'], true);
$projectName = (string)($cartSnapshot['productInfo']['store_name'] ?? '');
$now = time();
$date = date('Y-m-d', $now);
$request = 'm008-rights-writer-' . $sourceDetailId;
$lineId = 'm008-rights-line-1';
$holderId = (int)$source['holder_id'];
$detailId = (int)$source['detail_id'];
$orderId = (int)$source['oid'];
$productId = (int)$source['product_id'];
$staff = ['staffId' => 11, 'employeeId' => 1011, 'staffName' => '隔离手艺人',
    'staffVersion' => 1, 'storeId' => 11, 'employeeTypeCodeSnapshot' => 'internal',
    'employeeTypeAuthorityVersion' => 1];
$allocation = ['staffId' => 11, 'isPrimary' => true, 'sequence' => 1,
    'amountCents' => 0, 'staffVersion' => 1, 'staffName' => '隔离手艺人',
    'storeId' => 11, 'laborWeight' => 100];
$line = [
    'lineId' => $lineId, 'sortNo' => 10,
    'source' => [
        'entitlementInstanceType' => 'card_holder', 'entitlementInstanceId' => $holderId,
        'sourceKind' => 'count_card', 'isGift' => false, 'giftSourceType' => 'none',
        'giftId' => 0, 'giftVersion' => 0, 'holderId' => $holderId,
        'originOrderId' => $orderId, 'sourceNameSnapshot' => (string)$source['card_name'],
        'sourceCodeSnapshot' => (string)$source['card_no'], 'sourceDetailId' => $detailId,
        'projectId' => $productId, 'projectNameSnapshot' => $projectName,
        'projectCategoryIdSnapshot' => 0, 'projectCategoryNameSnapshot' => '未分类',
        'sourceVersion' => 1, 'detailVersion' => 1, 'purchaseAmountCents' => 0,
        'totalPurchaseTimes' => $totalTimes,
        'consumedTimesAtLock' => $totalTimes - $initialRemaining,
        'amountCalculationVersion' => $choice
            ? 'shared-card-purchase-amount-whole-yuan-floor-final-remainder-v1'
            : 'issued-card-rule-normal-whole-yuan-floor-final-remainder-v1',
    ],
    'quantity' => 1, 'actualEntitlementAmountCents' => 0,
    'performanceRuleSnapshot' => [
        'ruleVersion' => 1, 'consumptionMode' => 'actual_entitlement_amount',
        'consumptionConfiguredUnitAmountCents' => 0,
        'laborMode' => 'project_configured_amount', 'laborConfiguredUnitAmountCents' => 0,
    ],
    'consumptionPerformance' => ['mode' => 'actual_entitlement_amount', 'amountCents' => 0],
    'laborPerformance' => ['mode' => 'project_configured_amount', 'amountCents' => 0,
        'allocations' => [$allocation]],
    'serviceSnapshot' => [
        'serviceObject' => 'self', 'friendCountsAsCustomer' => false,
        'isExperience' => false, 'craftsmen' => [$allocation], 'primaryCraftsmanId' => 11,
        'businessDate' => $date, 'businessTimezone' => 'Asia/Shanghai',
        'occurredAt' => $now, 'settledAt' => $now, 'recordedAt' => $now,
        'sourceType' => 'direct', 'serviceOrderId' => 0, 'reservationId' => 0,
        'occupationContributors' => [],
    ],
];
$kernel = [
    'contractVersion' => CashierV3EntitlementCompletionKernel::CONTRACT_VERSION,
    'action' => CashierV3EntitlementCompletionKernel::ACTION,
    'composition' => CashierV3EntitlementCompletionKernel::COMPOSITION_ENTITLEMENT_ONLY,
    'persistenceStatus' => 'not_persisted', 'requiresGatewayTransaction' => true,
    'workspaceId' => 'm008-writer-isolated', 'stateContextId' => 'm008-writer-isolated',
    'memberId' => 421, 'storeId' => 11, 'operatorId' => 1,
    'businessDate' => $date, 'businessTimezone' => 'Asia/Shanghai',
    'occurredAt' => $now, 'settledAt' => $now, 'recordedAt' => $now,
    'dimensionSnapshot' => [
        'tenantId' => '0', 'organizationId' => 3, 'organizationName' => '渼约',
        'organizationPath' => '/1/3/', 'storeId' => 11, 'storeName' => '渼约隔离店',
        'memberId' => 421, 'memberName' => '隔离会员', 'operatorId' => 1,
        'operatorName' => '隔离收银员',
    ],
    'source' => ['type' => 'direct', 'serviceOrderId' => 0, 'reservationId' => 0],
    'entitlementDeductions' => [[
        'sourceKey' => 'card_holder:' . $holderId . ':' . $detailId,
        'entitlementInstanceType' => 'card_holder', 'entitlementInstanceId' => $holderId,
        'sourceKind' => 'count_card', 'isGift' => false, 'giftSourceType' => 'none',
        'giftId' => 0, 'giftVersion' => 0, 'holderId' => $holderId,
        'originOrderId' => $orderId, 'sourceDetailId' => $detailId,
        'projectId' => $productId, 'sourceVersion' => 1, 'detailVersion' => 1,
        'expectedPhysicalRemainingTimes' => $initialRemaining, 'deductPhysicalTimes' => 1,
        'convertCurrentSourceOccupiedTimes' => 0, 'lineIds' => [$lineId],
    ]],
    'linePlans' => [$line],
    'totals' => ['lineCount' => 1, 'serviceQuantity' => 1,
        'actualEntitlementAmountCents' => 0, 'consumptionPerformanceCents' => 0,
        'laborPerformanceCents' => 0],
];
$receiptId = CashierV3EntitlementCompletionPlanV1::receiptId('0', $request, 'm008-rights-idem');
$plan = CashierV3EntitlementCompletionPlanV1::fromKernelPlan($kernel, [
    'contractVersion' => CashierV3EntitlementCompletionPlanV1::CONTRACT_VERSION,
    'checkoutRequestId' => $request, 'commandIdempotencyKey' => 'm008-rights-idem',
    'businessEventNo' => 'EVT-' . $request, 'documentId' => $receiptId,
    'documentNo' => $receiptId, 'tenantNameSnapshot' => '隔离实例',
    'staffSnapshots' => [$staff],
]);
$writer = new ThinkPhpCashierV3EntitlementCompletionWriter();
Db::startTrans();
try {
    $result = $writer->persistInTx($plan);
    $inside = [
        'holder_remaining' => (int)Db::name('user_card_holder')->where('id', $holderId)->value('write_surplus_times'),
        'detail_remaining' => (int)Db::name('store_order_cart_info')->where('id', $detailId)->value('write_surplus_times'),
        'writeoff_facts' => (int)Db::name('cashier_v3_entitlement_writeoff_fact')->where('checkout_request_id', $request)->count(),
        'service_facts' => (int)Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id', $request)->count(),
    ];
    if ($inside !== ['holder_remaining' => $initialRemaining - 1,
        'detail_remaining' => $initialRemaining - 1,
        'writeoff_facts' => 1, 'service_facts' => 1]) {
        throw new RuntimeException('Completion writer did not atomically deduct and record service');
    }
    Db::rollback();
} catch (Throwable $error) {
    Db::rollback();
    throw $error;
}
if ((int)Db::name('user_card_holder')->where('id', $holderId)->value('write_surplus_times') !== $initialRemaining
    || (int)Db::name('cashier_v3_entitlement_writeoff_fact')->where('checkout_request_id', $request)->count() !== 0) {
    throw new RuntimeException('Isolated full writer rollback failed');
}
echo json_encode(['source_detail_id' => $sourceDetailId, 'rule_type' => $choice ? 'choice_count' : 'normal',
    'inside_transaction' => $inside,
    'writer_status' => $result['persistenceStatus'], 'rolled_back' => true],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;

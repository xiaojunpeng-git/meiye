<?php
declare(strict_types=1);

$testRoot = getenv('CASHIER_V3_TEST_ROOT') ?: '/workspace/tests/cashier-v3';
require $testRoot . '/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require $testRoot . '/lib/_lib.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionPlanV1;
use app\services\cashier\v3\checkout\persistence\ThinkPhpCashierV3EntitlementCompletionWriter;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');

function cardRuleCanonicalize($value)
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_keys($value) === ($value ? range(0, count($value) - 1) : [])) {
        return array_map('cardRuleCanonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = cardRuleCanonicalize($item);
    }
    return $value;
}

function cardRuleFingerprint(array $value): string
{
    return hash('sha256', json_encode(
        cardRuleCanonicalize($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
}

function insertRuleFixture(
    string $suffix,
    string $ruleType,
    int $holderId,
    int $memberId,
    int $choiceLimit,
    int $sharedTimes,
    array $components,
    int $validFrom,
    int $validThrough,
    bool $badFingerprint = false
): array {
    $now = time();
    $definition = ['fixture' => $suffix, 'ruleType' => $ruleType, 'components' => $components];
    $stateId = (int)Db::name('cashier_v3_card_rule_state')->insertGetId([
        'state_id' => 'CRS-TEST-' . $suffix,
        'tenant_id' => '0',
        'receipt_id' => 'CPR-TEST-' . $suffix,
        'sales_order_id' => 'SO-TEST-' . $suffix,
        'sales_order_line_id' => 'SOL-TEST-' . $suffix,
        'card_holder_id' => $holderId,
        'member_id' => $memberId,
        'issue_store_id' => 7,
        'catalog_product_id' => 800000 + $holderId,
        'catalog_sku_id' => 900000 + $holderId,
        'rule_type' => $ruleType,
        'rule_version' => 1,
        'definition_version' => 1,
        'choice_limit' => $choiceLimit,
        'selected_kind_count' => 0,
        'shared_total_times' => $sharedTimes,
        'shared_remaining_times' => $sharedTimes,
        'validity_mode' => 3,
        'valid_from' => $validFrom,
        'valid_through' => $validThrough,
        'immutable_fingerprint' => $badFingerprint ? str_repeat('0', 64) : cardRuleFingerprint($definition),
        'definition_snapshot_json' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'state_version' => 1,
        'status' => 'active',
        'occurred_at' => $now,
        'recorded_at' => $now,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $detailIds = [];
    foreach ($components as $index => $component) {
        $snapshot = [
            'configuredPriceCents' => (int)$component['configuredPriceCents'],
            'fixture' => $suffix . '-' . $index,
        ];
        if (is_array($component['snapshot'] ?? null)) {
            $snapshot = array_merge($snapshot, $component['snapshot']);
        }
        $detailId = (int)$component['detailId'];
        Db::name('cashier_v3_card_rule_component')->insert([
            'component_state_id' => 'CRC-TEST-' . $suffix . '-' . $index,
            'tenant_id' => '0',
            'rule_state_id' => $stateId,
            'card_holder_id' => $holderId,
            'legacy_detail_id' => $detailId,
            'relation_id' => 700000 + $detailId,
            'project_product_id' => (int)$component['projectId'],
            'project_sku_id' => 600000 + $detailId,
            'project_sku_unique' => 'SKU-TEST-' . $suffix . '-' . $index,
            'project_type' => 6,
            'project_name_snapshot' => '测试项目' . $index,
            'total_times' => (int)$component['totalTimes'],
            'remaining_times' => (int)$component['remainingTimes'],
            'writeoff_amount_cents' => (int)$component['writeoffAmountCents'],
            'selection_status' => (string)$component['selectionStatus'],
            'selected_at' => 0,
            'selected_store_id' => 0,
            'immutable_fingerprint' => cardRuleFingerprint($snapshot),
            'component_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'state_version' => 1,
            'status' => 'active',
            'occurred_at' => $now,
            'recorded_at' => $now,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        $detailIds[] = $detailId;
    }
    return ['stateId' => $stateId, 'detailIds' => $detailIds];
}

function deduction(int $holderId, int $detailId, int $projectId, int $quantity, int $expected): array
{
    return [
        'holder_id' => $holderId,
        'source_detail_id' => $detailId,
        'project_id' => $projectId,
        'deduct_physical_times' => $quantity,
        'expected_physical_remaining_times' => $expected,
    ];
}

/**
 * Build the same immutable final-completion handoff used by the cashier.
 * This fixture deliberately uses source_type=direct: the browser owns one
 * checkout snapshot while the writer still locks the legacy entitlement,
 * applies issued-card rules, and persists writeoff/service facts atomically.
 */
function replacementCompletionPlan(
    string $suffix,
    int $holderId,
    int $memberId,
    int $orderId,
    int $detailId,
    int $projectId,
    int $now
): CashierV3EntitlementCompletionPlanV1 {
    $requestId = 'replacement-completion-' . $suffix;
    $idempotencyKey = 'replacement-completion-idem-' . $suffix;
    $lineId = 'replacement-line-' . $suffix;
    $staff = [
        'staffId' => 1,
        'employeeId' => 1,
        'staffName' => '操作员一',
        'staffVersion' => 1,
        'storeId' => 11,
        'employeeTypeCodeSnapshot' => 'internal',
        'employeeTypeAuthorityVersion' => 1,
    ];
    $allocation = [
        'staffId' => 1,
        'isPrimary' => true,
        'sequence' => 1,
        'amountCents' => 0,
        'staffVersion' => 1,
        'staffName' => '操作员一',
        'storeId' => 11,
        'laborWeight' => 100,
        'craftsmanPerformanceType' => 'labor',
    ];
    $kernel = [
        'contractVersion' => CashierV3EntitlementCompletionKernel::CONTRACT_VERSION,
        'action' => CashierV3EntitlementCompletionKernel::ACTION,
        'composition' => CashierV3EntitlementCompletionKernel::COMPOSITION_ENTITLEMENT_ONLY,
        'persistenceStatus' => 'not_persisted',
        'requiresGatewayTransaction' => true,
        'workspaceId' => 'replacement-workspace-' . $suffix,
        'stateContextId' => 'replacement-state-' . $suffix,
        'memberId' => $memberId,
        'storeId' => 11,
        'operatorId' => 1,
        'businessDate' => date('Y-m-d', $now),
        'businessTimezone' => 'Asia/Shanghai',
        'occurredAt' => $now,
        'settledAt' => $now,
        'recordedAt' => $now,
        'dimensionSnapshot' => [
            'tenantId' => '0',
            'organizationId' => 3,
            'organizationName' => '测试组织',
            'organizationPath' => '/1/3/',
            'storeId' => 11,
            'storeName' => '测试门店',
            'memberId' => $memberId,
            'memberName' => '替换权益会员',
            'operatorId' => 1,
            'operatorName' => '操作员一',
        ],
        'source' => ['type' => 'direct', 'serviceOrderId' => 0, 'reservationId' => 0],
        'entitlementDeductions' => [[
            'sourceKey' => 'card_holder:' . $holderId . ':' . $detailId,
            'entitlementInstanceType' => 'card_holder',
            'entitlementInstanceId' => $holderId,
            'sourceKind' => 'choice_count_card',
            'isGift' => false,
            'giftSourceType' => 'none',
            'giftId' => 0,
            'giftVersion' => 0,
            'holderId' => $holderId,
            'originOrderId' => $orderId,
            'sourceDetailId' => $detailId,
            'projectId' => $projectId,
            'sourceVersion' => 1,
            'detailVersion' => 1,
            'expectedPhysicalRemainingTimes' => 1,
            'deductPhysicalTimes' => 1,
            'convertCurrentSourceOccupiedTimes' => 0,
            'lineIds' => [$lineId],
        ]],
        'linePlans' => [[
            'lineId' => $lineId,
            'source' => [
                'entitlementInstanceType' => 'card_holder',
                'entitlementInstanceId' => $holderId,
                'sourceKind' => 'choice_count_card',
                'isGift' => false,
                'giftSourceType' => 'none',
                'giftId' => 0,
                'giftVersion' => 0,
                'holderId' => $holderId,
                'originOrderId' => $orderId,
                'sourceNameSnapshot' => '替换来源卡项',
                'sourceCodeSnapshot' => 'REPLACEMENT-' . $holderId,
                'sourceDetailId' => $detailId,
                'projectId' => $projectId,
                'projectNameSnapshot' => '替换目标项目',
                'projectCategoryIdSnapshot' => 0,
                'projectCategoryNameSnapshot' => '测试分类',
                'sourceVersion' => 1,
                'detailVersion' => 1,
                'purchaseAmountCents' => 1662,
                'totalPurchaseTimes' => 1,
                'consumedTimesAtLock' => 0,
                'amountCalculationVersion' => 'replacement-operation-unit-value-v1',
            ],
            'quantity' => 1,
            'actualEntitlementAmountCents' => 1662,
            'performanceRuleSnapshot' => [
                'ruleVersion' => 1,
                'consumptionMode' => 'actual_entitlement_amount',
                'consumptionConfiguredUnitAmountCents' => 0,
                'laborMode' => 'project_configured_amount',
                'laborConfiguredUnitAmountCents' => 0,
            ],
            'consumptionPerformance' => [
                'mode' => 'actual_entitlement_amount',
                'amountCents' => 1662,
            ],
            'laborPerformance' => [
                'mode' => 'project_configured_amount',
                'amountCents' => 0,
                'allocations' => [$allocation],
            ],
            'serviceSnapshot' => [
                'serviceObject' => 'self',
                'friendCountsAsCustomer' => false,
                'isExperience' => false,
                'craftsmen' => [$allocation],
                'primaryCraftsmanId' => 1,
                'businessDate' => date('Y-m-d', $now),
                'businessTimezone' => 'Asia/Shanghai',
                'occurredAt' => $now,
                'settledAt' => $now,
                'recordedAt' => $now,
                'sourceType' => 'direct',
                'serviceOrderId' => 0,
                'reservationId' => 0,
                'occupationContributors' => [],
            ],
        ]],
        'totals' => [
            'lineCount' => 1,
            'serviceQuantity' => 1,
            'actualEntitlementAmountCents' => 1662,
            'consumptionPerformanceCents' => 1662,
            'laborPerformanceCents' => 0,
        ],
    ];
    $receiptId = CashierV3EntitlementCompletionPlanV1::receiptId('0', $requestId, $idempotencyKey);
    return CashierV3EntitlementCompletionPlanV1::fromKernelPlan($kernel, [
        'contractVersion' => CashierV3EntitlementCompletionPlanV1::CONTRACT_VERSION,
        'checkoutRequestId' => $requestId,
        'commandIdempotencyKey' => $idempotencyKey,
        'businessEventNo' => 'EVT-' . $suffix,
        'documentId' => $receiptId,
        'documentNo' => $receiptId,
        'tenantNameSnapshot' => '测试商户',
        'staffSnapshots' => [$staff],
    ]);
}

$service = new CashierV3CardRuleEntitlementAuthorityServices();
$memberId = 990001;
$now = time();
$suffix = substr(hash('sha256', getmypid() . ':' . microtime(true)), 0, 10);
$holderBase = 990000000 + random_int(1000, 9000);
$detailBase = 980000000 + random_int(1000, 9000);
$fixtureStateIds = [];

Db::startTrans();
try {
    $normal = insertRuleFixture($suffix . '-normal', 'normal', $holderBase + 1, $memberId, 0, 0, [[
        'detailId' => $detailBase + 1, 'projectId' => 501, 'totalTimes' => 5,
        'remainingTimes' => 5, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1000,
        'selectionStatus' => 'not_applicable',
    ], [
        'detailId' => $detailBase + 2, 'projectId' => 502, 'totalTimes' => 5,
        'remainingTimes' => 5, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1000,
        'selectionStatus' => 'not_applicable',
    ]], $now - 10, $now + 3600);
    $fixtureStateIds[] = $normal['stateId'];
    $service->applyDeductionsInTx([
        deduction($holderBase + 1, $detailBase + 1, 501, 2, 5),
    ], ['tenant_id' => '0', 'store_id' => 8, 'member_id' => $memberId, 'occurred_at' => $now]);
    ok('normal card decrements only selected component',
        (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 1)->value('remaining_times') === 3
        && (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 2)->value('remaining_times') === 5,
        '', 'CARD-RULE-MYSQL-01');

    // Reproduce the missing upgraded component, then consume exactly the
    // issued target quantity and verify that the old component was transferred.
    $upgradeDetail = $detailBase + 50;
    $upgradeOperation = 'COP-UPGRADE-' . $suffix;
    Db::name('store_order_cart_info')->insert([
        'id' => $upgradeDetail, 'uid' => $memberId, 'oid' => 0,
        'product_id' => 550, 'product_type' => 6, 'cart_type' => 2,
        'write_times' => 1, 'write_surplus_times' => 1,
        'cart_info' => json_encode(['sourceType' => 'cashier_v3_project_upgrade', 'cardOperationId' => $upgradeOperation]),
    ]);
    $service->replaceProjectComponentsInTx('0', $holderBase + 1,
        [['detailId' => $detailBase + 1, 'quantity' => 1]], 1, 106600,
        ['catalogId' => 550, 'skuId' => 550, 'skuUnique' => 'upgrade-test', 'catalogName' => '升级测试项目'],
        $upgradeDetail, $upgradeOperation, $now, 'project_upgrade');
    $upgraded = $service->authorityForDetail('0', $holderBase + 1, $upgradeDetail, true);
    // Transferred cards retain the issue-member snapshot. Only the current
    // holder may use the upgraded right; the former owner must be rejected.
    Db::name('user_card_holder')->insert([
        'id' => $holderBase + 1, 'uid' => $memberId + 1, 'oid' => 0,
        'is_del' => 0, 'card_name' => 'transfer-upgrade-fixture',
    ]);
    $oldMemberRejected = false;
    try {
        $service->applyDeductionsInTx([deduction($holderBase + 1, $upgradeDetail, 550, 1, 1)],
            ['tenant_id' => '0', 'store_id' => 8, 'member_id' => $memberId, 'occurred_at' => $now]);
    } catch (CashierV3CommandException $exception) {
        $oldMemberRejected = ($exception->getDetail()['reason'] ?? '') === 'card_rule_state_not_active';
    }
    $service->applyDeductionsInTx([deduction($holderBase + 1, $upgradeDetail, 550, 1, 1)],
        ['tenant_id' => '0', 'store_id' => 8, 'member_id' => $memberId + 1, 'occurred_at' => $now]);
    ok('upgraded right registers authority and supports writeoff in the same transaction',
        $oldMemberRejected && $upgraded['purchaseAmountCents'] === 106600
        && (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 1)->value('remaining_times') === 2
        && (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $upgradeDetail)->value('remaining_times') === 0,
        '', 'CARD-RULE-MYSQL-UPGRADE');

    $choice = insertRuleFixture($suffix . '-kind', 'choice_kind', $holderBase + 2, $memberId, 1, 0, [[
        'detailId' => $detailBase + 3, 'projectId' => 503, 'totalTimes' => 3,
        'remainingTimes' => 3, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1200,
        'selectionStatus' => 'candidate',
    ], [
        'detailId' => $detailBase + 4, 'projectId' => 504, 'totalTimes' => 3,
        'remainingTimes' => 3, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1200,
        'selectionStatus' => 'candidate',
    ]], $now - 10, $now + 3600);
    $fixtureStateIds[] = $choice['stateId'];
    $service->applyDeductionsInTx([
        deduction($holderBase + 2, $detailBase + 3, 503, 1, 3),
    ], ['tenant_id' => '0', 'store_id' => 9, 'member_id' => $memberId, 'occurred_at' => $now]);
    $choiceBlocked = false;
    try {
        $service->applyDeductionsInTx([
            deduction($holderBase + 2, $detailBase + 4, 504, 1, 3),
        ], ['tenant_id' => '0', 'store_id' => 10, 'member_id' => $memberId, 'occurred_at' => $now]);
    } catch (CashierV3CommandException $exception) {
        $choiceBlocked = ($exception->getDetail()['reason'] ?? '') === 'card_rule_choice_limit_exceeded';
    }
    ok('choice-kind first use freezes selection and rejects a new kind over limit',
        $choiceBlocked
        && (int)Db::name('cashier_v3_card_rule_state')->where('id', $choice['stateId'])->value('selected_kind_count') === 1
        && (string)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 3)->value('selection_status') === 'selected'
        && (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 3)->value('selected_store_id') === 9,
        '', 'CARD-RULE-MYSQL-02');

    $shared = insertRuleFixture($suffix . '-count', 'choice_count', $holderBase + 3, $memberId, 0, 8, [[
        'detailId' => $detailBase + 5, 'projectId' => 505, 'totalTimes' => 0,
        'remainingTimes' => 0, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1500,
        'selectionStatus' => 'not_applicable',
    ], [
        'detailId' => $detailBase + 6, 'projectId' => 506, 'totalTimes' => 0,
        'remainingTimes' => 0, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1600,
        'selectionStatus' => 'not_applicable',
    ]], $now - 10, $now + 3600);
    $fixtureStateIds[] = $shared['stateId'];
    $service->applyDeductionsInTx([
        deduction($holderBase + 3, $detailBase + 5, 505, 1, 8),
        deduction($holderBase + 3, $detailBase + 6, 506, 2, 8),
    ], ['tenant_id' => '0', 'store_id' => 11, 'member_id' => $memberId, 'occurred_at' => $now]);
    ok('choice-count aggregates different projects into one shared deduction',
        (int)Db::name('cashier_v3_card_rule_state')->where('id', $shared['stateId'])->value('shared_remaining_times') === 5
        && (int)Db::name('cashier_v3_card_rule_state')->where('id', $shared['stateId'])->value('state_version') === 2,
        '', 'CARD-RULE-MYSQL-03');

    $replacementOperationId = 'COP-TEST-' . strtoupper(substr($suffix, 0, 12));
    $replacementDetailId = $detailBase + 11;
    $replacementOrderId = 970000001;
    Db::name('store_order')->insert([
        'id' => $replacementOrderId,
        'uid' => $memberId,
        'store_id' => 11,
        'paid' => 1,
        'is_del' => 0,
        'is_system_del' => 0,
        'is_user_del' => 0,
        'refund_status' => 0,
        'terminal_action' => 0,
        'card_upgrade_use_oid' => 0,
        'order_id' => 'SO-REPLACEMENT-' . $suffix,
        'mark' => '替换权益最终核销测试',
        'pay_price' => '16.62',
        'cash_pay_price' => '16.62',
        'yue_pay_price' => '0.00',
        'debt_amount' => '0.00',
        'repaid_debt_amount' => '0.00',
    ]);
    Db::name('user_card_holder')->insert([
        'id' => $holderBase + 8,
        'uid' => $memberId,
        'oid' => $replacementOrderId,
        'card_name' => '替换来源卡项',
        'card_no' => 'REPLACEMENT-' . $suffix,
        'store_id' => 11,
        'product_type' => 4,
        'write_times' => 62,
        'write_surplus_times' => 62,
        'write_start' => $now - 3600,
        'write_end' => $now + 86400,
        'is_del' => 0,
    ]);
    Db::name('store_order_cart_info')->insert([
        'id' => $replacementDetailId,
        'uid' => $memberId,
        'oid' => $replacementOrderId,
        'cart_id' => 'replacement-' . $suffix,
        'cart_type' => 2,
        'product_id' => 511,
        'product_type' => 6,
        'pay_price' => '16.62',
        'write_times' => 1,
        'write_surplus_times' => 1,
        'cart_num' => 1,
        'surplus_num' => 1,
        'split_surplus_num' => 1,
        'is_writeoff' => 0,
        'is_gift' => 0,
        'write_start' => $now - 3600,
        'write_end' => $now + 86400,
        'debt_amount' => '0.00',
        'repaid_debt_amount' => '0.00',
        'cart_info' => json_encode([
            'sourceType' => 'cashier_v3_project_replacement',
            'cardOperationId' => $replacementOperationId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $replacementShared = insertRuleFixture(
        $suffix . '-replacement-count',
        'choice_count',
        $holderBase + 8,
        $memberId,
        0,
        62,
        [[
            'detailId' => $replacementDetailId,
            'projectId' => 511,
            'totalTimes' => 0,
            'remainingTimes' => 0,
            'writeoffAmountCents' => 0,
            'configuredPriceCents' => 1662,
            'selectionStatus' => 'not_applicable',
            'snapshot' => ['replacementOperationId' => $replacementOperationId],
        ]],
        $now - 10,
        $now + 3600
    );
    $fixtureStateIds[] = $replacementShared['stateId'];
    $completionPlan = replacementCompletionPlan(
        $suffix,
        $holderBase + 8,
        $memberId,
        $replacementOrderId,
        $replacementDetailId,
        511,
        $now
    );
    $completionWriter = new ThinkPhpCashierV3EntitlementCompletionWriter(null, $service);
    $completion = $completionWriter->persistInTx($completionPlan);
    ok('choice-count replacement target completes service through final atomic writer',
        (int)Db::name('cashier_v3_card_rule_state')
            ->where('id', $replacementShared['stateId'])
            ->value('shared_remaining_times') === 61
        && (int)Db::name('user_card_holder')->where('id', $holderBase + 8)->value('write_surplus_times') === 61
        && (int)Db::name('store_order_cart_info')->where('id', $replacementDetailId)->value('write_surplus_times') === 0
        && (int)Db::name('store_order_cart_info')->where('id', $replacementDetailId)->value('is_writeoff') === 1
        && (int)Db::name('cashier_v3_entitlement_writeoff_fact')
            ->where('checkout_request_id', 'replacement-completion-' . $suffix)->sum('quantity') === 1
        && (int)Db::name('cashier_v3_entitlement_service_fact')
            ->where('checkout_request_id', 'replacement-completion-' . $suffix)
            ->where('service_status', 'completed')->sum('quantity') === 1
        && preg_match('/^FW[0-9]{9}$/D', (string)Db::name('cashier_v3_entitlement_service_fact')
            ->where('checkout_request_id', 'replacement-completion-' . $suffix)
            ->value('service_record_no')) === 1
        && empty($completion['replayed']),
        '', 'CARD-RULE-MYSQL-03A');
    $completionReplay = $completionWriter->persistInTx($completionPlan);
    ok('replacement completion replay does not deduct or create facts twice',
        !empty($completionReplay['replayed'])
        && (int)Db::name('cashier_v3_card_rule_state')
            ->where('id', $replacementShared['stateId'])->value('shared_remaining_times') === 61
        && (int)Db::name('store_order_cart_info')->where('id', $replacementDetailId)->value('write_surplus_times') === 0
        && (int)Db::name('cashier_v3_entitlement_writeoff_fact')
            ->where('checkout_request_id', 'replacement-completion-' . $suffix)->count() === 1
        && (int)Db::name('cashier_v3_entitlement_service_fact')
            ->where('checkout_request_id', 'replacement-completion-' . $suffix)->count() === 1,
        '', 'CARD-RULE-MYSQL-03B');

    $time = insertRuleFixture($suffix . '-time', 'time', $holderBase + 4, $memberId, 0, 0, [[
        'detailId' => $detailBase + 7, 'projectId' => 507, 'totalTimes' => 0,
        'remainingTimes' => 0, 'writeoffAmountCents' => 8800, 'configuredPriceCents' => 0,
        'selectionStatus' => 'not_applicable',
    ]], $now - 10, $now + 3600);
    $fixtureStateIds[] = $time['stateId'];
    $service->applyDeductionsInTx([
        deduction($holderBase + 4, $detailBase + 7, 507, 3, CashierV3CardRuleEntitlementAuthorityServices::TIME_CARD_VIRTUAL_TIMES),
    ], ['tenant_id' => '0', 'store_id' => 12, 'member_id' => $memberId, 'occurred_at' => $now]);
    ok('time card records a successful state transition without count deduction',
        (int)Db::name('cashier_v3_card_rule_state')->where('id', $time['stateId'])->value('state_version') === 2
        && (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 7)->value('remaining_times') === 0
        && (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 7)->value('writeoff_amount_cents') === 8800,
        '', 'CARD-RULE-MYSQL-04');

    $expired = insertRuleFixture($suffix . '-expired', 'time', $holderBase + 5, $memberId, 0, 0, [[
        'detailId' => $detailBase + 8, 'projectId' => 508, 'totalTimes' => 0,
        'remainingTimes' => 0, 'writeoffAmountCents' => 9900, 'configuredPriceCents' => 0,
        'selectionStatus' => 'not_applicable',
    ]], $now - 3600, $now - 1);
    $fixtureStateIds[] = $expired['stateId'];
    $expiredBlocked = false;
    try {
        $service->applyDeductionsInTx([
            deduction($holderBase + 5, $detailBase + 8, 508, 1, CashierV3CardRuleEntitlementAuthorityServices::TIME_CARD_VIRTUAL_TIMES),
        ], ['tenant_id' => '0', 'store_id' => 13, 'member_id' => $memberId, 'occurred_at' => $now]);
    } catch (CashierV3CommandException $exception) {
        $expiredBlocked = ($exception->getDetail()['reason'] ?? '') === 'card_rule_state_outside_validity';
    }
    ok('expired time card fails closed before mutation', $expiredBlocked, '', 'CARD-RULE-MYSQL-05');

    $bad = insertRuleFixture($suffix . '-bad', 'normal', $holderBase + 6, $memberId, 0, 0, [[
        'detailId' => $detailBase + 9, 'projectId' => 509, 'totalTimes' => 2,
        'remainingTimes' => 2, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1000,
        'selectionStatus' => 'not_applicable',
    ]], $now - 10, $now + 3600, true);
    $fixtureStateIds[] = $bad['stateId'];
    $tamperBlocked = false;
    try {
        $service->snapshotForHolder('0', $holderBase + 6, false);
    } catch (CashierV3CommandException $exception) {
        $tamperBlocked = ($exception->getDetail()['reason'] ?? '') === 'card_rule_state_fingerprint_invalid';
    }
    ok('immutable issue snapshot tampering fails closed', $tamperBlocked, '', 'CARD-RULE-MYSQL-06');

    $race = insertRuleFixture($suffix . '-race', 'normal', $holderBase + 7, $memberId, 0, 0, [[
        'detailId' => $detailBase + 10, 'projectId' => 510, 'totalTimes' => 2,
        'remainingTimes' => 2, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1000,
        'selectionStatus' => 'not_applicable',
    ]], $now - 10, $now + 3600);
    $fixtureStateIds[] = $race['stateId'];
    $staleState = Db::name('cashier_v3_card_rule_state')->where('id', $race['stateId'])->find();
    $staleState = is_object($staleState) && method_exists($staleState, 'toArray')
        ? $staleState->toArray() : (array)$staleState;
    $updateState = new ReflectionMethod(CashierV3CardRuleEntitlementAuthorityServices::class, 'updateState');
    $updateState->setAccessible(true);
    $updateState->invoke($service, $staleState, [], $now);
    $staleBlocked = false;
    try {
        $updateState->invoke($service, $staleState, [], $now);
    } catch (CashierV3CommandException $exception) {
        $staleBlocked = ($exception->getDetail()['reason'] ?? '') === 'card_rule_state_update_conflict';
    }
    ok('stale concurrent state version is rejected by optimistic compare-and-swap',
        $staleBlocked
        && (int)Db::name('cashier_v3_card_rule_state')->where('id', $race['stateId'])->value('state_version') === 2,
        '', 'CARD-RULE-MYSQL-07');
    // 作废必须同步权威次数；任选种类不解除历史选定，时间卡不能虚增次数或有效期。
    foreach (['normal', 'choice_kind', 'choice_count', 'time'] as $index => $type) {
        $h = $holderBase + 100 + $index; $d = $detailBase + 100 + $index;
        $restore = insertRuleFixture($suffix . '-restore-' . $type, $type, $h, $memberId, 1, 2, [[
            'detailId'=>$d, 'projectId'=>600+$index, 'totalTimes'=>2, 'remainingTimes'=>0,
            'writeoffAmountCents'=>0, 'configuredPriceCents'=>1000,
            'selectionStatus'=>$type === 'choice_kind' ? 'selected' : 'not_applicable',
        ]], $now-100, $now+100);
        $fixtureStateIds[]=$restore['stateId'];
        Db::name('cashier_v3_card_rule_state')->where('id',$restore['stateId'])->update([
            'shared_remaining_times'=>0, 'status'=>$type === 'time' ? 'active' : 'exhausted',
        ]);
        $returned=$service->restoreServiceTimesInTx('0',$h,$d,2,$now);
        $snapshot=$service->snapshotForHolder('0',$h,true);
        $remaining=$type === 'choice_count' ? $snapshot['state']['shared_remaining_times'] : $snapshot['components'][0]['remaining_times'];
        ok('void restores exhausted ' . $type . ' without changing validity/selection',
            $returned === ($type === 'time' ? 0 : 2)
            && (int)$remaining === ($type === 'time' ? 0 : 2)
            && $snapshot['state']['status'] === 'active'
            && (int)$snapshot['state']['valid_through'] === $now+100
            && ($type !== 'choice_kind' || $snapshot['components'][0]['selection_status'] === 'selected'),
            '', 'CARD-RULE-RESTORE-' . $type);
        if ($type !== 'time') {
            $blocked=false;
            try { $service->restoreServiceTimesInTx('0',$h,$d,1,$now); }
            catch (CashierV3CommandException $e) { $blocked=($e->getDetail()['reason'] ?? '') === 'card_rule_restore_overflow'; }
            ok('restore cannot exceed original total ' . $type,$blocked,'','CARD-RULE-RESTORE-LIMIT-' . $type);
        }
    }
    ok('legacy card without a rule retains legacy restore path',
        $service->restoreServiceTimesInTx('0',$holderBase+999,$detailBase+999,1,$now) === null,
        '', 'CARD-RULE-RESTORE-LEGACY');
} finally {
    Db::rollback();
}

ok('integration fixture transaction rolls back without persistent card-rule rows',
    (int)Db::name('cashier_v3_card_rule_state')->whereIn('id', $fixtureStateIds)->count() === 0,
    '', 'CARD-RULE-MYSQL-08');

finish('CARD_RULE_ENTITLEMENT_AUTHORITY_MYSQL56');

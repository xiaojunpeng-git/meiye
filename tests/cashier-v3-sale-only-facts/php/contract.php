<?php

$root = dirname(__DIR__, 3);
$cashier = $root . '/后端代码/app/services/cashier/v3';
require $cashier . '/CashierV3BusinessDocumentNumberServices.php';
require $cashier . '/settlement/CashierV3CheckoutSettlementContractException.php';
require $cashier . '/settlement/CashierV3CheckoutSettlementCanonicalizer.php';
require $cashier . '/settlement/CashierV3CheckoutVerifiedSourceSet.php';
require $cashier . '/order/settlement/CashierV3SalesOrderAuthorityException.php';
require $cashier . '/order/settlement/CashierV3SalesOrderIdFactory.php';
require $cashier . '/order/settlement/CashierV3SalesOrderPlanV1.php';
require $cashier . '/settlement/payment/CashierV3PaymentCollectionAuthorityException.php';
require $cashier . '/settlement/payment/CashierV3PaymentCollectionIdFactory.php';
require $cashier . '/settlement/payment/CashierV3PaymentCollectionPlanV1.php';
require $cashier . '/settlement/CashierV3CheckoutDebtAuthorityServices.php';
require $cashier . '/fact/CashierV3CheckoutFactContractException.php';
require $cashier . '/fact/CashierV3CheckoutFactIdFactory.php';
require $cashier . '/fact/CashierV3CheckoutFactPlanV1.php';
require $cashier . '/fact/CashierV3SaleOnlyFactAssembler.php';
require $root . '/tests/cashier-v3-sales-order-authority/php/fixture.php';
require __DIR__ . '/fixture.php';

use app\services\cashier\v3\fact\CashierV3CheckoutFactContractException;
use app\services\cashier\v3\fact\CashierV3CheckoutFactIdFactory;
use app\services\cashier\v3\fact\CashierV3SaleOnlyFactAssembler;

$passed = 0;
$failed = 0;
function saleOnlyFactOk(string $name, bool $condition): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
}

function saleOnlyFactReason(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3CheckoutFactContractException $exception) {
        return $exception->reason();
    }
    return '';
}

function saleOnlyFactBuild(
    array $aggregate,
    string $secret = 'sale-only-fact-local-secret-at-least-32-bytes',
    bool $salesReplay = false,
    bool $paymentReplay = false,
    ?array $balanceMutation = null
): array {
    $commandKey = salesOrderAuthorityIdempotency('CHECKOUT', 99);
    $salesOrder = saleOnlyFactSalesOrder($aggregate, $commandKey, $secret);
    $payment = saleOnlyFactPaymentCollection($aggregate, $salesOrder, $commandKey, $secret);
    $plan = CashierV3SaleOnlyFactAssembler::assemble(
        $aggregate,
        $salesOrder,
        saleOnlyFactSalesResult($salesOrder, $salesReplay),
        $payment,
        saleOnlyFactPaymentResult($payment, $paymentReplay),
        saleOnlyFactEvent($salesOrder, $commandKey),
        $commandKey,
        $secret,
        $balanceMutation
    );
    return [$plan, $salesOrder, $payment];
}

$aggregate = saleOnlyFactLockedAggregate();
[$plan, $salesOrder, $paymentCollection] = saleOnlyFactBuild($aggregate);
$rows = $plan->rows();
$context = $plan->context();
$factIds = new CashierV3CheckoutFactIdFactory(
    'sale-only-fact-local-secret-at-least-32-bytes'
);

saleOnlyFactOk('balance facts have stable identities linked to the V3 order and ledger row',
    preg_match(
        '/^CFB-[0-9a-f]{40}$/D',
        $factIds->balanceFactId('tenant-1', 'CSO-order-1', 'ledger-901')
    ) === 1
        && $factIds->balanceFactId('tenant-1', 'CSO-order-1', 'ledger-901')
            === $factIds->balanceFactId('tenant-1', 'CSO-order-1', 'ledger-901')
        && strpos(
            $factIds->balanceNaturalKey('tenant-1', 'CSO-order-1', 'ledger-901'),
            'checkout_fact:balance_changed:v1:'
        ) === 0);

saleOnlyFactOk('one sale fact is produced for every formal sales-order line',
    count($rows['sale']) === count($salesOrder->lines())
        && array_column($rows['sale'], 'source_line_id')
            === array_column($salesOrder->lines(), 'order_line_id'));
saleOnlyFactOk('one payment fact is produced for every formal collection',
    count($rows['payment']) === count($paymentCollection->collections())
        && array_column($rows['payment'], 'source_line_id')
            === array_column($paymentCollection->collections(), 'collection_id')
        && array_column($rows['payment'], 'payment_method') === ['wechat', 'alipay']);
saleOnlyFactOk('balance consumption and labor facts stay outside this slice',
    $rows['balance'] === []
        && count($rows['performance']) === 1
        && $rows['performance'][0]['performance_type'] === 'actual_performance_recorded');
saleOnlyFactOk('no-salesperson actual performance equals seven-method cash total',
    $rows['performance'][0]['employee_id'] === 0
        && $rows['performance'][0]['amount_cents'] === 8500
        && $rows['performance'][0]['allocation_base_amount_cents'] === 8500
        && array_sum(array_column($rows['payment'], 'amount_cents')) === 8500);
saleOnlyFactOk('event authority owns business date and all three timestamps',
    $context['business_event_no'] === 'EV-0123456789abcdef0123456789abcdef'
        && $context['business_date'] === '2026-07-29'
        && $context['occurred_at'] === 1785283250
        && $context['settled_at'] === 1785283260
        && $context['recorded_at'] === 1785283261);
saleOnlyFactOk('fact ids and natural keys are stable HMAC identities',
    preg_match('/^CFS-[0-9a-f]{40}$/D', $rows['sale'][0]['fact_id']) === 1
        && preg_match('/^CFP-[0-9a-f]{40}$/D', $rows['payment'][0]['fact_id']) === 1
        && preg_match('/^CFA-[0-9a-f]{40}$/D', $rows['performance'][0]['fact_id']) === 1
        && preg_match('/^checkout_fact:payment_collected:v1:[0-9a-f]{64}$/D', $rows['payment'][0]['natural_key']) === 1);

$mixedAggregate = saleOnlyFactMixedLockedAggregate();
[$mixedPlan, $mixedSalesOrder, $mixedPaymentCollection] = saleOnlyFactBuild($mixedAggregate);
$mixedRows = $mixedPlan->rows();
saleOnlyFactOk('mixed checkout writes sales facts only for formal sale lines',
    $mixedSalesOrder->header()['composition'] === 'mixed'
        && $mixedPaymentCollection->composition() === 'mixed'
        && count($mixedRows['sale']) === count($mixedSalesOrder->lines())
        && count($mixedRows['sale']) === 3
        && !in_array(
            (string)$mixedAggregate['lines'][3]['line_id'],
            array_column($mixedRows['sale'], 'source_line_id'),
            true
        ));
saleOnlyFactOk('mixed checkout payment and actual-performance facts keep sale settlement totals',
    count($mixedRows['payment']) === 2
        && array_sum(array_column($mixedRows['payment'], 'amount_cents')) === 8500
        && count($mixedRows['performance']) === 1
        && $mixedRows['performance'][0]['amount_cents'] === 8500);

$balanceAggregate = saleOnlyFactLockedAggregate();
$balanceAggregate['request']['member_id'] = 9;
$balanceAggregate['request']['member_name_snapshot'] = '会员 A';
$balanceAggregate['request']['selected_payment_amount_cents'] = 5000;
$balanceAggregate['request']['cash_performance_amount_cents'] = 5000;
$balanceAggregate['request']['balance_deduction_amount_cents'] = 3500;
$balanceAggregate['request']['balance_authority_key'] = 'member_balance:9';
$balanceAggregate['request']['balance_account_id'] = '9';
$balanceAggregate['request']['balance_account_version'] = 6;
foreach ($balanceAggregate['lines'] as &$balanceLine) {
    $balanceLine['member_id'] = 9;
}
unset($balanceLine);
$balanceAggregate['payments'] = [saleOnlyFactPayment(1, 'wechat', 5000, 9)];
$balanceMutation = [
    'authorityKey' => 'member_balance:9',
    'accountId' => '9',
    'memberId' => 9,
    'accountVersionBefore' => 6,
    'accountVersionAfter' => 7,
    'before' => ['principalCents' => 10000, 'giftCents' => 0, 'totalCents' => 10000],
    'change' => ['principalCents' => -3500, 'giftCents' => 0, 'totalCents' => -3500],
    'after' => ['principalCents' => 6500, 'giftCents' => 0, 'totalCents' => 6500],
    'deductedTotalCents' => 3500,
    'ledgerId' => 901,
];
[$balancePlan, $balanceSalesOrder, $balancePaymentCollection] = saleOnlyFactBuild(
    $balanceAggregate,
    'sale-only-fact-local-secret-at-least-32-bytes',
    false,
    false,
    $balanceMutation
);
$balanceRows = $balancePlan->rows();
saleOnlyFactOk('balance checkout writes one real balance change fact without increasing cash performance',
    count($balanceRows['balance']) === 1
    && $balanceRows['balance'][0]['balance_change_type'] === 'order_payment'
    && $balanceRows['balance'][0]['principal_delta_cents'] === -3500
    && $balanceRows['balance'][0]['account_version'] === 7
    && count($balanceRows['payment']) === 1
    && $balanceRows['performance'][0]['amount_cents'] === 5000
    && $balancePaymentCollection->batch()['collected_amount_cents'] === 5000);

$debtAggregate = saleOnlyFactLockedAggregate();
$debtAggregate['request']['member_id'] = 9;
$debtAggregate['request']['member_name_snapshot'] = '会员 A';
$debtAggregate['request']['selected_payment_amount_cents'] = 5000;
$debtAggregate['request']['cash_performance_amount_cents'] = 5000;
$debtAggregate['request']['debt_amount_cents'] = 3500;
$debtAggregate['request']['debt_authority_key'] = 'checkout-debt-policy:store:8';
$debtAggregate['request']['debt_policy_version'] = 1;
foreach ($debtAggregate['lines'] as &$debtLine) {
    $debtLine['member_id'] = 9;
}
unset($debtLine);
$debtAggregate['payments'] = [saleOnlyFactPayment(1, 'wechat', 5000, 9)];
[$debtPlan, $debtSalesOrder, $debtPaymentCollection] = saleOnlyFactBuild($debtAggregate);
$debtRows = $debtPlan->rows();
saleOnlyFactOk('debt checkout keeps full sales while payment and performance use collected cash only',
    array_sum(array_column($debtRows['sale'], 'sale_amount_cents')) === 8500
    && count($debtRows['payment']) === 1
    && array_sum(array_column($debtRows['payment'], 'amount_cents')) === 5000
    && count($debtRows['performance']) === 1
    && $debtRows['performance'][0]['amount_cents'] === 5000
    && $debtPaymentCollection->batch()['collected_amount_cents'] === 5000);

[$samePlan] = saleOnlyFactBuild($aggregate);
saleOnlyFactOk('identical locked authorities produce identical fact plan',
    $samePlan->fingerprint() === $plan->fingerprint()
        && $samePlan->rows() === $plan->rows());
[$replayedPlan] = saleOnlyFactBuild($aggregate, 'sale-only-fact-local-secret-at-least-32-bytes', true, true);
saleOnlyFactOk('immutable authority-writer replay returns the same fact identities',
    $replayedPlan->fingerprint() === $plan->fingerprint()
        && $replayedPlan->rows() === $plan->rows());
[$otherSecretPlan] = saleOnlyFactBuild($aggregate, 'different-sale-only-fact-secret-at-least-32-bytes');
saleOnlyFactOk('different server namespace changes identity but not business amounts',
    $otherSecretPlan->rows()['sale'][0]['fact_id'] !== $rows['sale'][0]['fact_id']
        && array_sum(array_column($otherSecretPlan->rows()['sale'], 'sale_amount_cents')) === 8500);

$commandKey = salesOrderAuthorityIdempotency('CHECKOUT', 99);
$sales = saleOnlyFactSalesOrder($aggregate, $commandKey, 'sale-only-fact-local-secret-at-least-32-bytes');
$payments = saleOnlyFactPaymentCollection(
    $aggregate,
    $sales,
    $commandKey,
    'sale-only-fact-local-secret-at-least-32-bytes'
);
$salesResult = saleOnlyFactSalesResult($sales);
$paymentResult = saleOnlyFactPaymentResult($payments);
$event = saleOnlyFactEvent($sales, $commandKey);
$assemble = static function (
    array $candidateAggregate,
    array $candidateSalesResult,
    array $candidatePaymentResult,
    array $candidateEvent,
    string $candidateCommand = ''
) use ($sales, $payments, $commandKey) {
    return CashierV3SaleOnlyFactAssembler::assemble(
        $candidateAggregate,
        $sales,
        $candidateSalesResult,
        $payments,
        $candidatePaymentResult,
        $candidateEvent,
        $candidateCommand === '' ? $commandKey : $candidateCommand,
        'sale-only-fact-local-secret-at-least-32-bytes'
    );
};

$forged = $aggregate;
$forged['clientPayload'] = ['amount' => 1];
saleOnlyFactOk('client-shaped aggregate input is rejected',
    saleOnlyFactReason(static function () use ($assemble, $forged, $salesResult, $paymentResult, $event): void {
        $assemble($forged, $salesResult, $paymentResult, $event);
    }) === 'sale_only_fact_locked_aggregate_shape_invalid');

foreach ([
    'composition' => ['field' => 'composition', 'value' => 'mixed', 'reason' => 'sale_only_fact_composition_required'],
    'balance' => ['field' => 'balance_deduction_amount_cents', 'value' => 1, 'reason' => 'sale_only_fact_balance_authority_incomplete'],
    'debt without authority' => [
        'field' => 'debt_amount_cents',
        'value' => 1,
        'reason' => 'sale_only_fact_debt_authority_invalid',
    ],
    'entitlement' => ['field' => 'entitlement_actual_amount_cents', 'value' => 1, 'reason' => 'sale_only_fact_composition_required'],
] as $name => $case) {
    $candidate = $aggregate;
    $candidate['request'][$case['field']] = $case['value'];
    saleOnlyFactOk($name . ' checkout is rejected',
        saleOnlyFactReason(static function () use ($assemble, $candidate, $salesResult, $paymentResult, $event): void {
            $assemble($candidate, $salesResult, $paymentResult, $event);
        }) === $case['reason']);
}

$entitlementLine = $aggregate;
$entitlementLine['lines'][] = salesOrderAuthorityEntitlementLine(4, 9);
saleOnlyFactOk('sale-only authority cannot append an entitlement line without mixed composition',
    saleOnlyFactReason(static function () use ($assemble, $entitlementLine, $salesResult, $paymentResult, $event): void {
        $assemble($entitlementLine, $salesResult, $paymentResult, $event);
    }) === 'sale_only_fact_composition_required');

$badSalesResult = $salesResult;
$badSalesResult['planFingerprint'] = str_repeat('0', 64);
saleOnlyFactOk('sales-order receipt drift is rejected',
    saleOnlyFactReason(static function () use ($assemble, $aggregate, $badSalesResult, $paymentResult, $event): void {
        $assemble($aggregate, $badSalesResult, $paymentResult, $event);
    }) === 'sale_only_fact_sales_order_result_mismatch');

$badPaymentResult = $paymentResult;
$badPaymentResult['collectionCount']--;
saleOnlyFactOk('payment-collection receipt drift is rejected',
    saleOnlyFactReason(static function () use ($assemble, $aggregate, $salesResult, $badPaymentResult, $event): void {
        $assemble($aggregate, $salesResult, $badPaymentResult, $event);
    }) === 'sale_only_fact_payment_result_mismatch');

$badEvent = $event;
$badEvent['event_type'] = 'payment.collected';
saleOnlyFactOk('an unrelated event cannot authorize checkout facts',
    saleOnlyFactReason(static function () use ($assemble, $aggregate, $salesResult, $paymentResult, $badEvent): void {
        $assemble($aggregate, $salesResult, $paymentResult, $badEvent);
    }) === 'sale_only_fact_event_authority_mismatch');

$badEventName = $event;
$badEventName['aggregate_name_snapshot'] = 'forged-order-no';
saleOnlyFactOk('event aggregate name drift cannot authorize checkout facts',
    saleOnlyFactReason(static function () use ($assemble, $aggregate, $salesResult, $paymentResult, $badEventName): void {
        $assemble($aggregate, $salesResult, $paymentResult, $badEventName);
    }) === 'sale_only_fact_event_authority_mismatch');

$badTime = $event;
$badTime['recorded_at']++;
saleOnlyFactOk('event and authority time drift is rejected',
    saleOnlyFactReason(static function () use ($assemble, $aggregate, $salesResult, $paymentResult, $badTime): void {
        $assemble($aggregate, $salesResult, $paymentResult, $badTime);
    }) === 'sale_only_fact_event_authority_mismatch');

saleOnlyFactOk('a different final command key cannot reuse locked plans',
    saleOnlyFactReason(static function () use ($assemble, $aggregate, $salesResult, $paymentResult, $event): void {
        $assemble(
            $aggregate,
            $salesResult,
            $paymentResult,
            $event,
            salesOrderAuthorityIdempotency('CHECKOUT', 100)
        );
    }) === 'sale_only_fact_sales_order_plan_mismatch');

saleOnlyFactOk('short server identity secret is rejected',
    saleOnlyFactReason(static function () use (
        $aggregate,
        $sales,
        $salesResult,
        $payments,
        $paymentResult,
        $event,
        $commandKey
    ): void {
        CashierV3SaleOnlyFactAssembler::assemble(
            $aggregate,
            $sales,
            $salesResult,
            $payments,
            $paymentResult,
            $event,
            $commandKey,
            'short'
        );
    }) === 'checkout_fact_server_id_secret_invalid');

echo "SALE_ONLY_FACT_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

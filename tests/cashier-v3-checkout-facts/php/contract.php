<?php
declare(strict_types=1);

$backend = getenv('CHECKOUT_FACT_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require_once $backend . '/app/services/cashier/v3/fact/CashierV3CheckoutFactContractException.php';
require_once $backend . '/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';
require_once __DIR__ . '/fixture.php';

use app\services\cashier\v3\fact\CashierV3CheckoutFactContractException;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;

$passed = 0;
$failed = 0;
function factOk(string $name, bool $condition): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
}
function factReason(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3CheckoutFactContractException $exception) {
        return $exception->reason();
    } catch (Throwable $exception) {
        return 'UNEXPECTED:' . get_class($exception) . ':' . $exception->getMessage();
    }
    return '';
}

$plan = CashierV3CheckoutFactPlanV1::fromInternalAuthority(checkoutFactInput());
$rows = $plan->rows();
factOk('v1 emits four explicit fact domains', array_keys($rows) === ['sale', 'payment', 'balance', 'performance']);
factOk('all money values remain integer cents',
    is_int($rows['sale'][0]['sale_amount_cents'])
        && is_int($rows['payment'][0]['amount_cents'])
        && is_int($rows['balance'][0]['principal_delta_cents'])
        && is_int($rows['performance'][0]['amount_cents']));
factOk('seven payment methods are fixed and authoritative',
    CashierV3CheckoutFactPlanV1::paymentMethods() === [
        'unionpay','wechat','alipay','dianping_voucher','douyin_voucher',
        'partner_collection','other_collection',
    ]);
factOk('four performance facts are supported',
    CashierV3CheckoutFactPlanV1::performanceTypes() === [
        'sales_performance_allocated','actual_performance_recorded',
        'consumption_performance_recorded','labor_performance_allocated',
    ]);
factOk('actual performance is a persisted row with materialized amount',
    count(array_filter($rows['performance'], static function (array $row): bool {
        return $row['performance_type'] === 'actual_performance_recorded'
            && $row['amount_cents'] === 8000;
    })) === 1);
factOk('person type weight and rule are historical columns',
    $rows['performance'][1]['employee_type_snapshot'] === 'partner'
        && $rows['performance'][1]['employee_type_authority_version'] === 5
        && $rows['performance'][1]['allocation_weight_numerator'] === 1
        && $rows['performance'][1]['rule_version_snapshot'] === 'v3');
factOk('plan fingerprint is stable for identical authority',
    $plan->fingerprint() === CashierV3CheckoutFactPlanV1::fromInternalAuthority(checkoutFactInput())->fingerprint());

$oldCard = checkoutFactInput();
$oldCard['paymentFacts'][0]['paymentMethod'] = 'old_card_entry';
factOk('old card entry is rejected as payment fact',
    factReason(static function () use ($oldCard): void {
        CashierV3CheckoutFactPlanV1::fromInternalAuthority($oldCard);
    }) === 'old_card_entry_payment_fact_forbidden');

$floatMoney = checkoutFactInput();
$floatMoney['paymentFacts'][0]['amountCents'] = 100.5;
factOk('floating point money is rejected',
    factReason(static function () use ($floatMoney): void {
        CashierV3CheckoutFactPlanV1::fromInternalAuthority($floatMoney);
    }) === 'payment_amount_invalid');

$missingActual = checkoutFactInput();
$missingActual['performanceFacts'] = array_values(array_filter(
    $missingActual['performanceFacts'],
    static function (array $row): bool { return $row['performanceType'] !== 'actual_performance_recorded'; }
));
factOk('cash facts require materialized actual performance',
    factReason(static function () use ($missingActual): void {
        CashierV3CheckoutFactPlanV1::fromInternalAuthority($missingActual);
    }) === 'actual_performance_fact_required');

$wrongActual = checkoutFactInput();
$wrongActual['performanceFacts'][2]['amountCents'] = 7999;
factOk('query-time subtraction cannot replace wrong actual fact',
    factReason(static function () use ($wrongActual): void {
        CashierV3CheckoutFactPlanV1::fromInternalAuthority($wrongActual);
    }) === 'actual_performance_materialized_amount_invalid');

$unclassified = checkoutFactInput();
$unclassified['performanceFacts'][0]['employeeTypeSnapshot'] = '';
$unclassified['performanceFacts'][0]['employeeTypeAuthorityVersion'] = 0;
factOk('allocated performance requires classified person snapshot',
    factReason(static function () use ($unclassified): void {
        CashierV3CheckoutFactPlanV1::fromInternalAuthority($unclassified);
    }) === 'performance_employee_snapshot_required');

$reversal = CashierV3CheckoutFactPlanV1::fromInternalAuthority(checkoutFactReversalInput());
factOk('reversal plan is negative immutable facts linked to originals',
    $reversal->rows()['payment'][0]['fact_direction'] === 'reversal'
        && $reversal->rows()['payment'][0]['amount_cents'] === -10000
        && $reversal->rows()['payment'][0]['reversal_of'] === 'FACT-PAYMENT-WECHAT'
        && $reversal->rows()['performance'][1]['amount_cents'] === -8000);

$extra = checkoutFactInput();
$extra['context']['clientAmount'] = 1;
factOk('unknown orchestrator or client fields are rejected',
    factReason(static function () use ($extra): void {
        CashierV3CheckoutFactPlanV1::fromInternalAuthority($extra);
    }) === 'fact_shape_invalid');

echo "CHECKOUT_FACT_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

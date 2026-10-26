<?php
declare(strict_types=1);

require_once __DIR__ . '/mysql-bootstrap.php';
require_once __DIR__ . '/fixture.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\fact\CashierV3CheckoutFactContractException;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use app\services\cashier\v3\fact\ThinkPhpCashierV3CheckoutFactRepository;
use think\facade\Db;

checkoutFactMysqlBoot();
$passed = 0;
$failed = 0;
function factMysqlOk(string $name, bool $condition): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
}
function factMysqlReason(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3CheckoutFactContractException $exception) {
        return $exception->reason();
    } catch (Throwable $exception) {
        return get_class($exception) . ':' . $exception->getMessage();
    }
    return '';
}

$repository = new ThinkPhpCashierV3CheckoutFactRepository();
$operator = checkoutFactOperator();
$scope = checkoutFactScope();
$forwardInput = checkoutFactInput();
$plan = CashierV3CheckoutFactPlanV1::fromInternalAuthority($forwardInput);
checkoutFactReset();
$missingEvent = factMysqlReason(static function () use ($repository, $plan, $operator, $scope): void {
    Db::transaction(static function () use ($repository, $plan, $operator, $scope): void {
        $repository->persistInTx($plan, $operator, $scope);
    });
});
factMysqlOk('facts cannot be written without the same transaction business event',
    $missingEvent === 'checkout_fact_business_event_missing');
checkoutFactInsertEvent($forwardInput);

$written = Db::transaction(static function () use ($repository, $plan, $operator, $scope): array {
    return $repository->persistInTx($plan, $operator, $scope);
});
factMysqlOk('all fact domains commit in one caller-owned transaction',
    $written['inserted'] === ['sale' => 1, 'payment' => 1, 'balance' => 1, 'performance' => 5]
        && (int)Db::name('cashier_v3_sale_fact')->count() === 1
        && (int)Db::name('cashier_v3_payment_fact')->count() === 1
        && (int)Db::name('cashier_v3_balance_fact')->count() === 1
        && (int)Db::name('cashier_v3_performance_fact')->count() === 5);

$replay = Db::transaction(static function () use ($repository, $plan, $operator, $scope): array {
    return $repository->persistInTx($plan, $operator, $scope);
});
factMysqlOk('same natural keys and immutable payload replay without writes',
    array_sum($replay['inserted']) === 0
        && $replay['replayed'] === ['sale' => 1, 'payment' => 1, 'balance' => 1, 'performance' => 5]);

$conflictInput = checkoutFactInput();
$conflictInput['paymentFacts'][0]['collectionReference'] = 'WX-TAMPERED';
$conflictPlan = CashierV3CheckoutFactPlanV1::fromInternalAuthority($conflictInput);
$conflict = factMysqlReason(static function () use ($repository, $conflictPlan, $operator, $scope): void {
    Db::transaction(static function () use ($repository, $conflictPlan, $operator, $scope): void {
        $repository->persistInTx($conflictPlan, $operator, $scope);
    });
});
factMysqlOk('same natural key with changed immutable payload conflicts',
    $conflict === 'fact_natural_key_payload_conflict'
        && (string)Db::name('cashier_v3_payment_fact')->value('collection_reference') === 'WX-9001');

$tamper = factMysqlReason(static function () use ($repository, $plan, $operator, $scope): void {
    Db::transaction(static function () use ($repository, $plan, $operator, $scope): void {
        Db::name('cashier_v3_payment_fact')
            ->where('fact_id', 'FACT-PAYMENT-WECHAT')
            ->update(['collection_reference' => 'DB-TAMPER-WITH-SAME-FINGERPRINT']);
        $repository->persistInTx($plan, $operator, $scope);
    });
});
factMysqlOk('replay compares every immutable column rather than trusting stored hash alone',
    $tamper === 'fact_natural_key_payload_conflict'
        && (string)Db::name('cashier_v3_payment_fact')->value('collection_reference') === 'WX-9001');

$denied = factMysqlReason(static function () use ($repository, $plan, $operator): void {
    Db::transaction(static function () use ($repository, $plan, $operator): void {
        $repository->persistInTx(
            $plan,
            $operator,
            checkoutFactScope(7, CashierV3DataScopeContext::MODE_NONE)
        );
    });
});
factMysqlOk('NONE DataScope is fail closed', $denied === 'checkout_fact_data_scope_denied');

$reversalInput = checkoutFactReversalInput();
$reversalPlan = CashierV3CheckoutFactPlanV1::fromInternalAuthority($reversalInput);
checkoutFactInsertEvent($reversalInput);
$reversed = Db::transaction(static function () use ($repository, $reversalPlan, $operator, $scope): array {
    return $repository->persistInTx($reversalPlan, $operator, $scope);
});
factMysqlOk('reversals append negative facts and preserve originals',
    $reversed['inserted']['payment'] === 1
        && $reversed['inserted']['performance'] === 2
        && (int)Db::name('cashier_v3_payment_fact')->count() === 2
        && (int)Db::name('cashier_v3_payment_fact')->sum('amount_cents') === 0
        && (int)Db::name('cashier_v3_payment_fact')->where('fact_direction', 'forward')->count() === 1);

$overshootInput = checkoutFactReversalInput();
$overshootInput['paymentFacts'][0]['factId'] = 'FACT-PAYMENT-WECHAT-REV-OVER';
$overshootInput['paymentFacts'][0]['naturalKey'] = 'checkout:ORDER-9001:payment-wechat:reversal:over';
$overshootInput['paymentFacts'][0]['amountCents'] = -20000;
$overshootInput['performanceFacts'][1]['amountCents'] = -18000;
$overshootPlan = CashierV3CheckoutFactPlanV1::fromInternalAuthority($overshootInput);
$overshoot = factMysqlReason(static function () use ($repository, $overshootPlan, $operator, $scope): void {
    Db::transaction(static function () use ($repository, $overshootPlan, $operator, $scope): void {
        $repository->persistInTx($overshootPlan, $operator, $scope);
    });
});
factMysqlOk('cumulative reversal cannot exceed original amount',
    $overshoot === 'reversal_amount_exceeds_original');

echo "CHECKOUT_FACT_MYSQL_INTEGRATION passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

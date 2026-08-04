<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\settlement\CashierV3DebtRepaymentIdFactory;

$factory = new CashierV3DebtRepaymentIdFactory('debt-repayment-contract-secret');
$draft = $factory->draftId('180', 33, 'ws:118:338:SC-1');
$repayment = $factory->repaymentId('180', 33, 'DEBT-REPAY-00000000-0000-4000-8000-000000000001');
$collection = $factory->collectionId($repayment, 1);
$passed = preg_match('/^DRD-[a-f0-9]{40}$/D', $draft) === 1
    && preg_match('/^DRP-[a-f0-9]{40}$/D', $repayment) === 1
    && preg_match('/^DRC-[a-f0-9]{40}$/D', $collection) === 1
    && $repayment === $factory->repaymentId('180', 33, 'DEBT-REPAY-00000000-0000-4000-8000-000000000001')
    && $repayment !== $factory->repaymentId('180', 34, 'DEBT-REPAY-00000000-0000-4000-8000-000000000001')
    && preg_match('/^RP-20260802-[A-F0-9]{20}$/D', $factory->repaymentNo('180', 33, 'DEBT-REPAY-00000000-0000-4000-8000-000000000001', '2026-08-02')) === 1;

echo $passed ? "PASS debt repayment identifiers are stable and scope-bound\n" : "FAIL debt repayment identifiers\n";
exit($passed ? 0 : 1);

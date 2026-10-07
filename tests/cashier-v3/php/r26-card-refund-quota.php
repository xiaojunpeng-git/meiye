<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../后端代码/app/services/cashier/v3/order/CashierV3CardRefundQuotaPolicy.php';

use app\services\cashier\v3\order\CashierV3CardRefundQuotaPolicy;

function quotaCase(string $name, array $expected, int $purchase, int $total, int $used, int $returned, bool $wholeYuan = true): void
{
    $actual = CashierV3CardRefundQuotaPolicy::afterReturn($purchase, $total, $used, $returned, $wholeYuan);
    if ($actual !== $expected) {
        throw new RuntimeException($name . ': ' . json_encode(['expected' => $expected, 'actual' => $actual]));
    }
    echo 'PASS ' . $name . PHP_EOL;
}

quotaCase('450 refund keeps five original-price uses', [
    'remainingValueCents' => 55000, 'usableTimes' => 5,
    'usableValueCents' => 50000, 'unusableTailCents' => 5000,
], 100000, 10, 0, 45000);
quotaCase('two uses remain historical and three are still usable', [
    'remainingValueCents' => 35000, 'usableTimes' => 3,
    'usableValueCents' => 30000, 'unusableTailCents' => 5000,
], 100000, 10, 2, 45000);
quotaCase('original final-use remainder is never redistributed', [
    'remainingValueCents' => 54900, 'usableTimes' => 5,
    'usableValueCents' => 49500, 'unusableTailCents' => 5400,
], 99900, 10, 0, 45000);
quotaCase('full unused-value return leaves no uses', [
    'remainingValueCents' => 0, 'usableTimes' => 0,
    'usableValueCents' => 0, 'unusableTailCents' => 0,
], 100000, 10, 2, 80000);
quotaCase('cent-level legacy allocation preserves cents', [
    'remainingValueCents' => 55005, 'usableTimes' => 5,
    'usableValueCents' => 50000, 'unusableTailCents' => 5005,
], 100005, 10, 0, 45000, false);

try {
    CashierV3CardRefundQuotaPolicy::afterReturn(100000, 10, 2, 80001);
    throw new RuntimeException('over-refund was accepted');
} catch (InvalidArgumentException $exception) {
    echo 'PASS over-refund rejected' . PHP_EOL;
}

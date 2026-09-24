<?php

declare(strict_types=1);

$backend = getenv('CASHIER_V3_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\order\store\WriteOffOrderServices;

function reservationDebtCheck(string $label, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $label . PHP_EOL;
}

// This fixture mirrors the reported card: ¥6,980 for 30 uses with ¥5,480
// still unpaid. The paid ¥1,500 covers six whole uses, so a one-use
// reservation must not be rejected merely because the order still has debt.
$detail = [
    'id' => 3106913,
    'oid' => 1617982,
    'write_times' => 30,
    'write_surplus_times' => 30,
    'pay_price' => '6980.00',
    'debt_amount' => '0.00',
    'repaid_debt_amount' => '0.00',
    'is_gift' => 0,
    'cart_info' => '{}',
];
$order = ['id' => 1617982, 'uid' => 1003991, 'debt_amount' => '5480.00', 'repaid_debt_amount' => '0.00'];

// The calculation is pure; bypassing the framework-created DAO constructor
// keeps this boundary test deterministic while exercising the shared formula.
$writeoff = (new ReflectionClass(WriteOffOrderServices::class))->newInstanceWithoutConstructor();
$available = $writeoff->calcEffectiveWriteSurplusTimes($detail, 5480.00, $order, null, [$detail]);
$fullyBlocked = $writeoff->calcEffectiveWriteSurplusTimes($detail, 6980.00, $order, null, [$detail]);

reservationDebtCheck('reported partial debt leaves six usable times', $available === 6);
reservationDebtCheck('one reservation use is allowed by the partial-debt limit', $available >= 1);
reservationDebtCheck('debt covering the whole remaining amount still blocks use', $fullyBlocked === 0);

echo "RESERVATION_DEBT_LIMITED_WRITEOFF_CONTRACT=PASS\n";

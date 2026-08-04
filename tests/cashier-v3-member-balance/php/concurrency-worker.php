<?php
declare(strict_types=1);

require_once __DIR__ . '/mysql-bootstrap.php';

use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceContractException;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceWriterAdapter;
use think\facade\Db;

mbaMysqlBoot();
if ($argc !== 4) {
    fwrite(STDERR, "usage: worker-token command-key result-file\n");
    exit(2);
}
$token = (string)$argv[1];
$commandKey = (string)$argv[2];
$resultFile = (string)$argv[3];
Db::name('balance_test_barrier')->insert([
    'barrier_id' => 'member-balance-concurrency',
    'worker_token' => $token,
    'released' => 0,
]);
$deadline = microtime(true) + 15;
while (microtime(true) < $deadline) {
    $released = (int)Db::name('balance_test_barrier')
        ->where('barrier_id', 'member-balance-concurrency')
        ->where('worker_token', $token)
        ->value('released');
    if ($released === 1) {
        break;
    }
    usleep(20000);
}

$result = ['status' => 'unknown'];
try {
    $adapter = new CashierV3MemberBalanceWriterAdapter();
    $write = Db::transaction(function () use ($adapter, $commandKey): array {
        return $adapter->deductCheckoutInTx([
            'memberId' => 201,
            'expectedVersion' => 1,
            'amountCents' => 8000,
            'sourceOrderId' => 9201,
            'commandIdempotencyKey' => $commandKey,
            'paymentMode' => CashierV3MemberBalanceWriterAdapter::PAYMENT_MODE_COMBINED,
        ], mbaOperator(), mbaScope());
    });
    $result = ['status' => 'success', 'write' => $write];
} catch (CashierV3MemberBalanceContractException $exception) {
    $result = ['status' => 'failed', 'reason' => $exception->reason()];
} catch (Throwable $exception) {
    $result = [
        'status' => 'unexpected',
        'class' => get_class($exception),
        'message' => $exception->getMessage(),
    ];
}
file_put_contents($resultFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

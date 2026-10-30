<?php
declare(strict_types=1);

// Synthetic signed-transport boundary: no network request, merchant key, database, or money movement.
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../../后端代码/vendor/autoload.php';

use app\services\pay\SuixingPayCombinationRefundGateway;
use think\exception\ValidateException;

$method = new ReflectionMethod(SuixingPayCombinationRefundGateway::class, 'interpretQuery');
$receipt = ['merchant_id' => '399000000000001', 'refund_no' => 'SP_REFUND_001',
    'channel_order_no' => 'SP_ORIGINAL_001', 'amount_cents' => 100];
$body = ['bizCode' => '0000', 'ordNo' => 'SP_REFUND_001', 'origOrdNo' => 'SP_ORIGINAL_001',
    'mno' => '399000000000001', 'tranSts' => 'REFUNDSUC', 'refundAmount' => '1.00',
    'totalAmount' => '1.00', 'payType' => 'WECHAT', 'payWay' => '05', 'uuid' => 'refund-uuid'];
$query = static function (array $data, string $code = '0000') use ($method, $receipt): array {
    return $method->invoke(null, ['code' => $code, 'respData' => $data], $receipt);
};
$assert = static function (bool $okay, string $message): void {
    if (!$okay) throw new RuntimeException($message);
};
$reject = static function (array $data, string $message) use ($query): void {
    try {
        $query($data);
    } catch (ValidateException $expected) {
        return;
    }
    throw new RuntimeException($message);
};

$assert($query($body)['state'] === 'confirmed', 'Exact full refund not confirmed');
$pending = $body; $pending['tranSts'] = 'REFUNDING';
$assert($query($pending)['state'] === 'pending', 'In-progress refund closed');
$failed = $body; $failed['tranSts'] = 'REFUNDFAIL';
$assert($query($failed)['state'] === 'failed', 'Verified terminal failure not released');
$reject(array_replace($failed, ['refundAmount' => '2.00']), 'Mismatched failure amount released a refund');
$assert($query($body, '0002')['state'] === 'pending', 'Provider error closed a refund');
foreach ([
    ['refundAmount' => '2.00'], ['totalAmount' => '2.00'], ['ordNo' => 'other'],
    ['origOrdNo' => 'other'], ['mno' => 'other'], ['payType' => 'ALIPAY'],
    ['uuid' => ''],
] as $change) {
    $reject(array_replace($body, $change), 'Mismatched refund proof accepted: ' . array_key_first($change));
}
echo "PASS SuixingPay group refund requires exact original-channel full-refund proof\n";

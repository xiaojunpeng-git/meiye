<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\product\StoreProductServices;
use mohe\exceptions\AdminException;

$reflection = new ReflectionClass(StoreProductServices::class);
$service = $reflection->newInstanceWithoutConstructor();
$validator = Closure::bind(
    static function (StoreProductServices $target, $value, string $label): int {
        return $target->wholeYuanMoney($value, $label);
    },
    null,
    StoreProductServices::class
);
$normalizer = Closure::bind(
    static function (StoreProductServices $target, array $item, array $data): array {
        return $target->normalizeSkuWholeYuanMoney($item, $data);
    },
    null,
    StoreProductServices::class
);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert($validator($service, 88, '会员价') === 88, 'integer value should pass');
$assert($validator($service, '88', '会员价') === 88, 'integer string should pass');
$assert($validator($service, 0, '会员价') === 0, 'zero should pass');
$assert($validator($service, 100.0, '售价') === 100, 'integer float should normalize');
$assert($validator($service, '100.0', '售价') === 100, 'one-zero decimal should normalize');
$assert($validator($service, '100.00', '售价') === 100, 'two-zero decimal should normalize');

foreach (['88.5', '99.50', 88.5, '', '-1'] as $invalid) {
    try {
        $validator($service, $invalid, '规格【默认】的会员价');
        $failures[] = 'fractional or malformed value should fail: ' . var_export($invalid, true);
    } catch (AdminException $exception) {
        $assert(
            $exception->getMessage() === '规格【默认】的会员价必须填写整数金额',
            'validation should return the user-facing Chinese label'
        );
    }
}

$legacyHiddenPayload = $normalizer($service, [
    'suk' => '默认',
    'price' => '100.00',
    'cost' => '0.00',
    'ot_price' => '100.0',
    'settle_price' => 0,
    'vip_price' => '0.00',
    'brokerage' => '12.50',
    'brokerage_two' => '6.25',
    'level_price' => [['name' => '普通会员', 'price' => '100.00']],
], [
    'product_type' => 0,
    'is_vip' => 0,
    'is_brokerage' => 0,
    'is_sub' => 1,
    'level_type' => 1,
]);
$assert($legacyHiddenPayload['price'] === 100, '80558-style price should normalize to integer');
$assert($legacyHiddenPayload['vip_price'] === 0, 'disabled paid member price should normalize to zero');
$assert(
    $legacyHiddenPayload['brokerage'] === 0 && $legacyHiddenPayload['brokerage_two'] === 0,
    'disabled brokerage should normalize hidden values to zero'
);

try {
    $normalizer($service, [
        'suk' => '默认',
        'price' => 100,
        'vip_price' => '99.50',
    ], [
        'product_type' => 0,
        'is_vip' => 1,
        'is_brokerage' => 0,
        'is_sub' => 0,
        'level_type' => 1,
    ]);
    $failures[] = 'enabled fractional paid member price should fail';
} catch (AdminException $exception) {
    $assert(
        $exception->getMessage() === '规格【默认】的会员价必须填写整数金额',
        'enabled paid member price should retain whole-yuan validation'
    );
}

try {
    $normalizer($service, [
        'suk' => '默认',
        'price' => 100,
        'brokerage' => '10.00',
        'brokerage_two' => '5.50',
    ], [
        'product_type' => 0,
        'is_vip' => 0,
        'is_brokerage' => 1,
        'is_sub' => 1,
        'level_type' => 1,
    ]);
    $failures[] = 'enabled fractional custom brokerage should fail';
} catch (AdminException $exception) {
    $assert(
        $exception->getMessage() === '规格【默认】的二级返佣必须填写整数金额',
        'enabled custom brokerage should retain whole-yuan validation'
    );
}

$customLevelPayload = $normalizer($service, [
    'suk' => '默认',
    'price' => 100,
    'level_price' => [['name' => '普通会员', 'price' => '100.00', 'inputPrice' => '100.0']],
], [
    'product_type' => 0,
    'is_vip' => 0,
    'is_brokerage' => 0,
    'is_sub' => 0,
    'level_type' => 2,
]);
$assert($customLevelPayload['level_price'][0]['price'] === 100, 'enabled custom level price should normalize');
$assert($customLevelPayload['level_price'][0]['inputPrice'] === 100, 'custom level input projection should normalize');

try {
    $normalizer($service, [
        'suk' => '默认',
        'price' => 100,
        'level_price' => [['name' => '普通会员', 'price' => '99.50']],
    ], [
        'product_type' => 0,
        'is_vip' => 0,
        'is_brokerage' => 0,
        'is_sub' => 0,
        'level_type' => 2,
    ]);
    $failures[] = 'enabled fractional custom level price should fail';
} catch (AdminException $exception) {
    $assert(
        $exception->getMessage() === '规格【默认】的普通会员必须填写整数金额',
        'custom level price should use its Chinese level label'
    );
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "product whole-yuan backend validation: PASS\n";

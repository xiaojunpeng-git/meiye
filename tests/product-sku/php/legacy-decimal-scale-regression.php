<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\sku\StoreProductAttrServices;

if (!function_exists('app')) {
    function app(): object
    {
        return new class {
            public function make(string $class): object
            {
                return new class {
                    public function getSkuArray(array $where, string $field, string $key): array
                    {
                        return [];
                    }
                };
            }
        };
    }
}

$service = (new ReflectionClass(StoreProductAttrServices::class))->newInstanceWithoutConstructor();
$previousHandler = set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $result = $service->validateProductAttr(
        [[
            'value' => '规格',
            'detailValue' => '',
            'attrHidden' => '',
            'detail' => [['value' => '默认', 'pic' => '']],
        ]],
        [[
            'value1' => '默认',
            'detail' => ['规格' => '默认'],
            'pic' => 'https://example.test/legacy-sku.png',
            'price' => '698.00',
            'settle_price' => '0.00',
            'cost' => '0.00',
            'ot_price' => '0.00',
            'stock' => '0.00',
            'bar_code' => '',
            'weight' => 0,
            'volume' => 0,
            'brokerage' => 0,
            'brokerage_two' => 0,
            'code' => 0,
            'write_times' => 1,
            'write_valid' => 1,
            'days' => 0,
            'section_time' => [],
            // Legacy product SKU payloads do not contain decimal_scale.
        ]],
        78955
    );
} finally {
    restore_error_handler();
}

if (($result['valueGroup']['默认']['decimal_scale'] ?? null) !== 0) {
    fwrite(STDERR, "FAIL legacy SKU decimal_scale must default to integer scale\n");
    exit(1);
}

echo "legacy product SKU decimal_scale compatibility: PASS\n";

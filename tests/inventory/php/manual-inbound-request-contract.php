<?php
declare(strict_types=1);

$backend = getenv('INVENTORY_BACKEND_ROOT');
$backend = is_string($backend) && $backend !== ''
    ? rtrim($backend, '/')
    : __DIR__ . '/../../../后端代码';

require_once $backend . '/vendor/autoload.php';
require_once $backend . '/app/common.php';

use app\Request;

$passed = 0;
$failed = 0;

function inboundRequestAssert(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

$browserPayload = [
    'idempotency_key' => 'inbound-20260730-browser-json',
    'business_date' => '2026-07-30',
    'remark' => '浏览器 JSON 入库请求',
    'lines' => [[
        'product_id' => 1001,
        'sku_id' => 10011,
        'sku_unique' => 'sku-10011',
        'batch_no' => 'BATCH-20260730-001',
        'quantity' => '2',
        'unit_cost' => '35.00',
        'manufactured_date' => '2026-07-01',
        'expire_date' => '2027-07-01',
    ]],
];

$request = (new Request())
    ->withServer(['REQUEST_METHOD' => 'POST'])
    ->withHeader(['Content-Type' => 'application/json'])
    ->withInput((string)json_encode($browserPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$namedInput = $request->postMore([
    ['idempotency_key', ''], ['business_date', ''], ['remark', ''], ['lines', []],
], false, false);

inboundRequestAssert(
    'browser JSON reaches the manual inbound whitelist with named top-level fields',
    array_keys($namedInput) === ['idempotency_key', 'business_date', 'remark', 'lines']
        && $namedInput['idempotency_key'] === $browserPayload['idempotency_key']
        && $namedInput['business_date'] === $browserPayload['business_date']
        && $namedInput['lines'] === $browserPayload['lines']
);

$controllerSource = (string)file_get_contents(
    $backend . '/app/controller/store/product/inventory/InventoryManualInbound.php'
);
inboundRequestAssert(
    'manual inbound controller preserves named whitelist keys for the service',
    preg_match(
        '/postMore\(\s*\[\s*\[\'idempotency_key\', \'\'\],\s*\[\'business_date\', \'\'\],\s*\[\'remark\', \'\'\],\s*\[\'lines\', \[\]\],\s*\]\s*\)/s',
        $controllerSource
    ) === 1
    && strpos($controllerSource, '], true)') === false
);

echo "INVENTORY_MANUAL_INBOUND_REQUEST_RESULT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

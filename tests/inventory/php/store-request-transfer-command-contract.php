<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$requestController = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryStockRequest.php');
$transferController = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php');
$transferService = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php');
require $root . '/后端代码/vendor/autoload.php';

function normalizeCommand(object $service, array $input): array
{
    $method = new ReflectionMethod($service, 'normalize');
    $method->setAccessible(true);
    return $method->invoke($service, $input);
}

$checks = [
    'store request controller preserves the selected requester' => substr_count($requestController, "['requester_name','']") === 2,
    'store transfer controller forwards the target party contract' => strpos($transferController, "['target_party_type', 'STORE']") !== false,
    'transfer authority accepts the store-only target contract' => strpos($transferService, '$storeKeys = [\'idempotency_key\', \'business_date\', \'remark\', \'target_party_type\', \'target_store_id\', \'request_document_id\', \'lines\'];') !== false,
    'store transfer source remains server-derived rather than browser-supplied' => strpos($transferController, "['source_party_type'") === false && strpos($transferController, "['source_store_id'") === false,
];

try {
    $stockCommand = normalizeCommand(new \app\services\product\inventory\InventoryStockRequestServices(), [
        'idempotency_key' => 'request-contract-20260811', 'business_date' => '2026-08-11', 'remark' => '', 'requester_name' => '测试请货人', 'request_party_type' => 'STORE', 'request_party_id' => 1,
        'supply_party_type' => 'HQ', 'supply_party_id' => 0,
        'lines' => [['product_id' => 1, 'sku_id' => 2, 'sku_unique' => 'TEST-SKU', 'quantity' => '1']],
    ]);
    $checks['request normalizer accepts the controller-preserved requester'] = ($stockCommand['requesterName'] ?? '') === '测试请货人';
} catch (Throwable $exception) {
    $checks['request normalizer accepts the controller-preserved requester'] = false;
}

try {
    $transferCommand = normalizeCommand(new \app\services\product\inventory\InventoryCrossSubjectTransferServices(), [
        'idempotency_key' => 'transfer-contract-20260811', 'business_date' => '2026-08-11', 'remark' => '', 'target_party_type' => 'STORE', 'target_store_id' => 2, 'request_document_id' => 0,
        'lines' => [['product_id' => 1, 'sku_id' => 2, 'sku_unique' => 'TEST-SKU', 'quantity' => '1', 'request_line_id' => 0]],
    ]);
    $checks['transfer normalizer accepts the store controller payload'] = ($transferCommand['targetPartyType'] ?? '') === 'STORE';
} catch (Throwable $exception) {
    $checks['transfer normalizer accepts the store controller payload'] = false;
}

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
echo "STORE_REQUEST_TRANSFER_COMMAND_CONTRACT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

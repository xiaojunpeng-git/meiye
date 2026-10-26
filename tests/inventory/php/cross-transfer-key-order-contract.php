<?php
declare(strict_types=1);

/** No business writes: validate JSON ordering, stable fingerprints and strict schemas. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$service = (new ReflectionClass(app\services\product\inventory\InventoryCrossSubjectTransferServices::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($service, 'normalize');
$method->setAccessible(true);
$payload = ['idempotency_key' => 'r57-order-regression', 'business_date' => '2026-10-02', 'remark' => '', 'source_party_type' => 'HQ', 'source_store_id' => 0, 'target_party_type' => 'STORE', 'target_store_id' => 69, 'request_document_id' => 14, 'transfer_staff_id' => 0, 'lines' => [['product_id' => 1, 'sku_id' => 2, 'sku_unique' => 'test-sku', 'quantity' => '11', 'request_line_id' => 3]]];
$canonical = $method->invoke($service, $payload);
$reordered = array_reverse($payload, true);
$reordered['lines'][0] = array_reverse($reordered['lines'][0], true);
$result = $method->invoke($service, $reordered);
if ($canonical['fingerprint'] !== $result['fingerprint'] || $canonical['lines'] !== $result['lines']) throw new RuntimeException('Order changed normalized business identity.');
// The actual frontend spread puts source/staff fields after common target/lines fields.
$spread = array_diff_key($payload, array_flip(['source_party_type', 'source_store_id', 'transfer_staff_id'])) + ['source_party_type' => 'HQ', 'source_store_id' => 0, 'transfer_staff_id' => 0];
if ($method->invoke($service, $spread)['fingerprint'] !== $canonical['fingerprint']) throw new RuntimeException('Frontend spread rejected.');
foreach (['missing', 'extra', 'type', 'line-extra', 'line-missing', 'quantity'] as $case) {
    $bad = $payload;
    if ($case === 'missing') unset($bad['request_document_id']);
    if ($case === 'extra') $bad['untrusted'] = 1;
    if ($case === 'type') $bad['target_store_id'] = '69';
    if ($case === 'line-extra') $bad['lines'][0]['untrusted'] = 1;
    if ($case === 'line-missing') unset($bad['lines'][0]['sku_id']);
    if ($case === 'quantity') $bad['lines'][0]['quantity'] = '0';
    try { $method->invoke($service, $bad); } catch (InvalidArgumentException $expected) { continue; }
    throw new RuntimeException('Invalid schema accepted: ' . $case);
}
echo "CROSS_TRANSFER_KEY_ORDER_CONTRACT_OK\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\InventoryCrossSubjectTransferServices;

$service = new InventoryCrossSubjectTransferServices();
$method = new ReflectionMethod($service, 'createDraftBatch');
$draft = ['lines' => [['product_id' => 1]]];

/** Invalid envelopes must fail before a transaction or any stock/draft write begins. */
function assertInvalidBatch(ReflectionMethod $method, InventoryCrossSubjectTransferServices $service, array $input): void
{
    try {
        $method->invoke($service, $input, static function (): array {
            throw new RuntimeException('unexpected_draft_write');
        });
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'inventory_cross_transfer_batch_invalid') throw $exception;
        return;
    }
    throw new RuntimeException('batch_validation_failed');
}

assertInvalidBatch($method, $service, ['drafts' => [$draft], 'idempotency_key' => 'transfer-batch-2-01234567']);
assertInvalidBatch($method, $service, ['drafts' => array_fill(0, 201, $draft), 'idempotency_key' => 'transfer-batch-201-01234567']);
assertInvalidBatch($method, $service, ['drafts' => [['lines' => array_fill(0, 501, ['product_id' => 1])]], 'idempotency_key' => 'transfer-batch-1-01234567']);
assertInvalidBatch($method, $service, ['drafts' => [['lines' => []], ['lines' => []]], 'idempotency_key' => 'transfer-batch-1-01234567']);
assertInvalidBatch($method, $service, ['drafts' => [$draft], 'idempotency_key' => 'transfer-batch-1-01234567', 'dispatch_after_create' => 'true']);
assertInvalidBatch($method, $service, ['drafts' => [$draft], 'idempotency_key' => 'transfer-batch-1-01234567', 'dispatch_after_create' => true]);
echo "CROSS_TRANSFER_BATCH_CONTRACT_OK\n";
